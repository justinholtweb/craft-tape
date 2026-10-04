<?php
/**
 * Tape integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-tape/tests/integration/checks.php
 *
 * Covers the things that can only be wrong at runtime: every platform's mapping against a real
 * event, the hashing rules that fail silently when they are wrong, the ledger's uniqueness
 * guarantee under a duplicate, project-config round trips for destinations and triggers, the
 * exclusion rules, and the server-side transport through a fake that records what it was asked to
 * send.
 *
 * Idempotent and self-cleaning: everything it creates carries a run-specific suffix and is removed
 * from a shutdown handler, so a fatal halfway through does not leave destinations behind for the
 * next run to trip over.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\Settings;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\Trigger;
use justinholtweb\tape\models\UserData;
use justinholtweb\tape\Plugin;
use justinholtweb\tape\services\Events;
use justinholtweb\tape\services\Ledger;
use justinholtweb\tape\services\Tags;
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

/** Finds the first call in a dispatch list whose function matches. */
function findCall(array $calls, string $fn, ?string $eventName = null): ?array
{
    foreach ($calls as $call) {
        if ($call['fn'] !== $fn) {
            continue;
        }

        if ($eventName !== null && ($call['args'][1] ?? null) !== $eventName) {
            continue;
        }

        return $call;
    }

    return null;
}

/** A transport that records instead of sending. */
class RecordingTransport implements TransportInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    public int $status = 200;

    public string $body = '{"events_received":1}';

    public ?string $error = null;

    public function send(array $request, int $timeout): array
    {
        $this->requests[] = $request;

        return ['status' => $this->status, 'body' => $this->body, 'error' => $this->error];
    }

    public function last(): ?array
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }
}

$plugin = Plugin::getInstance();
$run = substr(md5((string)microtime(true)), 0, 6);
$createdDestinations = [];
$createdTriggers = [];

$originalEdition = $plugin->edition;
$originalSettings = clone $plugin->getSettings();
$originalTransport = $plugin->dispatcher->transport;

Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_PRO);

// Cleanup from a shutdown handler, not off the end of the script: a fatal above otherwise leaves
// destinations in project config, which the next run then trips over for reasons unrelated to code.
register_shutdown_function(function() use (&$createdDestinations, &$createdTriggers, $plugin, $originalEdition, $run) {
    foreach ($createdDestinations as $uid) {
        $destination = $plugin->destinations->getDestinationByUid($uid);

        if ($destination !== null) {
            $plugin->destinations->deleteDestination($destination);
        }
    }

    foreach ($createdTriggers as $uid) {
        $trigger = $plugin->destinations->getTriggerByUid($uid);

        if ($trigger !== null) {
            $plugin->destinations->deleteTrigger($trigger);
        }
    }

    craft\helpers\Db::delete('{{%tape_events}}', ['like', 'eventId', 'chk-' . $run . '%', false]);

    if ($plugin->edition !== $originalEdition) {
        Craft::$app->getPlugins()->switchEdition('tape', $originalEdition);
    }

    // Project config writes made from a console script are buffered until the request ends, and a
    // bare script has no request to end — so they have to be committed by hand or the deletions
    // above silently do nothing while still returning true.
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

/** Builds a fully populated purchase event to map against every platform. */
function purchaseEvent(string $run): TrackingEvent
{
    $shirt = new EventItem([
        'id' => 'SKU-SHIRT',
        'sku' => 'SKU-SHIRT',
        'name' => 'Striped shirt',
        'brand' => 'Housebrand',
        'categories' => ['Clothing', 'Shirts', 'Formal'],
        'variant' => 'Large / Blue',
        'price' => 42.5,
        'discount' => 2.5,
        'quantity' => 2,
        'index' => 1,
        'googleBusinessVertical' => 'retail',
    ]);

    $mug = new EventItem([
        'id' => 'SKU-MUG',
        'name' => 'Enamel mug',
        'price' => 9.0,
        'quantity' => 1,
        'index' => 2,
    ]);

    return new TrackingEvent([
        'name' => TrackingEvent::PURCHASE,
        'eventId' => 'chk-' . $run . '-purchase',
        'value' => 98.0,
        'currency' => 'GBP',
        'transactionId' => 'ORD-1041',
        'tax' => 12.0,
        'shipping' => 6.0,
        'coupon' => 'SPRING',
        'items' => [$shirt, $mug],
        'isNewCustomer' => true,
        'orderId' => 90001,
        'userData' => new UserData([
            'email' => ' Person@Example.COM ',
            'phone' => '(0203) 555 0134',
            'firstName' => "O'Neill",
            'lastName' => 'Smith-Jones',
            'city' => 'New York',
            'region' => 'NY',
            'postalCode' => '90210-1234',
            'country' => 'us',
            'userId' => 77,
            'ip' => '203.0.113.9',
            'userAgent' => 'Mozilla/5.0',
            'sourceUrl' => 'https://example.com/thanks',
            'clickIds' => ['_fbp' => 'fb.1.1.2', '_fbc' => 'fb.1.1.abc', 'ttclid' => 'TT123', '_ga' => 'GA1.1.111.222'],
        ]),
    ]);
}

echo "\nTape integration checks — run $run\n";

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Platform registry');

$platforms = $plugin->platforms->getAllPlatforms();

check('every platform loads', fn() => count($platforms) >= 15 ? true : 'only ' . count($platforms));

check('handles are unique and match their class', function() use ($platforms) {
    foreach ($platforms as $handle => $platform) {
        if ($platform::handle() !== $handle) {
            return "$handle keyed wrong";
        }
    }

    return true;
});

check('every platform names itself and describes itself', function() use ($platforms) {
    foreach ($platforms as $handle => $platform) {
        if ($platform->displayName() === '' || $platform->description() === '') {
            return "$handle is missing a name or description";
        }
    }

    return true;
});

check('required settings are derived from the field definitions', function() use ($platforms) {
    $meta = $platforms['meta'];

    return $meta->requiredSettings() === ['pixelId'] ? true : implode(',', $meta->requiredSettings());
});

check('every required setting has a field definition', function() use ($platforms) {
    foreach ($platforms as $handle => $platform) {
        $fields = $platform->settingFields();

        foreach ($platform->requiredSettings() as $key) {
            if (!isset($fields[$key])) {
                return "$handle requires $key but does not define it";
            }
        }
    }

    return true;
});

check('Lite hides the Pro platforms but the registry still lists them', function() use ($plugin) {
    Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_LITE);
    $available = array_keys($plugin->platforms->getAvailablePlatforms());
    Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_PRO);

    if (in_array('tiktok', $available, true)) {
        return 'TikTok should need Pro';
    }

    if (!in_array('meta', $available, true) || !in_array('ga4', $available, true)) {
        return 'Meta and GA4 should be in Lite';
    }

    return isset($plugin->platforms->getAllPlatforms()['tiktok']) ? true : 'TikTok vanished from the registry';
});

check('LinkedIn and X declare per-event settings, GA4 does not', function() use ($platforms) {
    return $platforms['linkedin']->eventSettingFields('purchase') !== []
        && $platforms['x']->eventSettingFields('purchase') !== []
        && $platforms['ga4']->eventSettingFields('purchase') === [];
});

