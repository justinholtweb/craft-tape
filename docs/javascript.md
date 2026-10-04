---
title: JavaScript API
slug: javascript
order: 90
summary: tape.track() for events after the page has rendered, tape.consent, tape.debug(), and the early queue.
---

For anything that happens after the page has rendered: an Ajax add-to-cart, a step in a
single-page checkout, a chat widget that collects a lead.

The runtime is one small script with no dependencies. It makes no decisions about what to send.
Every payload it fires was built in PHP by a platform adapter, and the runtime only handles what
can only be answered in a browser: consent, loading each vendor's script, and triggers.

## tape.track()

```js
tape.track('add_to_cart', { id: variantId, qty: 2 });
tape.track('view_item_list', { ids: [12, 13, 14], listId: 'related', listName: 'Related products' });
tape.track('search', { searchTerm: 'waterproof jacket' });
tape.track('generate_lead', { source: 'chat-widget' });
```

`track()` posts the event to Craft. Craft builds the payload for every destination and sends it
back, and the runtime fires it. The call returns a promise for the server's answer.

- **The event name must be one Tape accepts from a browser:** `view_item`, `view_item_list`,
  `select_item`, `search`, `add_to_wishlist`, `add_to_cart`, `remove_from_cart`, `view_cart`,
  `begin_checkout`, `add_shipping_info`, `add_payment_info`, `generate_lead`, `sign_up`, `login`,
  `contact`, `subscribe`, `scroll`, `click`, or a
  [custom event](/plugins/craft-tape/docs/twig#custom-events) name such as `brochure_download`.
  Anything else answers `400`.
- **`purchase` and `refund` are always refused.** They come from Commerce orders and nowhere else.
- **Products are identified, never priced.** Pass a variant ID as `id`, or up to 50 as `ids`, with
  an optional `qty`. Tape looks up the name and price in Commerce. A `value`, `currency` or
  `transaction_id` in the request is ignored.
- `listId`, `listName` and `searchTerm` are recognised. Up to 20 other scalar parameters are passed
  through, each at most 200 characters.

The endpoint behind `track()` is anonymous, because it has to work on a page served from a
full-page cache. It is throttled to **120 requests per IP per minute**. Above that it answers as if
no destination were configured, and Tape logs one warning. No real visitor gets anywhere near the
limit.

## tape.consent

```js
tape.consent.update({ ads: true, analytics: true, functionality: false });
tape.consent.grantAll();
tape.consent.denyAll();
tape.consent.get();   // { ad_storage: 'granted', ad_user_data: 'granted', … } — the seven signals
```

For a site with its own banner. Use `consentSource: 'api'` and call `update()` when the visitor
chooses. See [Consent](/plugins/craft-tape/docs/consent).

## tape.debug()

```js
tape.debug();   // { consent: {…}, booted: {…}, failed: {…}, held: 3 }
```

What the runtime currently believes: the consent state, which destinations have loaded, which
scripts failed to load (usually an ad blocker), and how many events are held waiting for consent.
Turn on **Debug** in the settings to have every event logged to the console as well.

## Calling it before it has loaded

The runtime loads `async`. To queue a call from an inline script that might run first:

```html
<script>
  window.tape = window.tape || [];
  tape.push(['track', 'view_item', { id: 42 }]);
</script>
```

Queued calls are replayed in order once the runtime starts. The queue takes top-level methods such
as `track`. For consent, wait for the runtime, or let a CMP integration handle it.

## Reading Tape events from a custom snippet

A [custom snippet](/plugins/craft-tape/docs/destinations#custom-snippet-pro) destination pushes
every event onto a global array, `window.tapeEvents` by default. Each entry looks like
`{ name, eventId, value, currency, items, … }`. To handle the ones already there and every one that
follows:

```html
<script>
  window.tapeEvents = window.tapeEvents || [];

  function handle(event) {
    myPixel('track', event.name, { value: event.value, currency: event.currency, dedupe: event.eventId });
  }

  window.tapeEvents.forEach(handle);
  window.tapeEvents.push = function (event) {
    handle(event);
    return Array.prototype.push.call(this, event);
  };
</script>
```

A snippet like this goes in the destination's **Head HTML**, and is held back until that
destination's consent category is granted.
