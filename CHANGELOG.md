# Release Notes for Tape

## 5.0.1 - 2026-10-06

> {warning} Behind a load balancer, proxy or CDN, set Craft's `trustedHosts` to your proxies'
> addresses. Tape's front-end endpoints now believe `X-Forwarded-For` only from proxies you've
> named, so without it every visitor shares the proxy's budget and real events get throttled.

### Security
- The anonymous `events/trigger` and `events/map` endpoints were throttled per IP, but the address
  came from `getUserIP()`, which believes `X-Forwarded-For` from anyone. A new header on each
  request was a fresh budget, so a script could still flood the queue with Conversions API sends and
  feed fake leads into an ad account. The budget is now keyed on the connecting address (forwarded
  headers only from `trustedHosts`, IPv6 grouped by /64), counted under a lock, and backed by a
  site-wide ceiling of 20 times the per-visitor limit.
- A trigger call replayed with the same event ID queued another server-side send each time. A
  firing whose event ID is already in the ledger is now answered as done, under a lock so
  simultaneous duplicates can't both get through.
- `events/map` resolved enabled variants of disabled or unpublished products, so an anonymous caller
  could read their names, SKUs and prices by guessing IDs. Lookups now need a live product as well as
  an enabled variant.

### Fixed
- The accuracy report counted every order ID in the ledger as tracked, including orders since
  deleted and conversions recorded on a different day, so it could show more than 100%. It now
  counts the completed orders in the range that have a purchase conversion, by the order's date.

## 5.0.0

Initial release.

### Tracking

- Thirteen platforms: Google Ads, Google Analytics 4, Google Tag Manager, Meta, Microsoft
  Advertising, TikTok, Pinterest, LinkedIn, Snapchat, Reddit, X, Microsoft Clarity, Hotjar, plus a
  custom-snippet destination and a webhook.
- The full GA4 ecommerce funnel, from `view_item_list` to `refund`, mapped into every platform's own
  vocabulary.
- Craft Commerce auto-tracking with no template changes, including across the redirects a payment
  gateway and a cart form both involve.
- Google Ads conversions, dynamic remarketing, Performance Max cart data, new-customer reporting and
  enhanced conversions.
- Refunds sent server-side the moment Commerce records one, full or partial, deduplicated per
  refund transaction, and only for purchases that were themselves sent.
- Custom event names, to the platforms that accept one.
- A Twig API (`craft.tape.*` and a bare `tape` global) and a JavaScript API (`tape.track()`).

### Consent

- Google Consent Mode v2: all seven signals, region defaults with `EU` and `US-PRIVACY` expansion,
  wait-for-update, URL passthrough and ads data redaction.
- Bridges for ten consent platforms plus a custom expression, and a first-party mirror cookie so
  server-side conversions honour consent as well.
- Do Not Track and Global Privacy Control, off by default.
- Per-destination consent categories. Events collected before consent are held and released when it
  is granted, rather than dropped.

### Server-side

- Conversions APIs for Meta, TikTok, Pinterest, Snapchat and Reddit; the GA4 Measurement Protocol;
  and a signed webhook.
- Browser and server halves of the same conversion share one event ID, so platforms deduplicate them
  rather than double-counting.
- Enhanced conversions and advanced matching, hashed in Craft (Pro).
- Sends go through the queue by default, and after the response when queueing is off — never during
  it.

### Accuracy

- An event ledger with a unique key per conversion, so a refreshed confirmation page, a duplicated
  webhook and a retried job all collapse into one conversion.
- The browser reports back what it actually dispatched, which distinguishes a conversion that reached
  a platform from one an ad blocker ate.
- Conversion recovery: orders whose conversion never reached anybody are sent from the server, on a
  schedule, without overriding a withheld consent.
- An accuracy report comparing orders placed against conversions tracked, per day and per
  destination (Pro).
- A per-destination health panel, and a console `doctor` that reports what is broken and what cannot
  be recovered.

### Control panel

- Destinations and triggers in project config, so tracking configuration deploys.
- A one-click test that sends a real event to a server-side API and prints the answer.
- Triggers for clicks, phone and email links, outbound links, form submissions, scroll depth,
  element visibility and time on page.
- Visitor exclusions by user group, IP range and URI pattern.