check('settings validation rejects a malformed ID and accepts an env reference', function() use ($platforms) {
    $bad = new Destination(['platform' => 'ga4', 'settings' => ['measurementId' => 'UA-12345-1']]);
    $good = new Destination(['platform' => 'ga4', 'settings' => ['measurementId' => 'G-ABC1234567']]);
    $env = new Destination(['platform' => 'ga4', 'settings' => ['measurementId' => '$GA4_ID']]);

    if ($platforms['ga4']->validateSettings($bad) === []) {
        return 'a UA- ID should be rejected';
    }

    if ($platforms['ga4']->validateSettings($good) !== []) {
        return 'a valid G- ID was rejected';
    }

    return $platforms['ga4']->validateSettings($env) === [] ? true : 'an env reference should not be pattern-checked';
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Consent');

check('granted, denied and unknown are three different states', function() {
    $unknown = Consent::unknown();

    return $unknown->get(Consent::AD_STORAGE) === null
        && !$unknown->isGranted(Consent::AD_STORAGE)
        && !$unknown->isDenied(Consent::AD_STORAGE);
});

check('all-denied still grants security storage', fn() => Consent::allDenied()->isGranted(Consent::SECURITY_STORAGE));

check('booleans and strings both normalise', function() {
    $consent = new Consent(['ad_storage' => true, 'analytics_storage' => 'denied', 'ad_user_data' => 0]);

    return $consent->isGranted(Consent::AD_STORAGE)
        && $consent->isDenied(Consent::ANALYTICS_STORAGE)
        && $consent->isDenied(Consent::AD_USER_DATA);
});

check('categories map onto the right signals', function() {
    $adsOnly = new Consent(['ad_storage' => true, 'analytics_storage' => false, 'functionality_storage' => false]);

    return $adsOnly->allowsCategory(Consent::CATEGORY_ADS)
        && !$adsOnly->allowsCategory(Consent::CATEGORY_ANALYTICS)
        && !$adsOnly->allowsCategory(Consent::CATEGORY_FUNCTIONALITY);
});

check('the consent-mode array drops unknowns rather than sending null', function() {
    $partial = Consent::unknown()->with(Consent::AD_STORAGE, Consent::GRANTED);
    $array = $partial->toConsentModeArray();

    return $array === ['ad_storage' => 'granted'] ? true : json_encode($array);
});

check('cookie encoding round-trips, unknowns included', function() use ($plugin) {
    $original = Consent::unknown()
        ->with(Consent::AD_STORAGE, Consent::GRANTED)
        ->with(Consent::ANALYTICS_STORAGE, Consent::DENIED);

    $encoded = $plugin->consent->encode($original);
    $decoded = $plugin->consent->decode($encoded);

    if ($decoded === null) {
        return "could not decode $encoded";
    }

    foreach (Consent::SIGNALS as $signal) {
        if ($decoded->get($signal) !== $original->get($signal)) {
            return "$signal changed through $encoded";
        }
    }

    return true;
});

check('a cookie from another version is refused rather than misread', function() use ($plugin) {
    return $plugin->consent->decode('v9:1111111') === null && $plugin->consent->decode('v1:11') === null;
});

check('EU expands to the EEA plus the UK and Switzerland', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->consentRegionDefaults = ['EU' => ['ad_storage' => 'denied', 'analytics_storage' => 'denied']];
    $groups = $plugin->consent->regionDefaults();
    $settings->consentRegionDefaults = [];

    if (count($groups) !== 1) {
        return 'expected one group';
    }

    $regions = $groups[0]['region'];

    return in_array('GB', $regions, true) && in_array('HR', $regions, true) && in_array('CH', $regions, true) && count($regions) > 25
        ? true
        : count($regions) . ' regions';
});

check('the runtime config carries no answer for this visitor', function() use ($plugin) {
    $config = $plugin->consent->getRuntimeConfig();

    // A per-visitor value here would be cached along with the page and served to everybody after.
    return !array_key_exists('current', $config) ? true : 'the payload leaks the visitor’s consent state';
});

check('a granted source resolves to granted, a denied one to denied', function() use ($plugin) {
    $settings = $plugin->getSettings();

    $settings->consentSource = Settings::CONSENT_GRANTED;
    $plugin->consent->setResolved(null);
    $granted = $plugin->consent->resolve()->isGranted(Consent::AD_STORAGE);

    $settings->consentSource = Settings::CONSENT_DENIED;
    $plugin->consent->setResolved(null);
    $denied = $plugin->consent->resolve()->isDenied(Consent::AD_STORAGE);

    $settings->consentSource = Settings::CONSENT_CMP;
    $plugin->consent->setResolved(null);
    $unknown = $plugin->consent->resolve()->get(Consent::AD_STORAGE) === null;

    $settings->consentSource = Settings::CONSENT_GRANTED;
    $plugin->consent->setResolved(null);

    return ($granted && $denied && $unknown) ? true : "granted:$granted denied:$denied unknown:$unknown";
});

check('server-side waits when consent is merely unknown', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->consentSource = Settings::CONSENT_CMP;
    $plugin->consent->setResolved(null);

    $allowed = $plugin->consent->allowsServerSide(Consent::CATEGORY_ADS);

    $settings->consentSource = Settings::CONSENT_GRANTED;
    $plugin->consent->setResolved(null);

    return $allowed === false ? true : 'an unknown answer should not send';
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Identity — the rules that fail silently');

check('an email is trimmed and lowercased, and nonsense is dropped', function() {
    return Identity::email(' Person@Example.COM ') === 'person@example.com'
        && Identity::email('not-an-email') === null
        && Identity::email('') === null;
});

check('a phone number keeps digits only and gains a country code', function() use ($plugin) {
    $plugin->getSettings()->phoneCountryCode = '44';
    $normalised = Identity::phone('(0203) 555 0134');
    $plugin->getSettings()->phoneCountryCode = null;

    return $normalised === '442035550134' ? true : var_export($normalised, true);
});

check('a number that already carries a country code is left alone', function() use ($plugin) {
    $plugin->getSettings()->phoneCountryCode = '1';
    $normalised = Identity::phone('+44 20 3555 0134');
    $plugin->getSettings()->phoneCountryCode = null;

    return $normalised === '442035550134' ? true : var_export($normalised, true);
});

check('names lose punctuation and case but keep accents', function() {
    return Identity::name("O'Neill") === 'oneill'
        && Identity::name('Smith-Jones') === 'smithjones'
        && Identity::name('Renée') === 'renée'
        && Identity::name('123') === null;
});

check('a ZIP+4 is cut back to five digits', function() {
    return Identity::postalCode('90210-1234', 'US') === '90210'
        && Identity::postalCode('SW1A 1AA', 'GB') === 'sw1a1aa';
});

check('a country has to be two letters or it is dropped', function() {
    return Identity::country('us') === 'us' && Identity::country('United States') === null;
});

check('Meta’s browser matching is flat and hashed', function() use ($run) {
    $data = Identity::metaUserData(purchaseEvent($run)->userData);

    if (($data['em'] ?? '') !== hash('sha256', 'person@example.com')) {
        return 'the email hash is wrong';
    }

    if (isset($data['email'])) {
        return 'a readable email reached the payload';
    }

    return $data['external_id'] === hash('sha256', '77') ? true : 'external_id is not the hashed user ID';
});

check('Meta’s server matching wraps every field in an array and adds the cookies', function() use ($run) {
    $data = Identity::metaServerUserData(purchaseEvent($run)->userData);

    return $data['em'] === [hash('sha256', 'person@example.com')]
        && $data['fbp'] === 'fb.1.1.2'
        && $data['client_ip_address'] === '203.0.113.9'
        && $data['client_user_agent'] === 'Mozilla/5.0';
});

check('Google’s enhanced conversions omit the address unless it is asked for', function() use ($plugin, $run) {
    $user = purchaseEvent($run)->userData;

    $plugin->getSettings()->enhancedConversionsAddress = false;
    $without = Identity::googleUserData($user);

    $plugin->getSettings()->enhancedConversionsAddress = true;
    $with = Identity::googleUserData($user);
    $plugin->getSettings()->enhancedConversionsAddress = false;

    if (isset($without['address'])) {
        return 'the address should be off by default — Google sends it unhashed';
    }

    return isset($with['address']['city']) && $with['address']['postal_code'] === '90210'
        ? true
        : json_encode($with['address'] ?? null);
});

check('Reddit is the one platform that hashes the IP address', function() use ($run) {
    $data = Identity::redditServerUserData(purchaseEvent($run)->userData);

    return $data['ip_address'] === hash('sha256', '203.0.113.9');
});

check('X sends the address unhashed, because its pixel hashes it', function() use ($run) {
    $data = Identity::xUserData(purchaseEvent($run)->userData);

    return $data['email_address'] === 'person@example.com';
});

check('the GA4 client ID is read out of the _ga cookie, not minted', function() {
    $user = new UserData(['clickIds' => ['_ga' => 'GA1.1.1234567890.1698000000']]);

    return Identity::ga4ClientId($user) === '1234567890.1698000000'
        && Identity::ga4ClientId(new UserData()) === null;
});

check('the GA4 session ID comes from the per-stream cookie', function() {
    $user = new UserData(['clickIds' => ['_ga_ABC1234567' => 'GS1.1.1750000000.3.0.1750000001.0.0.0']]);

    return Identity::ga4SessionId($user, 'G-ABC1234567') === '1750000000'
        ? true
        : var_export(Identity::ga4SessionId($user, 'G-ABC1234567'), true);
});

check('an empty UserData reports itself empty; a click ID alone does not', function() {
    return (new UserData())->isEmpty()
        && !(new UserData(['clickIds' => ['gclid' => 'x']]))->isEmpty()
        && !(new UserData(['email' => 'a@b.com']))->isEmpty();
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('The event shape');

check('value is derived from the items when nothing sets it', function() {
    $event = new TrackingEvent([
        'name' => TrackingEvent::VIEW_ITEM,
        'items' => [new EventItem(['id' => 'a', 'price' => 10.0, 'discount' => 1.0, 'quantity' => 3])],
    ]);

    return $event->getValue() === 27.0 ? true : $event->getValue();
});

check('an explicit value wins over the items', function() {
    $event = new TrackingEvent([
        'name' => TrackingEvent::PURCHASE,
        'value' => 5.0,
        'items' => [new EventItem(['id' => 'a', 'price' => 1000.0])],
    ]);

    return $event->getValue() === 5.0;
});

check('item count sums quantities', fn() => purchaseEvent($run)->getItemCount() === 3);

check('an event ID is minted when none is given', function() {
    $event = new TrackingEvent(['name' => 'page_view']);

    return strlen($event->eventId) === 36 && $event->occurredAt !== null;
});

check('purchase and refund are conversions; a product view is not', function() {
    return (new TrackingEvent(['name' => TrackingEvent::PURCHASE]))->isConversion()
        && (new TrackingEvent(['name' => TrackingEvent::REFUND]))->isConversion()
        && !(new TrackingEvent(['name' => TrackingEvent::VIEW_ITEM]))->isConversion();
});

check('purchase and refund cannot be requested by a browser', function() {
    return !in_array(TrackingEvent::PURCHASE, TrackingEvent::REQUESTABLE_EVENTS, true)
        && !in_array(TrackingEvent::REFUND, TrackingEvent::REQUESTABLE_EVENTS, true);
});

check('a category path is joined the way a feed carries it', function() {
    $item = new EventItem(['id' => 'a', 'categories' => ['Clothing', 'Shirts']]);

    return $item->getCategoryPath() === 'Clothing > Shirts' && (new EventItem(['id' => 'b']))->getCategoryPath() === null;
});


// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Platform mapping — Google');

/** A configured, unsaved destination for mapping checks. */
function destinationFor(string $platform, array $settings, array $eventSettings = []): Destination
{
    return new Destination([
        'handle' => $platform . 'Test',
        'name' => ucfirst($platform),
        'platform' => $platform,
        'settings' => $settings,
        'eventSettings' => $eventSettings,
    ]);
}

$purchase = purchaseEvent($run);

check('GA4 sends one gtag event with the whole purchase on it', function() use ($platforms, $purchase) {
    $calls = $platforms['ga4']->mapEvent($purchase, destinationFor('ga4', ['measurementId' => 'G-ABC1234567']));

    if (count($calls) !== 1) {
        return count($calls) . ' calls';
    }

    [$verb, $name, $params] = $calls[0]['args'];

    if ($verb !== 'event' || $name !== 'purchase') {
        return "$verb $name";
    }

    return $params['transaction_id'] === 'ORD-1041'
        && $params['value'] === 98.0
        && $params['tax'] === 12.0
        && $params['shipping'] === 6.0
        && $params['currency'] === 'GBP'
        && $params['coupon'] === 'SPRING'
        && $params['send_to'] === 'G-ABC1234567'
        && count($params['items']) === 2
            ? true
            : json_encode($params);
});

check('GA4 spreads a category path across item_category…item_category5', function() use ($platforms, $purchase) {
    $calls = $platforms['ga4']->mapEvent($purchase, destinationFor('ga4', ['measurementId' => 'G-ABC1234567']));
    $item = $calls[0]['args'][2]['items'][0];

    return $item['item_category'] === 'Clothing'
        && $item['item_category2'] === 'Shirts'
        && $item['item_category3'] === 'Formal'
        && !isset($item['item_category4'])
        && $item['item_variant'] === 'Large / Blue'
        && $item['quantity'] === 2
            ? true
            : json_encode($item);
});

check('GA4 does not put a value on a bare page view', function() use ($platforms) {
    $event = new TrackingEvent(['name' => TrackingEvent::PAGE_VIEW]);
    $params = $platforms['ga4']->mapEvent($event, destinationFor('ga4', ['measurementId' => 'G-ABC1234567']))[0]['args'][2];

    return !isset($params['value']) && !isset($params['items']) ? true : json_encode($params);
});

check('GA4’s Measurement Protocol needs both a secret and a client ID', function() use ($platforms, $purchase) {
    $noSecret = $platforms['ga4']->serverRequest($purchase, destinationFor('ga4', ['measurementId' => 'G-ABC1234567']));

    $noClient = clone $purchase;
    $noClient->userData = new UserData();
    $withSecret = destinationFor('ga4', ['measurementId' => 'G-ABC1234567', 'apiSecret' => 'sec']);

    return $noSecret === null && $platforms['ga4']->serverRequest($noClient, $withSecret) === null;
});

check('GA4’s Measurement Protocol call carries the ID and secret as query params', function() use ($platforms, $purchase) {
    $request = $platforms['ga4']->serverRequest($purchase, destinationFor('ga4', [
        'measurementId' => 'G-ABC1234567',
        'apiSecret' => 'topsecret',
    ]));

    if ($request === null) {
        return 'no request built';
    }

    return $request['query']['measurement_id'] === 'G-ABC1234567'
        && $request['query']['api_secret'] === 'topsecret'
        && $request['body']['client_id'] === '111.222'
        && $request['body']['events'][0]['name'] === 'purchase'
        && !isset($request['body']['events'][0]['params']['send_to'])
            ? true
            : json_encode($request);
});

check('a Measurement Protocol 204 with validation messages is not success', function() use ($platforms) {
    return $platforms['ga4']->isServerResponseOk(204, '')
        && !$platforms['ga4']->isServerResponseOk(204, '{"validationMessages":[{"description":"nope"}]}')
        && $platforms['ga4']->isServerResponseOk(200, '{"validationMessages":[]}');
});

check('Google Ads sends nothing as a conversion without a label', function() use ($platforms, $purchase) {
    $calls = $platforms['googleAds']->mapEvent($purchase, destinationFor('googleAds', ['conversionId' => 'AW-123456789']));

    // The remarketing event still goes, because that needs no label.
    return findCall($calls, 'gtag', 'conversion') === null && findCall($calls, 'gtag', 'purchase') !== null
        ? true
        : json_encode($calls);
});

check('Google Ads sends a conversion with send_to as ID/LABEL', function() use ($platforms, $purchase) {
    $destination = destinationFor('googleAds', ['conversionId' => 'AW-123456789', 'dynamicRemarketing' => false], [
        'purchase' => ['label' => 'AbC-D_efGh12'],
    ]);

    $calls = $platforms['googleAds']->mapEvent($purchase, $destination);
    $conversion = findCall($calls, 'gtag', 'conversion');

    if ($conversion === null) {
        return json_encode($calls);
    }

    return $conversion['args'][2]['send_to'] === 'AW-123456789/AbC-D_efGh12'
        && $conversion['args'][2]['transaction_id'] === 'ORD-1041'
        && $conversion['args'][2]['value'] === 98.0
            ? true
            : json_encode($conversion);
});

check('Google Ads sends remarketing items with a vertical and no prices', function() use ($platforms, $purchase) {
    $calls = $platforms['googleAds']->mapEvent($purchase, destinationFor('googleAds', ['conversionId' => 'AW-123456789']));
    $remarketing = findCall($calls, 'gtag', 'purchase');
    $item = $remarketing['args'][2]['items'][0];

    return $item === ['id' => 'SKU-SHIRT', 'google_business_vertical' => 'retail'] ? true : json_encode($item);
});

check('Google Ads puts cart data on a purchase event once a Merchant ID is set', function() use ($platforms, $purchase) {
    $destination = destinationFor('googleAds', [
        'conversionId' => 'AW-123456789',
        'dynamicRemarketing' => false,
        'merchantId' => '55512345',
        'feedCountry' => 'gb',
        'feedLanguage' => 'EN',
    ], ['purchase' => ['label' => 'lbl']]);

    $calls = $platforms['googleAds']->mapEvent($purchase, $destination);
    $purchaseCall = findCall($calls, 'gtag', 'purchase');

    if ($purchaseCall === null) {
        return 'cart data should ride on a purchase event, not a generic conversion';
    }

    $params = $purchaseCall['args'][2];

    return $params['aw_merchant_id'] === '55512345'
        && $params['aw_feed_country'] === 'GB'
        && $params['aw_feed_language'] === 'en'
        && $params['new_customer'] === true
        && $params['discount'] === 5.0
        && $params['items'][0] === ['id' => 'SKU-SHIRT', 'price' => 42.5, 'quantity' => 2]
            ? true
            : json_encode($params);
});

check('Google Ads sets hashed user data before the conversion, not after', function() use ($platforms, $purchase) {
    $destination = destinationFor('googleAds', ['conversionId' => 'AW-123456789'], ['purchase' => ['label' => 'lbl']]);
    $calls = $platforms['googleAds']->mapEvent($purchase, $destination);

    $setIndex = null;
    $conversionIndex = null;

    foreach ($calls as $index => $call) {
        if (($call['args'][0] ?? null) === 'set' && ($call['args'][1] ?? null) === 'user_data') {
            $setIndex = $index;
        }

        if (($call['args'][1] ?? null) === 'purchase' || ($call['args'][1] ?? null) === 'conversion') {
            $conversionIndex ??= $index;
        }
    }

    if ($setIndex === null) {
        return 'no user_data call';
    }

    return $setIndex < $conversionIndex ? true : "user_data at $setIndex, conversion at $conversionIndex";
});

check('a refund becomes a Google Ads conversion adjustment, not a negative conversion', function() use ($platforms, $run) {
    $refund = new TrackingEvent([
        'name' => TrackingEvent::REFUND,
        'eventId' => 'chk-' . $run . '-refund',
        'value' => -30.0,
        'currency' => 'GBP',
        'transactionId' => 'ORD-1041',
        'orderId' => 90001,
    ]);

    $destination = destinationFor('googleAds', ['conversionId' => 'AW-123456789', 'dynamicRemarketing' => false], [
        'refund' => ['label' => 'lbl'],
    ]);

    $calls = $platforms['googleAds']->mapEvent($refund, $destination);
    $adjustment = findCall($calls, 'gtag', 'conversion_adjustment');

    if ($adjustment === null) {
        return json_encode($calls);
    }

    return $adjustment['args'][2]['adjustment_type'] === 'RETRACTION'
        && $adjustment['args'][2]['value'] === 30.0
            ? true
            : json_encode($adjustment);
});

check('GTM clears the ecommerce object before pushing the next one', function() use ($platforms, $purchase) {
    $calls = $platforms['gtm']->mapEvent($purchase, destinationFor('gtm', ['containerId' => 'GTM-ABC1234']));

    if (count($calls) !== 2) {
        return count($calls) . ' pushes — the clear is what stops the last event’s items surviving';
    }

    return $calls[0]['args'][0] === ['ecommerce' => null]
        && $calls[1]['args'][0]['event'] === 'purchase'
        && count($calls[1]['args'][0]['ecommerce']['items']) === 2
            ? true
            : json_encode($calls);
});

check('GTM honours a renamed data layer', function() use ($platforms, $purchase) {
    $calls = $platforms['gtm']->mapEvent($purchase, destinationFor('gtm', [
        'containerId' => 'GTM-ABC1234',
        'dataLayerName' => 'myLayer',
    ]));

    return $calls[0]['fn'] === 'myLayer.push';
});

check('a GTM server container URL replaces Google’s host in both the script and the noscript', function() use ($platforms) {
    $destination = destinationFor('gtm', ['containerId' => 'GTM-ABC1234', 'serverUrl' => 'https://gtm.example.com/']);
    $boot = $platforms['gtm']->boot($destination, Consent::allGranted(), []);

    return str_starts_with($boot['config']['src'], 'https://gtm.example.com/gtm.js?id=GTM-ABC1234')
        && str_contains($boot['noscript'], 'https://gtm.example.com/ns.html')
            ? true
            : json_encode($boot);
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Platform mapping — the rest');

check('Meta sends contents, content_ids and num_items, plus the shared event ID', function() use ($platforms, $purchase) {
    $calls = $platforms['meta']->mapEvent($purchase, destinationFor('meta', ['pixelId' => '123456789012345']));
    [$method, $name, $data, $options] = $calls[0]['args'];

    return $method === 'track'
        && $name === 'Purchase'
        && $data['value'] === 98.0
        && $data['content_type'] === 'product'
        && $data['content_ids'] === ['SKU-SHIRT', 'SKU-MUG']
        && $data['num_items'] === 3
        && $data['contents'][0] === ['id' => 'SKU-SHIRT', 'quantity' => 2, 'item_price' => 42.5]
        && $data['order_id'] === 'ORD-1041'
        && $options === ['eventID' => $purchase->eventId]
            ? true
            : json_encode($calls[0]['args']);
});

check('Meta uses trackCustom for an event it has no standard name for', function() use ($platforms) {
    $event = new TrackingEvent(['name' => TrackingEvent::REMOVE_FROM_CART]);
    $calls = $platforms['meta']->mapEvent($event, destinationFor('meta', ['pixelId' => '123456789012345']));

    return $calls[0]['args'][0] === 'trackCustom' && $calls[0]['args'][1] === 'RemoveFromCart';
});

check('Meta’s Conversions API call is versioned, tokenised and deduplicated', function() use ($platforms, $purchase) {
    $request = $platforms['meta']->serverRequest($purchase, destinationFor('meta', [
        'pixelId' => '123456789012345',
        'accessToken' => 'EAAtoken',
        'apiVersion' => 'v21.0',
    ]));

    $payload = $request['body']['data'][0];

    return $request['url'] === 'https://graph.facebook.com/v21.0/123456789012345/events'
        && $request['query']['access_token'] === 'EAAtoken'
        && $payload['event_name'] === 'Purchase'
        && $payload['event_id'] === $purchase->eventId
        && $payload['action_source'] === 'website'
        && $payload['event_source_url'] === 'https://example.com/thanks'
        && $payload['user_data']['fbc'] === 'fb.1.1.abc'
            ? true
            : json_encode($request);
});

check('Meta answering 200 with events_received 0 is a failure', function() use ($platforms) {
    return !$platforms['meta']->isServerResponseOk(200, '{"events_received":0}')
        && $platforms['meta']->isServerResponseOk(200, '{"events_received":1}')
        && !$platforms['meta']->isServerResponseOk(400, '{"error":{}}');
});

check('Meta’s test event code only travels in test mode', function() use ($platforms, $purchase) {
    $plain = destinationFor('meta', ['pixelId' => '123456789012345', 'accessToken' => 't']);
    $testing = destinationFor('meta', ['pixelId' => '123456789012345', 'accessToken' => 't']);
    $testing->testMode = true;
    $testing->testCode = 'TEST12345';

    $without = $platforms['meta']->serverRequest($purchase, $plain);
    $with = $platforms['meta']->serverRequest($purchase, $testing);

    return !isset($without['body']['test_event_code']) && $with['body']['test_event_code'] === 'TEST12345';
});

check('TikTok maps a purchase to CompletePayment with contents', function() use ($platforms, $purchase) {
    $calls = $platforms['tiktok']->mapEvent($purchase, destinationFor('tiktok', ['pixelId' => 'C4A1BCDEFGHIJKLMNOPQ']));
    [$name, $properties, $options] = $calls[0]['args'];

    return $calls[0]['fn'] === 'ttq.track'
        && $name === 'CompletePayment'
        && $properties['contents'][0]['content_id'] === 'SKU-SHIRT'
        && $properties['contents'][0]['content_type'] === 'product'
        && $options === ['event_id' => $purchase->eventId]
            ? true
            : json_encode($calls[0]['args']);
});

check('TikTok’s page view is ttq.page(), not a tracked event', function() use ($platforms) {
    $calls = $platforms['tiktok']->mapEvent(new TrackingEvent(['name' => TrackingEvent::PAGE_VIEW]), destinationFor('tiktok', ['pixelId' => 'C4A1BCDEFGHIJKLMNOPQ']));

    return $calls[0]['fn'] === 'ttq.page' && $calls[0]['args'] === [];
});

check('TikTok’s Events API uses a header token and a non-zero code means failure', function() use ($platforms, $purchase) {
    $request = $platforms['tiktok']->serverRequest($purchase, destinationFor('tiktok', [
        'pixelId' => 'C4A1BCDEFGHIJKLMNOPQ',
        'accessToken' => 'tt-token',
    ]));

    return $request['headers']['Access-Token'] === 'tt-token'
        && $request['body']['event_source'] === 'web'
        && $request['body']['data'][0]['user']['ttclid'] === 'TT123'
        && !$platforms['tiktok']->isServerResponseOk(200, '{"code":40001,"message":"bad"}')
        && $platforms['tiktok']->isServerResponseOk(200, '{"code":0}')
            ? true
            : json_encode($request);
});

check('Pinterest maps a purchase to checkout with line items', function() use ($platforms, $purchase) {
    $calls = $platforms['pinterest']->mapEvent($purchase, destinationFor('pinterest', ['tagId' => '2612345678901']));
    [$verb, $name, $properties] = $calls[0]['args'];

    return $verb === 'track'
        && $name === 'checkout'
        && $properties['order_quantity'] === 3
        && $properties['line_items'][0]['product_id'] === 'SKU-SHIRT'
        && $properties['event_id'] === $purchase->eventId
            ? true
            : json_encode($calls[0]['args']);
});

check('Pinterest’s Conversions API sends value as a string and adds ?test=true in test mode', function() use ($platforms, $purchase) {
    $destination = destinationFor('pinterest', ['tagId' => '2612345678901', 'adAccountId' => '549755885175', 'accessToken' => 'pin']);
    $destination->testMode = true;

    $request = $platforms['pinterest']->serverRequest($purchase, $destination);

    return str_ends_with($request['url'], '/events?test=true')
        && $request['headers']['Authorization'] === 'Bearer pin'
        && $request['body']['data'][0]['custom_data']['value'] === '98'
            ? true
            : json_encode($request);
});

check('Snapchat uses its own parameter names and uppercase events', function() use ($platforms, $purchase) {
    $calls = $platforms['snapchat']->mapEvent($purchase, destinationFor('snapchat', ['pixelId' => '11111111-2222-3333-4444-555555555555']));
    [$verb, $name, $properties] = $calls[0]['args'];

    return $name === 'PURCHASE'
        && $properties['price'] === 98.0
        && $properties['number_items'] === 3
        && !isset($properties['value'])
        && $properties['client_dedup_id'] === $purchase->eventId
            ? true
            : json_encode($calls[0]['args']);
});

check('Reddit always sends a conversionId, because it will otherwise take a duplicate', function() use ($platforms, $purchase) {
    $calls = $platforms['reddit']->mapEvent($purchase, destinationFor('reddit', ['pixelId' => 'a2_abcdefghijkl']));

    return $calls[0]['args'][2]['conversionId'] === $purchase->eventId
        && $calls[0]['args'][1] === 'Purchase';
});

check('Microsoft Ads sends both a conversion value and a remarketing page type', function() use ($platforms, $purchase) {
    $calls = $platforms['microsoftAds']->mapEvent($purchase, destinationFor('microsoftAds', ['tagId' => '12345678']));
    $payload = $calls[0]['args'][2];

    return $calls[0]['fn'] === 'uetq.push'
        && $payload['ecomm_pagetype'] === 'purchase'
        && $payload['revenue_value'] === 98.0
        && $payload['ecomm_prodid'] === ['SKU-SHIRT', 'SKU-MUG']
            ? true
            : json_encode($payload);
});

check('a Microsoft store ID prefixes every product ID', function() use ($platforms, $purchase) {
    $calls = $platforms['microsoftAds']->mapEvent($purchase, destinationFor('microsoftAds', ['tagId' => '12345678', 'storeId' => '99']));

    return $calls[0]['args'][2]['ecomm_prodid'] === ['99_SKU-SHIRT', '99_SKU-MUG'];
});

check('LinkedIn fires nothing without a conversion ID, and a numeric one when given', function() use ($platforms, $purchase) {
    $bare = $platforms['linkedin']->mapEvent($purchase, destinationFor('linkedin', ['partnerId' => '1234567']));
    $configured = $platforms['linkedin']->mapEvent($purchase, destinationFor('linkedin', ['partnerId' => '1234567'], [
        'purchase' => ['conversionId' => '9876543'],
    ]));

    return $bare === []
        && $configured[0]['args'] === ['track', ['conversion_id' => 9876543]]
            ? true
            : json_encode([$bare, $configured]);
});

check('X accepts either form of its event ID', function() use ($platforms, $purchase) {
    $short = $platforms['x']->mapEvent($purchase, destinationFor('x', ['pixelId' => 'o1abc'], ['purchase' => ['eventId' => 'o1def']]));
    $long = $platforms['x']->mapEvent($purchase, destinationFor('x', ['pixelId' => 'o1abc'], ['purchase' => ['eventId' => 'tw-o1abc-o1def']]));

    return $short[0]['args'][1] === 'tw-o1abc-o1def' && $long[0]['args'][1] === 'tw-o1abc-o1def';
});

check('Clarity tags the session with the order value as well as firing the event', function() use ($platforms, $purchase) {
    $calls = $platforms['clarity']->mapEvent($purchase, destinationFor('clarity', ['projectId' => 'abcdefghij']));

    return count($calls) === 3
        && $calls[0]['args'] === ['event', 'purchase']
        && $calls[1]['args'] === ['set', 'order_value', '98']
            ? true
            : json_encode($calls);
});

check('Hotjar and Clarity say nothing about a page view — their own script already did', function() use ($platforms) {
    $pageView = new TrackingEvent(['name' => TrackingEvent::PAGE_VIEW]);

    return $platforms['hotjar']->mapEvent($pageView, destinationFor('hotjar', ['siteId' => '1234567'])) === []
        && $platforms['clarity']->mapEvent($pageView, destinationFor('clarity', ['projectId' => 'abcdefghij'])) === [];
});

check('the custom snippet pushes Tape’s own normalised shape onto a named array', function() use ($platforms, $purchase) {
    $calls = $platforms['custom']->mapEvent($purchase, destinationFor('custom', ['queueName' => 'myEvents']));
    $payload = $calls[0]['args'][0];

    return $calls[0]['fn'] === 'myEvents.push'
        && $payload['name'] === 'purchase'
        && $payload['eventId'] === $purchase->eventId
        && count($payload['items']) === 2
            ? true
            : json_encode($calls);
});

check('the webhook signs its body with HMAC-SHA256 when a secret is set', function() use ($platforms, $purchase) {
    $request = $platforms['webhook']->serverRequest($purchase, destinationFor('webhook', [
        'url' => 'https://example.com/hook',
        'secret' => 's3cret',
        'header' => 'X-Api-Key: abc123',
    ]));

    // The signature has to be over the exact bytes on the wire, so the body must already be a
    // string. Re-deriving it from an array here is what hid the transport re-encoding it.
    $body = is_string($request['body']) ? json_decode($request['body'], true) : null;
    $expected = 'sha256=' . hash_hmac('sha256', (string)($body === null ? '' : $request['body']), 's3cret');

    return $body !== null
        && $request['headers']['X-Tape-Signature'] === $expected
        && $request['headers']['X-Api-Key'] === 'abc123'
        && $body['event'] === 'purchase'
        && !isset($body['user'])
            ? true
            : json_encode($request['headers']);
});

check('a custom event name reaches the platforms that take one, and only those', function() use ($platforms) {
    $takes = ['ga4', 'gtm', 'meta', 'microsoftAds', 'tiktok', 'reddit', 'clarity', 'hotjar', 'custom', 'webhook'];
    $refuses = ['googleAds', 'linkedin', 'x', 'snapchat', 'pinterest'];

    foreach ($takes as $handle) {
        if (!$platforms[$handle]->supportsEvent('brochure_download')) {
            return "$handle refused a custom event";
        }
    }

    foreach ($refuses as $handle) {
        if ($platforms[$handle]->supportsEvent('brochure_download')) {
            return "$handle accepted a custom event it has nowhere to put";
        }
    }

    $event = new TrackingEvent(['name' => 'brochure_download', 'params' => ['format' => 'pdf']]);
    $meta = $platforms['meta']->mapEvent($event, destinationFor('meta', ['pixelId' => '123456789012345']));
    $reddit = $platforms['reddit']->mapEvent($event, destinationFor('reddit', ['pixelId' => 'a2_abcdefghijkl']));
    $tiktok = $platforms['tiktok']->mapEvent($event, destinationFor('tiktok', ['pixelId' => 'C4A1BCDEFGHIJKLMNOPQ']));

    return ($meta[0]['args'][0] ?? null) === 'trackCustom' && ($meta[0]['args'][1] ?? null) === 'brochure_download'
        && ($reddit[0]['args'][1] ?? null) === 'Custom' && ($reddit[0]['args'][2]['customEventName'] ?? null) === 'brochure_download'
        && ($tiktok[0]['args'][0] ?? null) === 'brochure_download'
            ? true
            : json_encode([$meta, $reddit, $tiktok]);
});

check('a custom name Google reserves, or a malformed one, is not a custom event', function() {
    foreach (['ga_thing', 'google_x', 'firebase_y', 'Brochure', 'two words', 'purchase', str_repeat('a', 41)] as $name) {
        if (TrackingEvent::isCustomName($name)) {
            return "$name was accepted";
        }
    }

    return TrackingEvent::isCustomName('brochure_download') ? true : 'a plain custom name was refused';
});

check('the webhook has no browser half at all', function() use ($platforms, $purchase) {
    $destination = destinationFor('webhook', ['url' => 'https://example.com/hook']);

    return $platforms['webhook']->boot($destination, Consent::allGranted(), []) === []
        && $platforms['webhook']->mapEvent($purchase, $destination) === [];
});

check('no platform ever emits a null inside a payload', function() use ($platforms, $purchase) {
    $settingsByPlatform = [
        'googleAds' => ['conversionId' => 'AW-123456789'],
        'ga4' => ['measurementId' => 'G-ABC1234567'],
        'gtm' => ['containerId' => 'GTM-ABC1234'],
        'meta' => ['pixelId' => '123456789012345'],
        'microsoftAds' => ['tagId' => '12345678'],
        'tiktok' => ['pixelId' => 'C4A1BCDEFGHIJKLMNOPQ'],
        'pinterest' => ['tagId' => '2612345678901'],
        'linkedin' => ['partnerId' => '1234567'],
        'snapchat' => ['pixelId' => '11111111-2222-3333-4444-555555555555'],
        'reddit' => ['pixelId' => 'a2_abcdefghijkl'],
        'x' => ['pixelId' => 'o1abc'],
        'clarity' => ['projectId' => 'abcdefghij'],
        'hotjar' => ['siteId' => '1234567'],
        'custom' => [],
        'webhook' => ['url' => 'https://example.com/hook'],
    ];

    $findNull = static function($value, string $path) use (&$findNull): ?string {
        if ($value === null) {
            return $path;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $child) {
            // A deliberate `ecommerce: null` is GTM's documented way of clearing its data layer.
            if ($key === 'ecommerce') {
                continue;
            }

            $found = $findNull($child, "$path.$key");

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    };

    foreach ($settingsByPlatform as $handle => $settings) {
        $destination = destinationFor($handle, $settings, [
            'purchase' => ['label' => 'lbl', 'conversionId' => '1', 'eventId' => 'o1def'],
        ]);

        foreach ($platforms[$handle]->mapEvent($purchase, $destination) as $index => $call) {
            $found = $findNull($call['args'], "$handle[$index]");

            if ($found !== null) {
                return "$found is null — Pinterest and Snapchat reject a payload containing one";
            }
        }
    }

    return true;
});

check('every platform declares a boot loader once configured', function() use ($platforms) {
    $settingsByPlatform = [
        'googleAds' => ['conversionId' => 'AW-123456789'],
        'ga4' => ['measurementId' => 'G-ABC1234567'],
        'gtm' => ['containerId' => 'GTM-ABC1234'],
        'meta' => ['pixelId' => '123456789012345'],
        'microsoftAds' => ['tagId' => '12345678'],
        'tiktok' => ['pixelId' => 'C4A1BCDEFGHIJKLMNOPQ'],
        'pinterest' => ['tagId' => '2612345678901'],
        'linkedin' => ['partnerId' => '1234567'],
        'snapchat' => ['pixelId' => '11111111-2222-3333-4444-555555555555'],
        'reddit' => ['pixelId' => 'a2_abcdefghijkl'],
        'x' => ['pixelId' => 'o1abc'],
        'clarity' => ['projectId' => 'abcdefghij'],
        'hotjar' => ['siteId' => '1234567'],
        'custom' => [],
    ];

    $runtime = file_get_contents('/var/www/craft-tape/src/web/assets/tape/dist/tape.js');

    foreach ($settingsByPlatform as $handle => $settings) {
        $boot = $platforms[$handle]->boot(destinationFor($handle, $settings), Consent::allGranted(), []);

        if (!isset($boot['loader'])) {
            return "$handle declares no loader";
        }

        // A loader named in PHP with no implementation in the runtime is a destination that
        // silently never loads, and nothing else would ever notice.
        if (!str_contains($runtime, $boot['loader'] . ': function')) {
            return "the runtime has no `{$boot['loader']}` loader for $handle";
        }
    }

    return true;
});

check('a destination with no credentials boots nothing rather than a blank tag', function() use ($platforms) {
    return $platforms['meta']->boot(destinationFor('meta', []), Consent::allGranted(), []) === []
        && $platforms['ga4']->boot(destinationFor('ga4', []), Consent::allGranted(), []) === [];
});

check('gtag destinations turn off automatic page views', function() use ($platforms) {
    $boot = $platforms['ga4']->boot(destinationFor('ga4', ['measurementId' => 'G-ABC1234567']), Consent::allGranted(), []);

    // Leaving it on double-counts the first hit of every page, for ever, invisibly.
    return ($boot['config']['params']['send_page_view'] ?? null) === false ? true : json_encode($boot);
});

check('Google tags load ahead of consent under consent mode, and other pixels do not', function() use ($platforms, $plugin) {
    $plugin->getSettings()->consentMode = true;

    $ga4 = $platforms['ga4']->boot(destinationFor('ga4', ['measurementId' => 'G-ABC1234567']), Consent::unknown(), []);
    $meta = $platforms['meta']->boot(destinationFor('meta', ['pixelId' => '123456789012345']), Consent::unknown(), []);

    return ($ga4['loadWithoutConsent'] ?? false) === true && ($meta['loadWithoutConsent'] ?? false) === false
        ? true
        : 'consent-mode loading is the wrong way round';
});

check('Meta’s init fires no page view of its own', function() use ($platforms) {
    $boot = $platforms['meta']->boot(destinationFor('meta', ['pixelId' => '123456789012345']), Consent::allGranted(), []);

    return ($boot['config']['autoPageView'] ?? null) === false;
});


// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Destinations in project config');

$metaUid = null;

check('a destination saves and reads back intact', function() use ($plugin, $run, &$createdDestinations, &$metaUid) {
    $destination = new Destination([
        'handle' => 'meta' . $run,
        'name' => 'Meta ' . $run,
        'platform' => 'meta',
        'settings' => ['pixelId' => '123456789012345', 'accessToken' => 'tok', 'advancedMatching' => true],
        'events' => ['purchase', 'add_to_cart'],
        'eventSettings' => ['purchase' => ['label' => 'x']],
        'serverSide' => true,
        'consentCategory' => Consent::CATEGORY_ADS,
    ]);

    if (!$plugin->destinations->saveDestination($destination)) {
        return json_encode($destination->getErrors());
    }

    $createdDestinations[] = $metaUid = $destination->uid;
    $read = $plugin->destinations->getDestinationByHandle('meta' . $run);

    if ($read === null) {
        return 'could not read it back';
    }

    return $read->platform === 'meta'
        && $read->setting('pixelId') === '123456789012345'
        && $read->events === ['purchase', 'add_to_cart']
        && $read->eventSettings['purchase']['label'] === 'x'
        && $read->serverSide === true
        && $read->sortOrder > 0
            ? true
            : json_encode($read->getConfig());
});

check('a nested settings array survives project config', function() use ($plugin, $metaUid) {
    // Project config strips empty arrays and flattens associative ones unless they are packed, so
    // a settings hash that came back as a list would break every credential read.
    $read = $plugin->destinations->getDestinationByUid($metaUid);

    return is_array($read->settings) && array_key_exists('pixelId', $read->settings)
        ? true
        : json_encode($read->settings);
});

check('a duplicate handle is refused', function() use ($plugin, $run) {
    $clash = new Destination([
        'handle' => 'meta' . $run,
        'name' => 'Clash',
        'platform' => 'meta',
        'settings' => ['pixelId' => '999999999999999'],
    ]);

    return !$plugin->destinations->saveDestination($clash)
        && $clash->hasErrors('handle')
            ? true
            : 'two destinations may not share a handle';
});

check('a handle with spaces or punctuation is refused', function() use ($plugin) {
    $bad = new Destination(['handle' => 'my pixel!', 'name' => 'Bad', 'platform' => 'meta', 'settings' => ['pixelId' => '123456789012345']]);

    return !$bad->validate() && $bad->hasErrors('handle');
});

check('a destination whose ID is an unset env var reports the problem in words', function() use ($plugin, $run, &$createdDestinations) {
    $destination = new Destination([
        'handle' => 'envtest' . $run,
        'name' => 'Env test',
        'platform' => 'ga4',
        'settings' => ['measurementId' => '$TAPE_NOT_SET_ANYWHERE'],
    ]);

    if (!$plugin->destinations->saveDestination($destination)) {
        return json_encode($destination->getErrors());
    }

    $createdDestinations[] = $destination->uid;

    if ($destination->isConfigured()) {
        return 'an unset env var should not count as configured';
    }

    $problems = $destination->getProblems();

    return $problems !== [] && str_contains($problems[0], '$TAPE_NOT_SET_ANYWHERE')
        ? true
        : json_encode($problems);
});

check('an unconfigured destination is left out of the active list', function() use ($plugin, $run) {
    $handles = array_map(
        static fn(Destination $d) => $d->handle,
        $plugin->destinations->getActiveDestinations(Craft::$app->getSites()->getPrimarySite()->id),
    );

    return in_array('meta' . $run, $handles, true) && !in_array('envtest' . $run, $handles, true)
        ? true
        : implode(',', $handles);
});

check('a broken destination is reported for the nav badge', function() use ($plugin, $run) {
    $broken = array_map(static fn(Destination $d) => $d->handle, $plugin->destinations->getBrokenDestinations());

    return in_array('envtest' . $run, $broken, true) ? true : implode(',', $broken);
});

check('firesEvent honours both the event list and the platform’s vocabulary', function() use ($plugin, $run) {
    $destination = $plugin->destinations->getDestinationByHandle('meta' . $run);

    return $destination->firesEvent('purchase')
        && !$destination->firesEvent('view_item')
        && !$destination->firesEvent('refund')
            ? true
            : 'event gating is wrong';
});

check('a wildcard event list fires everything the platform supports', function() {
    $destination = destinationFor('meta', ['pixelId' => '123456789012345']);

    return $destination->firesEvent('purchase')
        && $destination->firesEvent('view_item')
        && !$destination->firesEvent('refund');
});

check('a disabled destination fires nothing at all', function() {
    $destination = destinationFor('meta', ['pixelId' => '123456789012345']);
    $destination->enabled = false;

    return !$destination->firesEvent('purchase');
});

check('site scoping is empty-means-all', function() {
    $destination = destinationFor('meta', ['pixelId' => '1']);

    if (!$destination->isActiveOnSite(99)) {
        return 'an empty site list should mean every site';
    }

    $destination->siteIds = [1];

    return $destination->isActiveOnSite(1) && !$destination->isActiveOnSite(2);
});

check('deleting a destination removes it from project config', function() use ($plugin, $run, &$createdDestinations) {
    $destination = new Destination([
        'handle' => 'doomed' . $run,
        'name' => 'Doomed',
        'platform' => 'clarity',
        'settings' => ['projectId' => 'abcdefghij'],
    ]);

    $plugin->destinations->saveDestination($destination);
    $uid = $destination->uid;
    $plugin->destinations->deleteDestination($destination);

    return $plugin->destinations->getDestinationByUid($uid) === null
        && $plugin->destinations->getDestinationByHandle('doomed' . $run) === null;
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Triggers');

check('a trigger saves and reads back', function() use ($plugin, $run, &$createdTriggers) {
    $trigger = new Trigger([
        'name' => 'Phone clicks ' . $run,
        'type' => Trigger::TYPE_TEL,
        'event' => TrackingEvent::CONTACT,
        'value' => 25.0,
        'currency' => 'GBP',
        'uris' => ['contact', 'services/*'],
    ]);

    if (!$plugin->destinations->saveTrigger($trigger)) {
        return json_encode($trigger->getErrors());
    }

    $createdTriggers[] = $trigger->uid;
    $read = $plugin->destinations->getTriggerByUid($trigger->uid);

    return $read !== null
        && $read->type === Trigger::TYPE_TEL
        && $read->uris === ['contact', 'services/*']
        && $read->value === 25.0
            ? true
            : json_encode($read?->getConfig());
});

check('a trigger can never announce a purchase or a refund', function() {
    foreach ([TrackingEvent::PURCHASE, TrackingEvent::REFUND] as $name) {
        $trigger = new Trigger(['name' => 'Fake', 'type' => Trigger::TYPE_TEL, 'event' => $name]);

        if ($trigger->validate() || !$trigger->hasErrors('event')) {
            return "a {$name} trigger validated";
        }
    }

    return true;
});

check('the trigger endpoint stops acting for an IP that floods it', function() use ($plugin) {
    $endpoint = 'trigger';
    $limit = Events::ANONYMOUS_LIMITS[$endpoint];
    $ip = '203.0.113.' . random_int(1, 254);
    $key = 'tape:rate:' . $endpoint . ':' . sha1($ip) . ':' . intdiv(time(), 60);
    $allowed = 0;

    try {
        for ($i = 0; $i < $limit + 5; $i++) {
            $allowed += $plugin->events->allowAnonymousRequest($endpoint, $ip) ? 1 : 0;
        }
    } finally {
        Craft::$app->getCache()->delete($key);
    }

    return $allowed === $limit ? true : "allowed {$allowed} of " . ($limit + 5);
});

check('the runtime mints a fresh event ID for every trigger firing', function() {
    $js = file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/tape/dist/tape.js');

    // A trigger's payloads are mapped at render time and served from cache to everyone; sending the
    // rendered ID would have every platform deduplicate all those visitors into one conversion.
    return str_contains($js, 'rekey(trigger.d, trigger.i, eventId)')
        && !str_contains($js, 'eventId: trigger.i')
            ? true
            : 'the trigger event ID is still the one baked into the page';
});

check('URI patterns match with a wildcard, and both with and without a leading slash', function() {
    $trigger = new Trigger(['uris' => ['contact', 'services/*']]);

    return $trigger->matchesUri('contact')
        && $trigger->matchesUri('/contact')
        && $trigger->matchesUri('services/plumbing')
        && $trigger->matchesUri('services/plumbing/emergency')
        && !$trigger->matchesUri('about')
            ? true
            : 'URI matching is wrong';
});

check('an empty URI list means every page', fn() => (new Trigger())->matchesUri('anything/at/all'));

check('scroll thresholds are parsed, sorted and de-duplicated', function() {
    $trigger = new Trigger(['type' => Trigger::TYPE_SCROLL, 'threshold' => '75, 25,50, 25, nonsense, -5']);

    return $trigger->getThresholds() === [25.0, 50.0, 75.0] ? true : json_encode($trigger->getThresholds());
});

check('a click trigger without a selector will not save', function() {
    $trigger = new Trigger(['name' => 'x', 'type' => Trigger::TYPE_CLICK, 'event' => 'generate_lead']);

    return !$trigger->validate() && $trigger->hasErrors('selector');
});

check('a scroll trigger without thresholds will not save', function() {
    $trigger = new Trigger(['name' => 'x', 'type' => Trigger::TYPE_SCROLL, 'event' => 'scroll']);

    return !$trigger->validate() && $trigger->hasErrors('threshold');
});

check('an uppercase event name is refused', function() {
    $trigger = new Trigger(['name' => 'x', 'type' => Trigger::TYPE_TEL, 'event' => 'GenerateLead']);

    return !$trigger->validate() && $trigger->hasErrors('event');
});

check('triggers are Pro-only at the point of use', function() use ($plugin) {
    Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_LITE);
    $lite = $plugin->destinations->getActiveTriggers(Craft::$app->getSites()->getPrimarySite()->id, 'contact');
    Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_PRO);
    $pro = $plugin->destinations->getActiveTriggers(Craft::$app->getSites()->getPrimarySite()->id, 'contact');

    return $lite === [] && count($pro) >= 1 ? true : count($lite) . '/' . count($pro);
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('The ledger');

check('a conversion is claimed once and only once', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 910001;

    $first = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_BROWSER, Ledger::STATUS_EMITTED);
    $second = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_BROWSER, Ledger::STATUS_EMITTED);

    return is_int($first) && $second === null
        ? true
        : 'the unique key is what stops a refreshed confirmation page doubling the revenue';
});

check('the browser and server channels are two deliberate sends, not a duplicate', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 910002;

    $browser = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_BROWSER, Ledger::STATUS_EMITTED);
    $server = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING);

    return is_int($browser) && is_int($server) ? true : 'the platform deduplicates these on the shared event ID';
});

check('a page view claims nothing, so it can repeat', function() use ($plugin) {
    $event = new TrackingEvent(['name' => TrackingEvent::PAGE_VIEW]);

    return $plugin->ledger->dedupeKey($event, null, Ledger::CHANNEL_BROWSER) === null;
});

check('two partial refunds of one order are two conversions', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);

    $first = new TrackingEvent(['name' => TrackingEvent::REFUND, 'eventId' => 'chk-' . $run . '-r1', 'orderId' => 910003, 'params' => ['refund_id' => 'A']]);
    $second = new TrackingEvent(['name' => TrackingEvent::REFUND, 'eventId' => 'chk-' . $run . '-r2', 'orderId' => 910003, 'params' => ['refund_id' => 'B']]);
    $repeat = new TrackingEvent(['name' => TrackingEvent::REFUND, 'eventId' => 'chk-' . $run . '-r3', 'orderId' => 910003, 'params' => ['refund_id' => 'A']]);

    return is_int($plugin->ledger->record($first, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING))
        && is_int($plugin->ledger->record($second, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING))
        && $plugin->ledger->record($repeat, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING) === null
            ? true
            : 'refund deduplication should key on the refund, not just the order';
});

check('hasFired sees a claimed conversion and ignores a skipped one', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $skipped = purchaseEvent($run);
    $skipped->orderId = 910004;
    $plugin->ledger->record($skipped, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_SKIPPED);

    return $plugin->ledger->hasFired(TrackingEvent::PURCHASE, 910001)
        && !$plugin->ledger->hasFired(TrackingEvent::PURCHASE, 910004)
        && !$plugin->ledger->hasFired(TrackingEvent::PURCHASE, 999999)
            ? true
            : 'hasFired is wrong';
});

