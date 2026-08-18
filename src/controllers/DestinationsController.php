<?php

namespace justinholtweb\tape\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The destination library screens.
 *
 * Destinations live in project config, so writing to them needs `allowAdminChanges` — the same rule
 * as sections and fields, and for the same reason. It is checked here rather than left to Craft's
 * generic message, so that somebody on a locked-down production site is told *why* the save button
 * is missing instead of discovering it silently does nothing.
 */
class DestinationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('tape/destinations/_index', [
            'destinations' => $plugin->destinations->getAllDestinations(),
            'platforms' => $plugin->platforms->getAllPlatforms(),
            'health' => $plugin->ledger->getDestinationHealth(new \DateTime('-7 days')),
            'readOnly' => !$this->canWrite(),
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionEdit(?string $uid = null, ?Destination $destination = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($destination === null) {
            $destination = $uid !== null ? $plugin->destinations->getDestinationByUid($uid) : new Destination();

            if ($destination === null) {
                throw new NotFoundHttpException('No such destination.');
            }
        }

        $isNew = $uid === null;
        $platformHandle = Craft::$app->getRequest()->getQueryParam('platform') ?? $destination->platform;
        $platform = $platformHandle ? $plugin->platforms->getPlatform($platformHandle) : null;

        if ($isNew && $platform === null) {
            return $this->renderTemplate('tape/destinations/_choose', [
                'platforms' => $plugin->platforms->getAllPlatforms(),
                'isPro' => $plugin->isPro(),
            ]);
        }

        if ($platform !== null && $destination->platform === '') {
            $destination->platform = $platform::handle();
            $destination->name = $platform->displayName();
            $destination->handle = $this->suggestHandle($platform::handle());
            $destination->consentCategory = $platform->defaultConsentCategory();
        }

        return $this->renderTemplate('tape/destinations/_edit', [
            'destination' => $destination,
            'platform' => $platform,
            'isNew' => $isNew,
            'readOnly' => !$this->canWrite(),
            'isPro' => $plugin->isPro(),
            'eventOptions' => $this->eventOptions($platform),
            'consentOptions' => [
                Consent::CATEGORY_ADS => Craft::t('tape', 'Advertising'),
                Consent::CATEGORY_ANALYTICS => Craft::t('tape', 'Analytics'),
                Consent::CATEGORY_FUNCTIONALITY => Craft::t('tape', 'Functionality'),
            ],
            'siteOptions' => $this->siteOptions(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireWritePermission();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $uid = $request->getBodyParam('uid');

        $destination = $uid ? $plugin->destinations->getDestinationByUid($uid) : new Destination();

        if ($destination === null) {
            throw new NotFoundHttpException('No such destination.');
        }

        $destination->platform = (string)$request->getBodyParam('platform', $destination->platform);
        $destination->name = trim((string)$request->getBodyParam('name', ''));
        $destination->handle = trim((string)$request->getBodyParam('handle', ''));
        $destination->enabled = (bool)$request->getBodyParam('enabled', true);
        $destination->consentCategory = (string)$request->getBodyParam('consentCategory', Consent::CATEGORY_ADS);
        $destination->settings = (array)$request->getBodyParam('settings', []);
        $destination->serverSide = (bool)$request->getBodyParam('serverSide', false);
        $destination->serverSideOnly = (bool)$request->getBodyParam('serverSideOnly', false);
        $destination->testMode = (bool)$request->getBodyParam('testMode', false);
        $destination->testCode = $request->getBodyParam('testCode') ?: null;
        $destination->siteIds = array_map('intval', (array)$request->getBodyParam('siteIds', []));

        $events = $request->getBodyParam('events');
        $destination->events = ($events === null || $events === '*' || in_array('*', (array)$events, true))
            ? ['*']
            : array_values(array_filter((array)$events));

        $destination->eventSettings = $this->cleanEventSettings((array)$request->getBodyParam('eventSettings', []));

        if (!$plugin->platforms->isAvailable($destination->platform)) {
            $destination->addError('platform', Craft::t('tape', 'That platform needs Tape Pro.'));
        }

        if ($destination->hasErrors() || !$plugin->destinations->saveDestination($destination)) {
            Craft::$app->getSession()->setError(Craft::t('tape', 'Couldn’t save the destination.'));
            Craft::$app->getUrlManager()->setRouteParams(['destination' => $destination]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('tape', 'Destination saved.'));

        return $this->redirectToPostedUrl($destination);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireWritePermission();

        $uid = (string)Craft::$app->getRequest()->getRequiredBodyParam('uid');
        $destination = Plugin::getInstance()->destinations->getDestinationByUid($uid);

        if ($destination === null) {
            throw new NotFoundHttpException('No such destination.');
        }

        Plugin::getInstance()->destinations->deleteDestination($destination);

        return $this->asSuccess(Craft::t('tape', 'Destination deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireWritePermission();

        $uids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->destinations->reorderDestinations(is_string($uids) ? json_decode($uids, true) : (array)$uids);

        return $this->asSuccess();
    }

    /**
     * Sends a real event to a destination's server-side API and reports exactly what came back.
     *
     * The reason this exists rather than a “configuration looks valid” check: every one of these
     * APIs fails in a way that is invisible from the outside. A revoked token, a retired API
     * version, a pixel ID belonging to a different account — all of them return a perfectly
     * ordinary response to a browser tag and nothing ever appears in the platform's reporting. One
     * round trip with the answer printed is worth more than any amount of validation.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireWritePermission();

        $plugin = Plugin::getInstance();
        $uid = (string)Craft::$app->getRequest()->getRequiredBodyParam('uid');
        $destination = $plugin->destinations->getDestinationByUid($uid);

        if ($destination === null) {
            throw new NotFoundHttpException('No such destination.');
        }

        $event = new TrackingEvent([
            'name' => TrackingEvent::VIEW_ITEM,
            'value' => 9.99,
            'currency' => $plugin->getSettings()->defaultCurrency ?: 'USD',
            'source' => TrackingEvent::SOURCE_SERVER,
            'userData' => $plugin->events->getUserData(),
        ]);

        $result = $plugin->dispatcher->send($event, $destination);

        return $this->asJson([
            'ok' => $result->ok,
            'skipped' => $result->skipped,
            'message' => $result->getSummary(),
        ]);
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    private function canWrite(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->allowAdminChanges
            && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE);
    }

    private function requireWritePermission(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('tape', 'Tracking destinations are part of project config, so they can only be changed in an environment with admin changes allowed.'));
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE);
    }

    private function suggestHandle(string $base): string
    {
        $destinations = Plugin::getInstance()->destinations->getAllDestinations();
        $taken = array_map(static fn(Destination $d) => $d->handle, $destinations);

        if (!in_array($base, $taken, true)) {
            return $base;
        }

        $index = 2;

        while (in_array($base . $index, $taken, true)) {
            $index++;
        }

        return $base . $index;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function eventOptions(mixed $platform): array
    {
        $names = $platform !== null ? $platform->supportedEvents() : TrackingEvent::REQUESTABLE_EVENTS;
        $commerce = Plugin::getInstance()->commerce->isInstalled();
        $options = [];

        foreach ($names as $name) {
            // A store-less site listing “add to cart” among its events is noise, not flexibility.
            if (!$commerce && in_array($name, TrackingEvent::COMMERCE_EVENTS, true)) {
                continue;
            }

            $options[] = ['label' => $name, 'value' => $name];
        }

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function siteOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $options[] = ['label' => $site->name, 'value' => (string)$site->id];
        }

        return $options;
    }

    /**
     * Drops empty per-event settings.
     *
     * Every event gets an input on the edit screen, so an untouched form would otherwise write
     * thirty empty conversion labels into project config and turn every deployment diff into noise.
     *
     * @return array<string, array<string, mixed>>
     */
    private function cleanEventSettings(array $posted): array
    {
        $clean = [];

        foreach ($posted as $eventName => $settings) {
            if (!is_array($settings)) {
                continue;
            }

            $values = array_filter(array_map(
                static fn($value) => is_string($value) ? trim($value) : $value,
                $settings,
            ), static fn($value) => $value !== '' && $value !== null);

            if ($values !== []) {
                $clean[$eventName] = $values;
            }
        }

        return $clean;
    }
}
