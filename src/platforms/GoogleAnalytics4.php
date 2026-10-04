<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;

/**
 * Google Analytics 4, with the enhanced-ecommerce funnel.
 *
 * GA4's event vocabulary is Tape's internal one, so this adapter is nearly a pass-through — which
 * is deliberate. Picking the most completely specified schema as the internal shape means the
 * adapter with the most users is the one with the least code and the fewest places to be wrong.
 *
 * Server-side is the Measurement Protocol, which is a genuinely awkward API: it answers `204` to
 * everything, valid or not, and the only way to see a rejection is the separate `/debug/mp/collect`
 * endpoint. It also needs a `client_id` that matches the browser's, which is why Tape reads the
 * `_ga` cookie rather than minting one — a Measurement Protocol event with a fresh client ID
 * arrives as a brand-new user with no session, and quietly wrecks the very reports it was sent to
 * complete.
 */
class GoogleAnalytics4 extends GtagPlatform
{
    public static function handle(): string
    {
        return 'ga4';
    }

    /** GA4 takes any event name, in the browser and through the Measurement Protocol. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'Google Analytics 4';
    }

    public function description(): string
    {
        return Craft::t('tape', 'Enhanced ecommerce reporting and the full checkout funnel, plus the Measurement Protocol for server-side events.');
    }

    public function defaultConsentCategory(): string
    {
        return Consent::CATEGORY_ANALYTICS;
    }

    public function settingFields(): array
    {
        return [
            'measurementId' => [
                'label' => Craft::t('tape', 'Measurement ID'),
                'instructions' => Craft::t('tape', 'Admin → Data streams → your web stream. Starts with `G-`.'),
                'placeholder' => 'G-XXXXXXXXXX',
                'required' => true,
                'pattern' => '/^G-[A-Z0-9]{6,15}$/i',
                'patternMessage' => Craft::t('tape', 'A GA4 measurement ID looks like `G-XXXXXXXXXX`. `UA-` IDs are Universal Analytics, which stopped collecting data in 2023.'),
            ],
            'apiSecret' => [
                'label' => Craft::t('tape', 'Measurement Protocol API secret'),
                'instructions' => Craft::t('tape', 'Only needed for server-side events. Admin → Data streams → Measurement Protocol API secrets.'),
                'type' => 'autosuggest',
            ],
            'sendUserId' => [
                'label' => Craft::t('tape', 'Send the Craft user ID'),
                'instructions' => Craft::t('tape', 'Sets GA4’s `user_id` for logged-in visitors on pages that carry a conversion, and on server-side events, so purchases across devices join up. Never on ordinary pages, which may be cached and served to somebody else.'),
                'type' => 'lightswitch',
            ],
        ];
    }

    public function supportsServerSide(): bool
    {
        return true;
    }

    protected function tagId(Destination $destination): ?string
    {
        return $destination->setting('measurementId');
    }

    protected function configParams(Destination $destination, array $context = []): array
    {
        $params = [];

        // Only where matching data is allowed — a page carrying a conversion, which is never
        // served from cache. Written into an ordinary page, one visitor's user ID would be cached
        // and handed to everyone after them, and reading it would open a session on every page.
        if ($destination->setting('sendUserId') && ($context['userData'] ?? null) !== null) {
            $userId = Craft::$app->getUser()->getIdentity()?->id;

            if ($userId !== null) {
                $params['user_id'] = (string)$userId;
            }
        }

        return $params;
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $params = $this->eventParams($event);
        $params['send_to'] = $this->tagId($destination);

        return [$this->call('gtag', ['event', $event->name, $this->clean($params)])];
    }

    /**
     * The GA4 parameter set for an event.
     *
     * Shared with the Measurement Protocol and with Google Tag Manager's data layer, because all
     * three want exactly this object and disagreeing with itself across the three is how a store
     * ends up with browser and server purchase totals that differ by the shipping.
     */
    public function eventParams(TrackingEvent $event): array
    {
        $params = $event->params;
        $currency = $this->currency($event);

        $params += match ($event->name) {
            TrackingEvent::PURCHASE => [
                'transaction_id' => $event->transactionId,
                'value' => $this->money($event->getValue()),
                'tax' => $event->tax !== null ? $this->money($event->tax) : null,
                'shipping' => $event->shipping !== null ? $this->money($event->shipping) : null,
                'currency' => $currency,
                'coupon' => $event->coupon,
            ],
            TrackingEvent::REFUND => [
                'transaction_id' => $event->transactionId,
                'value' => $this->money(abs($event->getValue())),
                'currency' => $currency,
            ],
            TrackingEvent::VIEW_ITEM_LIST, TrackingEvent::SELECT_ITEM => [
                'item_list_id' => $event->listId,
                'item_list_name' => $event->listName,
            ],
            TrackingEvent::ADD_SHIPPING_INFO => [
                'value' => $this->money($event->getValue()),
                'currency' => $currency,
                'coupon' => $event->coupon,
                'shipping_tier' => $event->shippingTier,
            ],
            TrackingEvent::ADD_PAYMENT_INFO => [
                'value' => $this->money($event->getValue()),
                'currency' => $currency,
                'coupon' => $event->coupon,
                'payment_type' => $event->paymentType,
            ],
            TrackingEvent::BEGIN_CHECKOUT => [
                'value' => $this->money($event->getValue()),
                'currency' => $currency,
                'coupon' => $event->coupon,
            ],
            TrackingEvent::SEARCH => [
                'search_term' => $event->searchTerm,
            ],
            TrackingEvent::PAGE_VIEW => [],
            default => $event->items === [] ? [] : [
                'value' => $this->money($event->getValue()),
                'currency' => $currency,
            ],
        };

        if ($event->items !== []) {
            $params['items'] = $this->mapItems($event, fn(EventItem $item, int $index) => $this->mapItem($item, $index, $currency));
        }

        return $params;
    }