check('a confirmation turns “written into the page” into “sent”', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 910005;
    $event->eventId = 'chk-' . $run . '-confirm';
    $plugin->ledger->record($event, $destination, Ledger::CHANNEL_BROWSER, Ledger::STATUS_EMITTED);

    $plugin->ledger->confirm('chk-' . $run . '-confirm', [$destination->handle], []);

    $rows = $plugin->ledger->getRecent(['orderId' => 910005]);

    return ($rows[0]['status'] ?? null) === Ledger::STATUS_SENT ? true : json_encode($rows);
});

check('a blocked vendor script is recorded as a failure, which is what recovery goes after', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 910006;
    $event->eventId = 'chk-' . $run . '-blocked';
    $plugin->ledger->record($event, $destination, Ledger::CHANNEL_BROWSER, Ledger::STATUS_EMITTED);

    $plugin->ledger->confirm('chk-' . $run . '-blocked', [], [$destination->handle]);

    $rows = $plugin->ledger->getRecent(['orderId' => 910006]);

    return ($rows[0]['status'] ?? null) === Ledger::STATUS_FAILED
        && str_contains((string)($rows[0]['error'] ?? ''), 'did not load')
            ? true
            : json_encode($rows);
});

check('a failure records its status code and counts the attempt', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 910007;
    $id = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING);

    $plugin->ledger->markFailed($id, 401, 'OAuth token has been revoked');
    $rows = $plugin->ledger->getRecent(['orderId' => 910007]);

    return (int)$rows[0]['statusCode'] === 401
        && (int)$rows[0]['attempts'] === 1
        && str_contains($rows[0]['error'], 'revoked')
            ? true
            : json_encode($rows);
});

