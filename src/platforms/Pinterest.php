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
 * Pinterest — the tag and the Conversions API.
 *
 * Pinterest's event vocabulary is the smallest of any major platform: nine names, of which a store
 * uses five. Several distinct funnel steps therefore collapse onto `pagevisit`, which is correct
 * rather than lossy — Pinterest's optimisation works off those five signals and nothing else.
 */
class Pinterest extends BasePlatform
{
    private const EVENTS = [
        TrackingEvent::PAGE_VIEW => 'pagevisit',
        TrackingEvent::VIEW_ITEM => 'pagevisit',
        TrackingEvent::VIEW_ITEM_LIST => 'viewcategory',
        TrackingEvent::SEARCH => 'search',
        TrackingEvent::ADD_TO_CART => 'addtocart',
        TrackingEvent::PURCHASE => 'checkout',
        TrackingEvent::SIGN_UP => 'signup',
        TrackingEvent::GENERATE_LEAD => 'lead',
        TrackingEvent::SUBSCRIBE => 'lead',
    ];

    public static function handle(): string
    {
        return 'pinterest';
    }

    public function displayName(): string
    {
        return 'Pinterest';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The Pinterest tag and the Conversions API, with hashed matching.');
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_PRO;
    }

    public function settingFields(): array
    {
        return [
            'tagId' => [
                'label' => Craft::t('tape', 'Tag ID'),
                'instructions' => Craft::t('tape', 'Ads → Conversions → Pinterest tag. Thirteen digits.'),
                'placeholder' => '2612345678901',
                'required' => true,
                'pattern' => '/^[0-9]{10,20}$/',
            ],
            'adAccountId' => [
                'label' => Craft::t('tape', 'Ad account ID'),
                'instructions' => Craft::t('tape', 'Only needed for server-side events.'),
                'pattern' => '/^[0-9]{5,25}$/',
            ],
            'accessToken' => [
                'label' => Craft::t('tape', 'Conversions API access token'),
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
        $tagId = $destination->setting('tagId');

        if ($tagId === null) {
            return [];
        }

        return [
            'loader' => 'pintrk',
            'config' => [
                'id' => $tagId,
                'matching' => Plugin::getInstance()->enhancedConversions()
                    ? Identity::pinterestUserData($context['userData'] ?? null)
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

        return [$this->call('pintrk', ['track', $name, $this->properties($event) + ['event_id' => $event->eventId]])];
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        $accountId = $destination->setting('adAccountId');
        $token = $destination->setting('accessToken');
        $name = self::EVENTS[$event->name] ?? null;

        if ($accountId === null || $token === null || $name === null) {
            return null;
        }

        $body = [
            'data' => [$this->clean([
                'event_name' => $name,
                'action_source' => 'web',
                'event_time' => $event->occurredAt,
                'event_id' => $event->eventId,
                'event_source_url' => $event->userData?->sourceUrl,
                'user_data' => Identity::pinterestServerUserData($event->userData),
                'custom_data' => $this->clean([
                    'currency' => $this->currency($event),
                    'value' => (string)$this->money($event->getValue()),
                    'content_ids' => $event->getItemIds(),
                    'num_items' => $event->getItemCount(),
                    'order_id' => $event->transactionId,
                    'search_string' => $event->searchTerm,
                ]),
            ])],
        ];

        $url = 'https://api.pinterest.com/v5/ad_accounts/' . rawurlencode((string)$accountId) . '/events';

        if ($destination->testMode) {
            $url .= '?test=true';
        }

        return [
            'url' => $url,
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => $body,
        ];
    }

    public function isServerResponseOk(int $statusCode, string $body): bool
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $decoded = json_decode($body, true);

        return !is_array($decoded) || (int)($decoded['num_events_processed'] ?? 1) > 0;
    }

    private function properties(TrackingEvent $event): array
    {
        return $this->clean([
            'value' => $this->money($event->getValue()),
            'order_quantity' => $event->getItemCount() ?: null,
            'currency' => $this->currency($event),
            'search_query' => $event->searchTerm,
            'order_id' => $event->transactionId,
            'line_items' => $this->mapItems($event, fn(EventItem $item) => [
                'product_id' => $item->id,
                'product_name' => $item->name,
                'product_brand' => $item->brand,
                'product_category' => $item->getCategoryPath(),
                'product_price' => $item->price !== null ? $this->money($item->price) : null,
                'product_quantity' => $item->quantity,
            ]),
        ]) + $event->params;
    }
}
