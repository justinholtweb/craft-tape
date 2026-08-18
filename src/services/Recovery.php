<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use DateTime;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use Throwable;

/**
 * Finds conversions that never reached a platform, and sends them.
 *
 * This is the feature that pays for the plugin. Browser conversion tracking loses somewhere between
 * ten and forty percent of real orders, and none of that loss is visible from inside the ad
 * platform — the campaign simply looks worse than it is, and gets budget taken off it. The losses
 * come from three ordinary places:
 *
 * - **Redirect gateways.** The customer pays at the bank's page, the order completes, and they
 *   close the tab instead of coming back. The order exists; the confirmation page never rendered;
 *   no tag ever fired.
 * - **Ad and tracking blockers**, which are most of a young audience.
 * - **Tabs closed early**, before the vendor script finished loading.
 *
 * Two sweeps, because those cases look different in the ledger. Orders with no ledger row at all
 * never reached the confirmation page. Rows still sitting at `emitted` were written into a page
 * that then did not report back.
 *
 * Both are safe to over-fire. A recovered event carries the same event ID the browser tag would
 * have carried, so a platform that did receive the browser event deduplicates it away.
 *
 * What recovery will not do is override a decision. An order whose ledger says `skipped` had its
 * consent withheld, and stays that way.
 */
class Recovery extends Component
{
    /**
     * Runs both sweeps.
     *
     * @return array{missing: int, unconfirmed: int, sent: int}
     */
    public function sweep(?int $lookbackDays = null, int $limit = 200): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->recoveryEnabled || !$plugin->isPro() || !$plugin->commerce->isInstalled()) {
            return ['missing' => 0, 'unconfirmed' => 0, 'sent' => 0];
        }

        $lookbackDays ??= $settings->recoveryLookbackDays;

        // A conversion that has only just happened is not missing — the confirmation page may still
        // be open. The delay is what separates “lost” from “in progress”, and setting it too low is
        // how a recovery sweep starts fighting the browser tag it exists to back up.
        $until = new DateTime("-{$settings->recoveryDelayMinutes} minutes");
        $from = new DateTime("-$lookbackDays days");

        $sent = 0;
        $missing = $plugin->ledger->getUntrackedOrders($from, $until, $limit);

        foreach ($missing as $row) {
            $sent += $this->recoverOrder((int)$row['id']);
        }

        $unconfirmed = $plugin->ledger->getUnconfirmedConversions($until, $limit);
        $seen = [];

        foreach ($unconfirmed as $row) {
            $orderId = (int)$row['orderId'];

            if (isset($seen[$orderId])) {
                continue;
            }

            $seen[$orderId] = true;
            $sent += $this->recoverOrder($orderId);
        }

        if ($sent > 0) {
            Craft::info("Tape recovered $sent conversion(s).", Plugin::LOG_CATEGORY);
        }

        return ['missing' => count($missing), 'unconfirmed' => count($seen), 'sent' => $sent];
    }

    /**
     * Sends a purchase for one order to every destination that can take it server-side.
     *
     * @return int How many calls went out.
     */
    public function recoverOrder(int $orderId): int
    {
        $plugin = Plugin::getInstance();

        try {
            $order = \craft\commerce\elements\Order::find()->id($orderId)->status(null)->one();
        } catch (Throwable $e) {
            Craft::warning("Tape could not load order $orderId for recovery: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return 0;
        }

        if ($order === null || !$order->isCompleted) {
            return 0;
        }

        $event = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE);
        $event->source = TrackingEvent::SOURCE_RECOVERY;

        // The order's own completion time, not now. A conversion stamped hours late lands in the
        // wrong reporting day and, on several platforms, outside the attribution window entirely.
        $event->occurredAt = $order->dateOrdered?->getTimestamp() ?? $event->occurredAt;

        $sent = 0;

        foreach ($this->recoverableDestinations($event->siteId) as $destination) {
            if (!$destination->firesEvent(TrackingEvent::PURCHASE)) {
                continue;
            }

            $ledgerId = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_RECOVERY, Ledger::STATUS_PENDING);

            if ($ledgerId === null) {
                // Already recovered by an earlier sweep, or by a concurrent one.
                continue;
            }

            $result = $plugin->dispatcher->send($event, $destination);
            $plugin->dispatcher->recordResult($ledgerId, $result);

            if ($result->ok) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Destinations a lost conversion can actually be recovered to.
     *
     * Consent is not consulted here and the reason is worth being explicit about: recovery only
     * ever runs against orders with *no* ledger decision recorded, so there is no answer to
     * override. An order whose visitor denied consent has `skipped` rows and is excluded before it
     * gets this far.
     *
     * @return Destination[]
     */
    public function recoverableDestinations(?int $siteId = null): array
    {
        $plugin = Plugin::getInstance();

        return array_values(array_filter(
            $plugin->destinations->getActiveDestinations($siteId),
            static function(Destination $destination) {
                $platform = $destination->getPlatform();

                return $destination->serverSide && $platform !== null && $platform->supportsServerSide();
            },
        ));
    }

    /**
     * Destinations that would benefit from recovery but cannot have it.
     *
     * Shown on the reports screen, because “Google Ads is losing conversions and Tape cannot fix
     * it” is worth saying out loud rather than leaving somebody to infer from a gap.
     *
     * @return Destination[]
     */
    public function getUnrecoverableDestinations(): array
    {
        return array_values(array_filter(
            Plugin::getInstance()->destinations->getActiveDestinations(),
            static function(Destination $destination) {
                $platform = $destination->getPlatform();

                return $platform !== null
                    && $destination->firesEvent(TrackingEvent::PURCHASE)
                    && (!$platform->supportsServerSide() || !$destination->serverSide);
            },
        ));
    }
}
