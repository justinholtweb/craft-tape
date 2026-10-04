---
title: Installation
slug: installation
order: 10
summary: Requirements, install, your first destination, and checking it fires.
---

Tape puts every pixel on one screen, wires the checkout funnel behind them, puts consent in front
of them, and keeps a ledger underneath that knows which conversions never arrived.

Installing it takes two commands. Nothing is sent anywhere until you add a destination, so
installing Tape changes nothing on its own.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- Craft Commerce is optional. Without it you get page views, custom events, form and click
  conversions and everything else. With it you also get the checkout funnel.

There is no build step and no dependency beyond Craft itself. The front-end runtime is a single
small script with no dependencies of its own.

## Install

```sh
composer require justinholtweb/craft-tape
php craft plugin/install tape
```

Or install it from the control panel: **Settings → Plugins**, search for *Tape*, and install.

Lite is free. Pro is a one-off **$99** with a **$59/year** renewal. See
[Editions](/plugins/craft-tape/docs/editions) for what Pro adds.

## Add a destination

1. Open **Tape → Destinations → New destination**.
2. Pick a platform: Google Ads, GA4, Meta and so on.
3. Paste in its ID. Each field says exactly where to find it, and
   [Destinations and platforms](/plugins/craft-tape/docs/destinations) lists them all.
4. Save.

That is the whole setup for a browser pixel. Tape writes its tags into every front-end page
automatically, just before `</head>`. If you would rather place them yourself, see
[Twig API](/plugins/craft-tape/docs/twig).

Destinations are stored in project config. Put account IDs and tokens in environment variables
(`$META_PIXEL_ID`) rather than typing them in, so staging and production can share one
configuration with different accounts behind it.

## Check it worked

```sh
php craft tape/destinations/doctor
```

prints the edition, how many destinations can fire, how the Commerce funnel is wired, the consent
setup, and any problems. For a destination with a server-side API, also run:

```sh
php craft tape/destinations/test
```

which sends a real event and prints the platform's answer. In the browser, turn on **Debug** in
Tape's settings and every event appears in the console as it fires. You can also call
`tape.debug()` from the console at any time.

## Where to go next

If you run a Commerce store, read [Commerce](/plugins/craft-tape/docs/commerce) next, then
[Recovery and reports](/plugins/craft-tape/docs/recovery-and-reports). The accuracy report is where
you find out how many of your orders the ad platforms never heard about.

## How it works, in one paragraph

A Twig call, a Commerce order, an Ajax request, a click on a trigger and a recovery sweep all
produce the same thing: one **tracking event**, with one event ID. Each destination's platform
adapter turns that event into its own vocabulary on the server: `fbq('track', 'Purchase', …)` for
Meta, `gtag('event', 'purchase', …)` for GA4. The browser runtime only fires what it is handed.
That arrangement does three things:

- **The browser tag and the server-side call are the same event with the same ID**, so a platform
  that receives both deduplicates them instead of counting two.
- **A visitor cannot decide what gets sent.** Payloads are built in PHP, prices come from Commerce,
  and the browser can never ask for a purchase.
- **A site with no store loses nothing but the funnel.** Page views, leads, triggers and custom
  events work without Commerce.

## Rules Tape keeps

- **A tracking tag never causes a 500.** Every front-end hook is wrapped; a mapping that throws is
  logged and sends nothing.
- **Nothing blocks a response.** Server-side sends go through the queue, or run after the response
  has been sent if queueing is off.
- **Unknown consent is not denied consent.** It is a third state, and it decides whether a tag
  waits or gives up.
- **Recovery repairs failures and never overrides a decision.** An order whose visitor withheld
  consent is left alone.
- **Nothing that varies per visitor goes into a cacheable page.**

## Before you go live

- **Decide where consent comes from.** The default is *Granted*, which suits a site with no consent
  banner. If you have a banner, see [Consent](/plugins/craft-tape/docs/consent).
- **Exclude yourself.** Add your office IPs or a staff user group under **Who is not tracked** so
  your own test orders stay out of the ad accounts.
- **On a store, check the product ID source.** It has to match your Merchant Center or catalogue
  feed. See [Commerce](/plugins/craft-tape/docs/commerce#product-ids-must-match-your-feed).

## Uninstalling

```sh
php craft plugin/uninstall tape
```

This drops the event log table and removes the `tape.destinations` and `tape.triggers` keys from
project config, so reinstalling later starts clean instead of bringing back old pixels.
