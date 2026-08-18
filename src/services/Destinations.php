<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\Trigger;
use justinholtweb\tape\Plugin;
use Throwable;

/**
 * The library of destinations and triggers.
 *
 * Both live in project config and nowhere else. There is no records table shadowing them, which is
 * a deliberate simplification: a destination is configuration, it changes a handful of times in a
 * site's life, and every read of it happens after Craft has already loaded and cached the whole
 * config tree. A table would buy nothing and would need keeping in step.
 *
 * The consequence worth knowing about is that on a production site with `allowAdminChanges`
 * disabled these screens are read-only, exactly like sections and fields — which is correct, and
 * is also the point: a tracking change should arrive by deployment, not by somebody pasting a pixel
 * ID into production at five o'clock on a Friday.
 */
class Destinations extends Component
{
    public const CONFIG_DESTINATIONS_KEY = 'tape.destinations';
    public const CONFIG_TRIGGERS_KEY = 'tape.triggers';

    /** @var Destination[]|null */
    private ?array $destinations = null;

    /** @var Trigger[]|null */
    private ?array $triggers = null;

    // ── Destinations ────────────────────────────────────────────────────────────────────────

    /** @return Destination[] */
    public function getAllDestinations(): array
    {
        if ($this->destinations !== null) {
            return $this->destinations;
        }

        $configs = Craft::$app->getProjectConfig()->get(self::CONFIG_DESTINATIONS_KEY) ?? [];
        $destinations = [];

        foreach ($configs as $uid => $config) {
            if (!is_array($config)) {
                continue;
            }

            $destinations[] = $this->createFromConfig($uid, $config);
        }

        usort($destinations, static fn(Destination $a, Destination $b) => [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]);

        return $this->destinations = $destinations;
    }

    public function getDestinationByUid(string $uid): ?Destination
    {
        foreach ($this->getAllDestinations() as $destination) {
            if ($destination->uid === $uid) {
                return $destination;
            }
        }

        return null;
    }

    public function getDestinationByHandle(string $handle): ?Destination
    {
        foreach ($this->getAllDestinations() as $destination) {
            if ($destination->handle === $handle) {
                return $destination;
            }
        }

        return null;
    }

    /**
     * Destinations that could fire on this request, before consent and before event filtering.
     *
     * Unconfigured destinations are dropped here rather than at render time so that a destination
     * whose credentials live in an env var that is blank on this environment is simply absent,
     * rather than emitting a tag with an empty ID that reports nothing forever.
     *
     * @return Destination[]
     */
    public function getActiveDestinations(?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $platforms = Plugin::getInstance()->platforms;

        return array_values(array_filter(
            $this->getAllDestinations(),
            static fn(Destination $destination) => $destination->enabled
                && $destination->isActiveOnSite($siteId)
                && $destination->isConfigured()
                && $platforms->isAvailable($destination->platform),
        ));
    }

    /** @return Destination[] Destinations whose configuration cannot work as it stands. */
    public function getBrokenDestinations(): array
    {
        return array_values(array_filter(
            $this->getAllDestinations(),
            static fn(Destination $destination) => $destination->enabled && $destination->getProblems() !== [],
        ));
    }

    public function saveDestination(Destination $destination, bool $runValidation = true): bool
    {
        $isNew = $this->getDestinationByUid($destination->uid) === null;

        if ($runValidation && !$destination->validate()) {
            return false;
        }

        if ($this->handleIsTaken($destination)) {
            $destination->addError('handle', Craft::t('tape', 'That handle is already in use.'));

            return false;
        }

        if ($isNew && $destination->sortOrder === 0) {
            $destination->sortOrder = count($this->getAllDestinations()) + 1;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_DESTINATIONS_KEY . '.' . $destination->uid,
            ProjectConfigHelper::packAssociativeArrays($destination->getConfig()),
            "Save the “{$destination->name}” Tape destination",
        );

        return true;
    }

