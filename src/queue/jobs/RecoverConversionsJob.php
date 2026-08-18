<?php

namespace justinholtweb\tape\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\tape\Plugin;

/**
 * The scheduled recovery sweep.
 *
 * Pushed by the console command rather than self-scheduling: a queue job that re-queues itself
 * survives a plugin being uninstalled, and there is no good way to find it again afterwards. Cron
 * calls `php craft tape/recover`, which is a thing an operator can see and turn off.
 */
class RecoverConversionsJob extends BaseJob
{
    public ?int $lookbackDays = null;

    public int $limit = 200;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0.1, Craft::t('tape', 'Looking for missed conversions'));

        $result = Plugin::getInstance()->recovery->sweep($this->lookbackDays, $this->limit);

        $this->setProgress($queue, 1, Craft::t('tape', 'Recovered {count} conversions', ['count' => $result['sent']]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('tape', 'Recovering missed conversions');
    }
}
