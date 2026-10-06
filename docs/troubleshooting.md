---
title: Troubleshooting
slug: troubleshooting
order: 170
summary: Tags that do not appear, conversions that do not arrive, skipped server-side calls, and what to check first.
---

Start with the doctor. Most problems are a line in its output.

```sh
php craft tape/destinations/doctor
php craft tape/destinations/test
```

In the browser, turn on **Debug** and watch the console, or call `tape.debug()`. It shows the
consent state, which destinations loaded, which scripts failed, and how many events are held
waiting for consent.

## No Tape tags in the page at all

- **No destination can fire.** `php craft tape/destinations` lists each one with its problems. The
  most common is an ID set to `$SOME_VAR` that is empty in this environment. Tape leaves that
  destination out rather than write a tag with a blank ID.
- **You are excluded.** Logged in as a member of an excluded group, or browsing from an excluded IP.
  Try a private window on another network.
- **The page is excluded** by **Never track these pages**, or is a preview.
- **Auto-injection is off** and the layout does not output `tape.head()`.
- **The response is not HTML.** Tape only injects into `text/html` responses, and leaves a
  `feed.rss.twig` or `manifest.json.twig` alone. A page with no `</head>` gets no head tags.
- **A Pro destination on Lite** is kept but does not fire.

## The tag fires, but no conversions appear in the platform

- **Google Ads needs a conversion label** on each event, set on the destination. Without one Google
  discards the conversion.
- **LinkedIn and X need a per-event conversion ID or event ID.** An event without one is not sent.
- **The ID belongs to another account.** A browser tag cannot tell. For platforms with a
  server-side API, `tape/destinations/test` can.
- **Consent is held.** `tape.debug().held` above zero means events are waiting for a consent
  category that has not been granted.
