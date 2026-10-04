---
title: Twig API
slug: twig
order: 80
summary: Placing the tags, firing any event with craft.tape.track(), custom events, and the Commerce funnel helpers.
---

You do not need any of it. With Commerce installed and auto-tracking on, the funnel works with no
template changes. This page is for sites that do something unusual, and for events Commerce has no
opinion about.

Everything is available as `craft.tape` and as a bare `tape` global. The two are the same object.
Every event method returns nothing, so call it with Twig's `do` tag.

## Placing the tags

```twig
<head>
    …
    {{ tape.head() }}   {# consent defaults, custom snippets, the config and the runtime #}
</head>
<body>
    …
    {{ tape.body() }}   {# <noscript> pixels and custom body HTML #}
</body>
```

You only need these if **Add the tags automatically** is off, or if you want the tags somewhere
other than just before `</head>` and `</body>`. Placing either one by hand stops Tape injecting
that half for the current response, so you can opt a single layout out without changing the
setting.

## Any event

```twig
{% do craft.tape.track('generate_lead', { value: 50, currency: 'GBP', source: 'brochure' }) %}

{# only to some destinations, by handle #}
{% do craft.tape.track('sign_up', { only: ['metaMain', 'ga4'] }) %}
```

These keys are lifted onto the event itself: `value`, `currency`, `transactionId`, `coupon`,
`listId`, `listName`, `searchTerm`, `items`, and `only` (an array of destination handles). Anything
else, like `source` above, travels as an extra parameter to the destinations that can carry one.

Each destination sends the events its platform understands, mapped to that platform's own name:

- **Site events:** `page_view`, `generate_lead`, `sign_up`, `login`, `contact`, `subscribe`,
  `search`, `scroll`, `click`
- **Funnel events:** `view_item`, `view_item_list`, `select_item`, `add_to_wishlist`,
  `add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`,
  `add_payment_info`, `purchase`, `refund`

Not every platform takes every event. LinkedIn and X, for example, only send events you have given
a conversion ID.

## Custom events

```twig
{% do craft.tape.track('brochure_download', { format: 'pdf' }) %}
```

Any other name goes out as a custom event to GA4, Google Tag Manager, Meta (as a custom event),
Microsoft Advertising, Clarity, Hotjar, the custom snippet and the webhook, and TikTok and Reddit
in the browser. Google Ads, LinkedIn, X, Snapchat and Pinterest count conversions against IDs set
up in their own dashboards, so they have nowhere to put a name they have never seen, and they skip
it.

A custom name is lowercase letters, numbers and underscores, starts with a letter, and is at most
40 characters, which is GA4's limit. Names starting with `ga_`, `google_` or `firebase_` are
reserved by Google and refused. A destination limited to a list of events only sends a custom one
if it is on that list.

Custom names are accepted from Twig, from [`tape.track()`](/plugins/craft-tape/docs/javascript)
and from [triggers](/plugins/craft-tape/docs/triggers).

## The Commerce funnel by hand

```twig
{% do craft.tape.viewItem(variant) %}
{% do craft.tape.viewItemList(products, 'summer-sale', 'Summer sale') %}
{% do craft.tape.selectItem(variant, 'summer-sale', 'Summer sale') %}
{% do craft.tape.addToCart(variant, 2) %}
{% do craft.tape.removeFromCart(variant, 1) %}
{% do craft.tape.addToWishlist(variant) %}
{% do craft.tape.viewCart() %}
{% do craft.tape.beginCheckout() %}
{% do craft.tape.addShippingInfo() %}
{% do craft.tape.addPaymentInfo() %}
{% do craft.tape.purchase(order) %}
{% do craft.tape.search('waterproof jacket') %}
{% do craft.tape.pageView(entry) %}
```

| Method | Takes |
|---|---|
| `viewItem(variant, params)` | A variant or a product |
| `viewItemList(variants, listId, listName, params)` | Any iterable of variants or products |
| `selectItem(variant, listId, listName, params)` | The item chosen from a list |
| `addToCart(variant, qty, params)`, `removeFromCart(…)` | Already automatic with auto-tracking on |
| `addToWishlist(variant, params)` | A variant or a product |
| `viewCart(order)`, `beginCheckout(order)`, `addShippingInfo(order)`, `addPaymentInfo(order)` | An order. Defaults to the visitor's existing cart and does nothing if there is none. |
| `purchase(order)` | A completed order. Already automatic. |
| `search(term, params)` | The search query |
| `pageView(element)` | Already automatic while **Page views** is on |

`purchase()` is safe to call even with auto-tracking on. Tape refuses a second purchase for the
same order in one response, and the ledger's unique key drops any later attempt at the same order.

With Commerce not installed, the order-based methods do nothing.

## Inspecting

```twig
{{ tape.isTracking() }}         {# false for excluded visitors, previews and the control panel #}
{{ tape.destinations()|length }} {# destinations able to fire on this site #}
{{ tape.consent().isGranted('ad_storage') }}
```

Be careful with `consent()` and `isTracking()` on a cached page. Both depend on the visitor, and a
full-page cache serves one visitor's answer to everyone. `consent()` also only knows what the
mirror cookie said on this request. See [Caching](/plugins/craft-tape/docs/caching).
