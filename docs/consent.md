---
title: Consent
slug: consent
order: 50
summary: Consent Mode v2, ten consent platforms or an expression of your own, the mirror cookie, and region defaults.
---

Every destination waits for its consent category to be granted. Events collected before then are
held, not dropped. When the visitor accepts the banner, tracking starts on that page.

## The model

Tape uses Google Consent Mode v2's seven signals internally, even for platforms that have never
heard of it. They are the only widely published taxonomy that tells *storing* an ad identifier
apart from *sending* user data and from *personalising* ads.

A destination declares one of three **consent categories**, and loads only once the matching
signal is granted:

| Category | Waits for | Default for |
|---|---|---|
| `ads` | `ad_storage` | Google Ads, Meta, TikTok, Pinterest, LinkedIn, Snapchat, Reddit, X, Microsoft Advertising, custom snippets |
| `analytics` | `analytics_storage` | GA4, Google Tag Manager, Clarity, Hotjar, webhooks |
| `functionality` | `functionality_storage` | Nothing by default |

An answer given in categories expands to the seven signals. `ads` covers `ad_storage`,
`ad_user_data` and `ad_personalization`. `analytics` covers `analytics_storage`. `functionality`
covers `functionality_storage` and `personalization_storage`. `security_storage` is always
granted, because no consent framework treats it as a marketing choice.

### Google's tags are the exception

With Consent Mode on, Google Ads, GA4 and Google Tag Manager load *before* an answer and throttle
themselves to cookieless pings until consent is granted. That is how Consent Mode is designed to
work, and it is what lets Google model the conversions it cannot observe. Every other pixel stays
unloaded until its category is granted.

## Where the answer comes from

| `consentSource` | Meaning |
|---|---|
| `granted` (default) | No banner on this site; everything fires. Consent Mode still runs, reporting *granted*, so adding a banner later does not mean rewiring anything. |
| `denied` | Nothing fires until something calls `tape.consent.update()`. |
| `cmp` | Read from a consent management platform, set in `consentCmp`. |
| `api` | Your own banner tells Tape via `tape.consent.update()`, and Tape keeps the answer in its own cookie. |

### Consent platforms

Tape reads ten consent platforms, or an expression of your own. With `consentSource` set to `cmp`,
set `consentCmp` to one of:

| `consentCmp` | Platform |
|---|---|
| `cookiebot` | Cookiebot |
| `cookieyes` | CookieYes |
| `complianz` | Complianz |
| `iubenda` | Iubenda |
| `onetrust` | OneTrust / CookiePro |
| `osano` | Osano |
| `termly` | Termly |
| `klaro` | Klaro |
| `usercentrics` | Usercentrics |
| `civic` | Civic Cookie Control |
| `custom` | Anything else, read with [an expression of your own](#the-custom-expression) |

The runtime reads the platform's current state when the page loads and listens for its change
events. It also re-checks every half-second for the first ten seconds, for platforms that answer
only after their own script has loaded.

### Your own banner

```js
// After the visitor chooses
tape.consent.update({ ads: true, analytics: true, functionality: false });

// Or the two common buttons
tape.consent.grantAll();
tape.consent.denyAll();
```

`update()` also accepts the seven Consent Mode signals directly, if your banner already speaks in
them. Calling it updates Google's tags, writes the mirror cookie, loads every destination that has
just become allowed, and releases the events they were holding.

## The mirror cookie

The consent answer lives in the browser, where the banner is. A
[server-side conversion](/plugins/craft-tape/docs/server-side), though, is sent from PHP with no
browser involved. Whatever answer the runtime gets, it mirrors into a first-party cookie,
`tape_consent` by default. The server then reads that cookie before sending anything.

The value is seven characters after a version prefix, one per signal in Consent Mode's documented
order: `1` granted, `0` denied, `-` unknown.

```
tape_consent=v1:0001111   ; ads denied, analytics/functionality/security granted
```

It is set with `SameSite=Lax`, `Secure` on HTTPS, for `consentCookieDays` (180 by default).
Mention it in your cookie policy as a functional cookie. It holds the visitor's choice and nothing
else.

## Unknown is not denied

Every signal has three states: `granted`, `denied`, and **not yet answered**. They are treated
differently:

- **In the browser**, an unanswered destination holds its events. When the answer arrives they
  fire. If the answer is no, they never fire.
- **On the server**, unanswered counts as no. A Conversions API call cannot be taken back or
  modelled away, so it is not sent on a guess. On a real session the runtime has mirrored an answer
  long before anyone reaches checkout.
- **Recovery** never sends a conversion whose server-side half was skipped for consent. That was a
  decision, not a failure.

**Honour Do Not Track and Global Privacy Control** (off by default) treats a browser sending
`DNT: 1` or `Sec-GPC: 1` as a denial of everything except security storage, in the browser and on
the server.

## Region defaults

`consentRegionDefaults` sets the Consent Mode defaults Google's tags assume in a region before an
answer arrives. It is set in `config/tape.php`:

```php
'consentRegionDefaults' => [
    'EU' => ['ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied', 'analytics_storage' => 'denied'],
    'US-PRIVACY' => ['ad_storage' => 'denied'],
    'US-CA' => ['ad_user_data' => 'denied'],
],
```

- `EU` (or `EEA`) expands to the EEA member states plus the UK and Switzerland.
- `US-PRIVACY` (or `USP`) expands to the US states with a comprehensive privacy law.
- Anything else is passed through as an ISO country or region code: `GB`, `US-CA`.

Region defaults apply to **Google's tags only**. They are the `region` parameter of
`gtag('consent', 'default', …)`, and Google applies them by the visitor's location. Tape's own
gating of every other pixel follows `consentSource`. If you need Meta or TikTok held back in
Europe, use a consent platform rather than relying on region defaults.

**Wait for an answer** (`consentWaitForUpdate`, 500 ms) is how long Google's tags wait for an
update before going ahead as denied. **URL passthrough** and **Redact ad data** are Consent Mode's
own options and are on by default.

## The custom expression

For a banner Tape does not know, set `consentCmp` to `custom` and give a JavaScript expression
returning the current answer:

```js
window.MyBanner && window.MyBanner.ready
  ? { ads: MyBanner.allows('marketing'), analytics: MyBanner.allows('stats'), functionality: true }
  : null
```

Return `null` while the banner has not answered. That is the "unknown" state, and Tape asks again
when it next checks. If the expression throws, the error is logged and treated as no answer.

**Content-Security-Policy:** the expression is evaluated with `new Function`, so a strict CSP must
include `'unsafe-eval'` in `script-src` for it to run. Without that, the browser blocks the
evaluation and the expression never answers. Where you can, use one of the named integrations
above, which need no `eval`. For a banner of your own, call `tape.consent.update()` from its code.
Both avoid the problem completely.

The expression is written by an administrator in the control panel and is trusted at the same level
as a template. Nothing a visitor sends ever reaches it.

## Consent Mode off

With **Google Consent Mode v2** off, no consent signals are emitted and every destination loads
immediately. Only use this on a site that genuinely needs no consent.

`<noscript>` fallback pixels are written only when consent can never be in question: Consent Mode
off, or source `granted` with no region defaults. A noscript pixel fires exactly where no
JavaScript runs, so nothing can hold it back.
