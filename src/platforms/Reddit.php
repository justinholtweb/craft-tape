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
 * Reddit — the Reddit Pixel and the Conversions API.
 *
 * Reddit deduplicates on `conversion_id` rather than `event_id`, and it is one of the few platforms
 * that will happily accept the same conversion twice if the field is left off, so it is always
 * sent.
 */
class Reddit extends BasePlatform
{
    private const EVENTS = [
        TrackingEvent::PAGE_VIEW => 'PageVisit',
        TrackingEvent::VIEW_ITEM => 'ViewContent',
        TrackingEvent::VIEW_ITEM_LIST => 'ViewContent',
        TrackingEvent::SEARCH => 'Search',
        TrackingEvent::ADD_TO_CART => 'AddToCart',
        TrackingEvent::ADD_TO_WISHLIST => 'AddToWishlist',
        TrackingEvent::PURCHASE => 'Purchase',
        TrackingEvent::GENERATE_LEAD => 'Lead',
        TrackingEvent::SIGN_UP => 'SignUp',
        TrackingEvent::SUBSCRIBE => 'Lead',
    ];

    public static function handle(): string
    {
        return 'reddit';
    }

    /** In the browser only, as Reddit's `Custom` event carrying the name. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'Reddit';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The Reddit Pixel and the Conversions API.');
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
                'instructions' => Craft::t('tape', 'Ads → Events Manager. Starts with `a2_` or `t2_`.'),
                'placeholder' => 'a2_abcdefghijkl',
                'required' => true,
                'pattern' => '/^[at]2_[a-z0-9]{6,30}$/i',
            ],
            'accountId' => [
                'label' => Craft::t('tape', 'Ad account ID'),
                'instructions' => Craft::t('tape', 'Only needed for server-side events. Starts with `t2_`.'),
            ],
            'accessToken' => [
                'label' => Craft::t('tape', 'Conversions API token'),
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
        return array_keys(self::EVENTS);
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $pixelId = $destination->setting('pixelId');

        if ($pixelId === null) {
            return [];
        }

        return [
            'loader' => 'rdt',
            'config' => [
                'id' => $pixelId,
                'matching' => Plugin::getInstance()->getSettings()->enhancedConversions
                    ? Identity::redditUserData($context['userData'] ?? null)
                    : [],
            ],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $name = self::EVENTS[$event->name] ?? null;
        $custom = [];

        if ($name === null && $event->isCustom()) {
            $name = 'Custom';
            $custom = ['customEventName' => $event->name];
        }

        if ($name === null) {
            return [];
        }

        return [$this->call('rdt', ['track', $name, $custom + $this->clean([
            'currency' => $this->currency($event),
            'value' => $this->money($event->getValue()),
            'itemCount' => $event->getItemCount() ?: null,
            'transactionId' => $event->transactionId,
            'conversionId' => $event->eventId,
            'products' => $this->mapItems($event, fn(EventItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->getCategoryPath(),
            ]),
        ]) + $event->params])];
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        $accountId = $destination->setting('accountId');
        $token = $destination->setting('accessToken');
        $name = self::EVENTS[$event->name] ?? null;

        if ($accountId === null || $token === null || $name === null) {
            return null;
        }

        return [
            'url' => 'https://ads-api.reddit.com/api/v2.0/conversions/events/' . rawurlencode((string)$accountId),
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => [
                'test_mode' => $destination->testMode,
                'events' => [$this->clean([
                    'event_at' => gmdate('c', $event->occurredAt ?? time()),
                    'event_type' => ['tracking_type' => $name],
                    'click_id' => $event->userData?->getClickId('rdt_cid'),
                    'event_metadata' => $this->clean([
                        'currency' => $this->currency($event),
                        'value_decimal' => $this->money($event->getValue()),
                        'item_count' => $event->getItemCount() ?: null,
                        'conversion_id' => $event->eventId,
                        'products' => $this->mapItems($event, fn(EventItem $item) => [
                            'id' => $item->id,
                            'name' => $item->name,
                            'category' => $item->getCategoryPath(),
                        ]),
                    ]),
                    'user' => Identity::redditServerUserData($event->userData),
                ])],
            ],
        ];
    }
}
