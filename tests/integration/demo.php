<?php
/**
 * Sets Tape up in the harness so the front end can be looked at in a browser.
 *
 *     ddev exec php /var/www/craft-tape/tests/integration/demo.php
 *
 * Creates a GA4 destination, a Meta destination, a custom snippet and a phone-click trigger, then
 * writes `templates/tape-demo.twig`. Visit /tape-demo to see the injected tags, and
 * `window.tape.debug()` in the console to see what the runtime made of them.
 *
 * Pass `--remove` to take it all away again.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\Trigger;
use justinholtweb\tape\Plugin;

$plugin = Plugin::getInstance();
$remove = in_array('--remove', $argv, true);

$handles = ['demoGa4', 'demoMeta', 'demoSnippet'];

foreach ($handles as $handle) {
    $existing = $plugin->destinations->getDestinationByHandle($handle);

    if ($existing !== null) {
        $plugin->destinations->deleteDestination($existing);
        echo "removed $handle\n";
    }
}

foreach ($plugin->destinations->getAllTriggers() as $trigger) {
    if (str_starts_with($trigger->name, 'Demo ')) {
        $plugin->destinations->deleteTrigger($trigger);
        echo "removed trigger {$trigger->name}\n";
    }
}

if ($remove) {
    @unlink($root . '/templates/tape-demo.twig');
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    echo "done\n";
    exit(0);
}

Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_PRO);

$ga4 = new Destination([
    'handle' => 'demoGa4',
    'name' => 'Demo GA4',
    'platform' => 'ga4',
    'settings' => ['measurementId' => 'G-DEMO12345', 'sendUserId' => false],
    'consentCategory' => Consent::CATEGORY_ANALYTICS,
]);

$meta = new Destination([
    'handle' => 'demoMeta',
    'name' => 'Demo Meta',
    'platform' => 'meta',
    'settings' => ['pixelId' => '100000000000001', 'advancedMatching' => true],
    'consentCategory' => Consent::CATEGORY_ADS,
]);

$snippet = new Destination([
    'handle' => 'demoSnippet',
    'name' => 'Demo snippet',
    'platform' => 'custom',
    'settings' => [
        'queueName' => 'demoEvents',
        'headHtml' => '<script>window.__tapeSnippetRan = true;</script>',
    ],
    'consentCategory' => Consent::CATEGORY_ADS,
]);

foreach ([$ga4, $meta, $snippet] as $destination) {
    if (!$plugin->destinations->saveDestination($destination)) {
        echo "could not save {$destination->handle}: " . json_encode($destination->getErrors()) . "\n";
        exit(1);
    }

    echo "created {$destination->handle}\n";
}

$trigger = new Trigger([
    'name' => 'Demo phone clicks',
    'type' => Trigger::TYPE_TEL,
    'event' => TrackingEvent::CONTACT,
    'value' => 25.0,
    'currency' => 'GBP',
    'once' => Trigger::ONCE_NEVER,
]);

$scroll = new Trigger([
    'name' => 'Demo scroll depth',
    'type' => Trigger::TYPE_SCROLL,
    'threshold' => '50, 90',
    'event' => TrackingEvent::SCROLL,
    'once' => Trigger::ONCE_PAGE,
]);

foreach ([$trigger, $scroll] as $t) {
    if (!$plugin->destinations->saveTrigger($t)) {
        echo "could not save trigger: " . json_encode($t->getErrors()) . "\n";
        exit(1);
    }

    echo "created trigger “{$t->name}”\n";
}

file_put_contents($root . '/templates/tape-demo.twig', <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tape demo</title>
</head>
<body style="font-family: system-ui; max-width: 40rem; margin: 2rem auto; line-height: 1.6">
    <h1>Tape demo</h1>

    <p>Tracking this visitor: <strong>{{ tape.isTracking() ? 'yes' : 'no' }}</strong></p>
    <p>Destinations: <strong>{{ tape.destinations()|length }}</strong></p>

    <p><a href="tel:+441234567890" id="phone">Call us on 01234 567890</a> — a phone click is a
        configured trigger, so clicking it fires a <code>contact</code> event.</p>

    <p><button id="lead" type="button">Fire a lead from JavaScript</button></p>

    <div style="height: 200vh; background: linear-gradient(#eee, #fff)">
        Scroll down to fire the 50% and 90% scroll triggers.
    </div>

    <pre id="out" style="background:#111;color:#0f0;padding:1rem;overflow:auto"></pre>

    <script>
        document.getElementById('lead').addEventListener('click', function () {
            window.tape.track('generate_lead', { source: 'demo-button' });
        });

        setTimeout(function () {
            document.getElementById('out').textContent = JSON.stringify({
                debug: window.tape.debug(),
                snippetRan: window.__tapeSnippetRan === true,
                demoEvents: (window.demoEvents || []).map(function (e) { return e.name; }),
                gtagCalls: (window.dataLayer || []).length,
                fbqQueued: window.fbq ? (window.fbq.queue || []).length : null
            }, null, 2);
        }, 1500);
    </script>
</body>
</html>
TWIG);

// Buffered until the request ends — and a bare script has no request to end.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

echo "\nwrote templates/tape-demo.twig\n";
echo "visit /tape-demo\n";
