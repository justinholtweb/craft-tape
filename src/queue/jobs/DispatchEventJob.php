<?php

namespace justinholtweb\tape\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\UserData;
use justinholtweb\tape\Plugin;

/**
 * One server-side call.
 *
 * Deliberately tiny and re-derivable. An order-backed job carries an order ID and rebuilds the
 * event when it runs, which keeps customer data out of the queue table and means a job retried
 * after a failure sends what the order says now. A job with no order carries a flattened copy of
 * the event, minus anything identifying.
 *
 * A job that cannot find its destination or its order **succeeds**. Both are ordinary — a
 * destination removed while a job waited, an order deleted — and a job that failed on them would
 * be retried until it exhausted the queue's patience and then sat in the failed list forever.
 */
class DispatchEventJob extends BaseJob
{
    public string $destinationUid = '';

    public ?int $ledgerId = null;

    public string $eventName = TrackingEvent::PURCHASE;

    public string $eventId = '';

    public ?int $orderId = null;

    public ?int $occurredAt = null;

    public string $source = TrackingEvent::SOURCE_SERVER;

    /** @var array<string, mixed>|null Set only for events with no order to rebuild from. */
    public ?array $event = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $destination = $plugin->destinations->getDestinationByUid($this->destinationUid);

        if ($destination === null) {
            return;
        }

        $event = $this->buildEvent();

        if ($event === null) {
            return;
        }

        $this->setProgress($queue, 0.5, Craft::t('tape', 'Sending to {destination}', ['destination' => $destination->name]));

        $result = $plugin->dispatcher->send($event, $destination);
        $plugin->dispatcher->recordResult($this->ledgerId, $result);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('tape', 'Sending a {event} event', ['event' => $this->eventName]);
    }

    private function buildEvent(): ?TrackingEvent
    {
        $plugin = Plugin::getInstance();

        if ($this->orderId !== null) {
            $order = $plugin->commerce->isInstalled()
                ? \craft\commerce\elements\Order::find()->id($this->orderId)->status(null)->one()
                : null;

            if ($order === null) {
                return null;
            }

            $event = $plugin->commerce->eventFromOrder($order, $this->eventName);
            $event->eventId = $this->eventId ?: $event->eventId;
            $event->occurredAt = $this->occurredAt ?? $event->occurredAt;
            $event->source = $this->source;

            // Click identifiers cannot be re-derived from the order — they belonged to the request
            // that created the job — so they are carried on the job when there are any.
            if ($this->event !== null) {
                $event->userData ??= new UserData();
                $event->userData->clickIds = (array)($this->event['clickIds'] ?? []);
                $event->userData->ip ??= $this->event['ip'] ?? null;
                $event->userData->userAgent ??= $this->event['userAgent'] ?? null;
                $event->userData->sourceUrl ??= $this->event['sourceUrl'] ?? null;
            }

            return $event;
        }

        if ($this->event === null) {
            return null;
        }

        $data = $this->event;
        $items = array_map(static fn(array $item) => new EventItem($item), (array)($data['items'] ?? []));
        unset($data['items'], $data['clickIds'], $data['ip'], $data['userAgent'], $data['sourceUrl']);

        $event = new TrackingEvent($data + ['name' => $this->eventName, 'eventId' => $this->eventId]);
        $event->items = $items;
        $event->source = $this->source;
        $event->userData = new UserData([
            'clickIds' => (array)($this->event['clickIds'] ?? []),
            'ip' => $this->event['ip'] ?? null,
            'userAgent' => $this->event['userAgent'] ?? null,
            'sourceUrl' => $this->event['sourceUrl'] ?? null,
        ]);

        return $event;
    }
}