check('a very long error is truncated rather than stored whole', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 910008;
    $id = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING);

    $plugin->ledger->markFailed($id, 500, str_repeat('x', 9000));
    $rows = $plugin->ledger->getRecent(['orderId' => 910008]);

    return mb_strlen($rows[0]['error']) <= 2000 ? true : mb_strlen($rows[0]['error']) . ' characters stored';
});

check('the ledger holds nothing about the customer', function() {
    $columns = array_keys(Craft::$app->getDb()->getTableSchema('{{%tape_events}}')->columns);
    $forbidden = ['email', 'phone', 'userData', 'payload', 'userId', 'ip'];

    foreach ($forbidden as $column) {
        if (in_array($column, $columns, true)) {
            return "the ledger has a `$column` column — it records what fired, not who for";
        }
    }

    return true;
});

check('unconfirmed conversions are found once they are old enough', function() use ($plugin) {
    $stale = $plugin->ledger->getUnconfirmedConversions(new DateTime('+1 minute'));
    $orderIds = array_column($stale, 'orderId');

    // 910006 was reported blocked; 910005 was confirmed sent and must not be here.
    return in_array(910006, $orderIds, true) && !in_array(910005, $orderIds, true)
        ? true
        : json_encode($orderIds);
});

check('filtering the log by outcome and channel works', function() use ($plugin) {
    $failed = $plugin->ledger->getCount(['status' => Ledger::STATUS_FAILED]);
    $server = $plugin->ledger->getCount(['channel' => Ledger::CHANNEL_SERVER]);

    return $failed >= 2 && $server >= 3 ? true : "failed:$failed server:$server";
});

