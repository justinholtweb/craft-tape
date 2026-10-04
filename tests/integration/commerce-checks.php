<?php
/**
 * Tape's Commerce checks.
 *
 *     ddev exec php /var/www/craft-tape/tests/integration/commerce-checks.php
 *
 * Separate from `checks.php` because these build real orders, which is slow and needs Commerce
 * installed. They cover the part of Tape with the most moving parts and the least ability to be
 * unit-tested: turning an order into an event, deduplicating a purchase across the browser and
 * server halves, and recovering one that never got tracked.
 *
 * Orders are created completed and deleted at the end from a shutdown handler.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\Settings;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use justinholtweb\tape\services\Ledger;
use justinholtweb\tape\transports\TransportInterface;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

class CountingTransport implements TransportInterface
{
    public array $requests = [];
    public int $status = 200;
    public string $body = '{"events_received":1}';

    public function send(array $request, int $timeout): array
    {
        $this->requests[] = $request;

        return ['status' => $this->status, 'body' => $this->body, 'error' => null];
    }
}

$plugin = Plugin::getInstance();
$run = substr(md5((string)microtime(true)), 0, 6);
$orderIds = [];
$destinationUids = [];

$originalEdition = $plugin->edition;
Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_PRO);

$transport = new CountingTransport();
$plugin->dispatcher->transport = $transport;

// Server-side sends are made inline here so the checks can see the result, rather than being left
// in a queue nobody runs.
$plugin->getSettings()->queueServerSide = false;
$plugin->events->setShouldTrack(true);

register_shutdown_function(function() use (&$orderIds, &$destinationUids, $plugin, $originalEdition) {
    foreach ($orderIds as $id) {
        craft\helpers\Db::delete('{{%tape_events}}', ['orderId' => $id]);
        $order = Order::find()->id($id)->status(null)->one();

        if ($order !== null) {
            Craft::$app->getElements()->deleteElement($order, true);
        }
    }

    foreach ($destinationUids as $uid) {
        $destination = $plugin->destinations->getDestinationByUid($uid);

        if ($destination !== null) {
            $plugin->destinations->deleteDestination($destination);
        }
    }

    if ($plugin->edition !== $originalEdition) {
        Craft::$app->getPlugins()->switchEdition('tape', $originalEdition);
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

/** Builds and completes a real order. */
function makeOrder(array $quantities, string $email, ?DateTime $orderedAt = null): Order
{
    $order = new Order();
    $order->setEmail($email);
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->couponCode = null;

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('could not create the cart: ' . json_encode($order->getErrors()));
    }

    $lineItems = Craft::$app->getPlugins()->getPlugin('commerce')->getLineItems();

    foreach ($quantities as $variantId => $quantity) {
        $lineItem = $lineItems->createLineItem($order, $variantId, []);
        $lineItem->qty = $quantity;
        $order->addLineItem($lineItem);
    }

    // Commerce constructs its own owned Address element; handing it one built here is refused.
    $order->setShippingAddress([
        'firstName' => 'Ada',
        'lastName' => 'Lovelace',
        'addressLine1' => '1 Test Street',
        'locality' => 'London',
        'postalCode' => 'SW1A 1AA',
        'countryCode' => 'GB',
    ]);
    $order->sourceShippingAddressId = null;
    $order->setBillingAddress($order->getShippingAddress()?->toArray() ?? []);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('could not save the cart: ' . json_encode($order->getErrors()));
    }

    if (!$order->markAsComplete()) {
        throw new RuntimeException('could not complete the order: ' . json_encode($order->getErrors()));
    }

    if ($orderedAt !== null) {
        craft\helpers\Db::update('{{%commerce_orders}}', [
            'dateOrdered' => craft\helpers\Db::prepareDateForDb($orderedAt),
        ], ['id' => $order->id]);
    }

    return $order;
}

echo "\nTape Commerce checks — run $run\n";

$variants = Variant::find()->status(null)->limit(2)->all();

if (count($variants) < 2) {
    echo "  ! this harness has fewer than two Commerce variants; nothing to test against\n";
    exit(0);
}