    public function deleteDestination(Destination $destination): bool
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_DESTINATIONS_KEY . '.' . $destination->uid,
            "Delete the “{$destination->name}” Tape destination",
        );

        // Called directly as well as from the config handler, because project config coalesces a
        // create-then-delete within one request into no change at all — and then nothing ever
        // fires the removal handler.
        $this->forgetDestination($destination->uid);

        return true;
    }

    /**
     * @param string[] $uids
     */
    public function reorderDestinations(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $order => $uid) {
            $projectConfig->set(self::CONFIG_DESTINATIONS_KEY . '.' . $uid . '.sortOrder', $order + 1, 'Reorder Tape destinations');
        }

        return true;
    }

    // ── Triggers ────────────────────────────────────────────────────────────────────────────

    /** @return Trigger[] */
    public function getAllTriggers(): array
    {
        if ($this->triggers !== null) {
            return $this->triggers;
        }

        $configs = Craft::$app->getProjectConfig()->get(self::CONFIG_TRIGGERS_KEY) ?? [];
        $triggers = [];

        foreach ($configs as $uid => $config) {
            if (!is_array($config)) {
                continue;
            }

            $trigger = new Trigger($config + ['uid' => $uid]);
            $trigger->uid = $uid;
            $trigger->uris = array_values(array_filter((array)($config['uris'] ?? [])));
            $trigger->siteIds = array_map('intval', (array)($config['siteIds'] ?? []));
            $triggers[] = $trigger;
        }

        usort($triggers, static fn(Trigger $a, Trigger $b) => [$a->sortOrder, $a->name] <=> [$b->sortOrder, $b->name]);

        return $this->triggers = $triggers;
    }

    public function getTriggerByUid(string $uid): ?Trigger
    {
        foreach ($this->getAllTriggers() as $trigger) {
            if ($trigger->uid === $uid) {
                return $trigger;
            }
        }

        return null;
    }

    /**
     * Triggers live on this request.
     *
     * @return Trigger[]
     */
    public function getActiveTriggers(?int $siteId, string $uri): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return [];
        }

        return array_values(array_filter(
            $this->getAllTriggers(),
            static fn(Trigger $trigger) => $trigger->enabled && $trigger->isActiveOnSite($siteId) && $trigger->matchesUri($uri),
        ));
    }

    public function saveTrigger(Trigger $trigger, bool $runValidation = true): bool
    {
        if ($runValidation && !$trigger->validate()) {
            return false;
        }

        if ($trigger->sortOrder === 0) {
            $trigger->sortOrder = count($this->getAllTriggers()) + 1;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_TRIGGERS_KEY . '.' . $trigger->uid,
            ProjectConfigHelper::packAssociativeArrays($trigger->getConfig()),
            "Save the “{$trigger->name}” Tape trigger",
        );

        return true;
    }

    public function deleteTrigger(Trigger $trigger): bool
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_TRIGGERS_KEY . '.' . $trigger->uid,
            "Delete the “{$trigger->name}” Tape trigger",
        );

        $this->triggers = null;

        return true;
    }

    // ── Project config handlers ─────────────────────────────────────────────────────────────

    public function handleChangedDestination(ConfigEvent $event): void
    {
        $this->destinations = null;
        Plugin::getInstance()->tags->invalidateCache();
    }

    public function handleDeletedDestination(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0] ?? null;

        if ($uid !== null) {
            $this->forgetDestination($uid);
        }

        $this->destinations = null;
    }

    public function handleChangedTrigger(ConfigEvent $event): void
    {
        $this->triggers = null;
        Plugin::getInstance()->tags->invalidateCache();
    }

    public function handleDeletedTrigger(ConfigEvent $event): void
    {
        $this->triggers = null;
        Plugin::getInstance()->tags->invalidateCache();
    }

    /**
     * Cleans up after a destination that is no longer in project config.
     *
     * The ledger keeps its rows — they are the record of what was sent, and deleting a destination
     * does not un-send anything — but the destination's *pending* server-side rows are dropped,
     * because there is nothing left to send them to and they would otherwise be retried forever.
     */
    public function forgetDestination(string $uid): void
    {
        $this->destinations = null;
        Plugin::getInstance()->tags->invalidateCache();

        try {
            Db::delete('{{%tape_events}}', [
                'destinationUid' => $uid,
                'status' => Ledger::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            // Before install, or mid-migration.
            Craft::warning('Could not clear pending events for removed destination ' . $uid . ': ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * Drops ledger rows and pending sends belonging to destinations that no longer exist.
     *
     * Runs from garbage collection rather than only from the delete path, because a destination can
     * vanish without ever going through `deleteDestination()` — a restored `project.yaml`, a merge
     * that dropped a block, an environment that never had it. Anything keyed on a uid that is not
     * in project config any more is unreachable by definition.
     */
    public function garbageCollect(): void
    {
        $known = array_map(static fn(Destination $destination) => $destination->uid, $this->getAllDestinations());

        $orphans = (new Query())
            ->select(['destinationUid'])
            ->distinct()
            ->from(['{{%tape_events}}'])
            ->where(['not', ['destinationUid' => null]])
            ->andWhere($known === [] ? '1=1' : ['not in', 'destinationUid', $known])
            ->column();

        if ($orphans === []) {
            return;
        }

        Db::delete('{{%tape_events}}', [
            'and',
            ['destinationUid' => $orphans],
            ['status' => Ledger::STATUS_PENDING],
        ]);

        Craft::info('Cleared pending Tape events for ' . count($orphans) . ' removed destination(s).', Plugin::LOG_CATEGORY);
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    private function createFromConfig(string $uid, array $config): Destination
    {
        $destination = new Destination();
        $destination->uid = $uid;
        $destination->handle = (string)($config['handle'] ?? '');
        $destination->name = (string)($config['name'] ?? $destination->handle);
        $destination->platform = (string)($config['platform'] ?? '');
        $destination->enabled = (bool)($config['enabled'] ?? true);
        $destination->settings = ProjectConfigHelper::unpackAssociativeArray($config['settings'] ?? []) ?: [];
        $destination->consentCategory = (string)($config['consentCategory'] ?? Consent::CATEGORY_ADS);
        $destination->events = array_values((array)($config['events'] ?? ['*'])) ?: ['*'];
        $destination->eventSettings = ProjectConfigHelper::unpackAssociativeArray($config['eventSettings'] ?? []) ?: [];
        $destination->serverSide = (bool)($config['serverSide'] ?? false);
        $destination->serverSideOnly = (bool)($config['serverSideOnly'] ?? false);
        $destination->siteIds = array_map('intval', (array)($config['siteIds'] ?? []));
        $destination->testMode = (bool)($config['testMode'] ?? false);
        $destination->testCode = $config['testCode'] ?? null;
        $destination->sortOrder = (int)($config['sortOrder'] ?? 0);

        return $destination;
    }

    private function handleIsTaken(Destination $destination): bool
    {
        foreach ($this->getAllDestinations() as $existing) {
            if ($existing->handle === $destination->handle && $existing->uid !== $destination->uid) {
                return true;
            }
        }

        return false;
    }
}
