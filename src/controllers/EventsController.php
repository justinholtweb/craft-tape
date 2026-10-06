<?php

namespace justinholtweb\tape\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The three front-end endpoints.
 *
 * Every one of them is anonymous and CSRF-exempt, because they are called by a runtime living in a
 * page that may well have come out of a full-page cache — a CSRF token baked into a cached page is
 * either invalid for everyone or shared by everyone, and neither is worth having.
 *
 * That makes the trust model explicit and worth stating: **nothing a visitor sends here can decide
 * what a conversion is worth.** Event names are checked against a fixed list that excludes
 * `purchase` and `refund` outright; prices come from Commerce, looked up server-side from a product
 * ID; a `value` in the request body is ignored. The worst a determined visitor can do is fire, in
 * their own browser, events they could have fired by calling `fbq` from the console.
 *
 * The one thing a visitor *can* do here that the console cannot is make the server send — a
 * Conversions API call per request, with no browser involved. So both endpoints that can queue one
 * are throttled per IP ({@see \justinholtweb\tape\services\Events::allowAnonymousRequest()}),
 * and over the limit they answer as if nothing were configured.
 */
class EventsController extends Controller
{
    /** Keys the map endpoint reads itself, wherever in the body they arrive. */
    private const LIFTED_KEYS = ['id', 'ids', 'qty', 'searchTerm', 'listId', 'listName'];

    protected array|int|bool $allowAnonymous = true;

    public $enableCsrfValidation = false;

    /**
     * Maps an event the browser has just decided happened.
     *
     * Answers with finished per-destination payloads rather than raw data, which is what keeps the
     * platform adapters — and therefore every decision about what gets sent — on the server.
     */
    public function actionMap(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $body = $this->body();
        $name = (string)($body['event'] ?? '');

        // A custom name carries no value — none is ever read from a request — so it can be asked
        // for as freely as `view_item`.
        if (!in_array($name, TrackingEvent::REQUESTABLE_EVENTS, true) && !TrackingEvent::isCustomName($name)) {
            throw new BadRequestHttpException('Unrecognised event.');
        }

        $plugin = Plugin::getInstance();

        if (
            !$plugin->events->shouldTrack()
            || !$plugin->events->isEventEnabled($name)
            || !$plugin->events->allowAnonymousRequest('map')
        ) {
            return $this->asJson(['events' => []]);
        }

        $event = new TrackingEvent([
            'name' => $name,
            'source' => TrackingEvent::SOURCE_BROWSER,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]);

        // `tape.track(name, {id, qty, searchTerm, …})` posts everything under `params`; the same
        // keys at the top level are accepted too. Either way they are lifted out here rather than
        // forwarded as free-form parameters.
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $known = array_intersect_key($params, array_flip(self::LIFTED_KEYS)) + $body;
        $params = array_diff_key($params, array_flip(self::LIFTED_KEYS));

        $event->searchTerm = $this->text($known['searchTerm'] ?? null, 200);
        $event->listId = $this->text($known['listId'] ?? null, 100);
        $event->listName = $this->text($known['listName'] ?? null, 200);
        $event->params = $this->params($params);
        $event->items = $this->resolveItems($known);
        $event->userData = $plugin->events->getUserData();

        $result = $plugin->events->process($event);

        return $this->asJson([
            'events' => $result['dispatch'] === [] ? [] : [[
                'n' => $result['name'],
                'i' => $result['eventId'],
                'd' => $result['dispatch'],
            ]],
        ]);
    }

    /**
     * Records what the browser actually managed to dispatch.
     *
     * The difference between “Tape wrote a purchase tag into the page” and “the platform received
     * it” — and therefore the difference between a conversion that needs recovering and one that
     * does not. Arrives by `sendBeacon`, so it survives the visitor closing the tab.
     */
    public function actionConfirm(): Response
    {
        $this->requirePostRequest();

        $body = $this->body();
        $eventId = $this->text($body['eventId'] ?? null, 64);

        if ($eventId === null) {
            throw new BadRequestHttpException('No event.');
        }

        $delivered = $this->handles($body['delivered'] ?? []);
        $blocked = $this->handles($body['blocked'] ?? []);

        Plugin::getInstance()->ledger->confirm($eventId, $delivered, $blocked);

        return $this->asJson(['ok' => true]);
    }

