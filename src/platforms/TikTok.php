<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * TikTok — the pixel and the Events API.
 *
 * TikTok deduplicates on `event_id` like Meta does, and its attribution leans even harder on the
 * click identifier: `ttclid` arrives as a query parameter and is expected to be remembered in a
 * `_ttp` cookie for later conversions, so {@see Identity} keeps both.
 */
class TikTok extends BasePlatform
{
    private const EVENTS = [
        TrackingEvent::VIEW_ITEM => 'ViewContent',
        TrackingEvent::VIEW_ITEM_LIST => 'ViewContent',
        TrackingEvent::SEARCH => 'Search',
        TrackingEvent::ADD_TO_CART => 'AddToCart',
        TrackingEvent::ADD_TO_WISHLIST => 'AddToWishlist',
        TrackingEvent::BEGIN_CHECKOUT => 'InitiateCheckout',
        TrackingEvent::ADD_PAYMENT_INFO => 'AddPaymentInfo',
        TrackingEvent::PURCHASE => 'CompletePayment',
        TrackingEvent::GENERATE_LEAD => 'SubmitForm',
        TrackingEvent::SIGN_UP => 'CompleteRegistration',
        TrackingEvent::CONTACT => 'Contact',
        TrackingEvent::SUBSCRIBE => 'Subscribe',
    ];

    public static function handle(): string
    {
        return 'tiktok';
    }

    /** In the browser only: `ttq.track` takes a custom name, the Events API is kept to standard ones. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'TikTok';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The TikTok pixel plus matching Events API calls, deduplicated on a shared event ID.');
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_PRO;
    }

    public function settingFields(): array
    {
        return [
            'pixelId' => [
                'label' => Craft::t('tape', 'Pixel ID'),
                'instructions' => Craft::t('tape', 'TikTok Ads Manager → Assets → Events → Web events. A twenty-character code.'),
                'placeholder' => 'C4A1BCDEFGHIJKLMNOPQ',
                'required' => true,
                'pattern' => '/^[A-Z0-9]{15,30}$/i',
            ],
            'accessToken' => [
                'label' => Craft::t('tape', 'Events API access token'),
                'instructions' => Craft::t('tape', 'Only needed for server-side events. Generated alongside the pixel, under Events API.'),
                'type' => 'autosuggest',
            ],
        ];
    }

    public function supportsServerSide(): bool
    {
        return true;
    }

    public function supportedEvents(): array
    {
        return array_merge([TrackingEvent::PAGE_VIEW], array_keys(self::EVENTS));
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $pixelId = $destination->setting('pixelId');

        if ($pixelId === null) {
            return [];
        }

        return [
            'loader' => 'ttq',
            'config' => [
                'id' => $pixelId,
                'identify' => Plugin::getInstance()->enhancedConversions()
                    ? Identity::tiktokUserData($context['userData'] ?? null)
                    : [],
            ],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        if ($event->name === TrackingEvent::PAGE_VIEW) {
            return [$this->call('ttq.page', [])];
        }

        $name = self::EVENTS[$event->name] ?? ($event->isCustom() ? $event->name : null);

        if ($name === null) {
            return [];
        }

        return [$this->call('ttq.track', [$name, $this->properties($event), ['event_id' => $event->eventId]])];
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        $pixelId = $destination->setting('pixelId');
        $token = $destination->setting('accessToken');
        $name = self::EVENTS[$event->name] ?? null;

        if ($pixelId === null || $token === null || $name === null) {
            return null;
        }

        $body = [
            'event_source' => 'web',
            'event_source_id' => $pixelId,
            'data' => [$this->clean([
                'event' => $name,
                'event_time' => $event->occurredAt,
                'event_id' => $event->eventId,
                'user' => Identity::tiktokServerUserData($event->userData),
                'properties' => $this->properties($event),
                'page' => $this->clean(['url' => $event->userData?->sourceUrl]),
            ])],
        ];

        if ($destination->testMode && $destination->testCode) {
            $body['test_event_code'] = $destination->testCode;
        }

        return [
            'url' => 'https://business-api.tiktok.com/open_api/v1.3/event/track/',
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Access-Token' => $token,
            ],
            'body' => $body,
        ];
    }

    /** TikTok answers HTTP 200 with a non-zero `code` for every rejection it has. */
    public function isServerResponseOk(int $statusCode, string $body): bool
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $decoded = json_decode($body, true);

        return !is_array($decoded) || (int)($decoded['code'] ?? 0) === 0;
    }

    private function properties(TrackingEvent $event): array
    {
        return $this->clean([
            'currency' => $this->currency($event),
            'value' => $this->money($event->getValue()),
            'contents' => $this->mapItems($event, fn(EventItem $item) => [
                'content_id' => $item->id,
                'content_type' => 'product',
                'content_name' => $item->name,
                'content_category' => $item->getCategoryPath(),
                'brand' => $item->brand,
                'quantity' => $item->quantity,
                'price' => $item->price !== null ? $this->money($item->price) : null,
            ]),
            'query' => $event->searchTerm,
            'order_id' => $event->transactionId,
            'description' => $event->listName,
        ]) + $event->params;
    }
}
