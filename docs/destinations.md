---
title: Destinations and platforms
slug: destinations
order: 30
summary: Every platform Tape supports, exactly which ID each needs, and where to find it.
---

A destination is a platform, its credentials, and the events it is allowed to fire. Two of the same
platform is normal: an agency pixel and a client pixel, or two brands on one install.

## The library

**Tape → Destinations** lists every destination with a seven-day health summary: sent, failed, and
emitted but not yet confirmed. Every destination has these settings, whatever the platform:

| Setting | What it does |
|---|---|
| **Name**, **Handle** | The handle identifies the destination in the event log and in `only:` filters. Visitors never see it. |
| **Enabled** | Off keeps the configuration and fires nothing. |
| **Consent category** | `ads`, `analytics` or `functionality`: which consent signal must be granted before this destination loads. Each platform has a sensible default. See [Consent](/plugins/craft-tape/docs/consent). |
| **Events** | Every event the platform understands, or a chosen subset. "Only purchases go to LinkedIn" is a common request. Some platforms add a per-event field here, such as a Google Ads conversion label. |
| **Also send from the server** (Pro) | Sends each event again through the platform's Conversions API, with the same event ID. See [Server-side](/plugins/craft-tape/docs/server-side). |
| **Server only** | Drops the browser tag and keeps only the server call. Match quality goes down without click IDs and browser context, so only use it if you have a specific reason. |
| **Sites** | Leave all unticked for every site. |
| **Test mode**, **Test event code** | Sends the platform's debug flag where it has one, so events land in its test view instead of its reporting. Meta, TikTok and Snapchat each issue a test event code in their events manager. |

Fields are checked against each platform's ID format when you save. `AW-1234` typed where
`AW-123456789` belongs would load, run, throw nothing and report zero conversions forever. Save
time is the only point at which that mistake can be caught.

## Project config and environment variables

Destinations and triggers live in project config under `tape.destinations.<uid>` and
`tape.triggers.<uid>`, not in a database table. Staging and production want the same events sent
to different accounts, and project config plus environment variables is exactly that.

Any credential field accepts an environment variable reference:

```sh
# .env
META_PIXEL_ID=123456789012345
META_CAPI_TOKEN=EAAB...
GOOGLE_ADS_ID=AW-123456789
```

then enter `$META_PIXEL_ID` in the field. Use this for every access token: a token typed straight
into the field ends up in `project.yaml`, and so in your repository.

A destination whose required ID resolves to blank in the current environment is **left out of the
page completely**. Tape does not write a tag with an empty ID that reports nothing.
`php craft tape/destinations` lists it with the reason.

On an environment with `allowAdminChanges` off, the destination and trigger screens are read-only,
like sections and fields. Tracking changes should arrive by deployment.

## Platforms

