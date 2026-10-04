# Tape documentation

The [README](../README.md) covers installation, the platform list, editions and the reasoning behind
the design. This file is the reference for the parts a developer needs while integrating.

## Twig API

Available as `craft.tape.*` and as a bare `tape` global.

### Placing the tags

```twig
{{ tape.head() }}   {# in <head> — consent signals, pixel loaders, the event queue #}
{{ tape.body() }}   {# before </body> — <noscript> pixels, custom body HTML #}
```

Only needed if **Add the tags automatically** is off in the settings. Calling either one suppresses
auto-injection for that response, so you can turn injection off per-template without a setting.

### Events

```twig
{% do craft.tape.track('generate_lead', { value: 50, currency: 'GBP', source: 'brochure' }) %}
```

Recognised keys are lifted onto the event: `value`, `currency`, `transactionId`, `coupon`, `listId`,
`listName`, `searchTerm`, `items`, and `only` (an array of destination handles to restrict to).
Anything else travels as an extra parameter to whichever destinations can carry one.

Commerce helpers, all of which are unnecessary while auto-tracking is on:

```twig
{% do craft.tape.viewItem(variant) %}
{% do craft.tape.viewItemList(products, 'summer-sale', 'Summer sale') %}
{% do craft.tape.selectItem(variant, 'summer-sale') %}
{% do craft.tape.addToCart(variant, 2) %}
{% do craft.tape.removeFromCart(variant, 1) %}
{% do craft.tape.addToWishlist(variant) %}
{% do craft.tape.viewCart() %}          {# defaults to the current cart, if there is one #}
{% do craft.tape.beginCheckout() %}
{% do craft.tape.addShippingInfo() %}
{% do craft.tape.addPaymentInfo() %}
{% do craft.tape.purchase(order) %}
{% do craft.tape.search('waterproof jacket') %}
{% do craft.tape.pageView(entry) %}
```

`purchase()` is safe to call even with auto-tracking on: the ledger's unique key drops the second
attempt at the same order, and Tape refuses two purchase events in one response outright.

### Inspecting

```twig
{{ tape.isTracking() }}       {# false for excluded visitors, previews and the control panel #}
{{ tape.destinations()|length }}
{{ tape.consent().isGranted('ad_storage') }}
```

## JavaScript API

```js
tape.track('add_to_cart', { id: variantId, qty: 2 });
tape.track('generate_lead', { source: 'chat-widget' });

tape.consent.update({ ads: true, analytics: true, functionality: false });
tape.consent.grantAll();
tape.consent.denyAll();
tape.consent.get();          // the seven Consent Mode signals

tape.debug();                // what the runtime believes: consent, booted, failed, held
```

`track()` posts to Tape, which builds the per-destination payloads and hands them back. The event
name must be a standard one or a custom name (lowercase, underscores, at most 40 characters, not
starting `ga_`, `google_` or `firebase_`) — `purchase` and `refund` are refused, and any `value` in
the request is ignored. Prices come from Commerce, looked up from the product ID you pass as `id`
or `ids`.

To queue calls before the runtime has loaded:

```html
<script>window.tape = window.tape || []; tape.push(['track', 'view_item', { id: 42 }]);</script>
```

### Throttling

The front-end `map` and `trigger` endpoints are anonymous and CSRF-exempt, so each acts on at most
120 and 30 requests per IP per minute (`services\Events::ANONYMOUS_LIMITS`). Over the limit they
answer as if nothing were configured and log one warning. The count is keyed on Craft's
`getUserIP()`, so behind a proxy or CDN set the `ipHeaders` and `trustedHosts` general config
settings. Otherwise every visitor shares the proxy's IP and the whole site shares one allowance.

## Consent

`consentCmp: 'custom'` evaluates `consentExpression` with `new Function`, so a strict
Content-Security-Policy needs `'unsafe-eval'` in `script-src` for it, or it never answers. Prefer a
named CMP, or call `tape.consent.update()` from the banner's own code. Neither needs `eval`.

## Webhooks

