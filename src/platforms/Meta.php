<?php

namespace justinholtweb\tape\platforms;

use Craft;
use craft\helpers\Html;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * Meta — the Facebook/Instagram pixel and the Conversions API.
 *
 * The pixel and the API are the same event sent twice, on purpose. Browser tags are lost to ad
 * blockers, ITP and people closing the tab; server calls are lost to nothing but are missing the
 * browser context. Sending both and letting Meta collapse them is not belt-and-braces, it is the
 * documented design — and it works only if both carry the same `event_id`, which is why the ID is
 * minted once on {@see TrackingEvent} and never per transport.
 *
 * The other half of deduplication is `_fbc` and `_fbp`. Those two cookies do more for attribution
 * than hashed email ever will, and a server-side event sent without them is close to useless, so
 * {@see Identity} reads them out of the request rather than hoping.
 */
class Meta extends BasePlatform
{
    /** Tape's event names in Meta's vocabulary. Anything absent goes out as a custom event. */
    private const STANDARD_EVENTS = [
        TrackingEvent::PAGE_VIEW => 'PageView',
        TrackingEvent::VIEW_ITEM => 'ViewContent',
        TrackingEvent::SEARCH => 'Search',
        TrackingEvent::ADD_TO_CART => 'AddToCart',
        TrackingEvent::ADD_TO_WISHLIST => 'AddToWishlist',
        TrackingEvent::BEGIN_CHECKOUT => 'InitiateCheckout',
        TrackingEvent::ADD_PAYMENT_INFO => 'AddPaymentInfo',
        TrackingEvent::PURCHASE => 'Purchase',
        TrackingEvent::GENERATE_LEAD => 'Lead',
        TrackingEvent::SIGN_UP => 'CompleteRegistration',
        TrackingEvent::SUBSCRIBE => 'Subscribe',
        TrackingEvent::CONTACT => 'Contact',
    ];

    private const CUSTOM_EVENTS = [
        TrackingEvent::VIEW_ITEM_LIST => 'ViewCategory',
        TrackingEvent::SELECT_ITEM => 'SelectItem',
        TrackingEvent::REMOVE_FROM_CART => 'RemoveFromCart',
        TrackingEvent::VIEW_CART => 'ViewCart',
        TrackingEvent::ADD_SHIPPING_INFO => 'AddShippingInfo',
        TrackingEvent::LOGIN => 'Login',
        TrackingEvent::SCROLL => 'Scroll',
        TrackingEvent::CLICK => 'Click',
    ];

    public static function handle(): string
    {
        return 'meta';
    }