- **Test mode is on.** Events go to the platform's test view, not its reports.
- **It is a custom event on a platform that skips them.** Google Ads, LinkedIn, X, Snapchat and
  Pinterest only count events they have an ID for. See
  [Custom events](/plugins/craft-tape/docs/twig#custom-events).

## Server-side calls are skipped

The event log shows `skipped` rows on the `server` channel. Server-side sends are consent-gated, and
on the server an *unanswered* category counts as no. Check that:

- the destination's consent category is actually granted. Look at `tape.consent.get()` in the
  browser;
- the mirror cookie (`tape_consent` by default) is present on requests to the site;
- the visitor had answered before the conversion. A brand-new session that converts on its first
  request has no answer yet.

`pending` rows that never change mean the queue is not running. Run `php craft queue/run` on a cron
or as a daemon, or turn off **Send through the queue**.

## Cart events are not tracked

Check the Commerce section of `doctor`. A step marked **NOT WIRED** means this Commerce version
publishes none of the event names Tape knows for it. Call `craft.tape.addToCart()` or
`removeFromCart()` from a template until Tape is updated. Also check **Track the Commerce funnel
automatically** is on. `view_item`, `view_cart` and the checkout steps are never automatic. See
[Commerce](/plugins/craft-tape/docs/commerce).

## Refunds are not showing up

Refunds are sent server-side only, to GA4 and webhooks, on Pro. A refund is skipped if its purchase
was skipped for consent, and nothing is sent if Tape has no record of the purchase, such as an
order placed before Tape was installed. Google Ads never receives automatic refunds. See
[Refunds](/plugins/craft-tape/docs/commerce#refunds).

## Dynamic remarketing matches no products

The product IDs Tape sends do not match your feed. Compare one item ID in Merchant Center with what
Tape sends (the Debug console shows it), then change **Product IDs** and **Product ID prefix**
until they match.

## tape.track() does nothing

- The event name is neither a standard event nor a valid
  [custom name](/plugins/craft-tape/docs/twig#custom-events), or it is `purchase` or `refund`, which
  are always refused. The request answers `400`. See
  [JavaScript API](/plugins/craft-tape/docs/javascript).
- The visitor is excluded, the event is switched off in `enabledEvents`, or no destination sends
  that event.
- The endpoint is throttled. See below.

## Front-end endpoints are throttled

The anonymous endpoints behind `tape.track()` and server-side triggers act on at most **120** and
**30** requests per visitor per minute, and 20 times that across the whole site. Over the limit they
answer as if nothing were configured, and Craft's log gets one warning a minute:

```
Tape is throttling the map endpoint.
```

No real visitor gets near those numbers. If the warning appears for ordinary traffic, **Tape is not
seeing your visitors' real IP addresses**. A visitor is their connecting IP address, with an IPv6
address counted by its /64. Behind a load balancer, reverse proxy or CDN every request arrives from
the proxy, so the whole site shares one allowance. IP exclusions are affected the same way.

Tell Craft which proxies to believe, with two general config settings:

- **`trustedHosts`**: the hosts trusted to send forwarded headers such as `X-Forwarded-For`. Tape
  only reads a forwarded address when this names specific proxies. With Craft's default of "any
  host", it ignores forwarded headers and counts the connecting address, because otherwise a visitor
  could send a new header on every request and never be throttled.
- **`ipHeaders`**: the headers Craft takes the client IP from, in order. List the header your proxy
  writes, such as `CF-Connecting-IP` for Cloudflare or `X-Forwarded-For` for most load balancers.

```php
// config/general.php
use craft\config\GeneralConfig;

return GeneralConfig::create()
    // the header your proxy or CDN puts the visitor's IP in
    ->ipHeaders(['X-Forwarded-For'])
    // only believe forwarded headers from the proxy itself
    ->trustedHosts(['10.0.0.0/8']);
```

Both can be set from the environment instead, as `CRAFT_IP_HEADERS` and `CRAFT_TRUSTED_HOSTS`. Use
the address ranges your host or CDN publishes. Afterwards, check that Craft sees real addresses: an
entry for your own IP in **Exclude these addresses** should stop Tape tracking you.

## Webhook signatures fail to verify

Almost always the receiver is computing the HMAC over a *re-encoded* body. A framework parses the
JSON, then the code serialises it again and signs that. The bytes differ even though the data is
the same, most often in escaped slashes or Unicode. Sign the raw request body exactly as received,
before any parsing. In Express that means `express.raw()` rather than `express.json()` on that
route. The header is `X-Tape-Signature: sha256=<hex>`. See
[Webhooks](/plugins/craft-tape/docs/server-side#webhooks) for working PHP and Node examples.

## Content-Security-Policy

- Tape writes two small inline scripts into `<head>`: the Consent Mode defaults and its
  configuration object. It also loads its runtime from Craft's published resources. Your
  `script-src` has to allow both, and every vendor domain your destinations load from
  (`googletagmanager.com`, `connect.facebook.net` and so on). Their `connect-src` and `img-src`
  hosts need allowing too.
- **A custom consent expression needs `'unsafe-eval'`.** It is evaluated with `new Function`, which
  a strict CSP blocks. The symptom is that consent never arrives and every non-Google destination
  stays held. Use a named CMP integration instead, or call `tape.consent.update()` from your
  banner's own code. Neither needs `eval`. See
  [the custom expression](/plugins/craft-tape/docs/consent#the-custom-expression).

## Conversions are counted twice

Tape sends the browser tag and the server call with the same event ID, and the platform
deduplicates on it. Double counting usually means a second, non-Tape copy of the same pixel: a
hard-coded snippet in the layout, a GTM tag, or another plugin. Remove the other one. Tape already
refuses a second purchase for the same order in one response.

## The accuracy report reads low

That is what it is for. Turn on [server-side sending](/plugins/craft-tape/docs/server-side) for
every destination that supports it, schedule
[recovery](/plugins/craft-tape/docs/recovery-and-reports), and check `doctor`'s list of
destinations that cannot be recovered.

## The accuracy screen shows an upgrade note

The accuracy report is a Pro feature. On Lite the ledger keeps recording, so the report fills in
from the first conversion once the site is on Pro. See [Editions](/plugins/craft-tape/docs/editions).

## The destination screens are read-only

`allowAdminChanges` is off on this environment. Destinations and triggers are project config, so
make the change in development and deploy it.

## Getting help

Email [justin@justinholt.com](mailto:justin@justinholt.com) with the output of
`php craft tape/destinations/doctor`.
