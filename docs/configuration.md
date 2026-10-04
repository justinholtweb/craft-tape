---
title: Configuration
slug: configuration
order: 20
summary: Every setting, per-environment overrides in config/tape.php, and environment variables.
---

Settings are for things that are true of the whole site: what currency, what counts as revenue,
who is staff, where consent comes from. Anything a single pixel might want to do differently is set
on its [destination](/plugins/craft-tape/docs/destinations).

None of the settings is required. With the defaults, a fresh install tracks page views to whatever
destinations you add, with Consent Mode on and consent treated as granted.

## The settings

Under **Tape → Settings**. The config key for each is in the second column.

### Delivery

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Add the tags automatically** | `autoInject` | On | Writes Tape's tags before `</head>` and `</body>` on every HTML response. Off means your templates place them. See [Twig API](/plugins/craft-tape/docs/twig). |
| **Never track these pages** | `excludeUris` | None | URI patterns Tape never injects into. `*` is a wildcard. |

### What gets tracked

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Page views** | `trackPageViews` | On | A `page_view` on every rendered front-end page. |
| **Track the Commerce funnel automatically** | `autoTrackCommerce` | On | Wires purchases, cart changes and refunds to Commerce's own events. See [Commerce](/plugins/craft-tape/docs/commerce). |
| **What a purchase is worth** | `valueBasis` | `gross` | `gross`: order total with tax and shipping. `net`: minus tax and shipping. `items`: line items only. |
| **Product IDs** | `productIdSource` | `sku` | `sku`, `variantId` or `productId`. Must match your merchant feed. |
| **Product ID prefix** | `productIdPrefix` | None | Prepended to every product ID, for feeds built by a third party. |
| **Brand field** | `brandField` | None | A custom field handle on the product that holds the brand. |
| **Category field** | `categoryField` | Product type | A custom field handle holding the product category. A category field is expanded to its full path. |
| **Default currency** | `defaultCurrency` | None | For events with no order behind them, such as a lead. Three uppercase letters. Blank falls back to `USD`. |
| *Config file only* | `enabledEvents` | All on | A global switchboard, e.g. `['view_item_list' => false]`. Turning an event off here turns it off for every destination. An event not listed is on. |
| *Config file only* | `googleBusinessVertical` | `retail` | The dynamic remarketing vertical sent with each item when a Google Ads destination does not set its own. |

### Consent

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Google Consent Mode v2** | `consentMode` | On | Emits the seven Consent Mode signals and gates every destination on its consent category. |
| **Where the answer comes from** | `consentSource` | `granted` | `granted`, `denied`, `cmp` or `api`. See [Consent](/plugins/craft-tape/docs/consent). |
| **Consent platform** | `consentCmp` | None | Which CMP to read when the source is `cmp`, or `custom`. |
| **Custom expression** | `consentExpression` | None | A JavaScript expression returning the consent state. Only used with `custom`. |
| **Consent cookie** | `consentCookieName` | `tape_consent` | The first-party cookie the runtime mirrors consent into. |
| *Config file only* | `consentCookieDays` | 180 | How long that cookie lasts, 1–400 days. |
| *Config file only* | `consentRegionDefaults` | None | Per-region default states. See [region defaults](/plugins/craft-tape/docs/consent#region-defaults). |
| **Wait for an answer** | `consentWaitForUpdate` | 500 | Milliseconds Google's tags wait for a consent update before running as denied. |
| **URL passthrough** | `urlPassthrough` | On | Passes ad click IDs through the URL when cookies are denied. |
| **Redact ad data** | `adsDataRedaction` | On | Redacts ad click identifiers in requests while `ad_storage` is denied. |
| **Honour Do Not Track and Global Privacy Control** | `respectDoNotTrack` | Off | Treats `DNT: 1` or `Sec-GPC: 1` as a denial of everything except security storage. |

### Who is not tracked

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Exclude logged-in users** | `excludeLoggedIn` | Off | Off because on a membership site the logged-in visitors *are* the customers. You probably want the next setting instead. |
| **Exclude these user groups** | `excludeUserGroups` | None | User group handles whose members are never tracked: staff, the agency. |
| **Exclude these addresses** | `excludeIps` | None | IPs and CIDR ranges, IPv4 or IPv6: the office, the QA runner. |

Previews, Live Preview and the control panel are never tracked. Excluding by user or group means
reading who the visitor is, which opens a session. Tape only does that when one of these two
settings is in use. See [Caching](/plugins/craft-tape/docs/caching).

### Server-side (Pro)

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Send through the queue** | `queueServerSide` | On | Off sends after the response has been flushed instead. Never inline. |
| **Timeout** | `serverSideTimeout` | 8 | Seconds, 1–60. |
| **Enhanced conversions and advanced matching** | `enhancedConversions` | On | Hashes and sends customer data with conversions. Pro only: on Lite nothing is hashed or sent, whatever this says. See [Privacy](/plugins/craft-tape/docs/privacy). |
| **Also send the billing address** | `enhancedConversionsAddress` | Off | Google's format sends street, city, region and postcode **unhashed** in the page source. Off unless you choose it. |
| **Country calling code** | `phoneCountryCode` | None | Assumed for phone numbers that have none: `1`, `44`, `61`. A phone number hashed without its country code matches nothing, and no error tells you so. |

### Recovery (Pro)

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Recover missed conversions** | `recoveryEnabled` | On | See [Recovery and reports](/plugins/craft-tape/docs/recovery-and-reports). |
| **Wait before recovering** | `recoveryDelayMinutes` | 30 | How long after an order completes before an untracked purchase counts as missed. |
| **Look back** | `recoveryLookbackDays` | 3 | 1–7 days. Platforms reject events much older than a week. |

### Housekeeping

| Setting | Key | Default | What it does |
|---|---|---|---|
| **Keep the event log for** | `ledgerRetentionDays` | 400 | Days, minimum 7. Order rows are kept for the full window; other rows for a tenth of it. |
| **Debug** | `debug` | Off | Logs every event Tape builds to the browser console and to Craft's log. |

## config/tape.php

Any setting can be overridden per environment in `config/tape.php`. Craft's multi-environment form
works as usual:

```php
<?php

use craft\helpers\App;

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
        'enabledEvents' => ['view_item_list' => false],
    ],
    'dev' => [
        'debug' => true,
        'autoInject' => false,
    ],
    'production' => [
        'excludeIps' => array_filter([App::env('OFFICE_IP')]),
    ],
];
```

## Environment variables

Settings belong in `config/tape.php`, where `App::env()` reads anything you need from the
environment. **Credentials belong on destinations**, which accept `$VARIABLE` references in every
field and resolve them at runtime. That way `project.yaml` can be committed with no secrets in it.
See [Destinations](/plugins/craft-tape/docs/destinations#project-config-and-environment-variables).
