<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;

/**
 * The record of what was sent where.
 *
 * Deduplication is the whole reason this exists, and it is worth being precise about what is being
 * deduplicated. A conversion can be emitted twice for entirely mundane reasons — the customer
 * refreshes the confirmation page, the payment gateway posts its webhook twice, a queue job is
 * retried after a timeout that had in fact succeeded — and each of those doubles the revenue the
 * platform reports. So every conversion claims a `dedupeKey` with a unique index behind it, and
 * *the insert is the claim*: if it fails on the constraint, somebody else got there first and this
 * one is dropped. A check-then-insert would leave the race open exactly where it matters, which is
 * two concurrent requests for the same order.
 *
 * The second job is knowing what did *not* happen, which is what makes recovery and the accuracy
 * report possible. `emitted` is an honest word for the browser channel: Tape knows it wrote the
 * payload into the page, not that an ad blocker let it run.
 */
class Ledger extends Component
{
    /** The browser payload was written into the page. Not proof that it ran. */
    public const STATUS_EMITTED = 'emitted';
    /** Queued for a server-side send. */
    public const STATUS_PENDING = 'pending';
    /** The platform accepted it. */
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    /** Deliberately not sent — consent denied, visitor excluded, edition too low. */
    public const STATUS_SKIPPED = 'skipped';

    public const CHANNEL_BROWSER = 'browser';
    public const CHANNEL_SERVER = 'server';
    public const CHANNEL_RECOVERY = 'recovery';

