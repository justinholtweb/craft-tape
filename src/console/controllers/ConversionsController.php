<?php

namespace justinholtweb\tape\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\tape\Plugin;
use yii\console\ExitCode;

/**
 * `php craft tape/conversions/…`
 *
 * Recovery is meant to run on a schedule — every fifteen minutes is a reasonable cron — because the
 * conversions it finds are ones a customer's browser was never going to report. It is a console
 * command rather than a self-rescheduling queue job so that an operator can see it, log it and turn
 * it off.
 */
class ConversionsController extends Controller
{
    /** How far back to look, in days. Platforms reject events much older than a week. */
    public ?int $days = null;

    /** How many orders to consider in one sweep. */
    public int $limit = 200;

    public function options($actionID): array
    {
        return match ($actionID) {
            'recover' => array_merge(parent::options($actionID), ['days', 'limit']),
            'prune' => array_merge(parent::options($actionID), ['days']),
            default => parent::options($actionID),
        };
    }

    /**
     * Finds conversions that never reached a platform and sends them server-side.
     */
    public function actionRecover(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            $this->stderr("Conversion recovery needs Tape Pro.\n", Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$plugin->commerce->isInstalled()) {
            $this->stderr("Conversion recovery needs Craft Commerce.\n", Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$plugin->getSettings()->recoveryEnabled) {
            $this->stdout("Recovery is switched off in Tape's settings.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $result = $plugin->recovery->sweep($this->days, $this->limit);

        $this->stdout(sprintf(
            "Orders with no conversion at all: %d\nConversions written but never confirmed: %d\nSent: %d\n",
            $result['missing'],
            $result['unconfirmed'],
            $result['sent'],
        ), $result['sent'] > 0 ? Console::FG_GREEN : Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Drops ledger rows past the retention window.
     *
     * Runs from Craft's garbage collection as well; this is for doing it now.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->ledger->prune($this->days);
        $this->stdout("Removed $deleted ledger row(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
