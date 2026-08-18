<?php

namespace justinholtweb\tape\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use justinholtweb\tape\queue\jobs\RecoverConversionsJob;
use yii\web\Response;

/**
 * The two screens that answer “is this actually working?”
 *
 * **Events** is the raw ledger — every conversion, what channel it went out on, whether the
 * platform took it, and the response when it did not. It is the first place to look when somebody
 * says a pixel is not firing, and it usually ends the conversation.
 *
 * **Accuracy** is the one worth putting in front of a client. Orders placed against conversions
 * tracked, per day. A store reading 62% has a tracking problem that is costing it more than any
 * amount of campaign tuning would earn — and there is no way to see that number from inside Google
 * Ads, because Google Ads has no idea how many orders you actually took.
 */
class ReportsController extends Controller
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

    public function actionEvents(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $criteria = array_filter([
            'eventName' => $request->getQueryParam('event'),
            'status' => $request->getQueryParam('status'),
            'channel' => $request->getQueryParam('channel'),
            'destinationUid' => $request->getQueryParam('destination'),
        ]);

        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 100;

        return $this->renderTemplate('tape/events/_index', [
            'rows' => $plugin->ledger->getRecent($criteria, $perPage, ($page - 1) * $perPage),
            'total' => $plugin->ledger->getCount($criteria),
            'page' => $page,
            'perPage' => $perPage,
            'criteria' => $criteria,
            'destinations' => $this->destinationsByUid(),
            'eventNames' => $this->eventNames(),
        ]);
    }

    public function actionAccuracy(): Response
    {
        $plugin = Plugin::getInstance();
        $days = max(1, min(90, (int)Craft::$app->getRequest()->getQueryParam('days', 30)));
        $destinationUid = Craft::$app->getRequest()->getQueryParam('destination') ?: null;

        $from = new DateTime("-$days days");
        $rows = $plugin->ledger->getAccuracy($from, new DateTime(), $destinationUid);

        $orders = array_sum(array_column($rows, 'orders'));
        $tracked = array_sum(array_column($rows, 'tracked'));

        return $this->renderTemplate('tape/reports/_accuracy', [
            'rows' => $rows,
            'days' => $days,
            'orders' => $orders,
            'tracked' => $tracked,
            'rate' => $orders > 0 ? round(($tracked / $orders) * 100, 1) : null,
            'destinations' => $this->destinationsByUid(),
            'destinationUid' => $destinationUid,
            'health' => $plugin->ledger->getDestinationHealth($from),
            'broken' => $plugin->destinations->getBrokenDestinations(),
            'unrecoverable' => $plugin->recovery->getUnrecoverableDestinations(),
            'commerce' => $plugin->commerce->isInstalled(),
            'recoveryEnabled' => $plugin->getSettings()->recoveryEnabled && $plugin->isPro(),
        ]);
    }

    /**
     * Runs a recovery sweep now.
     *
     * Queued rather than run inline: a sweep can send a hundred requests to five APIs, and a
     * control-panel request is not the place for that.
     */
    public function actionRecover(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_RECOVER);

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            return $this->asFailure(Craft::t('tape', 'Conversion recovery needs Tape Pro.'));
        }

        if (!$plugin->commerce->isInstalled()) {
            return $this->asFailure(Craft::t('tape', 'Conversion recovery needs Craft Commerce.'));
        }

        Craft::$app->getQueue()->push(new RecoverConversionsJob());

        return $this->asSuccess(Craft::t('tape', 'Looking for missed conversions.'));
    }

    /** @return array<string, Destination> */
    private function destinationsByUid(): array
    {
        $byUid = [];

        foreach (Plugin::getInstance()->destinations->getAllDestinations() as $destination) {
            $byUid[$destination->uid] = $destination;
        }

        return $byUid;
    }

    /** @return string[] */
    private function eventNames(): array
    {
        $names = array_merge([TrackingEvent::PAGE_VIEW], TrackingEvent::REQUESTABLE_EVENTS, [
            TrackingEvent::PURCHASE,
            TrackingEvent::REFUND,
        ]);

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