[$small, $large] = $variants;

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Auto-tracking is actually wired to Commerce');

check('every funnel step resolved to a real Commerce event', function() use ($plugin) {
    // The failure this catches is the nastiest one in the plugin: Commerce renames an event, the
    // `defined()` guard skips it, and cart tracking is silently off while everything looks fine.
    $hooks = $plugin->resolveCommerceHooks();

    if ($hooks === []) {
        return 'nothing resolved at all';
    }

    foreach ($hooks as $step => $event) {
        if ($event === null) {
            return "no Commerce event matched for “{$step}” — auto-tracking for it is off";
        }
    }

    return true;
});

check('the resolved event names exist on the Order class', function() use ($plugin) {
    $published = array_values((new ReflectionClass(Order::class))->getConstants());

    foreach ($plugin->resolveCommerceHooks() as $step => $event) {
        if (!in_array($event, $published, true)) {
            return "“{$event}” is not a Commerce Order event";
        }
    }

    return true;
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Turning an order into an event');

$order = null;

check('an order becomes a purchase event with its lines, tax and shipping', function() use ($plugin, $small, $large, &$order, &$orderIds, $run) {
    $order = makeOrder([$small->id => 2, $large->id => 1], "tape-$run@example.test");
    $orderIds[] = $order->id;

    $event = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE);

    if (count($event->items) !== 2) {
        return count($event->items) . ' items';
    }

    $expected = round((float)$order->getTotalPrice(), 4);

    return $event->name === 'purchase'
        && $event->orderId === $order->id
        && $event->transactionId === (string)($order->reference ?: $order->number)
        && $event->getValue() === $expected
        && $event->currency !== null
        && $event->getItemCount() === 3
        && $event->tax !== null
        && $event->shipping !== null
            ? true
            : json_encode(['value' => $event->getValue(), 'expected' => $expected, 'currency' => $event->currency]);
});

check('line items carry the price the customer actually paid', function() use ($plugin, $order, $small) {
    $event = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE);
    $item = null;

    foreach ($event->items as $candidate) {
        if ($candidate->sku === $small->sku) {
            $item = $candidate;
        }
    }

    if ($item === null) {
        return 'the small variant is missing from the event';
    }

    $lineItem = null;

    foreach ($order->getLineItems() as $line) {
        if ($line->getPurchasable()?->id === $small->id) {
            $lineItem = $line;
        }
    }

    return abs($item->price - (float)($lineItem->salePrice ?? $lineItem->price)) < 0.0001
        && $item->quantity === 2
        && $item->id === $small->sku
            ? true
            : "price {$item->price} vs {$lineItem->salePrice}";
});

check('an item carries the product title and the variant name separately', function() use ($plugin, $order) {
    $item = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->items[0];

    return $item->name !== null && $item->name !== $item->variant
        ? true
        : json_encode(['name' => $item->name, 'variant' => $item->variant]);
});

check('the category falls back to the Commerce product type name', function() use ($plugin, $order) {
    $item = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->items[0];

    return $item->categories !== [] ? true : 'no category at all';
});

check('the value basis setting changes what a purchase is worth', function() use ($plugin, $order) {
    $settings = $plugin->getSettings();

    $settings->valueBasis = Settings::VALUE_GROSS;
    $gross = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->getValue();

    $settings->valueBasis = Settings::VALUE_NET;
    $net = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->getValue();

    $settings->valueBasis = Settings::VALUE_ITEMS;
    $items = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->getValue();

    $settings->valueBasis = Settings::VALUE_GROSS;

    $tax = (float)$order->getTotalTax();
    $shipping = (float)$order->getTotalShippingCost();

    return abs($gross - $net - $tax - $shipping) < 0.0001
        && abs($items - (float)$order->getItemSubtotal()) < 0.0001
            ? true
            : "gross:$gross net:$net items:$items tax:$tax shipping:$shipping";
});

