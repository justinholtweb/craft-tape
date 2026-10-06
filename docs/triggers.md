---
title: Triggers
slug: triggers
order: 70
summary: Conversions from clicks, forms, scroll depth and time on page, set up from the control panel.
---

Triggers are a **Pro** feature.

"Count it as a lead when somebody clicks the phone number" is a marketing request. A developer
should not have to turn it into a ticket. **Tape → Triggers** handles it from the control panel.

## Types

| When | Fires when | Needs | Adds |
|---|---|---|---|
| **Click on an element** | An element matching the selector, or anything inside it, is clicked | CSS selector | `link_url`, `link_text` |
| **Click on a phone number** | A `tel:` link is clicked | — | `link_url`, `link_text` |
| **Click on an email address** | A `mailto:` link is clicked | — | `link_url`, `link_text` |
| **Click on a link to another site** | A link to another hostname is clicked | — | `link_url`, `link_text` |
| **Form submitted** | A form matching the selector is submitted | CSS selector | — |
| **Scrolled to a depth** | The page is scrolled past each percentage | Thresholds, e.g. `25, 50, 75, 90` | `percent_scrolled` |
| **Element scrolled into view** | Half of an element matching the selector scrolls into view | CSS selector | — |
| **Time spent on the page** | The page has been open this many seconds | Seconds | `seconds` |

Each scroll threshold fires its own event. So does each time threshold, if you give more than one.

## The rest of a trigger

- **Event**: the field suggests `generate_lead` (the default), `contact`, `sign_up`, `subscribe`,
  `login`, `search`, `scroll` and `click`. Each destination maps these to its own vocabulary:
  `generate_lead` becomes Meta's `Lead`, TikTok's `SubmitForm`, and a Google Ads conversion if you
  have given that event a conversion label. You can also type a
  [custom event](/plugins/craft-tape/docs/twig#custom-events) name, such as `brochure_download`.
- **Value** and **Currency**: fixed on the trigger. A visitor cannot change them.
- **Repeat**: *Once per session* (the default), *Once per page*, or *Every time* the condition is
  met.
- **Only on these pages**: where the trigger is live, with `*` wildcards, such as `contact` or
  `products/*`. Empty means every page.
- **Sites**: empty means every site.

## Triggers cannot fire purchases or refunds

A trigger fires on the browser's say-so. A purchase or a refund has an order behind it, and only
Commerce is allowed to announce one. A trigger whose event is `purchase` or `refund` will not save.
If one somehow reached project config anyway, the server would refuse to act on it.

## Why a visitor cannot forge one

The *condition* is checked in the browser. The *payload* is built on the server. When a page
renders, Tape maps each live trigger's event for every destination and ships the finished payloads
with the trigger. When the condition is met, the runtime fires what it was given. Nobody can talk it
into sending a conversion worth ten thousand pounds.

A destination that also sends server-side needs a call back to Craft when the trigger fires. That
endpoint is anonymous, so it is throttled to **30 requests per visitor per minute**, and 20 times
that for the whole site. Above that it answers as though no trigger were configured, and Tape logs
one warning. No real visitor gets anywhere near the limit. A firing is only acted on once: a call
repeating an event ID the ledger already holds is answered without another server-side send. See
[Troubleshooting](/plugins/craft-tape/docs/troubleshooting#front-end-endpoints-are-throttled) if
every visitor seems to share one IP address.

## Every firing gets its own event ID

A trigger's payloads are built when the page renders, and a cached page is served to many visitors.
If the event ID were fixed at render time, every one of them would carry the same ID and the
platforms would merge them into one conversion. Repeat firings on one page would merge the same
way.

So the runtime mints a **fresh event ID each time a trigger fires** and writes it into every
payload before dispatch. The server-side call is sent the same ID, so the browser tag and the
Conversions API call for that firing still deduplicate against each other. They do not deduplicate
against any other firing.

## Consent

Trigger events obey consent like everything else. A trigger that fires before the visitor has
answered is held with the rest of that destination's events and sent when consent is granted.
