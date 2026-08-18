# Tape

**Conversion tracking for Craft CMS, wired up properly.**

Tape puts Google Ads, GA4, Meta, TikTok and a dozen other pixels on your Craft site — with the full
ecommerce funnel behind them, Consent Mode v2 in front of them, and server-side tracking underneath
so the conversions your customers' browsers lose still get counted.

It is the plugin the WooCommerce world calls a pixel manager, for Craft. If you have moved a store
off WooCommerce and miss having every platform's events set up correctly from one screen, this is
that screen.

---

## Requirements

Craft CMS 5.3+ and PHP 8.2+. Craft Commerce is optional — without it you get page views, custom
events, form and click conversions and everything else; with it you get the whole checkout funnel.

## Installation

```sh
composer require justinholtweb/craft-tape
php craft plugin/install tape
```

Then **Tape → Destinations → New destination**, pick a platform, paste in the ID. That is the whole
setup for a browser pixel.

---

## What it tracks

| | |
|---|---|
| Every page | `page_view` |
| Product pages | `view_item` |
| Listings and search | `view_item_list`, `select_item`, `search` |
| The basket | `add_to_cart`, `remove_from_cart`, `view_cart`, `add_to_wishlist` |
| Checkout | `begin_checkout`, `add_shipping_info`, `add_payment_info` |
| The money | `purchase`, `refund` |
| Whatever else you want | any event name, from Twig, JavaScript, or a trigger |

With Commerce installed, all of the ecommerce events are wired to Commerce's own events. **No
template changes at all** — including across a redirect, so a basket updated by a form post still
reports the right event on the page the customer lands on.

## Where it sends

| Platform | Browser | Server-side | Edition |
|---|---|---|---|
| Google Ads — conversions, dynamic remarketing, cart data, enhanced conversions | ✓ | | Lite |
| Google Analytics 4 — enhanced ecommerce | ✓ | Measurement Protocol | Lite |
| Google Tag Manager — container plus a real data layer | ✓ | | Lite |
| Meta (Facebook and Instagram) | ✓ | Conversions API | Lite |
| Microsoft Clarity | ✓ | | Lite |
| Hotjar | ✓ | | Lite |
| Microsoft Advertising (Bing UET) | ✓ | | Pro |
| TikTok | ✓ | Events API | Pro |
| Pinterest | ✓ | Conversions API | Pro |
| LinkedIn | ✓ | | Pro |
| Snapchat | ✓ | Conversions API | Pro |
| Reddit | ✓ | Conversions API | Pro |
| X (Twitter) | ✓ | | Pro |
| Any other pixel — your own HTML plus a JavaScript event queue | ✓ | | Pro |
| Webhook — the event as JSON, to a URL of yours | | ✓ | Pro |

Two of the same platform is normal and supported: an agency pixel and a client pixel, two brands on
one install.

---

## The bit that pays for itself

Browser conversion tracking loses somewhere between a tenth and nearly half of real orders. It
happens for three completely ordinary reasons:

- **Redirect payment gateways.** The customer pays at the bank's page, the order completes, and they
  close the tab instead of coming back. The order exists. The confirmation page never rendered. No
  tag ever fired.
- **Ad and tracking blockers**, which is most of a young audience.
- **Tabs closed early**, before the vendor script finished loading.

None of that loss is visible from inside Google Ads or Meta — they only know what reached them, so
the campaign simply looks worse than it is and gets its budget cut.

Tape keeps a ledger of every conversion and what became of it, and the browser reports back when it
has actually dispatched one. That gives two things nothing else can:

**The accuracy report.** Orders you took, against conversions anybody was told about, per day. If it
reads 62%, that is worth more than any amount of campaign tuning.

**Recovery.** Orders whose conversion never reached anyone are sent again from the server, carrying
the same event ID the browser tag would have carried — so a platform that *did* get it deduplicates
rather than double-counting. Put it on a schedule:

```
*/15 * * * * cd /path/to/craft && php craft tape/conversions/recover
```

Recovery repairs failures. It does not override decisions: an order whose visitor withheld consent
is left alone.

---

## Consent

Google Consent Mode v2 is on by default, with all seven signals, region defaults, URL passthrough
and ads data redaction.

Tape reads the answer from your consent platform — Cookiebot, CookieYes, Complianz, Iubenda,
OneTrust, Osano, Termly, Klaro, Usercentrics, Civic, or a JavaScript expression of your own — and
**mirrors it into a first-party cookie**, which is what lets server-side conversions honour consent
too. If you have your own banner, one call is enough:

```js
tape.consent.update({ ads: true, analytics: true, functionality: false });
```

