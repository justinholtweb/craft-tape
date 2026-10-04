<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;

/**
 * Microsoft Clarity — heatmaps, session recordings and funnel tags.
 *
 * Clarity takes both events and *tags*: a tag is a key/value pair attached to the session, and it
 * is what makes “show me recordings of sessions worth over £200 that didn't convert” possible. The
 * purchase value therefore goes out as a tag as well as an event.
 */
class Clarity extends BasePlatform
{
    public static function handle(): string
    {
        return 'clarity';
    }

    /** Clarity events are free-form names, used for filtering recordings. */
    public function supportsCustomEvents(): bool
    {
        return true;
    }

    public function displayName(): string
    {
        return 'Microsoft Clarity';
    }

    public function description(): string
    {
        return Craft::t('tape', 'Free heatmaps and session recordings, tagged with funnel events and order values.');
    }

    public function defaultConsentCategory(): string
    {
        return Consent::CATEGORY_ANALYTICS;
    }

    public function settingFields(): array
    {
        return [
            'projectId' => [
                'label' => Craft::t('tape', 'Project ID'),
                'instructions' => Craft::t('tape', 'Clarity → Settings → Overview. Ten lowercase characters.'),
                'placeholder' => 'abcdefghij',
                'required' => true,
                'pattern' => '/^[a-z0-9]{6,20}$/i',
            ],
        ];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $projectId = $destination->setting('projectId');

        if ($projectId === null) {
            return [];
        }

        return [
            // No consent value is baked in: the runtime calls `clarity('consent', …)` from its own
            // state. A per-visitor answer written into the page would be cached and served to
            // everybody who came after.
            'loader' => 'clarity',
            'config' => ['id' => $projectId],
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        if ($event->name === TrackingEvent::PAGE_VIEW) {
            return [];
        }

        $calls = [$this->call('clarity', ['event', $event->name])];

        if ($event->name === TrackingEvent::PURCHASE) {
            $calls[] = $this->call('clarity', ['set', 'order_value', (string)$this->money($event->getValue())]);
            $calls[] = $this->call('clarity', ['set', 'order_currency', $this->currency($event)]);
        }

        return $calls;
    }
}
