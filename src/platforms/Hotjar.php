<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;

/**
 * Hotjar — heatmaps and session recordings.
 *
 * Not an ad platform and not a conversion destination, which is why it defaults to the analytics
 * consent category and why most of the funnel maps to nothing. What it *is* good for is being able
 * to filter recordings by “people who added to cart and then didn't buy”, and that needs the
 * events sent as Hotjar events, which is all this does.
 */
class Hotjar extends BasePlatform
{
    public static function handle(): string
    {
        return 'hotjar';
    }

    /** Hotjar events are free-form names, used for filtering recordings and heatmaps. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'Hotjar';
    }

    public function description(): string
    {
        return Craft::t('tape', 'Heatmaps and session recordings, with funnel events sent through so recordings can be filtered by behaviour.');
    }

    public function defaultConsentCategory(): string
    {
        return Consent::CATEGORY_ANALYTICS;
    }

    public function settingFields(): array
    {
        return [
            'siteId' => [
                'label' => Craft::t('tape', 'Site ID'),
                'instructions' => Craft::t('tape', 'Hotjar → Settings → Sites & organizations. Seven digits.'),
                'placeholder' => '1234567',
                'required' => true,
                'pattern' => '/^[0-9]{4,12}$/',
            ],
        ];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $siteId = $destination->setting('siteId');

        if ($siteId === null) {
            return [];
        }

        return [
            'loader' => 'hotjar',
            'config' => ['id' => (int)$siteId],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        // Hotjar events are a name and nothing else — no value, no items, no parameters.
        if ($event->name === TrackingEvent::PAGE_VIEW) {
            return [];
        }

        return [$this->call('hj', ['event', $event->name])];
    }
}
