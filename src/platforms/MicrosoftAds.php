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
 * Microsoft Advertising — the UET tag, remarketing and enhanced conversions.
 *
 * UET is an array you push onto rather than a function you call, and it wants two different things
 * from the same page: an *event* for conversions and a set of `ecomm_*` variables for remarketing.
 * Both go out here, because a UET tag with conversions and no `ecomm_pagetype` builds no audiences
 * at all, and that is the half people forget.
 */
class MicrosoftAds extends BasePlatform
{
    /** UET's remarketing page types, keyed by the funnel event that implies them. */
    private const PAGE_TYPES = [
        TrackingEvent::PAGE_VIEW => 'other',
        TrackingEvent::VIEW_ITEM => 'product',
        TrackingEvent::VIEW_ITEM_LIST => 'category',
        TrackingEvent::SEARCH => 'searchresults',
        TrackingEvent::ADD_TO_CART => 'cart',
        TrackingEvent::VIEW_CART => 'cart',
        TrackingEvent::BEGIN_CHECKOUT => 'cart',
        TrackingEvent::PURCHASE => 'purchase',
    ];

    public static function handle(): string
    {
        return 'microsoftAds';
    }

    /** UET takes any event action; a goal can be defined on it in Microsoft Advertising. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'Microsoft Advertising';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The UET tag — Bing conversions, remarketing audiences and enhanced conversions.');
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_PRO;
    }

    public function settingFields(): array
    {
        return [
            'tagId' => [
                'label' => Craft::t('tape', 'UET tag ID'),
                'instructions' => Craft::t('tape', 'Tools → UET tag. Eight or nine digits.'),
                'placeholder' => '12345678',
                'required' => true,
                'pattern' => '/^[0-9]{5,15}$/',
            ],
            'storeId' => [
                'label' => Craft::t('tape', 'Merchant store ID'),
                'instructions' => Craft::t('tape', 'Only needed if you run Microsoft Shopping campaigns and want product IDs matched to your feed.'),
                'pattern' => '/^[0-9]{1,15}$/',
            ],
        ];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $tagId = $destination->setting('tagId');

        if ($tagId === null) {
            return [];
        }

        $enhanced = [];

        if (Plugin::getInstance()->getSettings()->enhancedConversions) {
            $enhanced = Identity::microsoftUserData($context['userData'] ?? null);
        }

        return [
            // UET's consent value comes from the runtime, not from here — see Clarity for why.
            'loader' => 'uetq',
            'config' => [
                'id' => $tagId,
                'enhanced' => $enhanced,
            ],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $payload = [
            'ecomm_pagetype' => self::PAGE_TYPES[$event->name] ?? null,
            'ecomm_prodid' => $event->getItemIds() ?: null,
            'ecomm_totalvalue' => $event->items === [] ? null : $this->money($event->getValue()),
            'revenue_value' => $event->name === TrackingEvent::PURCHASE ? $this->money($event->getValue()) : null,
            'currency' => $this->currency($event),
            'transaction_id' => $event->transactionId,
            'search_term' => $event->searchTerm,
            'items' => $this->mapItems($event, fn(EventItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price !== null ? $this->money($item->price) : null,
                'quantity' => $item->quantity,
            ]),
        ];

        if ($storeId = $destination->setting('storeId')) {
            $payload['ecomm_prodid'] = array_map(
                static fn(string $id) => $storeId . '_' . $id,
                $payload['ecomm_prodid'] ?? [],
            ) ?: null;
        }

        return [$this->call('uetq.push', ['event', $event->name, $this->clean($payload) + $event->params])];
    }
}
