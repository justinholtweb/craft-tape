---
title: Commerce
slug: commerce
order: 40
summary: The funnel events that are automatic, the ones that take one line of Twig, refunds, and product IDs that match your feed.
---

With Commerce installed, the events that matter most are wired to Commerce itself. You do not edit
any templates for purchases or basket changes.

## What is automatic

With **Track the Commerce funnel automatically** on (the default):

| Event | Fired from |
|---|---|
| `page_view` | Every rendered front-end page, store or not. |
| `add_to_cart` | Commerce's line-item added event. |
| `remove_from_cart` | Commerce's line-item removed event. |
| `purchase` | Commerce's order-completed event. |
| `refund` | Commerce's refund-transaction event, server-side only. See [Refunds](#refunds). |

**These events survive redirects.** A basket updated by a form post redirects, and a payment leaves
the site for the gateway. Neither request renders a page, so Tape keeps the event in the session
and fires it on the next page the customer lands on. If the customer never comes back from the
gateway, [recovery](/plugins/craft-tape/docs/recovery-and-reports) sends the purchase from the
server.

Commerce has renamed these events between major versions. Tape looks for each one under every name
it has had, and `php craft tape/destinations/doctor` prints which Commerce event each funnel step
is wired to. A step that could not be wired shows as **NOT WIRED**, with the Twig call to use
instead.

## What takes one line of Twig

Commerce has no event for "somebody looked at a product" or "somebody opened the checkout". Those
are pages, and only your templates know which ones. Each is a single call wherever it belongs:

```twig
{# product template #}
{% do craft.tape.viewItem(product.defaultVariant) %}

{# category or search listing #}
{% do craft.tape.viewItemList(products, 'summer-sale', 'Summer sale') %}

{# basket page #}
{% do craft.tape.viewCart() %}

{# checkout steps — each defaults to the current cart #}
{% do craft.tape.beginCheckout() %}
{% do craft.tape.addShippingInfo() %}
{% do craft.tape.addPaymentInfo() %}
```

The order-based calls default to the visitor's *existing* cart and do nothing if there is not one.
Tape never creates a cart to report on, because creating one sets a session cookie and makes the
page uncacheable. The full list is on the [Twig API](/plugins/craft-tape/docs/twig) page, and
[`tape.track()`](/plugins/craft-tape/docs/javascript) covers Ajax baskets and single-page
checkouts.

## Refunds

When a refund succeeds in Commerce, from the control panel, a console command or a gateway webhook,
Tape sends it server-side. There is no page involved, so it goes to destinations with a server-side
API that takes a refund: **GA4**, through the Measurement Protocol, and the **webhook**. Server-side
sending is a Pro feature.

- **A full refund** carries the order's items. **A partial refund** carries its amount and no
  items, which is how GA4 tells the two apart. Both carry the order's transaction ID.
- **Each refund is sent once.** It is deduplicated on the refund transaction, so a gateway retrying
  its webhook does not refund twice.
- **A refund follows its purchase.** If the purchase reached a platform, the refund goes too. If
  the customer withheld consent and the purchase was skipped, the refund is recorded as skipped as
  well. If Tape has no record of the purchase at all, for an order placed before it was installed
  for example, nothing is sent, because there is nothing on the platform's side to reverse. The
  administrator's own consent cookie plays no part.

Google Ads' refund mapping, a conversion adjustment, only exists in the browser, so an automatic
refund does not reach it. A `refund` can never come from a browser request or a trigger.

## What a purchase carries

- **Value**, counted by **What a purchase is worth**: the order total (`gross`, the default), the
  total minus tax and shipping (`net`), or the line items alone (`items`).
- The order's currency, its reference as the transaction ID, tax, shipping and coupon code.
- Every line item, with ID, name, price, quantity, brand and category.
- **New customer**: whether this is the customer's first completed order, matched on email so
  guest checkouts count. This drives Google's new-customer acquisition bidding.
- An **event ID derived from the order** and hashed with your security key. It is the same every
  time it is worked out, so the browser tag, the server-side call and a later recovery all
  deduplicate into one conversion. It is not the order number, so nobody can guess it.

Firing `craft.tape.purchase(order)` by hand on a confirmation page is safe even with auto-tracking
on. The ledger allows one purchase per order per destination per channel, and Tape refuses a second
purchase event for the same order within one response.

## Product IDs must match your feed

This is the setting most likely to break dynamic remarketing without anyone noticing. The
`item_id` Tape sends has to be the same identifier your Google Merchant Center, Meta catalogue or
Microsoft Shopping feed uses. If it isn't, the tag fires perfectly and matches no product at all.

| Product IDs | Sends |
|---|---|
| `sku` (default) | The variant SKU, or its ID if it has no SKU |
| `variantId` | The variant's element ID |
| `productId` | The owning product's element ID |

**Product ID prefix** is prepended to all three. Feeds built by a third-party app often look like
`shopify_GB_1234` or `craft_1234`. Open your feed, look at one item's ID, and set these two
settings so Tape produces the same string.

## Brand and category

Commerce has no brand field, and every platform wants one. Point **Brand field** at a custom field
on the product that holds it. If you leave it blank, no brand is sent.

**Category field** points at a field holding the product's category. A category field is expanded
to its full ancestor path, such as *Bikes > Road > Frames*, which is what a merchant feed carries.
If you leave it blank, Tape uses the product type name.

## Prices from the browser

When the browser reports a product event through `tape.track()`, it sends a variant ID and a
quantity and nothing else. Tape looks the price up in Commerce. Any `value` in the request is
ignored, and only enabled variants are found, so a guessed ID cannot reveal an unreleased product.
