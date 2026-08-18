<?php

namespace justinholtweb\tape\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\Trigger;
use justinholtweb\tape\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The trigger screens.
 *
 * Triggers are how “count a phone-number click as a lead” stops being a development ticket. They
 * are Pro, and the edition check is here rather than only in the runtime so that the screen says so
 * plainly rather than saving something that will never fire.
 */
class TriggersController extends Controller
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
        return $this->renderTemplate('tape/triggers/_index', [
            'triggers' => Plugin::getInstance()->destinations->getAllTriggers(),
            'readOnly' => !$this->canWrite(),
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    public function actionEdit(?string $uid = null, ?Trigger $trigger = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($trigger === null) {
            $trigger = $uid !== null ? $plugin->destinations->getTriggerByUid($uid) : new Trigger();

            if ($trigger === null) {
                throw new NotFoundHttpException('No such trigger.');
            }
        }

        return $this->renderTemplate('tape/triggers/_edit', [
            'trigger' => $trigger,
            'isNew' => $uid === null,
            'readOnly' => !$this->canWrite(),
            'isPro' => $plugin->isPro(),
            'typeOptions' => $this->typeOptions(),
            'eventOptions' => $this->eventOptions(),
            'siteOptions' => array_map(
                static fn($site) => ['label' => $site->name, 'value' => (string)$site->id],
                Craft::$app->getSites()->getAllSites(),
            ),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireWritePermission();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $uid = $request->getBodyParam('uid');
        $trigger = $uid ? $plugin->destinations->getTriggerByUid($uid) : new Trigger();

        if ($trigger === null) {
            throw new NotFoundHttpException('No such trigger.');
        }

        $trigger->name = trim((string)$request->getBodyParam('name', ''));
        $trigger->enabled = (bool)$request->getBodyParam('enabled', true);
        $trigger->type = (string)$request->getBodyParam('type', Trigger::TYPE_CLICK);
        $trigger->selector = $request->getBodyParam('selector') ?: null;
        $trigger->threshold = $request->getBodyParam('threshold') ?: null;
        $trigger->event = trim((string)$request->getBodyParam('event', TrackingEvent::GENERATE_LEAD));
        $trigger->value = ($value = $request->getBodyParam('value')) === '' || $value === null ? null : (float)$value;
        $trigger->currency = ($currency = $request->getBodyParam('currency')) ? strtoupper(trim((string)$currency)) : null;
        $trigger->once = (string)$request->getBodyParam('once', Trigger::ONCE_SESSION);
        $trigger->uris = $this->lines($request->getBodyParam('uris', ''));
        $trigger->siteIds = array_map('intval', (array)$request->getBodyParam('siteIds', []));

        if (!$plugin->destinations->saveTrigger($trigger)) {
            Craft::$app->getSession()->setError(Craft::t('tape', 'Couldn’t save the trigger.'));
            Craft::$app->getUrlManager()->setRouteParams(['trigger' => $trigger]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('tape', 'Trigger saved.'));

        return $this->redirectToPostedUrl($trigger);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireWritePermission();

        $uid = (string)Craft::$app->getRequest()->getRequiredBodyParam('uid');
        $trigger = Plugin::getInstance()->destinations->getTriggerByUid($uid);

        if ($trigger === null) {
            throw new NotFoundHttpException('No such trigger.');
        }

        Plugin::getInstance()->destinations->deleteTrigger($trigger);

        return $this->asSuccess(Craft::t('tape', 'Trigger deleted.'));
    }

    private function canWrite(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->allowAdminChanges
            && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE);
    }

    private function requireWritePermission(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('tape', 'Triggers are part of project config, so they can only be changed in an environment with admin changes allowed.'));
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function typeOptions(): array
    {
        $labels = [
            Trigger::TYPE_CLICK => Craft::t('tape', 'Click on an element'),
            Trigger::TYPE_OUTBOUND => Craft::t('tape', 'Click on a link to another site'),
            Trigger::TYPE_TEL => Craft::t('tape', 'Click on a phone number'),
            Trigger::TYPE_MAILTO => Craft::t('tape', 'Click on an email address'),
            Trigger::TYPE_FORM_SUBMIT => Craft::t('tape', 'Form submitted'),
            Trigger::TYPE_SCROLL => Craft::t('tape', 'Scrolled to a depth'),
            Trigger::TYPE_VISIBLE => Craft::t('tape', 'Element scrolled into view'),
            Trigger::TYPE_TIME => Craft::t('tape', 'Time spent on the page'),
        ];

        return array_map(static fn($value, $label) => ['label' => $label, 'value' => $value], array_keys($labels), $labels);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function eventOptions(): array
    {
        $names = [
            TrackingEvent::GENERATE_LEAD,
            TrackingEvent::CONTACT,
            TrackingEvent::SIGN_UP,
            TrackingEvent::SUBSCRIBE,
            TrackingEvent::LOGIN,
            TrackingEvent::SEARCH,
            TrackingEvent::SCROLL,
            TrackingEvent::CLICK,
        ];

        return array_map(static fn(string $name) => ['label' => $name, 'value' => $name], $names);
    }

    /** @return string[] */
    private function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)$value) ?: [])));
    }
}
