<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * LinkedIn Insight Tag.
 *
 * LinkedIn is the odd one out: it has no event vocabulary at all. Every conversion is a numeric
 * *conversion ID* created in Campaign Manager, and the tag's only verb is “fire conversion 12345”.
 * So there is nothing to map — there is a per-event ID to configure, and an event with no ID
 * configured correctly does nothing rather than firing something wrong.
 *
 * That also means LinkedIn carries no value, no currency and no products. Revenue is attached to
 * the conversion action in Campaign Manager, not to the event.
 */
class LinkedIn extends BasePlatform
{
    public static function handle(): string
    {
        return 'linkedin';
    }

    public function displayName(): string
    {
        return 'LinkedIn';
    }

    public function description(): string
    {
        return Craft::t('tape', 'The Insight Tag for retargeting, plus one conversion ID per event you care about.');
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_PRO;
    }

    public function settingFields(): array
    {
        return [
            'partnerId' => [
                'label' => Craft::t('tape', 'Partner ID'),
                'instructions' => Craft::t('tape', 'Campaign Manager → Analyze → Insight Tag. Six or seven digits.'),
                'placeholder' => '1234567',
                'required' => true,
                'pattern' => '/^[0-9]{4,12}$/',
            ],
        ];
    }

    public function eventSettingFields(string $eventName): array
    {
        return [
            'conversionId' => [
                'label' => Craft::t('tape', 'Conversion ID'),
                'instructions' => Craft::t('tape', 'The numeric ID of the conversion action in Campaign Manager. Without one this event is not sent.'),
                'placeholder' => '9876543',
            ],
        ];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $partnerId = $destination->setting('partnerId');

        if ($partnerId === null) {
            return [];
        }

        return [
            'loader' => 'lintrk',
            'config' => ['id' => $partnerId],
            'noscript' => '<noscript><img height="1" width="1" style="display:none" alt="" src="https://px.ads.linkedin.com/collect/?pid=' . rawurlencode($partnerId) . '&fmt=gif" /></noscript>',
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $conversionId = $destination->eventSetting($event->name, 'conversionId');

        if ($conversionId === null) {
            return [];
        }

        return [$this->call('lintrk', ['track', ['conversion_id' => (int)$conversionId]])];
    }
}