| Platform | Browser | Server-side | Default consent | Edition |
|---|---|---|---|---|
| [Google Ads](#google-ads) | ✅ | — | ads | Lite |
| [Google Analytics 4](#google-analytics-4) | ✅ | Measurement Protocol | analytics | Lite |
| [Google Tag Manager](#google-tag-manager) | ✅ | — | analytics | Lite |
| [Meta](#meta-facebook-and-instagram) | ✅ | Conversions API | ads | Lite |
| [Microsoft Clarity](#microsoft-clarity) | ✅ | — | analytics | Lite |
| [Hotjar](#hotjar) | ✅ | — | analytics | Lite |
| [Microsoft Advertising](#microsoft-advertising-pro) | ✅ | — | ads | Pro |
| [TikTok](#tiktok-pro) | ✅ | Events API | ads | Pro |
| [Pinterest](#pinterest-pro) | ✅ | Conversions API | ads | Pro |
| [LinkedIn](#linkedin-pro) | ✅ | — | ads | Pro |
| [Snapchat](#snapchat-pro) | ✅ | Conversions API | ads | Pro |
| [Reddit](#reddit-pro) | ✅ | Conversions API | ads | Pro |
| [X (Twitter)](#x-twitter-pro) | ✅ | — | ads | Pro |
| [Custom snippet](#custom-snippet-pro) | ✅ | — | ads | Pro |
| [Webhook](#webhook-pro) | — | ✅ | analytics | Pro |

Server-side sending itself is a Pro feature, so on Lite even Meta and GA4 send from the browser
only.

### Google Ads

Conversion actions, dynamic remarketing, cart data for Performance Max, and enhanced conversions.

- **Conversion ID** (required): Google Ads → Goals → Conversions → your action → Tag setup. Starts
  with `AW-`. A `G-` ID belongs in a GA4 destination, and `GT-` or `GTM-` in Google Tag Manager.
- **Conversion label**, per event: from the conversion action's tag setup, the part after the
  slash. **Google Ads discards a conversion that has no label**, so set one on every event you want
  counted, `purchase` above all.
- **Dynamic remarketing** (on by default): sends product IDs with funnel events. Needs a Merchant
  Center feed using the same IDs. See
  [product IDs](/plugins/craft-tape/docs/commerce#product-ids-must-match-your-feed).
- **Merchant Center ID**, **Feed country**, **Feed language**: enable cart data on the purchase
  conversion, which Performance Max uses to bid on profit rather than revenue.
- **Business vertical**: which dynamic remarketing feed type the account uses. Retail by default.

Google Ads has no server-side API in Tape, so a purchase lost to an ad blocker cannot be recovered
to it. `tape/destinations/doctor` lists it under "cannot be recovered".

### Google Analytics 4

Enhanced ecommerce reporting and the full checkout funnel.

- **Measurement ID** (required): Admin → Data streams → your web stream. Starts with `G-`. `UA-` IDs
  are Universal Analytics, which stopped collecting data in 2023.
- **Measurement Protocol API secret**: only needed for server-side events. Admin → Data streams →
  Measurement Protocol API secrets.
- **Send the Craft user ID**: sets GA4's `user_id` for logged-in visitors on pages that carry a
  conversion, and on server-side events, so purchases join up across devices. It is never written
  into an ordinary page, which might be cached and served to somebody else.

### Google Tag Manager

Loads a container and gives it a complete GA4-shaped data layer to read.

- **Container ID** (required): `GTM-XXXXXXX`.
- **Server container URL**: if you run server-side tagging, the first-party URL your container is
  served from, such as `https://gtm.example.com`. Leave blank to load from Google.
- **Data layer name**: only change it if something else on the site already owns `dataLayer`.
- **Environment parameters**: the `gtm_auth=…&gtm_preview=…&gtm_cookies_win=x` string from a GTM
  environment snippet, if you use environments.

### Meta (Facebook and Instagram)

- **Pixel ID** (required): Events Manager → Data sources → your pixel. Fifteen or sixteen digits.
- **Conversions API access token**: only needed for server-side events. Events Manager → Settings →
  Conversions API → Generate access token. Keep it in an environment variable.
- **Graph API version**: Meta retires a version roughly two years after release. Bump it when
  Events Manager starts warning you.
- **Advanced matching** (on by default, Pro): sends a hashed email, phone and name with the pixel
  so Meta can match conversions to accounts. Hashing happens in Craft. Nothing appears in the page
  source unhashed.

### Microsoft Clarity

- **Project ID** (required): Clarity → Settings → Overview. Ten lowercase characters.

Every event except the page view is sent as a Clarity custom event, and a purchase also sets
`order_value` and `order_currency`, so recordings can be filtered by them.

### Hotjar

- **Site ID** (required): Hotjar → Settings → Sites & organizations. Seven digits.

### Microsoft Advertising (Pro)

Bing UET.

- **UET tag ID** (required): Tools → UET tag. Eight or nine digits.
- **Merchant store ID**: only needed if you run Microsoft Shopping campaigns and want product IDs
  matched to your feed.

### TikTok (Pro)

- **Pixel ID** (required): TikTok Ads Manager → Assets → Events → Web events. A twenty-character
  code.
- **Events API access token**: only needed for server-side events. Generated alongside the pixel,
  under Events API.

### Pinterest (Pro)

- **Tag ID** (required): Ads → Conversions → Pinterest tag. Thirteen digits.
- **Ad account ID** and **Conversions API access token**: only needed for server-side events.

### LinkedIn (Pro)

- **Partner ID** (required): Campaign Manager → Analyze → Insight Tag. Six or seven digits.
- **Conversion ID**, per event: the numeric ID of the conversion action in Campaign Manager. **An
  event with no conversion ID is not sent**. The Insight Tag itself still loads for retargeting.

### Snapchat (Pro)

- **Pixel ID** (required): Ads Manager → Events Manager. A UUID.
- **Conversions API token**: only needed for server-side events.

### Reddit (Pro)

- **Pixel ID** (required): Ads → Events Manager. Starts with `a2_` or `t2_`.
- **Ad account ID**: only needed for server-side events. Starts with `t2_`.
- **Conversions API token**: only needed for server-side events.

### X (Twitter) (Pro)

- **Pixel ID** (required): Ads Manager → Tools → Events manager. A short alphanumeric code.
- **Event ID**, per event: the `tw-…-…` code from the event's snippet, or just the part after the
  pixel ID. Create one conversion event in Ads Manager per event you want counted.

### Custom snippet (Pro)

Any pixel Tape has no adapter for.

- **Head HTML**: written into `<head>`, after Tape's consent signals and before its runtime.
- **Body HTML**: written just before `</body>`.
- **Event queue**: every Tape event is pushed onto `window.<name>` (default `tapeEvents`) as
  `{name, eventId, value, currency, items, …}`. Your snippet can read the array on load and push a
  listener onto it afterwards. See
  [the JavaScript API](/plugins/craft-tape/docs/javascript#reading-tape-events-from-a-custom-snippet).

When consent is in play, the snippet sits inside an inert `<template>` and does not run until this
destination's consent category is granted. See [Caching](/plugins/craft-tape/docs/caching).

### Webhook (Pro)

Posts each event to a URL of yours as JSON: a warehouse, a CRM, an automation, or a platform Tape
has no adapter for. Server-side only.

- **Endpoint** (required): an HTTPS URL. Use an environment variable so it can differ between
  staging and production.
- **Signing secret**: if set, every request carries an `X-Tape-Signature` header.
- **Extra header**: one more request header, as `Name: value`, for an API key the receiver expects.
- **Include customer data**: adds a `user` object to the body with a SHA-256 email and phone, the
  Craft user ID, the IP address, the user agent and any ad click IDs. Off unless the receiver needs
  it.

The body format and how to verify the signature are in
[Server-side and webhooks](/plugins/craft-tape/docs/server-side#webhooks).

## A platform that is not listed

Use a custom snippet for a browser pixel, or a webhook for anything server-side. To add a
first-class adapter from your own module, see [Extending Tape](/plugins/craft-tape/docs/extending).
