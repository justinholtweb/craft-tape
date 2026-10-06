# Tape — Craft CMS 5 Plugin

## Project Overview

Tape is conversion tracking for Craft: a dozen advertising and analytics pixels, the Commerce
ecommerce funnel, Consent Mode v2, server-side Conversions APIs, and recovery of the conversions
browsers lose. It is the Craft answer to WooCommerce's "pixel manager" plugins. Distributed as
`justinholtweb/craft-tape`. Lite (free) + Pro editions — **Pro $99, renewal $59/year**.

The marketing site is **justinholt.com/plugins/craft-tape**, part of the justinholt.com install,
not a standalone site. Its docs are this repo's `docs/*.md` (front matter required; `README.md` is
skipped), synced with `ddev exec php craft pluginsite/docs/sync craft-tape` from `~/Sites/justinholt`.
Plugin Store promos are `promos/` — `./build.sh`, seven JPEGs. A price change touches the seed
`justinholt/scripts/seed/plugin-pages/craft-tape.json`, README, `docs/editions.md`,
`docs/installation.md`, `docs/faq.md`, the promo cover, and the Craft Console listing.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **Craft Commerce is optional**, suggested only, and confined to `services\Commerce`
- No build step. One hand-written vanilla-JS runtime, `src/web/assets/tape/dist/tape.js`, ~850 lines,
  no dependencies. Editing it needs `php craft clear-caches/cp-resources`.