Every destination declares a consent category, and its pixel does not load until that category is
granted. Events collected before then are held, not dropped — so accepting a banner starts tracking
immediately rather than on the next page. Google's own tags are the deliberate exception: under
Consent Mode they load first and throttle themselves, which is what makes conversion modelling work.

Three states, not two: `granted`, `denied` and **not yet answered**, which is a different thing and
is treated as one.

---

## Triggers

"Count it as a lead when somebody clicks the phone number" is a marketing request, not a development
ticket. **Tape → Triggers** answers it from the control panel:

- a click on any CSS selector
- a click on a phone number, an email address, or a link to another site
- a form submission
- scroll depth — `25, 50, 75, 90`, each firing its own event
- an element scrolling into view
- time spent on the page

The condition is evaluated in the browser. The *payload* is not: Tape builds each trigger's event
for every destination when the page renders, so a visitor cannot talk it into sending a conversion
worth ten thousand pounds.

---

## Templates

Nothing is required. With Commerce installed and auto-tracking on, the funnel works out of the box.
Everything below is for when a site does something unusual, or for events Commerce has no opinion
about.

```twig
{# Any event, with anything on it #}
{% do craft.tape.track('generate_lead', { value: 50, currency: 'GBP' }) %}

{# The funnel, by hand #}
{% do craft.tape.viewItem(variant) %}
{% do craft.tape.viewItemList(products, 'summer-sale', 'Summer sale') %}
{% do craft.tape.addToCart(variant, 2) %}
{% do craft.tape.purchase(order) %}

{# Place the tags yourself instead of having them injected #}
{{ tape.head() }}
...
{{ tape.body() }}
```

And from JavaScript, for anything that happens after the page has rendered:

```js
tape.track('add_to_cart', { id: variantId, qty: 2 });
```

The payload is always built on the server, because that is where the platform adapters live — and
because it is the only arrangement in which a visitor cannot dictate what gets sent. The endpoint
refuses `purchase` and `refund` outright, and ignores any value in the request: prices come from
Commerce, looked up from a product ID.

---

## Editions

| | Lite (free) | Pro |
|---|---|---|
| Google Ads, GA4, GTM, Meta, Clarity, Hotjar | ✓ | ✓ |
| Full ecommerce funnel and Commerce auto-tracking | ✓ | ✓ |
| Consent Mode v2 and CMP integration | ✓ | ✓ |
| Order deduplication and the event log | ✓ | ✓ |
| Twig and JavaScript event APIs | ✓ | ✓ |
| TikTok, Pinterest, LinkedIn, Snapchat, Reddit, X, Microsoft Ads | | ✓ |
| Custom snippets and webhooks | | ✓ |
| Server-side tracking (Conversions API, Measurement Protocol) | | ✓ |
| Enhanced conversions and advanced matching | | ✓ |
| Conversion recovery and the accuracy report | | ✓ |
| Triggers | | ✓ |

A licence that lapses breaks nothing. Pro destinations stop firing and their configuration is left
untouched, so renewing restores exactly what was there.

---

## Privacy

Worth stating plainly, because a tracking plugin should be specific about this:

- **The event ledger holds nothing about the customer.** No email, no hashes, no payloads. It records
  what fired, not who it fired for.
- **Matching data is hashed in Craft**, never written to the page in the clear, and only ever put on
  a page that carries a conversion — never on a product page, where it could be cached and served to
  the next hundred visitors.
- **The queue carries identifiers, not identities.** An order-backed job stores an order ID and
  re-derives everything when it runs.
- Google's enhanced-conversions format sends the postal address *unhashed*. Tape leaves that off
  unless you explicitly ask for it, and says so on the switch.

---

## Diagnosing it

```sh
php craft tape/destinations            # what is configured, and what is broken
php craft tape/destinations/doctor     # the whole picture, including Commerce wiring
php craft tape/destinations/test       # send a real event to every server-side API
php craft tape/conversions/recover     # look for missed conversions now
php craft tape/conversions/prune       # trim the event log
```

`test` is the one that matters. A revoked access token, a retired API version and a pixel ID
belonging to somebody else's account all look *exactly* like success from a browser: the tag fires,
nothing errors, and no conversion ever appears in the platform's reporting. One round trip with the
answer printed is worth more than any amount of validation.

## Caching

Tape is safe behind a full-page cache. Nothing it writes into a page varies by visitor: the consent
answer is read from a cookie by the runtime rather than baked in, custom snippets sit inert inside a
`<template>` until consent allows them, and matching data appears only on pages that carry a
conversion — which are the pages nobody caches.

---

## Licence

See [LICENSE.md](LICENSE.md). © Justin Holt.