    private function mapItem(EventItem $item, int $index, string $currency): array
    {
        $mapped = [
            'item_id' => $item->id,
            'item_name' => $item->name,
            'item_brand' => $item->brand,
            'item_variant' => $item->variant,
            'price' => $item->price !== null ? $this->money($item->price) : null,
            'discount' => $item->discount !== null ? $this->money($item->discount) : null,
            'quantity' => $item->quantity,
            'currency' => $currency,
            'index' => $item->index ?? ($index + 1),
            'item_list_id' => $item->listId,
            'item_list_name' => $item->listName,
        ];

        // GA4 takes five levels, named `item_category` then `item_category2`…`item_category5`.
        foreach (array_slice($item->categories, 0, 5) as $level => $category) {
            $mapped['item_category' . ($level === 0 ? '' : $level + 1)] = $category;
        }

        return $mapped;
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        $measurementId = $this->tagId($destination);
        $secret = $destination->setting('apiSecret');

        if ($measurementId === null || $secret === null) {
            return null;
        }

        $clientId = Identity::ga4ClientId($event->userData);

        // A refund is issued from the control panel, where there is no `_ga` cookie to read. GA4
        // matches refunds on the transaction ID and documents that any client ID will do, so one
        // is derived from the event — stable, so a retried send is the same refund.
        if ($clientId === null && $event->name === TrackingEvent::REFUND) {
            $clientId = hexdec(substr(md5($event->eventId), 0, 7)) . '.' . hexdec(substr(md5($event->eventId), 7, 7));
        }

        if ($clientId === null) {
            return null;
        }

        $params = $this->eventParams($event);
        unset($params['send_to']);

        // Without this the event is attributed to `(not set)` rather than to whatever brought the
        // visitor to the site, which makes server-side purchases look like they came from nowhere.
        $params['engagement_time_msec'] = 1;
        $params['session_id'] = Identity::ga4SessionId($event->userData, $measurementId);

        $body = [
            'client_id' => $clientId,
            'timestamp_micros' => $event->occurredAt * 1000000,
            'non_personalized_ads' => false,
            'events' => [[
                'name' => $event->name,
                'params' => $this->clean($params),
            ]],
        ];

        if ($event->userData?->userId !== null && $destination->setting('sendUserId')) {
            $body['user_id'] = (string)$event->userData->userId;
        }

        $host = $destination->testMode
            ? 'https://www.google-analytics.com/debug/mp/collect'
            : 'https://www.google-analytics.com/mp/collect';

        return [
            'url' => $host,
            'method' => 'POST',
            'query' => ['measurement_id' => $measurementId, 'api_secret' => $secret],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    /**
     * The Measurement Protocol answers 204 to everything it accepts *and* to most things it
     * rejects. The debug endpoint is the only one that says anything, and it says it in a 200.
     */
    public function isServerResponseOk(int $statusCode, string $body): bool
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        if ($body === '') {
            return true;
        }

        $decoded = json_decode($body, true);

        return !is_array($decoded) || ($decoded['validationMessages'] ?? []) === [];
    }
}