    /**
     * Claims this conversion and records it, or returns null if it has already been claimed.
     *
     * Null is the ordinary answer, not an error: it means this exact conversion is already in the
     * ledger and must not be sent again.
     */
    public function record(
        TrackingEvent $event,
        ?Destination $destination,
        string $channel,
        string $status,
    ): ?int {
        $dedupeKey = $this->dedupeKey($event, $destination, $channel);

        $row = [
            'dedupeKey' => $dedupeKey,
            'eventId' => $event->eventId,
            'eventName' => $event->name,
            'destinationUid' => $destination?->uid,
            'channel' => $channel,
            'status' => $status,
            'orderId' => $event->orderId,
            'elementId' => $event->elementId,
            'siteId' => $event->siteId,
            'value' => $event->getValue(),
            'currency' => $event->currency,
            'transactionId' => $event->transactionId,
            'occurredAt' => Db::prepareDateForDb(new DateTime('@' . ($event->occurredAt ?? time()))),
        ];

        try {
            Db::insert('{{%tape_events}}', $row);
        } catch (IntegrityException) {
            // Somebody else claimed it. That is the constraint doing its job.
            return null;
        } catch (DbException $e) {
            Craft::error('Could not write to the Tape ledger: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }

        return (int)Craft::$app->getDb()->getLastInsertID('{{%tape_events}}');
    }

    /**
     * The key that makes a conversion unique, or null for events that repeat legitimately.
     *
     * Only conversions get one. A visitor may view the same product forty times in an afternoon and
     * every one of those is a real event; a visitor may only ever buy order #1041 once.
     */
    public function dedupeKey(TrackingEvent $event, ?Destination $destination, string $channel): ?string
    {
        if (!$event->isConversion() || $event->orderId === null) {
            return null;
        }

        // The channel is part of the key on purpose. A browser tag and a Conversions API call for
        // the same order are two *deliberate* sends that the platform itself deduplicates on the
        // shared event ID; collapsing them here would suppress the half that exists to survive ad
        // blockers. Recovery is its own channel for the same reason, and is separately guarded
        // against recovering something that was already delivered.
        return implode(':', array_filter([
            $event->name,
            (string)$event->orderId,
            $destination->uid ?? '-',
            $channel,
            $event->name === TrackingEvent::REFUND ? ($event->params['refund_id'] ?? null) : null,
        ]));
    }

    public function markSent(int $id, int $statusCode): void
    {
        Db::update('{{%tape_events}}', [
            'status' => self::STATUS_SENT,
            'statusCode' => $statusCode,
            'error' => null,
            'sentAt' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $id]);
    }

    public function markFailed(int $id, ?int $statusCode, string $error): void
    {
        Db::update('{{%tape_events}}', [
            'status' => self::STATUS_FAILED,
            'statusCode' => $statusCode,
            // The response is truncated rather than stored whole: an API that is having a bad day
            // can answer with an HTML error page, and a thousand of those is a table nobody wants.
            'error' => mb_substr($error, 0, 2000),
        ], ['id' => $id]);

        Craft::$app->getDb()->createCommand()
            ->update('{{%tape_events}}', ['attempts' => new \yii\db\Expression('[[attempts]] + 1')], ['id' => $id])
            ->execute();
    }

    /** Whether a conversion has already been claimed for this order. */
    public function hasFired(string $eventName, int $orderId, ?string $destinationUid = null, ?string $channel = null): bool
    {
        $query = (new Query())
            ->from(['{{%tape_events}}'])
            ->where(['eventName' => $eventName, 'orderId' => $orderId])
            ->andWhere(['not', ['status' => self::STATUS_SKIPPED]]);

        if ($destinationUid !== null) {
            $query->andWhere(['destinationUid' => $destinationUid]);
        }

        if ($channel !== null) {
            $query->andWhere(['channel' => $channel]);
        }

        return $query->exists();
    }

    /**
     * Orders that completed in the window and never had a purchase event emitted for them.
     *
     * This is the recovery sweep's whole question, and it is asked with a left join rather than by
     * loading orders and checking each one, because on a busy store the window can hold thousands
     * of orders and only a handful of misses.
     *
     * @return array<int, array{id: int, number: string|null, dateOrdered: string}>
     */
    public function getUntrackedOrders(DateTime $from, DateTime $until, int $limit = 100): array
    {
        if (!Plugin::getInstance()->commerce->isInstalled()) {
            return [];
        }

        // Any row at all disqualifies an order — a `skipped` row included. A skipped row means the
        // visitor's consent was withheld and Tape deliberately did not send; recovering that would
        // be overriding the answer rather than repairing a failure.
        $tracked = (new Query())
            ->select(['orderId'])
            ->from(['{{%tape_events}}'])
            ->where(['eventName' => TrackingEvent::PURCHASE])
            ->andWhere(['not', ['orderId' => null]]);

        return (new Query())
            ->select(['id', 'number', 'dateOrdered'])
            ->from(['{{%commerce_orders}}'])
            ->where(['isCompleted' => true])
            ->andWhere(['>=', 'dateOrdered', Db::prepareDateForDb($from)])
            ->andWhere(['<=', 'dateOrdered', Db::prepareDateForDb($until)])
            ->andWhere(['not in', 'id', $tracked])
            ->orderBy(['dateOrdered' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * Records what the browser actually managed to do.
     *
     * The runtime reports back per destination once a conversion's payloads have been dispatched,
     * and separately when a vendor's script failed to load at all. Without this, `emitted` would be
     * the terminal state for every browser conversion and there would be no way to tell a
     * conversion that reached Meta from one an ad blocker ate — which is precisely the gap recovery
     * exists to close.
     *
     * @param string[] $delivered Destination handles whose payloads dispatched.
     * @param string[] $blocked Destination handles whose script never loaded.
     */
    /**
     * Whether any ledger row carries this event ID — i.e. this firing has been handled already.
     */
    public function hasEventId(string $eventId): bool
    {
        return $eventId !== '' && (new Query())->from('{{%tape_events}}')->where(['eventId' => $eventId])->exists();
    }

    public function confirm(string $eventId, array $delivered, array $blocked): int
    {
        $uids = [];

        foreach (Plugin::getInstance()->destinations->getAllDestinations() as $destination) {
            $uids[$destination->handle] = $destination->uid;
        }

        $updated = 0;

        $deliveredUids = array_values(array_filter(array_map(static fn(string $h) => $uids[$h] ?? null, $delivered)));
        $blockedUids = array_values(array_filter(array_map(static fn(string $h) => $uids[$h] ?? null, $blocked)));

        if ($deliveredUids !== []) {
            $updated += Db::update('{{%tape_events}}', [
                'status' => self::STATUS_SENT,
                'sentAt' => Db::prepareDateForDb(new DateTime()),
            ], [
                'eventId' => $eventId,
                'channel' => self::CHANNEL_BROWSER,
                'status' => self::STATUS_EMITTED,
                'destinationUid' => $deliveredUids,
            ]);
        }

        if ($blockedUids !== []) {
            $updated += Db::update('{{%tape_events}}', [
                'status' => self::STATUS_FAILED,
                'error' => 'The vendor script did not load in the browser.',
            ], [
                'eventId' => $eventId,
                'channel' => self::CHANNEL_BROWSER,
                'status' => self::STATUS_EMITTED,
                'destinationUid' => $blockedUids,
            ]);
        }

        return $updated;
    }

    /**
     * Browser conversions that were written into a page and never confirmed.
     *
     * Either the visitor closed the tab before the runtime finished, or something blocked it. Both
     * are recoverable server-side, and both look identical from here — which is fine, because
     * sending a conversion the platform already has is harmless when it carries the same event ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnconfirmedConversions(DateTime $before, int $limit = 100): array
    {
        $delivered = (new Query())
            ->select(['orderId'])
            ->from(['{{%tape_events}} d'])
            ->where(['d.eventName' => TrackingEvent::PURCHASE])
            ->andWhere(['d.channel' => [self::CHANNEL_SERVER, self::CHANNEL_RECOVERY]])
            ->andWhere(['d.status' => self::STATUS_SENT]);

        // A browser row the runtime never confirmed is also what a *held* tag looks like: consent
        // was denied, so the payload waited and nothing reported back. The server half recorded
        // that decision as `skipped`, and recovering the order would override it.
        $decided = (new Query())
            ->select(['orderId'])
            ->from(['{{%tape_events}} s'])
            ->where(['s.eventName' => TrackingEvent::PURCHASE])
            ->andWhere(['s.status' => self::STATUS_SKIPPED]);

        return (new Query())
            ->from(['{{%tape_events}}'])
            ->where([
                'eventName' => TrackingEvent::PURCHASE,
                'channel' => self::CHANNEL_BROWSER,
                'status' => [self::STATUS_EMITTED, self::STATUS_FAILED],
            ])
            ->andWhere(['not', ['orderId' => null]])
            ->andWhere(['<', 'occurredAt', Db::prepareDateForDb($before)])
            ->andWhere(['not in', 'orderId', $delivered])
            ->andWhere(['not in', 'orderId', $decided])
            ->orderBy(['occurredAt' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * Whether this order's purchase reached any platform, which is what a refund for it hangs on.
     *
     * False when any purchase row was skipped — the visitor's consent decision, which a refund must
     * not override — and when there is no purchase row at all, because then there is nothing on
     * the platform's side for a refund to reverse.
     */
    public function purchaseWasSent(int $orderId): bool
    {
        $statuses = (new Query())
            ->select(['status'])
            ->from(['{{%tape_events}}'])
            ->where(['eventName' => TrackingEvent::PURCHASE, 'orderId' => $orderId])
            ->column();

        if ($statuses === [] || in_array(self::STATUS_SKIPPED, $statuses, true)) {
            return false;
        }

        return array_intersect($statuses, [self::STATUS_SENT, self::STATUS_EMITTED]) !== [];
    }

    /** @return array<int, array<string, mixed>> Ledger rows for the events screen. */
    public function getRecent(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return $this->buildQuery($criteria)
            ->orderBy(['occurredAt' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();
    }

    public function getCount(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count('[[id]]');
    }

    /**
     * How many conversions were emitted against how many orders were placed, per day.
     *
     * The single most useful number in the plugin and the reason the ledger exists at all: a store
     * whose Google Ads conversions read 60% of its real orders has a tracking problem worth more
     * than any amount of campaign tuning, and there is no way to see that from inside Google Ads.
     *
     * @return array<int, array{date: string, orders: int, tracked: int}>
     */
    public function getAccuracy(DateTime $from, DateTime $until, ?string $destinationUid = null): array
    {
        if (!Plugin::getInstance()->commerce->isInstalled()) {
            return [];
        }

        $db = Craft::$app->getDb();
        $dateExpr = $db->getIsMysql() ? 'DATE([[dateOrdered]])' : "to_char([[dateOrdered]], 'YYYY-MM-DD')";

        $orders = (new Query())
            ->select([new \yii\db\Expression("$dateExpr AS [[day]]"), 'total' => new \yii\db\Expression('COUNT(*)')])
            ->from(['{{%commerce_orders}}'])
            ->where(['isCompleted' => true])
            ->andWhere(['>=', 'dateOrdered', Db::prepareDateForDb($from)])
            ->andWhere(['<=', 'dateOrdered', Db::prepareDateForDb($until)])
            ->groupBy(['day'])
            ->indexBy('day')
            ->all();

        // Tracked = the orders above that have a purchase conversion, by the order's own date. Not
        // a count of distinct order IDs in the ledger: that included orders since deleted, and
        // conversions recorded on a different day from the order, so the report could claim more
        // tracked orders than there were orders.
        $converted = (new Query())
            ->from(['e' => '{{%tape_events}}'])
            ->where('[[e.orderId]] = [[o.id]]')
            ->andWhere(['e.eventName' => TrackingEvent::PURCHASE])
            ->andWhere(['not', ['e.status' => self::STATUS_SKIPPED]]);

        if ($destinationUid !== null) {
            $converted->andWhere(['e.destinationUid' => $destinationUid]);
        }

        $orderDateExpr = $db->getIsMysql() ? 'DATE([[o.dateOrdered]])' : "to_char([[o.dateOrdered]], 'YYYY-MM-DD')";

        $tracked = (new Query())
            ->select([new \yii\db\Expression("$orderDateExpr AS [[day]]"), 'total' => new \yii\db\Expression('COUNT(*)')])
            ->from(['o' => '{{%commerce_orders}}'])
            ->where(['o.isCompleted' => true])
            ->andWhere(['>=', 'o.dateOrdered', Db::prepareDateForDb($from)])
            ->andWhere(['<=', 'o.dateOrdered', Db::prepareDateForDb($until)])
            ->andWhere(['exists', $converted])
            ->groupBy(['day'])
            ->indexBy('day')
            ->all();

        $rows = [];

        foreach ($orders as $day => $row) {
            $rows[] = [
                'date' => (string)$day,
                'orders' => (int)$row['total'],
                'tracked' => (int)($tracked[$day]['total'] ?? 0),
            ];
        }

        usort($rows, static fn(array $a, array $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * @return array<string, array{sent: int, failed: int, emitted: int, pending: int, lastAt: string|null}>
     *         Keyed by destination uid.
     */
    public function getDestinationHealth(DateTime $since): array
    {
        $rows = (new Query())
            ->select([
                'destinationUid',
                'status',
                'total' => new \yii\db\Expression('COUNT(*)'),
                'lastAt' => new \yii\db\Expression('MAX([[occurredAt]])'),
            ])
            ->from(['{{%tape_events}}'])
            ->where(['>=', 'occurredAt', Db::prepareDateForDb($since)])
            ->groupBy(['destinationUid', 'status'])
            ->all();

        $health = [];

        foreach ($rows as $row) {
            $uid = (string)($row['destinationUid'] ?? '-');
            $health[$uid] ??= ['sent' => 0, 'failed' => 0, 'emitted' => 0, 'pending' => 0, 'skipped' => 0, 'lastAt' => null];
            $health[$uid][$row['status']] = ($health[$uid][$row['status']] ?? 0) + (int)$row['total'];

            if ($health[$uid]['lastAt'] === null || $row['lastAt'] > $health[$uid]['lastAt']) {
                $health[$uid]['lastAt'] = $row['lastAt'];
            }
        }

        return $health;
    }

    /** @return array<int, array<string, mixed>> Pending or retryable server-side rows. */
    public function getRetryable(int $maxAttempts = 3, int $limit = 50): array
    {
        return (new Query())
            ->from(['{{%tape_events}}'])
            ->where(['status' => self::STATUS_FAILED])
            ->andWhere(['<', 'attempts', $maxAttempts])
            ->andWhere(['not', ['orderId' => null]])
            ->orderBy(['occurredAt' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * Drops rows past the retention window.
     *
     * Conversions attached to an order are kept for the full window; everything else is kept for a
     * tenth of it, because a page view from six months ago cannot be deduplicated against anything
     * and is not evidence of anything either.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->ledgerRetentionDays;
        $cutoff = Db::prepareDateForDb(new DateTime("-$days days"));
        $shortCutoff = Db::prepareDateForDb(new DateTime('-' . max(7, (int)ceil($days / 10)) . ' days'));

        $deleted = Db::delete('{{%tape_events}}', ['and', ['not', ['orderId' => null]], ['<', 'occurredAt', $cutoff]]);
        $deleted += Db::delete('{{%tape_events}}', ['and', ['orderId' => null], ['<', 'occurredAt', $shortCutoff]]);

        return $deleted;
    }

    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())->from(['{{%tape_events}}']);

        foreach (['eventName', 'status', 'channel', 'destinationUid', 'orderId', 'siteId'] as $key) {
            if (isset($criteria[$key]) && $criteria[$key] !== '') {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        if (!empty($criteria['since'])) {
            $query->andWhere(['>=', 'occurredAt', Db::prepareDateForDb($criteria['since'])]);
        }

        return $query;
    }
}