check('the customer’s details reach the event, and the address with them', function() use ($plugin, $order, $run) {
    $user = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->userData;

    return $user->email === "tape-$run@example.test"
        && $user->firstName === 'Ada'
        && $user->postalCode === 'SW1A 1AA'
        && $user->country === 'GB'
            ? true
            : json_encode($user->toArray());
});

check('a first order is flagged as a new customer', function() use ($plugin, $order) {
    return $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->isNewCustomer === true;
});

check('a returning customer’s second order is not', function() use ($plugin, $small, $run, &$orderIds) {
    $second = makeOrder([$small->id => 1], "tape-$run@example.test");
    $orderIds[] = $second->id;

    return $plugin->commerce->eventFromOrder($second, TrackingEvent::PURCHASE)->isNewCustomer === false
        ? true
        : 'Google Shopping’s new-customer bidding depends on this being right';
});

check('the conversion ID is derived from the order, so it is the same every time', function() use ($plugin, $order) {
    $first = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->eventId;
    $second = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->eventId;

    return $first === $second && strlen($first) === 36
        ? true
        : "$first vs $second";
});

check('the shipping tier and payment gateway are attached to their own funnel steps', function() use ($plugin, $order) {
    $shipping = $plugin->commerce->eventFromOrder($order, TrackingEvent::ADD_SHIPPING_INFO);
    $payment = $plugin->commerce->eventFromOrder($order, TrackingEvent::ADD_PAYMENT_INFO);

    return $shipping->name === 'add_shipping_info'
        && $payment->name === 'add_payment_info'
        && $shipping->transactionId === null
            ? true
            : 'a cart event should carry no transaction ID';
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Purchases through the full pipeline');

$metaUid = null;

check('a Meta destination with server-side tracking is set up', function() use ($plugin, $run, &$destinationUids, &$metaUid) {
    $destination = new Destination([
        'handle' => 'cmeta' . $run,
        'name' => 'Commerce Meta ' . $run,
        'platform' => 'meta',
        'settings' => ['pixelId' => '100000000000009', 'accessToken' => 'test-token'],
        'serverSide' => true,
    ]);

    if (!$plugin->destinations->saveDestination($destination)) {
        return json_encode($destination->getErrors());
    }

    $destinationUids[] = $metaUid = $destination->uid;

    return true;
});

check('processing a purchase writes a browser row and sends the server half', function() use ($plugin, $order, $transport, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $before = count($transport->requests);

    $event = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE);
    $result = $plugin->events->process($event, ['destinations' => [$destination]]);

    // Queueing is off for this script, so the send is held until after the response — which in a
    // console script has to be flushed by hand.
    $plugin->dispatcher->flushDeferred();

    $rows = $plugin->ledger->getRecent(['orderId' => $order->id]);
    $channels = array_column($rows, 'channel');

    return $result['dispatch'] !== []
        && in_array('browser', $channels, true)
        && in_array('server', $channels, true)
        && count($transport->requests) === $before + 1
            ? true
            : json_encode(['channels' => $channels, 'sent' => count($transport->requests) - $before]);
});

check('the browser tag and the server call carry the same event ID', function() use ($plugin, $order, $transport) {
    $expected = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE)->eventId;
    $last = $transport->requests[count($transport->requests) - 1];

    return ($last['body']['data'][0]['event_id'] ?? null) === $expected
        ? true
        : 'without a shared event ID the platform counts the conversion twice';
});

check('the same purchase processed again sends nothing', function() use ($plugin, $order, $transport, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $before = count($transport->requests);

    $event = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE);
    $plugin->events->process($event, ['destinations' => [$destination]]);
    $plugin->dispatcher->flushDeferred();

    return count($transport->requests) === $before
        ? true
        : 'a refreshed confirmation page must not double the revenue';
});

