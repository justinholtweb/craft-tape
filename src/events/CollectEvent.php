<?php

namespace justinholtweb\tape\events;

use craft\events\CancelableEvent;
use justinholtweb\tape\models\TrackingEvent;

/**
 * Fired as an event is about to be queued.
 *
 * The one extension point that covers most of what anybody wants to do to Tape from outside:
 * enrich an event with a custom parameter, suppress one on a particular page, or rewrite the item
 * IDs to match a feed built by something else. Setting `isValid` to false drops it.
 *
 * Extends `craft\events\CancelableEvent` rather than the plain Yii base — a "cancellable" event
 * extending `yii\base\Event` throws on the first read of `isValid`, at runtime, in the one code
 * path it exists for.
 */
class CollectEvent extends CancelableEvent
{
    public TrackingEvent $trackingEvent;
}
