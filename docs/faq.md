---
title: FAQ
slug: faq
order: 180
summary: Common questions about conversion tracking, consent and Commerce with Tape.
---

## Do I need Craft Commerce?

No. Commerce is optional. Without it you get page views, custom events, form and click conversions
and everything else. With it you get the whole checkout funnel, wired to Commerce's own events with
no template changes, and refunds tracked automatically. See
[Commerce](/plugins/craft-tape/docs/commerce).

## Why not paste each platform's snippet into my layout?

You can, and it will fire page views. The work is everything after that: mapping each funnel step
into thirteen different vocabularies, holding events until consent arrives, sending the same
purchase from the server with an ID the platform can match, and noticing when an order was never
reported at all. That is what Tape does from one screen.

## If the browser and the server both send a purchase, won't it be counted twice?

Not when both carry the same event ID, and in Tape they always do, because they are the same event.
The ID for an order is derived from the order itself, so the browser tag, the Conversions API call
and a later recovery all carry the same one. Platforms deduplicate on it. See
[Server-side](/plugins/craft-tape/docs/server-side#deduplication-one-event-one-id).

## What does recovery send, and when?

Run `php craft tape/conversions/recover` on a schedule; every fifteen minutes is typical. It finds
orders whose conversion never reached a destination and sends them from the server, with the same
event ID the browser tag would have used. It leaves alone any order with a skipped row, including
one whose visitor withheld consent. Recovery and the accuracy report are Pro. See
[Recovery and reports](/plugins/craft-tape/docs/recovery-and-reports).

## Does it work with my cookie banner?

Tape reads ten consent platforms, or an expression of your own: Cookiebot, CookieYes, Complianz,
Iubenda, OneTrust/CookiePro, Osano, Termly, Klaro, Usercentrics and Civic Cookie Control. With your
own banner, call `tape.consent.update({ ads: true, analytics: false })` when the visitor answers.
See [Consent](/plugins/craft-tape/docs/consent).

## What happens to events before the visitor answers the banner?

They are held in the page. "Not yet answered" is its own state, so a destination waiting on consent
queues its events and fires them if the visitor accepts, on the same page. If the visitor declines,
the tag gives up and they are never sent. Google's tags are the exception: under Consent Mode they
load first and throttle themselves.

## Can I track events that aren't on the standard list?

Yes. Any lowercase name of letters, digits and underscores, starting with a letter and at most
40 characters, is a custom event, from Twig, `tape.track()` or a trigger. Names starting `ga_`,
`google_` or `firebase_` are refused. GA4, GTM, Meta, Microsoft Advertising, Clarity, Hotjar, the
custom snippet and the webhook take them, and TikTok and Reddit in the browser. Google Ads,
LinkedIn, X, Snapchat and Pinterest skip them. See
[Custom events](/plugins/craft-tape/docs/twig#custom-events).

## Are refunds tracked?

Yes, on Pro, automatically from Commerce's refund transaction event. They go server-side to GA4,
through the Measurement Protocol, and to webhooks. A full refund carries the items; a partial one
carries only the amount. A refund is only sent if its purchase was. Google Ads' refund mapping is
browser-only, so automatic refunds do not reach it. See
[Refunds](/plugins/craft-tape/docs/commerce#refunds).

## Can a visitor fake a conversion?

Not a valuable one. Payloads are built on the server, and the endpoint behind `tape.track()`
refuses `purchase` and `refund` outright and ignores any posted value. A visitor can say which
product; the price comes from Commerce, looked up from the product ID.

## Will it work behind a full-page cache?

Yes. Nothing Tape writes into a page varies by visitor. Consent is read from a cookie by the
runtime, custom snippets sit inside a `<template>` until consent allows them, and matching data and
GA4's user ID appear only on pages that carry a conversion, which are not cached. See
[Caching](/plugins/craft-tape/docs/caching).

## What does Tape store about my customers?

Nothing in its own table. The event ledger records what fired, per destination and channel, with no
email, no hashes and no payloads. Queue jobs carry an order ID and re-derive what they need from
the order. Matching data for enhanced conversions (Pro) is hashed in Craft and never written to the
page in the clear. See [Privacy](/plugins/craft-tape/docs/privacy).

## How do I know a pixel is actually working?

`php craft tape/destinations/test` sends a real event to every server-side API and prints each
answer. It exists because a revoked token, a retired API version or somebody else's pixel ID all
look like success from the browser: the tag fires, nothing errors, and nothing appears in the
platform's reporting. In the browser, `tape.debug()` shows what the runtime believes about consent
and which tags booted.

## Can I add a platform Tape doesn't list?

Two ways. On Pro, the custom snippet destination takes your own HTML and pushes every event onto a
JavaScript array for it to read. Or write a platform class in PHP and register it with
`EVENT_REGISTER_PLATFORMS`; it answers what needs configuring, what script boots it, and how an
event maps into its vocabulary, and nothing else needs wiring. See
[Extending Tape](/plugins/craft-tape/docs/extending).

## What are the requirements?

Craft CMS 5.3 or later and PHP 8.2 or later. Craft Commerce is optional. No PHP dependencies beyond
Craft, and no build step.

## Is Tape free?

Lite is, and it is not a trial: Google Ads, GA4, GTM, Meta, Clarity and Hotjar, the whole Commerce
funnel, Consent Mode v2 and the event log, for nothing. Pro is a one-off $99 with a $59/year
renewal.

## What is the difference between Lite and Pro?

Pro adds seven more platforms, custom snippets and webhooks, server-side tracking (including
refunds), enhanced conversions and advanced matching, the accuracy report, conversion recovery,
and triggers. See [Editions](/plugins/craft-tape/docs/editions) for the full table.

## What happens when the renewal lapses?

Pro keeps working. Renewals buy updates, the way every Craft plugin licence does. If a site is ever
switched back to Lite, nothing breaks: Pro destinations stop firing, their configuration is kept,
and switching back to Pro restores them.