- No PHP dependencies beyond Craft.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\tape`
- Package: `justinholtweb/craft-tape`
- Handle: `tape`

### The load-bearing idea: one event shape

`models\TrackingEvent` is what a Twig call, a Commerce hook, an Ajax request, a browser trigger and a
recovery sweep all become *before* anything platform-specific happens. `platforms\*` are the only
classes that have heard of `fbq`, `ttq` or `send_to`, and they only ever read a `TrackingEvent`.

Three consequences the code depends on:

- Adding a platform is one file with no wiring anywhere else.
- The browser tag and the Conversions API call are literally the same event with the same
  `eventId` — which is the only reason platforms deduplicate them instead of double-counting.
- A site with no store loses nothing but the funnel.

### The other load-bearing idea: PHP maps, JavaScript dispatches

`tape.js` makes no decisions. Every payload it fires was built in PHP by a platform adapter and
shipped as `{fn: 'fbq', args: [...], d: 'handle', c: 'ads'}`, where `fn` is a dotted path resolved
against `window`. The runtime owns only the three things that can *only* be answered in a browser:
consent (reading a CMP, mirroring it to a cookie), loading (each vendor's own snippet, transcribed),
and triggers.

This is why there is no PHP/JS mapping duplication to keep in lockstep, and why a visitor cannot
dictate what gets sent.

### Data model

- **Destinations and triggers live in project config**, not a table (`tape.destinations.<uid>`,
  `tape.triggers.<uid>`). Tracking configuration is a deployment concern: staging and production want
  the same events with different account IDs, which is project config plus env vars exactly. The
  consequence is that these screens are read-only when `allowAdminChanges` is off, which is correct.
- `{{%tape_events}}` — the ledger, and the only table. One row per conversion per destination per
  channel, with a **unique index on `dedupeKey`**. The insert *is* the claim: an `IntegrityException`
  means somebody else got there first and this one is dropped. A check-then-insert would leave the
  race open exactly where it matters, which is two concurrent requests for one order.
- **The ledger holds nothing about the customer.** No email, no hashes, no payloads. Anything that
  needs re-deriving is re-derived from the order.

### Services

- `platforms` — the registry; third parties add their own via `EVENT_REGISTER_PLATFORMS`
- `destinations` — the library, in project config, plus triggers and GC of orphans
- `events` — collect, decide (exclusions), process (map + ledger + queue)
- `ledger` — claim, confirm, query, prune; the accuracy report and health panel
- `consent` — resolve, encode/decode the mirror cookie, region expansion, runtime config
- `tags` — compose the head and body, and inject into the response
- `dispatcher` — server-side sends through a swappable `TransportInterface`
- `recovery` — find conversions nobody got, and send them
- `commerce` — everything that knows Commerce exists, including `trackRefund()`

### Rules the code does not break

- **A tracking tag is never worth a 500.** Every front-end hook is wrapped; a mapping that throws
  logs and produces no payload.
- **Nothing blocks a response.** Server-side sends go through the queue, or from
  `EVENT_AFTER_REQUEST` when queueing is off. Never inline.
- **Browser payloads are not consent-gated server-side; the runtime gates them.** Consent can be
  granted *after* a page renders, and a payload never written can never be fired later. Server-side
  sends *are* gated, because there is no later.
- **Unknown consent is not denied consent.** It is a third state, and it decides whether a tag waits
  or gives up.
- **Recovery repairs failures; it never overrides a decision.** An order with a `skipped` row is left
  alone.
- **Nothing that varies per visitor goes into a cacheable page.** The consent answer is read from a
  cookie by the runtime; custom snippets sit inert in a `<template>`; matching data appears only on
  pages carrying a conversion.
- **A visitor may say which product; never what it is worth.** The map endpoint refuses `purchase`
  and `refund`, ignores any posted `value`, and looks prices up in Commerce from a product ID.
- **Reading the visitor's identity opens a session, which ends full-page caching.** Every such read
  is behind a check that a setting actually depends on it.

## Traps found while building this

- **`array_fill_keys($signals, DENIED) + [SECURITY_STORAGE => GRANTED]` denies security storage.**
  PHP's `+` keeps the *left* operand for duplicate keys. `array_merge` is what was meant. Symptom:
  fraud prevention and login broken on any site that honours the signal.
- **Commerce 5's line-item events are `EVENT_AFTER_ADD_LINE_ITEM`, not
  `EVENT_AFTER_ADD_LINE_ITEM_TO_ORDER`** — though the *value* is `afterAddLineItemToOrder`, which is
  what makes the wrong guess so plausible. A `defined()` guard then skips it and cart tracking is
  **silently off** with nothing in any log. Constants are now resolved from a candidate list and
  `resolveCommerceHooks()` is asserted in the checks and printed by `doctor`. Prefer the
  `EVENT_AFTER_APPLY_*` variants: the plain ones fire before the order is saved, so a brand-new
  cart's line item has no `orderId` yet.
- **`requireAcceptsJson()` reads the `Accept` header and nothing else.** The runtime sent
  `Content-Type` and `X-Requested-With` but no `Accept`, so `tape.track()` answered 400 and did
  nothing — with no console error, because the failure was a rejected promise. A test that sets the
  header itself hides this; the smoke test now asserts the runtime sends it.
- **`getPublishedUrl($path, true, $file)` already appends a cache buster**, so adding `?v=…` produces
  `tape.js?v=1787068557?v=5.0.0` — a URL that works by accident.
- **`craft\console\Request` has no `getCookies()`.** Anything reading cookies has to check
  `getIsConsoleRequest()` first, or a console command that touches the consent service fatals.
- **A `<noscript>` pixel cannot be consent-gated at all**, because it fires precisely where there is
  no JavaScript. It is written only on a site that has declared consent is never in question.
- **Craft 5 answers a successful `users/login` with the user model, not `{"success":true}`.**
- **CP controllers calling `requireCpRequest()` cannot be posted to via `/index.php`** — the action
  has to go through the cpTrigger (`/admin/actions/…`). And a raw `redirect` body param is rejected
  as "invalid body param" because Craft expects it hashed.
- **A curly quote straight after an interpolated variable is part of the variable name** — PHP allows
  bytes above 0x7F in identifiers. `"… “$step” …"` reads `step”`. Brace it.
- **A settings property backed by a getter/setter is silently never persisted** unless `attributes()`
  names it, because Craft saves plugin settings by iterating that list. Needed here because Craft's
  editable table posts `[['uri' => …], …]` rather than a flat list. Declare them with `@property` too
  or nothing outside the class can see them.
- **Twig arrow functions cannot take a hash literal body**: `map(u => { uri: u })` does not parse.
  Build the array with a `for` loop and `merge`.
- **`Craft::t()` inside a Twig string containing `{placeholder}` preceded by `#`** is consumed as
  interpolation. Not hit here, but the reason every `|t` argument is single-quoted.