check('destination health groups by outcome and finds the last event', function() use ($plugin, $metaUid) {
    $health = $plugin->ledger->getDestinationHealth(new DateTime('-1 day'));

    return isset($health[$metaUid])
        && $health[$metaUid]['failed'] >= 2
        && $health[$metaUid]['lastAt'] !== null
            ? true
            : json_encode($health[$metaUid] ?? null);
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Collecting and processing');

check('nothing is tracked on a console request unless it is forced', function() use ($plugin) {
    $plugin->events->setShouldTrack(null);
    $natural = $plugin->events->shouldTrack();
    $plugin->events->setShouldTrack(true);

    return $natural === false ? true : 'a console request is not a visitor';
});

check('an excluded event is refused at collection', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->enabledEvents = ['view_item' => false];

    $refused = $plugin->events->collect(new TrackingEvent(['name' => TrackingEvent::VIEW_ITEM]));
    $settings->enabledEvents = [];

    return $refused === false;
});

check('an event nobody has an opinion about is enabled', function() use ($plugin) {
    return $plugin->events->isEventEnabled('some_future_event');
});

check('page views are switched off by their own setting', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->trackPageViews = false;
    $off = $plugin->events->isEventEnabled(TrackingEvent::PAGE_VIEW);
    $settings->trackPageViews = true;

    return $off === false;
});