The body is JSON encoded once and sent as exactly those bytes. With a signing secret set, each
request carries `X-Tape-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with the secret>`.
Verify against the **raw** body as received. A body that was parsed and re-encoded can escape
slashes or Unicode differently, and then the signature fails.

```php
$raw = file_get_contents('php://input');
$ok = hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $_SERVER['HTTP_X_TAPE_SIGNATURE'] ?? '');
```

```js
// express.raw({ type: 'application/json' }) on the route, so req.body is a Buffer
const expected = Buffer.from('sha256=' + crypto.createHmac('sha256', secret).update(req.body).digest('hex'));
const given = Buffer.from(req.get('X-Tape-Signature') || '');
const ok = given.length === expected.length && crypto.timingSafeEqual(given, expected);
```

Use `eventId` as the idempotency key. A recovered purchase arrives with the same ID as the original.

## Config file

`config/tape.php` overrides any setting, per environment:

```php
return [
    '*' => [
        'consentMode' => true,
        'consentSource' => 'cmp',
        'consentCmp' => 'cookiebot',
        'consentRegionDefaults' => [
            'EU' => ['ad_storage' => 'denied', 'ad_user_data' => 'denied', 'analytics_storage' => 'denied'],
            'US-PRIVACY' => ['ad_storage' => 'denied'],
        ],
        'productIdSource' => 'sku',
        'phoneCountryCode' => '44',
        'excludeUserGroups' => ['staff'],
        'excludeIps' => ['203.0.113.0/24'],
    ],
    'dev' => [
        'debug' => true,
        'autoInject' => false,
    ],
];
```

`EU` expands to the EEA plus the UK and Switzerland; `US-PRIVACY` to the states with a comprehensive
privacy law.

## Extending it

### Enriching or suppressing an event

```php
use justinholtweb\tape\events\CollectEvent;
use justinholtweb\tape\services\Events;
use yii\base\Event;

Event::on(Events::class, Events::EVENT_BEFORE_COLLECT, function(CollectEvent $event) {
    // Add a parameter every destination that can carry one will receive.
    $event->trackingEvent->params['store_region'] = 'north';

    // Or drop the event entirely.
    if ($event->trackingEvent->name === 'view_item_list') {
        $event->isValid = false;
    }
});
```

### Adding a platform

Implement `platforms\PlatformInterface` — in practice, extend `platforms\BasePlatform` — and register
the class:

```php
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\tape\services\Platforms;
use yii\base\Event;

Event::on(Platforms::class, Platforms::EVENT_REGISTER_PLATFORMS, function(RegisterComponentTypesEvent $event) {
    $event->types[] = MyAdNetwork::class;
});
```

A platform answers three questions and no others: what needs configuring (`settingFields()`), what
script boots it (`boot()`), and what a `TrackingEvent` looks like in its own vocabulary
(`mapEvent()`). Consent, deduplication, ordering, exclusions, queueing and the ledger are all decided
before it is asked.

`boot()` returns a `loader` name, and the runtime must have an implementation for it. To load a
script Tape does not know about, use the `custom` destination instead — it takes your own HTML and
pushes every event onto a global array for it to read.

### Swapping the transport

```php
Plugin::getInstance()->dispatcher->transport = new MyProxyTransport();
```

Used by the integration checks to exercise every Conversions API without a network.

## Console commands

```sh
php craft tape/destinations             # what is configured, and what is broken
php craft tape/destinations/doctor      # the whole picture, Commerce wiring included
php craft tape/destinations/test        # send a real event to every server-side API
php craft tape/destinations/test --handle=meta
php craft tape/conversions/recover      # find and send missed conversions
php craft tape/conversions/recover --days=2 --limit=500
php craft tape/conversions/prune        # trim the event log
```

## Permissions

- **View destinations and reports** — the event log, the accuracy report, destination settings
- **Add and edit destinations and triggers** — also needs `allowAdminChanges`, because these are
  project config
- **Run conversion recovery**

Viewing and editing are separate on purpose: these screens hold access tokens for advertising
accounts, and "can see whether the pixel fired" is a much smaller permission than "can change where
conversions are sent".
