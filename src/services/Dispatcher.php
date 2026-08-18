<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\DispatchResult;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use justinholtweb\tape\queue\jobs\DispatchEventJob;
use justinholtweb\tape\transports\HttpTransport;
use justinholtweb\tape\transports\TransportInterface;
use Throwable;

/**
 * Sends events to platforms' server-side APIs.
 *
 * Two rules shape this class:
 *
 * **Nothing blocks a response.** A conversions API is somebody else's uptime attached to your
 * checkout. Everything goes through the queue by default, and when queueing is turned off the call
 * is made from `EVENT_AFTER_REQUEST` — after the response has already gone out — rather than inline.
 *
 * **A failure is recorded, not thrown.** A revoked access token should show up as a red row on the
 * events screen, not as a 500 on the order confirmation page. The ledger row carries the status
 * code and the response body, which between them answer every “why isn't this working” question
 * these APIs generate.
 */
class Dispatcher extends Component
{
    /** Swappable for tests and for sites behind an outbound proxy. */
    public TransportInterface $transport;

    /** @var array<int, array{event: TrackingEvent, destination: Destination, ledgerId: int|null}> */
    private array $deferred = [];

    public function init(): void
    {
        parent::init();

        $this->transport ??= new HttpTransport();
    }

    /**
     * Queues an event for a destination, or holds it until after the response.
     *
     * Order-backed events are queued by identifier rather than by value: the job re-derives the
     * event from the order when it runs. That keeps customer data out of the queue table, and means
     * a job retried an hour later sends what the order says now rather than a stale copy.
     */
    public function dispatch(TrackingEvent $event, Destination $destination, ?int $ledgerId = null): void
    {
        if (!Plugin::getInstance()->getSettings()->queueServerSide) {
            $this->deferred[] = ['event' => $event, 'destination' => $destination, 'ledgerId' => $ledgerId];

            return;
        }

        $job = new DispatchEventJob([
            'destinationUid' => $destination->uid,
            'ledgerId' => $ledgerId,
            'eventName' => $event->name,
            'eventId' => $event->eventId,
            'orderId' => $event->orderId,
            'occurredAt' => $event->occurredAt,
            'source' => $event->source,
        ]);

        // An order-backed job re-derives everything from the order, but click identifiers belong to
        // the *request*, not the order, and cannot be recovered later — so those travel either way.
        $job->event = $event->orderId === null
            ? $this->serializeEvent($event)
            : $this->serializeRequestContext($event);

        Craft::$app->getQueue()->push($job);
    }

    /**
     * Runs anything held back for after the response.
     *
     * Called from `Application::EVENT_AFTER_REQUEST`, where the body has already been flushed, so
     * a slow API costs the visitor nothing even with queueing off.
     */
    public function flushDeferred(): void
    {
        $held = $this->deferred;
        $this->deferred = [];

        foreach ($held as $item) {
            try {
                $result = $this->send($item['event'], $item['destination']);
                $this->recordResult($item['ledgerId'], $result);
            } catch (Throwable $e) {
                Craft::error('Tape deferred dispatch failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }
    }

    /** Performs the call. The only method that touches the network. */
    public function send(TrackingEvent $event, Destination $destination): DispatchResult
    {
        $platform = $destination->getPlatform();

        if ($platform === null) {
            return DispatchResult::skipped(Craft::t('tape', 'Unknown platform.'));
        }

        if (!$platform->supportsServerSide()) {
            return DispatchResult::skipped(Craft::t('tape', 'No server-side API.'));
        }

        try {
            $request = $platform->serverRequest($event, $destination);
        } catch (Throwable $e) {
            return new DispatchResult(['error' => $e->getMessage()]);
        }

        if ($request === null) {
            return DispatchResult::skipped(Craft::t('tape', 'Nothing to send — missing credentials, or this platform has no equivalent of that event.'));
        }

        $timeout = Plugin::getInstance()->getSettings()->serverSideTimeout;
        $response = $this->transport->send($request, $timeout);

        $result = new DispatchResult([
            'statusCode' => $response['status'] ?: null,
            'body' => $response['body'],
            'error' => $response['error'],
            'ok' => $response['error'] === null && $platform->isServerResponseOk($response['status'], $response['body']),
        ]);

        if (Plugin::getInstance()->getSettings()->debug) {
            Craft::info(
                "Tape → {$destination->handle} ({$event->name}): " . $result->getSummary(),
                Plugin::LOG_CATEGORY,
            );
        }

        return $result;
    }

    public function recordResult(?int $ledgerId, DispatchResult $result): void
    {
        if ($ledgerId === null) {
            return;
        }

        $ledger = Plugin::getInstance()->ledger;

        if ($result->ok) {
            $ledger->markSent($ledgerId, (int)$result->statusCode);

            return;
        }

        $ledger->markFailed($ledgerId, $result->statusCode, $result->getSummary());
    }

    /**
     * Just the parts of an event that came from the HTTP request and cannot be re-derived.
     *
     * @return array<string, mixed>
     */
    private function serializeRequestContext(TrackingEvent $event): array
    {
        return [
            'clickIds' => $event->userData?->clickIds ?? [],
            'ip' => $event->userData?->ip,
            'userAgent' => $event->userData?->userAgent,
            'sourceUrl' => $event->userData?->sourceUrl,
        ];
    }

    /**
     * A queue-safe copy of an event with no customer data in it.
     *
     * Used only for events with no order behind them, which cannot be re-derived. A hashed email in
     * a queue row would outlive the request that built it, which is the one thing
     * {@see \justinholtweb\tape\models\UserData} promises not to do — so the click identifiers
     * travel and the identity does not.
     *
     * @return array<string, mixed>
     */
    private function serializeEvent(TrackingEvent $event): array
    {
        return [
            'name' => $event->name,
            'eventId' => $event->eventId,
            'value' => $event->value,
            'currency' => $event->currency,
            'transactionId' => $event->transactionId,
            'coupon' => $event->coupon,
            'listId' => $event->listId,
            'listName' => $event->listName,
            'searchTerm' => $event->searchTerm,
            'params' => $event->params,
            'siteId' => $event->siteId,
            'elementId' => $event->elementId,
            'occurredAt' => $event->occurredAt,
            'items' => array_map(static fn($item) => $item->toArray(), $event->items),
            'clickIds' => $event->userData?->clickIds ?? [],
            'ip' => $event->userData?->ip,
            'userAgent' => $event->userData?->userAgent,
            'sourceUrl' => $event->userData?->sourceUrl,
        ];
    }
}
