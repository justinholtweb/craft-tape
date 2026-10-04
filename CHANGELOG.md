# Release Notes for Tape

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
