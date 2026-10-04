<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * Google Ads — conversions, dynamic remarketing and cart data.
 *
 * Google Ads is the destination that most repays being wired up properly and the one people most
 * often half-wire. Three separate things travel on the same tag and are constantly confused:
 *
 * - **Conversions.** One `AW-ID/LABEL` pair per conversion action, configured in Google Ads. A
 *   label is *not* optional decoration; a conversion sent without one is silently discarded, which
 *   is why labels are per-event settings rather than a single field.
 * - **Dynamic remarketing.** The same funnel events sent to the bare `AW-ID`, carrying product IDs
 *   that must match the Merchant Center feed. This is what makes the ads show the product somebody
 *   actually looked at.
 * - **Cart data.** Line items on the purchase conversion, which is what Performance Max and Smart
 *   Shopping use to bid on profit rather than revenue. It needs the Merchant Center ID and the feed
 *   locale, which is why those are settings and not guesses.
 *
 * Enhanced conversions are hashed here rather than handed to `gtag` in the clear. Google accepts
 * both, and both are equivalent to Google — but only one of them puts a customer's email address
 * into the page source of the order confirmation.
 */
class GoogleAds extends GtagPlatform
{
    /** Events Google Ads understands as dynamic-remarketing signals. */
    private const REMARKETING_EVENTS = [
        TrackingEvent::VIEW_ITEM,
        TrackingEvent::VIEW_ITEM_LIST,
        TrackingEvent::ADD_TO_CART,
        TrackingEvent::VIEW_CART,
        TrackingEvent::BEGIN_CHECKOUT,
        TrackingEvent::PURCHASE,
    ];

    public static function handle(): string
    {
        return 'googleAds';
    }

    public function displayName(): string
    {
        return 'Google Ads';
    }

    public function description(): string
    {
        return Craft::t('tape', 'Conversion actions, dynamic remarketing audiences, cart data for Performance Max, and enhanced conversions.');
    }

    public function settingFields(): array
    {
        return [
            'conversionId' => [
                'label' => Craft::t('tape', 'Conversion ID'),
                'instructions' => Craft::t('tape', 'Google Ads → Goals → Conversions → your action → Tag setup. Starts with `AW-`.'),
                'placeholder' => 'AW-123456789',
                'required' => true,
                'pattern' => '/^AW-[0-9]{6,15}$/i',
                'patternMessage' => Craft::t('tape', 'A Google Ads conversion ID looks like `AW-123456789`. `G-` IDs belong to a GA4 destination and `GT-`/`GTM-` to Google Tag Manager.'),
            ],
            'dynamicRemarketing' => [
                'label' => Craft::t('tape', 'Dynamic remarketing'),
                'instructions' => Craft::t('tape', 'Sends product IDs with funnel events so remarketing ads can show what the visitor looked at. Needs a Merchant Center feed using the same IDs.'),
                'type' => 'lightswitch',
                'default' => true,
            ],
            'merchantId' => [
                'label' => Craft::t('tape', 'Merchant Center ID'),
                'instructions' => Craft::t('tape', 'Enables cart data on the purchase conversion, which Performance Max uses to bid on profit rather than revenue.'),
                'placeholder' => '123456789',
                'pattern' => '/^[0-9]{5,15}$/',
            ],
            'feedCountry' => [
                'label' => Craft::t('tape', 'Feed country'),
                'instructions' => Craft::t('tape', 'Two-letter country code of the Merchant Center feed — `US`, `GB`, `DE`.'),
                'placeholder' => 'US',
                'pattern' => '/^[A-Za-z]{2}$/',
            ],
            'feedLanguage' => [
                'label' => Craft::t('tape', 'Feed language'),
                'placeholder' => 'en',
                'pattern' => '/^[A-Za-z]{2}$/',
            ],
            'businessVertical' => [
                'label' => Craft::t('tape', 'Business vertical'),
                'instructions' => Craft::t('tape', 'Which dynamic remarketing feed type this account uses.'),
                'type' => 'select',
                'options' => [
                    'retail' => 'Retail',
                    'education' => 'Education',
                    'flights' => 'Flights',
                    'hotel_rental' => 'Hotels and rentals',
                    'jobs' => 'Jobs',
                    'local' => 'Local deals',
                    'real_estate' => 'Real estate',
                    'travel' => 'Travel',
                    'custom' => 'Custom',
                ],
            ],
        ];
    }