check('a purchase with consent withheld is recorded as skipped, not sent', function() use ($plugin, $small, $run, $transport, $metaUid, &$orderIds) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $order = makeOrder([$small->id => 1], "skip-$run@example.test");
    $orderIds[] = $order->id;

    $settings = $plugin->getSettings();
    $settings->consentSource = Settings::CONSENT_CMP;
    $plugin->consent->setResolved(null);

    $before = count($transport->requests);
    $event = $plugin->commerce->eventFromOrder($order, TrackingEvent::PURCHASE);
    $plugin->events->process($event, ['destinations' => [$destination]]);
    $plugin->dispatcher->flushDeferred();

    $settings->consentSource = Settings::CONSENT_GRANTED;
    $plugin->consent->setResolved(null);

    $rows = $plugin->ledger->getRecent(['orderId' => $order->id, 'channel' => 'server']);

    return count($transport->requests) === $before
        && ($rows[0]['status'] ?? null) === Ledger::STATUS_SKIPPED
            ? true
            : json_encode($rows);
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Recovery');

check('an order that never reached the confirmation page is found', function() use ($plugin, $small, $run, &$orderIds) {
    $order = makeOrder([$small->id => 1], "lost-$run@example.test", new DateTime('-2 hours'));
    $orderIds[] = $order->id;

    $untracked = $plugin->ledger->getUntrackedOrders(new DateTime('-3 days'), new DateTime('-1 minute'));
    $ids = array_column($untracked, 'id');

    return in_array($order->id, $ids, true) ? true : 'the lost order was not found among ' . count($ids);
});

check('a sweep sends it server-side and records the recovery', function() use ($plugin, $transport, &$orderIds) {
    $lost = $orderIds[count($orderIds) - 1];
    $before = count($transport->requests);

    $result = $plugin->recovery->sweep(3, 50);

    $rows = $plugin->ledger->getRecent(['orderId' => $lost, 'channel' => 'recovery']);

    return $result['sent'] >= 1
        && count($transport->requests) > $before
        && ($rows[0]['status'] ?? null) === Ledger::STATUS_SENT
            ? true
            : json_encode(['result' => $result, 'rows' => $rows]);
});

check('a recovered conversion carries the order’s own time, not now', function() use ($plugin, $transport, &$orderIds) {
    $last = $transport->requests[count($transport->requests) - 1];
    $eventTime = $last['body']['data'][0]['event_time'] ?? 0;

    // Stamping it "now" would land the conversion in the wrong reporting day and, on several
    // platforms, outside the attribution window entirely.
    return $eventTime < time() - 3000 ? true : 'event_time is ' . (time() - $eventTime) . 's ago';
});

check('a second sweep does not send it again', function() use ($plugin, $transport) {
    $before = count($transport->requests);
    $plugin->recovery->sweep(3, 50);

    return count($transport->requests) === $before
        ? true
        : 'recovery must be idempotent or a cron job inflates every conversion';
});

check('the order skipped for consent is never recovered', function() use ($plugin, $run, &$orderIds) {
    $untracked = $plugin->ledger->getUntrackedOrders(new DateTime('-3 days'), new DateTime());
    $ids = array_column($untracked, 'id');

    $skipped = Order::find()->isCompleted(true)->email("skip-$run@example.test")->one();

    // Its browser row is `emitted` and never confirmed, exactly like a lost tag. The unconfirmed
    // sweep has to tell the two apart, and only the `skipped` server row can. A cutoff in the
    // future makes the row old enough to be swept.
    $unconfirmed = array_map('intval', array_column(
        $plugin->ledger->getUnconfirmedConversions(new DateTime('+1 hour'), 1000),
        'orderId',
    ));

    return $skipped !== null
        && !in_array($skipped->id, $ids, true)
        && !in_array($skipped->id, $unconfirmed, true)
        ? true
        : 'recovery repairs failures; it does not override a decision';
});

check('the accuracy report compares orders against conversions', function() use ($plugin) {
    $rows = $plugin->ledger->getAccuracy(new DateTime('-3 days'), new DateTime());

    if ($rows === []) {
        return 'no rows at all';
    }

    $orders = array_sum(array_column($rows, 'orders'));
    $tracked = array_sum(array_column($rows, 'tracked'));

    return $orders >= 4 && $tracked >= 1 && $tracked <= $orders
        ? true
        : "orders:$orders tracked:$tracked";
});

echo "\n$passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