- **The harness dashboard 500s** (`Invalid user ID`, unrelated to Tape), so the smoke test reads its
  CSRF token from one of Tape's own screens.
- **Project config writes from a bare console script are buffered** and lost without
  `saveModifiedConfigData()` — the checks' shutdown handler calls it, or its own cleanup silently
  does nothing while returning true.
- **`Json::encode($x, JSON_UNESCAPED_SLASHES)` inside a `<script>` leaves `</script>` intact.**
  Passing flags replaces Craft's defaults; a search term or coupon code then ends the element.
  Everything written into a script goes through `Tags::scriptJson()`, which hex-escapes `<>&`.
- **Anything mapped at render time is shared by everyone a cached page is served to.** Triggers'
  event IDs were, so every platform deduplicated all visitors into one conversion. The runtime now
  mints a UUID per firing and `rekey()`s it into the pre-mapped payloads.
- **Guzzle's `json` option re-encodes with flags `0`.** Signing `json_encode($body,
  JSON_UNESCAPED_SLASHES)` and sending the array meant the signed bytes were never the sent bytes.
  A string `body` is now sent verbatim. The old test re-derived the signature the same wrong way.
- **The shared harness's `.env` is edited by other sessions.** A run that coincides with
  `CRAFT_ALLOW_ADMIN_CHANGES` being off fails with "project config … read-only mode" and cascades.
  Re-run the suite alone before believing it.
- **`Variant::find()->status(null)->limit(2)` picks whatever the harness lists first**, which can be
  a disabled variant Commerce refuses as a line item without a word — a one-item order and a check
  that fails depending on other plugins' leftovers. Pick enabled, priced variants in a fixed order.
- **The front-end throttle is `helpers\RateLimit`** (the family's): connecting address, `X-Forwarded-For`
  only behind `trustedHosts`, IPv6 by /64, a lock, and a 20× site-wide ceiling. Until 5.0.1 it keyed on
  `getUserIP()`, so a new forwarded header was a new budget. Its window is per minute, so a test that
  bursts the limit has to start early in a minute or it straddles two and sees the limit plus some.
- **A browser-only destination writes no ledger row for a trigger** — only server-side halves do. A
  test about trigger dedupe needs a server-side destination, or it counts zero rows both times.
- **A refund's request is the administrator's, not the customer's.** Consent for it comes from the
  purchase's ledger rows (`Ledger::purchaseWasSent()`), passed to `process()` as `serverConsent`.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php  /var/www/craft-tape/tests/integration/checks.php           # 171 checks
ddev exec php  /var/www/craft-tape/tests/integration/commerce-checks.php  #  31 checks, real orders
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-tape/tests/integration/security.php  # 9: throttle over HTTP, replay dedupe, live-only variants
ddev exec bash /var/www/craft-tape/tests/integration/cp-smoke.sh          #  20 checks, every screen
ddev exec bash -c 'cd /var/www/craft-tape && vendor/bin/phpstan analyse && vendor/bin/ecs check'
```

`tests/integration/demo.php` sets up two pixels, a custom snippet and two triggers, and writes
`templates/tape-demo.twig` in the harness. `--remove` takes it away. That page is how the runtime
gets exercised in a real browser, which is the only way to test the half of the plugin that is
JavaScript — and it is how the missing `Accept` header was found.

Both check scripts clean up from a **shutdown handler**, not off the end of the script: a fatal
halfway through otherwise leaves destinations in project config and orders in the database for the
next run to trip over.

The checks worth keeping honest are the ones that assert something *cannot* happen: no platform
emits a null inside a payload, no PHP loader name lacks a JavaScript implementation, the ledger has
no customer columns, the queue payload contains no email, and every Commerce funnel step resolved to
an event that actually exists.

## Coding conventions

- `Craft::t('tape', '…')` for user-facing strings; `src/translations/en/tape.php` lists them all
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Read credentials through `Destination::setting()`, never `$destination->settings[...]`, so env vars
  resolve
