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
 */
class EventsController extends Controller
{
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

        if (!in_array($name, TrackingEvent::REQUESTABLE_EVENTS, true)) {
            throw new BadRequestHttpException('Unrecognised event.');
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->events->shouldTrack() || !$plugin->events->isEventEnabled($name)) {
            return $this->asJson(['events' => []]);
        }

        $event = new TrackingEvent([
            'name' => $name,
            'source' => TrackingEvent::SOURCE_BROWSER,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]);

        $event->searchTerm = $this->text($body['searchTerm'] ?? null, 200);
        $event->listId = $this->text($body['listId'] ?? null, 100);
        $event->listName = $this->text($body['listName'] ?? null, 200);
        $event->params = $this->params($body['params'] ?? []);
        $event->items = $this->resolveItems($body);
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

        if ($trigger === null || !$trigger->enabled || !$plugin->events->shouldTrack()) {
            return $this->asJson(['ok' => false]);
        }

        $event = new TrackingEvent([
            'name' => $trigger->event,
            'eventId' => $this->text($body['eventId'] ?? null, 64) ?: '',
            'value' => $trigger->value,
            'currency' => $trigger->currency,
            'source' => TrackingEvent::SOURCE_BROWSER,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]);

        $event->params = $this->params($body['params'] ?? []);
        $event->userData = $plugin->events->getUserData();

        // Browser payloads are discarded: the page already has them. This call is only here for the
        // ledger row and the server-side send.
        $plugin->events->process($event);

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
        $variants = \craft\commerce\elements\Variant::find()->id($ids)->status(null)->all();
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

    private function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