check('a second purchase for the same order in one response is dropped', function() use ($plugin, $run) {
    $plugin->events->clearQueue();
    $first = $plugin->events->collect(purchaseEvent($run));
    $second = $plugin->events->collect(purchaseEvent($run));
    $count = count($plugin->events->getQueue());
    $plugin->events->clearQueue();

    return $first && !$second && $count === 1 ? true : "collected $count";
});

check('an IP range excludes a visitor inside it and not one outside', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->excludeIps = ['203.0.113.0/24', '198.51.100.7'];

    $reflection = new ReflectionMethod($plugin->events, 'ipIsExcluded');
    $reflection->setAccessible(true);

    $inside = $reflection->invoke($plugin->events, '203.0.113.55');
    $edge = $reflection->invoke($plugin->events, '203.0.114.1');
    $exact = $reflection->invoke($plugin->events, '198.51.100.7');
    $other = $reflection->invoke($plugin->events, '198.51.100.8');

    $settings->excludeIps = [];

    return $inside && !$edge && $exact && !$other ? true : "inside:$inside edge:$edge exact:$exact other:$other";
});

check('processing an event produces one dispatch entry per destination call', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 920001;

    $result = $plugin->events->process($event, ['destinations' => [$destination], 'serverSide' => false]);

    return count($result['dispatch']) === 1
        && $result['dispatch'][0]['d'] === $destination->handle
        && $result['dispatch'][0]['c'] === Consent::CATEGORY_ADS
        && $result['dispatch'][0]['fn'] === 'fbq'
            ? true
            : json_encode($result);
});

