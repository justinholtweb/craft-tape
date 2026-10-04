---
title: Caching
slug: caching
order: 110
summary: Why Tape is safe behind a full-page cache or CDN, and what to watch in your own templates.
---

Tape is safe behind a full-page cache. Nothing it writes into a cacheable page varies by visitor, so
a cached copy served to the next hundred people is correct for all of them.

## What makes that true

- **Consent is answered in the browser.** The page carries the site's consent *configuration*,
  never this visitor's answer. The runtime reads the answer from the mirror cookie or the CMP when
  the page loads. Browser payloads are written regardless of consent and gated by the runtime,
  because consent can be granted after the page has rendered, and a payload that was never written
  could never fire.
- **Custom snippets sit inert in a `<template>`.** Nothing inside a template element loads, runs or
  makes a request. The runtime activates the snippet once this visitor's consent allows it, so the
  cached page can carry it for everyone.
- **Matching data only appears on pages that carry a conversion.** Hashed customer data is never
  written onto a product page, where it would be cached and served to strangers. It goes only on
  pages like an order confirmation, which nobody caches.
- **Trigger events get a fresh event ID per firing**, in the browser. A trigger's payload is built
  at render time, so on a cached page every visitor would otherwise share one ID and be merged into
  a single conversion. See
  [Triggers](/plugins/craft-tape/docs/triggers#every-firing-gets-its-own-event-id).
- **No session is opened just to find out who the visitor is.** Reading the visitor's identity
  starts a session, a session sets a cookie, and a cookie on every response ends full-page caching.
  Tape only does it when a setting needs it: **Exclude logged-in users** or **Exclude these user
  groups**, and on a page that carries a conversion, which is never cached anyway.
- **GA4's *Send the Craft user ID* follows the same rule.** The user ID is written only into a page
  carrying a conversion, and onto server-side events, never into an ordinary page.
- **No cart is created to report on.** The cart helpers look for an existing cart and do nothing if
  there is none. Commerce's `getCart()` would create one and set a cookie.
- **The front-end endpoints need no CSRF token.** A token baked into a cached page is either
  invalid for everyone or shared by everyone. Instead, nothing a visitor posts can set a price or
  ask for a purchase.

## What to watch in your own templates

- `tape.consent()` and `tape.isTracking()` depend on the visitor. Branching a cached template on
  either bakes one visitor's answer into the page for everyone.
- A Twig event call inside a `cache` tag block only runs when the block is rendered. On a cache hit
  it is skipped. Keep event calls outside `cache` tags.
- Do not cache order confirmation pages. They carry the purchase, its matching data and a
  customer's details. Recovery will pick up a purchase whose page never rendered, but not one
  served from somebody else's cached copy.

## Excluded visitors on a cached site

IP and user exclusions are decided when Craft renders the page. A page rendered for an excluded
visitor has no tags, and a page rendered for anyone else has them. A full-page cache in front of
Craft serves whichever version it stored first. If your staff browse the live site through the
same cache, exclude them at the platform level as well, or skip the cache for logged-in users.