    /**
     * The server-side half of a trigger that has just fired.
     *
     * The browser already has its payloads — they were pre-mapped when the page rendered — so this
     * exists only to write the ledger row and queue any Conversions API calls. Only triggers whose
     * destinations actually have a server-side half ask for it.
     */
    public function actionTrigger(): Response
    {
        $this->requirePostRequest();

        $body = $this->body();
        $uid = $this->text($body['trigger'] ?? null, 64);
        $plugin = Plugin::getInstance();

        if ($uid === null) {
            throw new BadRequestHttpException('No trigger.');
        }

        $trigger = $plugin->destinations->getTriggerByUid($uid);

        if (
            $trigger === null
            || !$trigger->enabled
            || in_array($trigger->event, [TrackingEvent::PURCHASE, TrackingEvent::REFUND], true)
            || !$plugin->events->shouldTrack()
            || !$plugin->events->allowAnonymousRequest('trigger')
        ) {
            return $this->asJson(['ok' => false]);
        }

        // The browser's own ID for this firing, if it sent a well-formed one. Without it the event
        // mints its own, and there is nothing to deduplicate against.
        $clientEventId = $this->eventId($body['eventId'] ?? null);

        $event = new TrackingEvent([
            'name' => $trigger->event,
            'eventId' => $clientEventId,
            'value' => $trigger->value,
            'currency' => $trigger->currency,
            'source' => TrackingEvent::SOURCE_BROWSER,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]);

        $event->params = $this->params($body['params'] ?? []);
        $event->userData = $plugin->events->getUserData();

        // One firing, one send. A retried or replayed call with an event ID the ledger already holds
        // is answered as done rather than queuing another Conversions API call — under a lock, so
        // the same ID posted twice at once can't both get past the check.
        $mutex = Craft::$app->getMutex();
        $lock = $clientEventId !== '' ? 'tape:trigger:' . $clientEventId : null;

        if ($lock !== null && !$mutex->acquire($lock, 2)) {
            return $this->asJson(['ok' => true]);
        }

        try {
            if ($lock !== null && $plugin->ledger->hasEventId($clientEventId)) {
                return $this->asJson(['ok' => true]);
            }

            // Browser payloads are discarded: the page already has them. This call is only here for
            // the ledger row and the server-side send.
            $plugin->events->process($event);
        } finally {
            if ($lock !== null) {
                $mutex->release($lock);
            }
        }

        return $this->asJson(['ok' => true]);
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function body(): array
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getRawBody();

        if ($raw !== '' && str_contains((string)$request->getContentType(), 'json')) {
            // A JSON body is not parsed into `bodyParams`, and Craft's bracket notation would not
            // reach into it if it were.
            $decoded = Json::decodeIfJson($raw);

            return is_array($decoded) ? $decoded : [];
        }

        return $request->getBodyParams();
    }

    /**
     * Turns the product IDs in a request into items with real prices.
     *
     * The prices are never taken from the request. A visitor may say *which* product they added;
     * what it is worth is Commerce's to answer, and the difference is the whole security model of
     * this endpoint.
     *
     * @param array<string, mixed> $body
     * @return EventItem[]
     */
    private function resolveItems(array $body): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->commerce->isInstalled()) {
            return [];
        }

        $ids = $body['ids'] ?? ($body['id'] ?? null);
        $ids = array_slice(array_filter(array_map('intval', (array)$ids)), 0, 50);

        if ($ids === []) {
            return [];
        }

        $quantity = max(1, min(999, (int)($body['qty'] ?? 1)));
        // Live only, and the product too: an anonymous caller must not be able to read the name and
        // price of a product that has not been released, simply by guessing its ID. A variant's own
        // status is only half of that — an enabled variant of a disabled or not-yet-published
        // product resolved until 5.0.1.
        $variants = \craft\commerce\elements\Variant::find()
            ->id($ids)
            ->status('enabled')
            ->hasProduct(['status' => \craft\commerce\elements\Product::STATUS_LIVE])
            ->all();
        $items = [];
        $index = 1;

        foreach ($variants as $variant) {
            $item = $plugin->commerce->itemFromVariant($variant, $quantity);

            if ($item !== null) {
                $item->index = $index++;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Extra parameters, kept to scalars and kept small.
     *
     * `value` and `currency` are dropped on purpose: a conversion's worth is never a visitor's to
     * declare. It comes from the cart, the order, or the trigger's own configuration.
     *
     * @return array<string, string|int|float|bool>
     */
    private function params(mixed $params): array
    {
        if (!is_array($params)) {
            return [];
        }

        $clean = [];

        foreach ($params as $key => $value) {
            if (count($clean) >= 20) {
                break;
            }

            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/i', $key) !== 1) {
                continue;
            }

            if (in_array(strtolower($key), ['value', 'currency', 'transaction_id', 'transactionid'], true)) {
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 200);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @return string[] */
    private function handles(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $handles = [];

        foreach (array_slice($values, 0, 50) as $value) {
            if (is_string($value) && preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{0,63}$/', $value) === 1) {
                $handles[] = $value;
            }
        }

        return $handles;
    }

    /**
     * The browser's event ID for a trigger, so its tag and the server call deduplicate.
     *
     * Only ever the runtime's own UUIDs; anything else is replaced, so a caller cannot choose an ID
     * that collides with — and suppresses on the platform — somebody else's conversion.
     */
    private function eventId(mixed $value): string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
            ? strtolower($value)
            : '';
    }

    private function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