    public function eventSettingFields(string $eventName): array
    {
        return [
            'label' => [
                'label' => Craft::t('tape', 'Conversion label'),
                'instructions' => Craft::t('tape', 'From the conversion action’s tag setup — the part after the slash. Without one, Google Ads discards the conversion.'),
                'placeholder' => 'AbC-D_efGh12ijK',
            ],
        ];
    }

    protected function tagId(Destination $destination): ?string
    {
        return $destination->setting('conversionId');
    }

    protected function configParams(Destination $destination, array $context = []): array
    {
        return [
            'allow_enhanced_conversions' => (bool)Plugin::getInstance()->enhancedConversions(),
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $conversionId = $this->tagId($destination);

        if ($conversionId === null) {
            return [];
        }

        $calls = [];
        $currency = $this->currency($event);
        $label = $destination->eventSetting($event->name, 'label');

        // Enhanced conversions have to be set *before* the conversion fires, and they are set on
        // the tag rather than passed with the event — so this is an ordering constraint, not a
        // parameter, and it is the reason mapEvent returns a list rather than one call.
        if ($label !== null && Plugin::getInstance()->enhancedConversions()) {
            $userData = Identity::googleUserData($event->userData);

            if ($userData !== []) {
                $calls[] = $this->call('gtag', ['set', 'user_data', $userData]);
            }
        }

        if ($destination->setting('dynamicRemarketing', true) && in_array($event->name, self::REMARKETING_EVENTS, true)) {
            $calls[] = $this->call('gtag', ['event', $event->name, $this->clean([
                'send_to' => $conversionId,
                'value' => $this->money($event->getValue()),
                'currency' => $currency,
                'items' => $this->remarketingItems($event, $destination),
            ])]);
        }

        if ($label === null) {
            return $calls;
        }

        $conversion = $this->clean([
            'send_to' => $conversionId . '/' . $label,
            'value' => $this->money(abs($event->getValue())),
            'currency' => $currency,
            'transaction_id' => $event->transactionId,
        ]);

        if ($event->name === TrackingEvent::REFUND) {
            // A refund is a negative conversion adjustment, not an event with a negative value.
            return array_merge($calls, [$this->call('gtag', ['event', 'conversion_adjustment', $this->clean([
                'send_to' => $conversionId . '/' . $label,
                'transaction_id' => $event->transactionId,
                'value' => $this->money(abs($event->getValue())),
                'currency' => $currency,
                'adjustment_type' => 'RETRACTION',
            ])])]);
        }

        // Cart data rides on a `purchase` event rather than the generic `conversion` one — that is
        // the only shape Google accepts `items` on.
        if ($event->name === TrackingEvent::PURCHASE && $event->items !== [] && $destination->setting('merchantId')) {
            $conversion['items'] = $this->cartItems($event, $destination);
            $conversion['aw_merchant_id'] = (string)$destination->setting('merchantId');
            $conversion['aw_feed_country'] = strtoupper((string)$destination->setting('feedCountry', 'US'));
            $conversion['aw_feed_language'] = strtolower((string)$destination->setting('feedLanguage', 'en'));
            $conversion['discount'] = $this->money($this->discountTotal($event));

            if ($event->isNewCustomer !== null) {
                $conversion['new_customer'] = $event->isNewCustomer;
            }

            $calls[] = $this->call('gtag', ['event', 'purchase', $conversion]);

            return $calls;
        }

        $calls[] = $this->call('gtag', ['event', 'conversion', $conversion]);

        return $calls;
    }

    /** Remarketing wants an ID, a value and a vertical — and rejects the GA4 item shape. */
    private function remarketingItems(TrackingEvent $event, Destination $destination): array
    {
        $vertical = $destination->setting('businessVertical')
            ?: Plugin::getInstance()->getSettings()->googleBusinessVertical;

        return $this->mapItems($event, fn(EventItem $item) => [
            'id' => $item->id,
            'google_business_vertical' => $item->googleBusinessVertical ?: $vertical,
        ]);
    }

    private function cartItems(TrackingEvent $event, Destination $destination): array
    {
        return $this->mapItems($event, fn(EventItem $item) => [
            'id' => $item->id,
            'price' => $this->money($item->price ?? 0.0),
            'quantity' => $item->quantity,
        ]);
    }

    private function discountTotal(TrackingEvent $event): float
    {
        $total = 0.0;

        foreach ($event->items as $item) {
            $total += ($item->discount ?? 0.0) * $item->quantity;
        }

        return $total;
    }
}