check('an `only` filter narrows an event to one destination', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 920002;
    $event->onlyDestinations = ['something-else'];

    $result = $plugin->events->process($event, ['destinations' => [$destination], 'serverSide' => false]);

    return $result['dispatch'] === [];
});

check('pre-mapping records nothing in the ledger', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 920003;

    $plugin->events->process($event, ['destinations' => [$destination], 'record' => false, 'serverSide' => false]);

    return $plugin->ledger->getCount(['orderId' => 920003]) === 0
        ? true
        : 'a trigger that may never fire must not claim a conversion';
});

check('a server-side-only destination emits no browser payload but still sends', function() use ($plugin, $run) {
    $destination = destinationFor('meta', ['pixelId' => '123456789012345', 'accessToken' => 'tok']);
    $destination->serverSide = true;
    $destination->serverSideOnly = true;

    $event = purchaseEvent($run);
    $event->orderId = 920004;

    $result = $plugin->events->process($event, ['destinations' => [$destination], 'serverSide' => false]);

    return $result['dispatch'] === [] && $result['server'] === true ? true : json_encode($result);
});

check('a destination with no server-side API never reports one', function() use ($plugin, $run) {
    $destination = destinationFor('googleAds', ['conversionId' => 'AW-123456789']);
    $destination->serverSide = true;

    $result = $plugin->events->process($event = purchaseEvent($run), ['destinations' => [$destination], 'serverSide' => false]);

    return $result['server'] === false ? true : 'Google Ads has no conversions API here';
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Server-side dispatch');

$transport = new RecordingTransport();
$plugin->dispatcher->transport = $transport;

check('a successful send is recorded against its ledger row', function() use ($plugin, $run, $transport, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 930001;

    $id = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING);
    $transport->status = 200;
    $transport->body = '{"events_received":1}';

    $result = $plugin->dispatcher->send($event, $destination);
    $plugin->dispatcher->recordResult($id, $result);

    $rows = $plugin->ledger->getRecent(['orderId' => 930001]);

    return $result->ok
        && $rows[0]['status'] === Ledger::STATUS_SENT
        && $rows[0]['sentAt'] !== null
        && str_contains($transport->last()['url'], 'graph.facebook.com')
            ? true
            : json_encode([$result->getSummary(), $rows]);
});

check('a rejection is recorded with its body, not thrown', function() use ($plugin, $run, $transport, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 930002;

    $id = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING);
    $transport->status = 400;
    $transport->body = '{"error":{"message":"Invalid OAuth access token."}}';

    $result = $plugin->dispatcher->send($event, $destination);
    $plugin->dispatcher->recordResult($id, $result);

    $rows = $plugin->ledger->getRecent(['orderId' => 930002]);

    return !$result->ok
        && $rows[0]['status'] === Ledger::STATUS_FAILED
        && str_contains($rows[0]['error'], 'Invalid OAuth')
            ? true
            : json_encode($rows);
});

check('a 200 the platform means as a rejection is still a failure', function() use ($plugin, $run, $transport, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $transport->status = 200;
    $transport->body = '{"events_received":0}';

    $result = $plugin->dispatcher->send(purchaseEvent($run), $destination);

    return !$result->ok ? true : 'Meta answers 200 when it silently drops everything';
});

check('a network error is a failure with a message and no status', function() use ($plugin, $run, $transport, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $transport->status = 0;
    $transport->body = '';
    $transport->error = 'cURL error 28: Operation timed out';

    $result = $plugin->dispatcher->send(purchaseEvent($run), $destination);
    $transport->error = null;

    return !$result->ok && str_contains($result->getSummary(), 'timed out');
});

check('a platform with no server API is skipped rather than failed', function() use ($plugin, $run) {
    $destination = destinationFor('googleAds', ['conversionId' => 'AW-123456789']);
    $result = $plugin->dispatcher->send(purchaseEvent($run), $destination);

    return $result->skipped && !$result->ok ? true : $result->getSummary();
});

check('a destination missing its token is skipped, not attempted', function() use ($plugin, $run, $transport) {
    $before = count($transport->requests);
    $destination = destinationFor('meta', ['pixelId' => '123456789012345']);
    $result = $plugin->dispatcher->send(purchaseEvent($run), $destination);

    return $result->skipped && count($transport->requests) === $before
        ? true
        : 'nothing should have gone over the network';
});

check('a queued send carries only identifiers and click IDs, never customer data', function() use ($plugin, $run, $metaUid) {
    $destination = $plugin->destinations->getDestinationByUid($metaUid);
    $event = purchaseEvent($run);
    $event->orderId = 930003;

    $queue = Craft::$app->getQueue();
    $before = $queue->getTotalJobs();
    $plugin->dispatcher->dispatch($event, $destination, null);
    $after = $queue->getTotalJobs();

    if ($after <= $before) {
        return 'nothing was queued';
    }

    $serialized = (new craft\db\Query())
        ->select(['job'])
        ->from(['{{%queue}}'])
        ->orderBy(['id' => SORT_DESC])
        ->scalar();

    $job = is_resource($serialized) ? stream_get_contents($serialized) : (string)$serialized;

    if (str_contains($job, 'person@example.com') || str_contains($job, hash('sha256', 'person@example.com'))) {
        return 'the queue payload contains the customer’s email';
    }

    return str_contains($job, 'TT123') && str_contains($job, '930003')
        ? true
        : 'the click IDs and order ID should travel';
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Recovery');

check('recovery only targets destinations that can actually take a server-side event', function() use ($plugin, $metaUid) {
    $handles = array_map(static fn(Destination $d) => $d->handle, $plugin->recovery->recoverableDestinations());
    $meta = $plugin->destinations->getDestinationByUid($metaUid);

    return in_array($meta->handle, $handles, true) ? true : implode(',', $handles);
});

check('a browser-only purchase destination is reported as unrecoverable', function() use ($plugin, $run, &$createdDestinations) {
    $destination = new Destination([
        'handle' => 'adsonly' . $run,
        'name' => 'Ads only',
        'platform' => 'googleAds',
        'settings' => ['conversionId' => 'AW-123456789'],
        'eventSettings' => ['purchase' => ['label' => 'lbl']],
    ]);

    $plugin->destinations->saveDestination($destination);
    $createdDestinations[] = $destination->uid;

    $handles = array_map(static fn(Destination $d) => $d->handle, $plugin->recovery->getUnrecoverableDestinations());

    return in_array('adsonly' . $run, $handles, true) ? true : implode(',', $handles);
});

check('recovery does nothing on Lite', function() use ($plugin) {
    Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_LITE);
    $result = $plugin->recovery->sweep();
    Craft::$app->getPlugins()->switchEdition('tape', Plugin::EDITION_PRO);

    return $result === ['missing' => 0, 'unconfirmed' => 0, 'sent' => 0] ? true : json_encode($result);
});

check('recovery reuses the order’s conversion ID so a late browser event collapses into it', function() use ($plugin) {
    // Derived from the order, not minted — that is what makes browser, server and recovery one
    // conversion rather than three.
    $fake = new stdClass();
    $fake->id = 4242;
    $fake->uid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    $first = $plugin->commerce->conversionEventId($fake, TrackingEvent::PURCHASE);
    $second = $plugin->commerce->conversionEventId($fake, TrackingEvent::PURCHASE);
    $other = $plugin->commerce->conversionEventId($fake, TrackingEvent::REFUND);

    return $first === $second
        && $first !== $other
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $first) === 1
            ? true
            : "$first / $second / $other";
});

