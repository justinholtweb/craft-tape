<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * Snapchat — the Snap Pixel and the Conversions API.
 *
 * Snap's parameter names are its own: `price` rather than `value`, `number_items` rather than
 * `num_items`, uppercase event names. It also rejects a payload containing a null outright rather
 * than ignoring the key, which is why {@see BasePlatform::clean()} exists.
 */
class Snapchat extends BasePlatform
{
    private const EVENTS = [
        TrackingEvent::PAGE_VIEW => 'PAGE_VIEW',
        TrackingEvent::VIEW_ITEM => 'VIEW_CONTENT',
        TrackingEvent::VIEW_ITEM_LIST => 'LIST_VIEW',
        TrackingEvent::SEARCH => 'SEARCH',
        TrackingEvent::ADD_TO_CART => 'ADD_CART',
        TrackingEvent::ADD_TO_WISHLIST => 'ADD_TO_WISHLIST',
        TrackingEvent::VIEW_CART => 'VIEW_CONTENT',
        TrackingEvent::BEGIN_CHECKOUT => 'START_CHECKOUT',
        TrackingEvent::ADD_PAYMENT_INFO => 'ADD_BILLING',
        TrackingEvent::PURCHASE => 'PURCHASE',
        TrackingEvent::SIGN_UP => 'SIGN_UP',
        TrackingEvent::GENERATE_LEAD => 'SIGN_UP',
        TrackingEvent::SUBSCRIBE => 'SUBSCRIBE',
    ];

    public static function handle(): string
    {
        return 'snapchat';
    }

    public function displayName(): string
    {
        return 'Snapchat';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The Snap Pixel and the Conversions API.');
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
                'instructions' => Craft::t('tape', 'Ads Manager → Events Manager. A UUID.'),
                'placeholder' => '00000000-0000-0000-0000-000000000000',
                'required' => true,
                'pattern' => '/^[0-9a-f-]{20,40}$/i',
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
            'loader' => 'snaptr',
            'config' => [
                'id' => $pixelId,
                'matching' => Plugin::getInstance()->enhancedConversions()
                    ? Identity::snapchatUserData($context['userData'] ?? null)
                    : [],
            ],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $name = self::EVENTS[$event->name] ?? null;

        if ($name === null) {
            return [];
        }

        return [$this->call('snaptr', ['track', $name, $this->properties($event)])];
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
            'data' => [$this->clean([
                'event_name' => $name,
                'event_time' => $event->occurredAt,
                'event_id' => $event->eventId,
                'action_source' => 'WEB',
                'event_source_url' => $event->userData?->sourceUrl,
                'user_data' => Identity::snapchatServerUserData($event->userData),
                'custom_data' => $this->clean([
                    'currency' => $this->currency($event),
                    'value' => $this->money($event->getValue()),
                    'order_id' => $event->transactionId,
                    'content_ids' => $event->getItemIds(),
                    'num_items' => $event->getItemCount() ?: null,
                    'search_string' => $event->searchTerm,
                ]),
            ])],
        ];

        if ($destination->testMode) {
            $body['test_event_code'] = $destination->testCode ?: 'TEST';
        }

        return [
            'url' => 'https://tr.snapchat.com/v3/' . rawurlencode($pixelId) . '/events',
            'method' => 'POST',
            'query' => ['access_token' => $token],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    private function properties(TrackingEvent $event): array
    {
        return $this->clean([
            'price' => $this->money($event->getValue()),
            'currency' => $this->currency($event),
            'transaction_id' => $event->transactionId,
            'item_ids' => $event->getItemIds(),
            'number_items' => $event->getItemCount() ?: null,
            'item_category' => $event->items === [] ? null : $event->items[0]->getCategoryPath(),
            'search_string' => $event->searchTerm,
            'client_dedup_id' => $event->eventId,
        ]) + $event->params;
    }
}
