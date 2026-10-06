<?php
/**
 * What an anonymous visitor can make Tape's front-end endpoints do — checked in the plugin-testing
 * harness, over HTTP where it matters.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-tape/tests/integration/security.php
 *
 * Until 5.0.1: the throttle on events/trigger and events/map keyed on getUserIP(), which believes
 * X-Forwarded-For from anyone, so a new header bought a fresh budget; it had no site-wide ceiling
 * and no lock; a replayed trigger call queued another server-side send every time; and events/map
 * resolved enabled variants of disabled or unreleased products, names, SKUs and prices included.
 *
 * Creates a GA4 destination, a trigger and two products, and removes them — the project-config
 * half in a fresh process (see craft-nuke's tests/README). Flushes the harness cache, which holds
 * the budgets.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\Trigger;
use justinholtweb\tape\Plugin;
use justinholtweb\tape\services\Events;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$sentIds = [];
$products = [];

$destination = new Destination([
    'handle' => "ga4Sec$run", 'name' => "GA4 sec $run", 'platform' => 'ga4',
    'settings' => ['measurementId' => 'G-' . strtoupper(substr(sha1($run), 0, 10))],
]);
$plugin->destinations->saveDestination($destination) or throw new RuntimeException(json_encode($destination->getErrors()));

// A server-side destination: duplicate trigger calls only cost anything where a firing becomes a
// Conversions API send. Whatever it does with this event — queue it, or skip it for consent — it
// writes a ledger row, which is what the dedupe checks count. Queued sends are removed afterwards.
$server = new Destination([
    'handle' => "metaSec$run", 'name' => "Meta sec $run", 'platform' => 'meta',
    'settings' => ['pixelId' => '1' . str_pad((string)random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT), 'accessToken' => 'not-a-real-token'],
    'events' => [TrackingEvent::CONTACT],
    'serverSide' => true,
]);
$plugin->destinations->saveDestination($server) or throw new RuntimeException(json_encode($server->getErrors()));
$queueFloor = (int)(new Query())->from('{{%queue}}')->max('id');

$trigger = new Trigger(['name' => "Calls $run", 'type' => Trigger::TYPE_TEL, 'event' => TrackingEvent::CONTACT, 'value' => 25.0, 'currency' => 'GBP']);
$plugin->destinations->saveTrigger($trigger) or throw new RuntimeException(json_encode($trigger->getErrors()));
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);
Craft::$app->getCache()->flush();

register_shutdown_function(function() use (&$sentIds, &$products, $destination, $server, $trigger, $root, $queueFloor) {
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['and', ['>', 'id', $queueFloor], ['like', 'job', 'tape']])->execute();
    if ($sentIds !== []) {
        Craft::$app->getDb()->createCommand()->delete('{{%tape_events}}', ['eventId' => $sentIds])->execute();
    }
    foreach ($products as $product) {
        Craft::$app->getElements()->deleteElement($product, true);
    }
    Craft::$app->getCache()->flush();

    $restore = sys_get_temp_dir() . '/tape-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
Craft::$app->getPlugins()->loadPlugins();
$d = justinholtweb\\tape\\Plugin::getInstance()->destinations;
if ($t = $d->getTriggerByUid(' . var_export($trigger->uid, true) . ')) { $d->deleteTrigger($t); }
foreach (' . var_export([$destination->uid, $server->uid], true) . ' as $uid) {
    if ($x = $d->getDestinationByUid($uid)) { $d->deleteDestination($x); }
}
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not clean up: " . implode("\n", $out) . "\n");
});

$http = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'headers' => ['User-Agent' => 'Mozilla/5.0 (tape security.php)']]);

$fire = static function(?string $eventId = null, array $headers = []) use ($http, $trigger, &$sentIds): bool {
    $eventId ??= StringHelper::UUID();
    $sentIds[] = $eventId;
    $response = $http->post('index.php?p=actions/tape/events/trigger', ['json' => ['trigger' => $trigger->uid, 'eventId' => $eventId], 'headers' => $headers]);

    return (json_decode((string)$response->getBody(), true)['ok'] ?? null) === true;
};

$rows = static fn(string $eventId) => (int)(new Query())->from('{{%tape_events}}')->where(['eventId' => $eventId])->count();

echo "\nThe trigger endpoint\n";

check('a firing is recorded once', function() use ($fire, $rows) {
    $id = StringHelper::UUID();

    return $fire($id) && $rows($id) > 0 ?: 'not recorded';
});

check('replaying the same event ID doesn’t record or send it again', function() use ($fire, $rows) {
    $id = StringHelper::UUID();
    $fire($id);
    $first = $rows($id);
    $fire($id);
    $fire($id);

    return $first > 0 && $rows($id) === $first ?: "rows went from $first to " . $rows($id);
});

check('a new X-Forwarded-For on every request doesn’t buy a fresh budget', function() use ($fire) {
    // The budget is per minute: start the burst early in one, so it can't straddle two.
    if (time() % 60 > 30) {
        sleep(61 - time() % 60);
    }
    Craft::$app->getCache()->flush();
    $limit = Events::ANONYMOUS_LIMITS['trigger'];
    $accepted = 0;
    for ($i = 0; $i < $limit + 5; $i++) {
        $accepted += $fire(null, ['X-Forwarded-For' => '198.51.100.' . ($i + 1)]) ? 1 : 0;
    }

    return $accepted === $limit ?: "$accepted accepted, limit $limit";
});

echo "\nThe budgets themselves\n";

check('one IPv6 /64 is one visitor', function() use ($plugin) {
    Craft::$app->getCache()->flush();
    $limit = Events::ANONYMOUS_LIMITS['trigger'];
    $accepted = 0;
    for ($i = 0; $i < $limit + 5; $i++) {
        $accepted += $plugin->events->allowAnonymousRequest('trigger', sprintf('2001:db8:aa:bb::%x', $i + 1)) ? 1 : 0;
    }

    return $accepted === $limit ?: "$accepted accepted";
});

check('many addresses together still meet a site-wide ceiling', function() use ($plugin) {
    Craft::$app->getCache()->flush();
    $ceiling = Events::ANONYMOUS_LIMITS['trigger'] * \justinholtweb\tape\helpers\RateLimit::GLOBAL_FACTOR;
    $accepted = 0;
    for ($i = 0; $i < $ceiling + 10; $i++) {
        $accepted += $plugin->events->allowAnonymousRequest('trigger', '10.' . intdiv($i, 250) . '.' . ($i % 250) . '.1') ? 1 : 0;
    }
    Craft::$app->getCache()->flush();

    return $accepted === $ceiling ?: "$accepted accepted, ceiling $ceiling";
});

echo "\nThe map endpoint\n";

$type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0] ?? null;
$type or throw new RuntimeException('The harness has no product type.');

$makeProduct = static function(string $label, array $attributes, bool $variantEnabled = true) use ($type, $run, &$products): Variant {
    $product = new Product(['typeId' => $type->id, 'title' => "Tape $label $run"] + $attributes);
    $variant = new Variant(['sku' => "TAPE-$label-$run", 'price' => 19.99, 'isDefault' => true, 'title' => "Tape $label $run", 'enabled' => $variantEnabled]);
    $product->setVariants([$variant]);
    Craft::$app->getElements()->saveElement($product) or throw new RuntimeException(json_encode($product->getErrors()));
    $products[] = $product;

    return Variant::find()->sku("TAPE-$label-$run")->status(null)->one() ?? throw new RuntimeException("no $label variant");
};

$map = static function(int $variantId) use ($http): string {
    return (string)$http->post('index.php?p=actions/tape/events/map', [
        'json' => ['event' => TrackingEvent::VIEW_ITEM, 'params' => ['id' => $variantId]],
        'headers' => ['Accept' => 'application/json'],
    ])->getBody();
};

Craft::$app->getCache()->flush();
$live = Variant::find()->price('> 0')->hasProduct(['status' => Product::STATUS_LIVE])->one();

check('a live product’s variant resolves, name and SKU included', function() use ($map, $live) {
    if (!$live) {
        return 'no live variant in the harness to compare against';
    }

    return str_contains($map((int)$live->id), (string)$live->sku) ?: 'not in the payload — the other map checks would mean nothing';
});

check('an enabled variant of a disabled product doesn’t', function() use ($map, $makeProduct) {
    $variant = $makeProduct('Disabled', ['enabled' => false]);

    return !str_contains($map((int)$variant->id), (string)$variant->sku) ?: 'resolved';
});

check('…nor of one that isn’t published yet', function() use ($map, $makeProduct) {
    $variant = $makeProduct('Pending', ['postDate' => new DateTime('+1 year')]);

    return !str_contains($map((int)$variant->id), (string)$variant->sku) ?: 'resolved';
});

check('…nor a disabled variant of a live one', function() use ($map, $makeProduct) {
    $variant = $makeProduct('VariantOff', [], false);

    return !str_contains($map((int)$variant->id), (string)$variant->sku) ?: 'resolved';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
