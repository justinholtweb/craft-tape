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
 * X (Twitter) Ads.
 *
 * Like LinkedIn, X models conversions as pre-created *events* rather than named actions, so each
 * one needs its own ID from Ads Manager. Unlike LinkedIn it does carry value and contents, so a
 * purchase configured here is a real revenue conversion rather than a counter.
 */
class XAds extends BasePlatform
{
    public static function handle(): string
    {
        return 'x';
    }

    public function displayName(): string
    {
        return 'X (Twitter)';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The X Pixel, with one event ID per conversion you have created in Ads Manager.');
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
                'instructions' => Craft::t('tape', 'Ads Manager → Tools → Events manager. A short alphanumeric code.'),
                'placeholder' => 'o1abc',
                'required' => true,
                'pattern' => '/^[a-z0-9]{4,12}$/i',
            ],
        ];
    }

    public function eventSettingFields(string $eventName): array
    {
        return [
            'eventId' => [
                'label' => Craft::t('tape', 'Event ID'),
                'instructions' => Craft::t('tape', 'The `tw-…-…` code from the event’s snippet, or just the part after the pixel ID.'),
                'placeholder' => 'tw-o1abc-o1def',
            ],
        ];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $pixelId = $destination->setting('pixelId');

        if ($pixelId === null) {
            return [];
        }

        return [
            'loader' => 'twq',
            'config' => ['id' => $pixelId],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $pixelId = $destination->setting('pixelId');
        $eventId = $destination->eventSetting($event->name, 'eventId');

        if ($pixelId === null || $eventId === null) {
            return [];
        }

        // Both forms of the ID are accepted, because the snippet shows the long one and the
        // Ads Manager table shows the short one, and nobody should have to know which is which.
        if (!str_starts_with($eventId, 'tw-')) {
            $eventId = 'tw-' . $pixelId . '-' . $eventId;
        }

        $payload = [
            'value' => $this->money($event->getValue()),
            'currency' => $this->currency($event),
            'conversion_id' => $event->eventId,
            'contents' => $this->mapItems($event, fn(EventItem $item) => [
                'content_id' => $item->id,
                'content_name' => $item->name,
                'content_type' => 'product',
                'content_price' => $item->price !== null ? $this->money($item->price) : null,
                'num_items' => $item->quantity,
            ]),
        ];

        if (Plugin::getInstance()->getSettings()->enhancedConversions) {
            $payload += Identity::xUserData($event->userData);
        }

        return [$this->call('twq', ['event', $eventId, $this->clean($payload) + $event->params])];
    }
}