    /** Sent as `trackCustom` under its own name, which a custom conversion or audience can be built on. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'Meta';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The Facebook and Instagram pixel, with matching Conversions API events and advanced matching.');
    }

    public function settingFields(): array
    {
        return [
            'pixelId' => [
                'label' => Craft::t('tape', 'Pixel ID'),
                'instructions' => Craft::t('tape', 'Events Manager → Data sources → your pixel. Fifteen or sixteen digits.'),
                'placeholder' => '123456789012345',
                'required' => true,
                'pattern' => '/^[0-9]{10,20}$/',
                'patternMessage' => Craft::t('tape', 'A Meta pixel ID is digits only.'),
            ],
            'accessToken' => [
                'label' => Craft::t('tape', 'Conversions API access token'),
                'instructions' => Craft::t('tape', 'Only needed for server-side events. Events Manager → Settings → Conversions API → Generate access token. Store it in an environment variable.'),
                'type' => 'autosuggest',
            ],
            'apiVersion' => [
                'label' => Craft::t('tape', 'Graph API version'),
                'instructions' => Craft::t('tape', 'Meta retires a version roughly two years after release. Bump this when Events Manager starts warning about it.'),
                'placeholder' => 'v23.0',
                'pattern' => '/^v[0-9]{1,3}\.[0-9]$/',
            ],
            'advancedMatching' => [
                'label' => Craft::t('tape', 'Advanced matching'),
                'instructions' => Craft::t('tape', 'Sends a hashed email, phone and name with the pixel so Meta can match conversions to accounts. Hashed in Craft, never in the page source.'),
                'type' => 'lightswitch',
                'default' => true,
            ],
        ];
    }

    public function supportsServerSide(): bool
    {
        return true;
    }

    public function supportedEvents(): array
    {
        return array_merge(array_keys(self::STANDARD_EVENTS), array_keys(self::CUSTOM_EVENTS));
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $pixelId = $destination->setting('pixelId');

        if ($pixelId === null) {
            return [];
        }

        $matching = [];

        if ($destination->setting('advancedMatching', true) && Plugin::getInstance()->enhancedConversions()) {
            $matching = Identity::metaUserData($context['userData'] ?? null);
        }

        return [
            'loader' => 'fbq',
            'config' => [
                'id' => $pixelId,
                'matching' => $matching,
                // Meta's own snippet fires PageView on init. Tape fires page views itself, from
                // the event queue, so that they are subject to the same consent and exclusion
                // rules as everything else — leaving both on double-counts every page.
                'autoPageView' => false,
            ],
            'noscript' => Html::tag('noscript', Html::tag('img', '', [
                'height' => '1',
                'width' => '1',
                'style' => 'display:none',
                'alt' => '',
                'src' => 'https://www.facebook.com/tr?id=' . rawurlencode($pixelId) . '&ev=PageView&noscript=1',
            ])),
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        [$method, $metaName] = $this->metaEvent($event->name);

        if ($metaName === null) {
            return [];
        }

        return [$this->call('fbq', [$method, $metaName, $this->customData($event), ['eventID' => $event->eventId]])];
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        $pixelId = $destination->setting('pixelId');
        $token = $destination->setting('accessToken');
        [, $metaName] = $this->metaEvent($event->name);

        if ($pixelId === null || $token === null || $metaName === null) {
            return null;
        }

        $version = (string)$destination->setting('apiVersion', 'v23.0');

        $payload = [
            'event_name' => $metaName,
            'event_time' => $event->occurredAt,
            'event_id' => $event->eventId,
            'action_source' => 'website',
            'event_source_url' => $event->userData?->sourceUrl,
            'user_data' => Identity::metaServerUserData($event->userData),
            'custom_data' => $this->customData($event),
        ];

        $body = ['data' => [$this->clean($payload)]];

        if ($destination->testMode && $destination->testCode) {
            $body['test_event_code'] = $destination->testCode;
        }

        return [
            'url' => "https://graph.facebook.com/$version/" . rawurlencode($pixelId) . '/events',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'query' => ['access_token' => $token],
            'body' => $body,
        ];
    }

    /**
     * Meta answers 200 with `events_received: 0` when it silently drops everything, which is what a
     * malformed `user_data` block looks like from the outside.
     */
    public function isServerResponseOk(int $statusCode, string $body): bool
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $decoded = json_decode($body, true);

        return !is_array($decoded) || ($decoded['events_received'] ?? 1) > 0;
    }

    /** @return array{0: string, 1: string|null} `track`/`trackCustom`, and the Meta event name. */
    private function metaEvent(string $name): array
    {
        if (isset(self::STANDARD_EVENTS[$name])) {
            return ['track', self::STANDARD_EVENTS[$name]];
        }

        if (isset(self::CUSTOM_EVENTS[$name])) {
            return ['trackCustom', self::CUSTOM_EVENTS[$name]];
        }

        // An event nobody has mapped is still worth sending — as a custom event under its own
        // name, which is at least something a Meta audience can be built from.
        return ['trackCustom', preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1 ? $name : null];
    }

    private function customData(TrackingEvent $event): array
    {
        $data = [
            'currency' => $this->currency($event),
            'value' => $this->money($event->getValue()),
            'content_type' => $event->items === [] ? null : 'product',
            'content_ids' => $event->getItemIds(),
            'contents' => $this->mapItems($event, fn(EventItem $item) => [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'item_price' => $item->price !== null ? $this->money($item->price) : null,
            ]),
            'num_items' => $event->items === [] ? null : $event->getItemCount(),
            'content_name' => $event->items === [] ? null : ($event->items[0]->name ?? null),
            'content_category' => $event->items === [] ? null : $event->items[0]->getCategoryPath(),
            'order_id' => $event->transactionId,
            'search_string' => $event->searchTerm,
        ];

        return $this->clean($data) + $event->params;
    }
}