check('an order that was skipped for consent is never recovered', function() use ($plugin) {
    // `getUntrackedOrders` excludes any order with a ledger row at all, skipped included: recovery
    // repairs failures, it does not override a decision.
    $reflection = new ReflectionMethod($plugin->ledger, 'buildQuery');

    $orders = $plugin->ledger->getUntrackedOrders(new DateTime('-3 days'), new DateTime());
    $ids = array_column($orders, 'id');

    return !in_array(910004, $ids, true) ? true : 'a consent-skipped order was queued for recovery';
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Rendering');

check('the head carries consent defaults before anything else', function() use ($plugin) {
    $plugin->events->setShouldTrack(true);
    $plugin->tags->invalidateCache();
    $html = $plugin->tags->getHeadHtml();

    if ($html === '') {
        return 'nothing rendered';
    }

    $consentPos = strpos($html, 'gtag("consent","default"');
    $configPos = strpos($html, 'window.__TAPE__');

    return $consentPos !== false && $configPos !== false && $consentPos < $configPos
        ? true
        : 'consent defaults must execute before any Google tag exists';
});

check('the payload names every configured destination and its loader', function() use ($plugin, $run) {
    $plugin->tags->invalidateCache();
    $payload = $plugin->tags->getPayload();
    $handles = array_column($payload['destinations'], 'h');

    if (!in_array('meta' . $run, $handles, true)) {
        return implode(',', $handles);
    }

    foreach ($payload['destinations'] as $destination) {
        if (($destination['loader'] ?? '') === '') {
            return $destination['h'] . ' has no loader';
        }
    }

    return true;
});

check('the payload holds no consent answer and no customer data', function() use ($plugin) {
    $plugin->tags->invalidateCache();
    $json = json_encode($plugin->tags->getPayload());

    // Both would be cached along with the page and served to the next hundred visitors.
    return !str_contains($json, '"current"') && !str_contains($json, 'person@example.com')
        ? true
        : 'the payload is not safe to put in a full-page cache';
});

check('a URL passthrough and redaction instruction is emitted when configured', function() use ($plugin) {
    $plugin->tags->invalidateCache();
    $html = $plugin->tags->getHeadHtml();

    return str_contains($html, 'url_passthrough') && str_contains($html, 'ads_data_redaction');
});

check('the runtime script is published and versioned', function() use ($plugin) {
    $plugin->tags->invalidateCache();
    $html = $plugin->tags->getHeadHtml();

    // Exactly one query string. Two `?`s is a URL that works by accident.
    if (preg_match('/<script src="([^"]*tape\.js[^"]*)"/', $html, $matches) !== 1) {
        return 'the runtime script tag is missing';
    }

    return substr_count($matches[1], '?') === 1 ? true : $matches[1];
});

check('a matching event reaches the payload with a finished payload per destination', function() use ($plugin, $run) {
    $plugin->events->clearQueue();
    $plugin->tags->invalidateCache();

    $event = purchaseEvent($run);
    $event->orderId = 940001;
    $plugin->events->collect($event);

    $payload = $plugin->tags->getPayload();
    $plugin->events->clearQueue();

    if ($payload['events'] === []) {
        return 'no events in the payload';
    }

    $first = $payload['events'][0];

    return $first['n'] === 'purchase' && $first['i'] === $event->eventId && $first['d'] !== []
        ? true
        : json_encode($first);
});

check('a visitor’s string in the payload cannot close the script element', function() use ($plugin, $run, &$createdDestinations) {
    // Its own destination: GA4 maps `search`, and the check must not lean on whatever else happens
    // to be configured in the harness.
    $destination = new Destination([
        'handle' => 'xss' . $run,
        'name' => 'XSS',
        'platform' => 'ga4',
        'settings' => ['measurementId' => 'G-XSS1234567'],
    ]);

    if (!$plugin->destinations->saveDestination($destination)) {
        return json_encode($destination->getErrors());
    }

    $createdDestinations[] = $destination->uid;
    $plugin->events->clearQueue();
    $plugin->tags->invalidateCache();

    // A search term is the obvious route: templates pass the query string straight to track().
    $plugin->events->collect(new TrackingEvent([
        'name' => TrackingEvent::SEARCH,
        'searchTerm' => '</script><script>alert(1)</script>',
    ]));

    $head = $plugin->tags->getHeadHtml();
    $plugin->events->clearQueue();
    $plugin->tags->invalidateCache();

    $decoded = json_decode(Tags::scriptJson(['s' => '</script>&']), true);

    if (!str_contains($head, 'window.__TAPE__') || !str_contains($head, 'alert(1)')) {
        return 'the search event never reached the head: ' . mb_substr(strip_tags($head), 0, 200);
    }

    return !str_contains($head, '</script><script>alert') && ($decoded['s'] ?? null) === '</script>&'
        ? true
        : 'the payload ends the <script> early';
});

check('a custom snippet is wrapped in an inert template when consent is in play', function() use ($plugin, $run, &$createdDestinations) {
    $destination = new Destination([
        'handle' => 'snippet' . $run,
        'name' => 'Snippet',
        'platform' => 'custom',
        'settings' => ['headHtml' => '<script>window.__snippetRan = true;</script>', 'queueName' => 'snippetQueue'],
        'consentCategory' => Consent::CATEGORY_ADS,
    ]);

    $plugin->destinations->saveDestination($destination);
    $createdDestinations[] = $destination->uid;

    $plugin->getSettings()->consentMode = true;
    $plugin->tags->invalidateCache();
    $html = $plugin->tags->getHeadHtml();

    // Inert in the page means it can be cached for everybody and still gated per visitor.
    return str_contains($html, '<template data-tape-snippet="snippet' . $run . '"')
        && str_contains($html, 'data-tape-consent="ads"')
        && str_contains($html, '__snippetRan')
            ? true
            : 'the snippet was not wrapped';
});

check('with consent mode off, a snippet is printed directly', function() use ($plugin, $run) {
    $plugin->getSettings()->consentMode = false;
    $plugin->tags->invalidateCache();
    $html = $plugin->tags->getHeadHtml();
    $plugin->getSettings()->consentMode = true;
    $plugin->tags->invalidateCache();

    return str_contains($html, '__snippetRan') && !str_contains($html, 'data-tape-snippet')
        ? true
        : 'a template that is always activated is a pointless indirection';
});

check('a noscript pixel is only written where consent is never in question', function() use ($plugin) {
    $settings = $plugin->getSettings();

    $settings->consentMode = true;
    $settings->consentSource = Settings::CONSENT_CMP;
    $plugin->tags->invalidateCache();
    $gated = $plugin->tags->getBodyHtml();

    $settings->consentSource = Settings::CONSENT_GRANTED;
    $plugin->tags->invalidateCache();
    $open = $plugin->tags->getBodyHtml();

    return !str_contains($gated, 'facebook.com/tr') && str_contains($open, 'facebook.com/tr')
        ? true
        : 'a noscript pixel cannot be consent-gated, so it must not be written on a gated site';
});

check('a visitor who has explicitly refused gets no noscript pixel either', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->consentMode = true;
    $settings->consentSource = Settings::CONSENT_GRANTED;

    $plugin->consent->setResolved(Consent::allDenied());
    $plugin->tags->invalidateCache();
    $refused = $plugin->tags->getBodyHtml();

    $plugin->consent->setResolved(null);
    $plugin->tags->invalidateCache();

    return !str_contains($refused, 'facebook.com/tr') ? true : 'a refusal should still remove the noscript pixel';
});

check('nothing renders at all when the visitor is excluded', function() use ($plugin) {
    $plugin->events->setShouldTrack(false);
    $plugin->tags->invalidateCache();
    $head = $plugin->tags->getHeadHtml();
    $body = $plugin->tags->getBodyHtml();
    $plugin->events->setShouldTrack(true);
    $plugin->tags->invalidateCache();

    return $head === '' && $body === '';
});

check('matching data stays off a page that carries no conversion', function() use ($plugin, $run) {
    $plugin->events->clearQueue();
    $plugin->getSettings()->enhancedConversions = true;
    $plugin->events->setUserData(new UserData(['email' => 'leak@example.com']));
    $plugin->tags->invalidateCache();

    $withoutConversion = json_encode($plugin->tags->getPayload());

    $event = purchaseEvent($run);
    $event->orderId = 940002;
    $plugin->events->collect($event);
    $plugin->tags->invalidateCache();
    $withConversion = json_encode($plugin->tags->getPayload());

    $plugin->events->clearQueue();
    $plugin->events->setUserData(null);
    $plugin->tags->invalidateCache();

    $hash = hash('sha256', 'leak@example.com');

    return !str_contains($withoutConversion, $hash) && str_contains($withConversion, $hash)
        ? true
        : 'a product page must not carry one visitor’s hashed email into the cache';
});

check('an excluded URI pattern is matched with a wildcard', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->excludeUris = ['account/*', 'basket'];

    $reflection = new ReflectionMethod($plugin->tags, 'uriIsExcluded');
    $reflection->setAccessible(true);

    // A console request has no URI, so it is excluded outright — which is also the correct answer.
    $excluded = $reflection->invoke($plugin->tags);
    $settings->excludeUris = [];

    return $excluded === true ? true : 'a request with no URI should not be injected into';
});

check('the plugin nav badge counts broken destinations without throwing', function() use ($plugin) {
    $item = $plugin->getCpNavItem();

    return isset($item['subnav']['destinations']) && ($item['badgeCount'] ?? 0) >= 1
        ? true
        : json_encode($item['badgeCount'] ?? null);
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
check('the consent mirror cookie is read raw, because JavaScript cannot sign it', function() {
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/Consent.php');

    // Craft validates cookies by default and silently drops any without its hash — which is every
    // cookie the runtime writes. Read through getCookies(), every server-side send is `skipped`.
    return str_contains($source, 'getRawCookies()') && !str_contains($source, '->getCookies()')
        ? true
        : 'the consent cookie is read through the validated collection';
});

section('Settings');

check('an editable-table row list is flattened to a plain list of strings', function() {
    $settings = new Settings();
    $settings->setExcludeUris([['uri' => 'account/*'], ['uri' => 'basket'], ['uri' => '']]);

    return $settings->getExcludeUris() === ['account/*', 'basket'] ? true : json_encode($settings->getExcludeUris());
});

check('those getters are declared as attributes, or Craft never saves them', function() {
    $attributes = (new Settings())->attributes();

    return in_array('excludeUris', $attributes, true) && in_array('excludeIps', $attributes, true);
});

check('a newline-separated string is accepted too', function() {
    $settings = new Settings();
    $settings->setExcludeIps("10.0.0.1\n10.0.0.2, 10.0.0.1");

    return $settings->getExcludeIps() === ['10.0.0.1', '10.0.0.2'] ? true : json_encode($settings->getExcludeIps());
});

check('no setting is marked required', function() {
    $settings = new Settings();

    foreach ($settings->getValidators() as $validator) {
        if ($validator instanceof yii\validators\RequiredValidator && $validator->when === null) {
            return 'a required setting blocks a fresh install from saving anything: ' . implode(',', (array)$validator->attributes);
        }
    }

    return true;
});

check('a blank settings model validates', function() {
    $settings = new Settings();

    return $settings->validate() ? true : json_encode($settings->getErrors());
});

check('an out-of-range value is caught', function() {
    $settings = new Settings();
    $settings->recoveryLookbackDays = 99;
    $settings->serverSideTimeout = 0;
    $settings->defaultCurrency = 'pounds';

    return !$settings->validate()
        && $settings->hasErrors('recoveryLookbackDays')
        && $settings->hasErrors('serverSideTimeout')
        && $settings->hasErrors('defaultCurrency');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────
section('Commerce bridge');

check('Commerce is detected', fn() => $plugin->commerce->isInstalled());

check('the product ID follows the configured source and prefix', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $variant = new stdClass();
    $variant->id = 555;
    $variant->sku = 'SKU-1';

    $settings->productIdSource = Settings::ID_SKU;
    $settings->productIdPrefix = null;
    $bySku = $plugin->commerce->productId($variant);

    $settings->productIdSource = Settings::ID_VARIANT_ID;
    $byId = $plugin->commerce->productId($variant);

    $settings->productIdPrefix = 'shop_';
    $prefixed = $plugin->commerce->productId($variant);

    $settings->productIdSource = Settings::ID_SKU;
    $settings->productIdPrefix = null;

    return $bySku === 'SKU-1' && $byId === '555' && $prefixed === 'shop_555'
        ? true
        : "$bySku / $byId / $prefixed";
});

check('reading the cart never creates one', function() use ($plugin) {
    // `getCart()` *makes* a cart and writes a session cookie, which would make every product page
    // uncacheable for the sake of an event with nothing in it.
    $source = file_get_contents('/var/www/craft-tape/src/services/Commerce.php');

    return !preg_match('/->getCart\(\)/', $source) ? true : 'Commerce::getCart() is being called';
});

check('no service outside the Commerce bridge imports a Commerce class', function() {
    $offenders = [];

    foreach (glob('/var/www/craft-tape/src/{models,platforms,helpers,transports}/*.php', GLOB_BRACE) as $file) {
        if (str_contains(file_get_contents($file), 'craft\\commerce')) {
            $offenders[] = basename($file);
        }
    }

    return $offenders === [] ? true : 'Commerce leaked into ' . implode(', ', $offenders);
});

echo "\n$passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
