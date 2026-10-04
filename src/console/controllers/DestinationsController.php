<?php

namespace justinholtweb\tape\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use yii\console\ExitCode;

/**
 * `php craft tape/destinations/…`
 *
 * The commands worth having in a deploy script and in a support conversation: what is configured,
 * what is broken, and does the API actually answer.
 */
class DestinationsController extends Controller
{
    /** Destination handle. Omit to test every configured destination. */
    public ?string $handle = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'test' => array_merge(parent::options($actionID), ['handle']),
            default => parent::options($actionID),
        };
    }

    /**
     * Lists every destination and whether it can fire.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $destinations = $plugin->destinations->getAllDestinations();

        if ($destinations === []) {
            $this->stdout("No destinations configured yet.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($destinations as $destination) {
            $platform = $destination->getPlatform();
            $problems = $destination->getProblems();

            $this->stdout($destination->handle, Console::FG_CYAN, Console::BOLD);
            $this->stdout('  ' . ($platform?->displayName() ?? $destination->platform));
            $this->stdout('  ' . ($destination->enabled ? 'enabled' : 'disabled'), $destination->enabled ? Console::FG_GREEN : Console::FG_GREY);

            if ($destination->serverSide) {
                $this->stdout('  server-side', Console::FG_BLUE);
            }

            $this->stdout("\n");

            foreach ($problems as $problem) {
                $this->stdout('  ! ' . $problem . "\n", Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Sends a real event to each destination's server-side API and prints what came back.
     *
     * Worth doing after every credential change. A revoked token, a retired API version and a pixel
     * belonging to a different account all look identical from the browser — the tag fires, nothing
     * errors, and no conversion ever appears in the platform's reporting. This is the only thing
     * that says so out loud.
     */
    public function actionTest(): int
    {
        $plugin = Plugin::getInstance();
        $destinations = $this->handle !== null
            ? array_filter([$plugin->destinations->getDestinationByHandle($this->handle)])
            : $plugin->destinations->getAllDestinations();

        if ($destinations === []) {
            $this->stderr("No matching destination.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $event = new TrackingEvent([
            'name' => TrackingEvent::VIEW_ITEM,
            'value' => 9.99,
            'currency' => $plugin->getSettings()->defaultCurrency ?: 'USD',
        ]);

        $failed = 0;

        foreach ($destinations as $destination) {
            $result = $plugin->dispatcher->send($event, $destination);

            $this->stdout(str_pad($destination->handle, 24));

            if ($result->skipped) {
                $this->stdout('skipped  ' . $result->getSummary() . "\n", Console::FG_GREY);
                continue;
            }

            if ($result->ok) {
                $this->stdout('ok       ' . $result->getSummary() . "\n", Console::FG_GREEN);
                continue;
            }

            $failed++;
            $this->stdout('FAILED   ' . $result->getSummary() . "\n", Console::FG_RED);
        }

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * A configuration review: what is wired up, what is missing, and what cannot be recovered.
     */
    public function actionDoctor(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout("Tape " . $plugin->getVersion() . ' (' . $plugin->edition . ")\n\n", Console::FG_CYAN, Console::BOLD);

        $active = $plugin->destinations->getActiveDestinations();
        $all = $plugin->destinations->getAllDestinations();

        $this->line('Destinations', count($all) . ' configured, ' . count($active) . ' able to fire');
        $this->line('Commerce', $plugin->commerce->isInstalled() ? 'installed' : 'not installed — the ecommerce funnel is off');

        if ($plugin->commerce->isInstalled() && $settings->autoTrackCommerce) {
            foreach ($plugin->resolveCommerceHooks() as $step => $event) {
                $this->stdout(str_pad('  ' . $step, 24), Console::FG_GREY);
                $this->stdout(($event ?? 'NOT WIRED — call craft.tape.' . $step . '() from a template') . "\n",
                    $event === null ? Console::FG_RED : Console::FG_GREY);
            }
        }
        $this->line('Auto-injection', $settings->autoInject ? 'on' : 'off — templates must call tape.head() and tape.body()');
        $this->line('Consent mode', $settings->consentMode ? 'on, source: ' . $settings->consentSource : 'off');
        $this->line('Enhanced conversions', $plugin->enhancedConversions() ? 'on' : ($settings->enhancedConversions ? 'off — needs Pro' : 'off'));
        $this->line('Recovery', $settings->recoveryEnabled && $plugin->isPro() ? 'on, ' . $settings->recoveryDelayMinutes . ' minute delay' : 'off');
        $this->line('Product IDs', $settings->productIdSource . ' — these must match your merchant feed');

        $broken = $plugin->destinations->getBrokenDestinations();

        if ($broken !== []) {
            $this->stdout("\nProblems\n", Console::FG_RED, Console::BOLD);

            foreach ($broken as $destination) {
                foreach ($destination->getProblems() as $problem) {
                    $this->stdout('  ' . $destination->handle . ': ' . $problem . "\n", Console::FG_RED);
                }
            }
        }

        $unrecoverable = $plugin->recovery->getUnrecoverableDestinations();

        if ($unrecoverable !== []) {
            $this->stdout("\nPurchases that cannot be recovered if the browser tag is blocked\n", Console::FG_YELLOW, Console::BOLD);

            foreach ($unrecoverable as $destination) {
                $platform = $destination->getPlatform();
                $why = $platform !== null && !$platform->supportsServerSide()
                    ? 'this platform has no server-side API'
                    : 'server-side tracking is off for this destination';
                $this->stdout('  ' . $destination->handle . ': ' . $why . "\n", Console::FG_YELLOW);
            }
        }

        if ($plugin->commerce->isInstalled()) {
            $accuracy = $plugin->ledger->getAccuracy(new \DateTime('-30 days'), new \DateTime());
            $orders = array_sum(array_column($accuracy, 'orders'));
            $tracked = array_sum(array_column($accuracy, 'tracked'));

            $this->stdout("\n");
            $this->line('Last 30 days', $orders === 0
                ? 'no completed orders'
                : sprintf('%d orders, %d tracked (%.1f%%)', $orders, $tracked, ($tracked / $orders) * 100));
        }

        return ExitCode::OK;
    }

    private function line(string $label, string $value): void
    {
        $this->stdout(str_pad($label, 24), Console::FG_GREY);
        $this->stdout($value . "\n");
    }
}
