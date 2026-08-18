<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * A pixel Tape does not have an adapter for.
 *
 * There will always be one — a regional ad network, an affiliate platform, a client's in-house
 * analytics. This destination exists so that “we also need to drop this script on the site” never
 * has to mean a template edit, and so those tags get the same consent gating, the same site
 * scoping and the same exclusion rules as everything else rather than being pasted into a layout
 * where none of that applies.
 *
 * Events are pushed onto a named global array in Tape's own normalised shape, so the snippet can
 * subscribe to them and translate. That is genuinely all a platform adapter does; this is the
 * escape hatch for writing one in JavaScript instead of PHP.
 *
 * The HTML is written by an admin with `allowAdminChanges` enabled — the same level of trust as
 * editing a template — and is emitted verbatim.
 */
class CustomSnippet extends BasePlatform
{
    public static function handle(): string
    {
        return 'custom';
    }

    public function displayName(): string
    {
        return Craft::t('tape', 'Custom snippet');
    }

    public function description(): string
    {
        return Craft::t('tape', 'Any other pixel: your own HTML in the head or body, plus every Tape event pushed onto a global array for it to read.');
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_PRO;
    }

    public function settingFields(): array
    {
        return [
            'headHtml' => [
                'label' => Craft::t('tape', 'Head HTML'),
                'instructions' => Craft::t('tape', 'Written into `<head>`, after Tape’s consent signals and before its runtime.'),
                'type' => 'textarea',
                'class' => 'code',
            ],
            'bodyHtml' => [
                'label' => Craft::t('tape', 'Body HTML'),
                'instructions' => Craft::t('tape', 'Written just before `</body>` — the right place for a `<noscript>` pixel.'),
                'type' => 'textarea',
                'class' => 'code',
            ],
            'queueName' => [
                'label' => Craft::t('tape', 'Event queue'),
                'instructions' => Craft::t('tape', 'Every event is pushed onto `window.<name>` as `{name, eventId, value, currency, items, …}`. Your snippet can read the array on load and push a listener onto it afterwards.'),
                'placeholder' => 'tapeEvents',
                'pattern' => '/^[A-Za-z_$][A-Za-z0-9_$]*$/',
            ],
        ];
    }

    /** Nothing here is worth blocking a save over — a snippet with no HTML in it simply does nothing. */
    public function requiredSettings(): array
    {
        return [];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        return [
            'loader' => 'queue',
            'config' => ['queue' => (string)$destination->setting('queueName', 'tapeEvents')],
            'html' => (string)$destination->setting('headHtml', ''),
            'bodyHtml' => (string)$destination->setting('bodyHtml', ''),
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $queue = (string)$destination->setting('queueName', 'tapeEvents');

        return [$this->call($queue . '.push', [$this->clean([
            'name' => $event->name,
            'eventId' => $event->eventId,
            'value' => $this->money($event->getValue()),
            'currency' => $this->currency($event),
            'transactionId' => $event->transactionId,
            'coupon' => $event->coupon,
            'listId' => $event->listId,
            'listName' => $event->listName,
            'searchTerm' => $event->searchTerm,
            'items' => $this->mapItems($event, static fn(EventItem $item) => $item->toArray()),
        ]) + $event->params])];
    }
}
