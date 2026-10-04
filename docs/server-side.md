---
title: Server-side and webhooks
slug: server-side
order: 60
summary: Conversions APIs, the queue, deduplication on a shared event ID, and signed webhooks.
---

Server-side sending is a **Pro** feature.

It sends a second copy of each conversion from Craft. Ad blockers cannot stop it, and closing a tab
cannot lose it. The platform counts it once.

## Which platforms

| Platform | API | Needs |
|---|---|---|
| Meta | Conversions API | Access token |
| Google Analytics 4 | Measurement Protocol | API secret |
| TikTok | Events API | Access token |
| Pinterest | Conversions API | Ad account ID and access token |
| Snapchat | Conversions API | Token |
| Reddit | Conversions API | Ad account ID and token |
| Webhook | Your URL | Endpoint, optionally a signing secret |

Google Ads, Microsoft Advertising, LinkedIn, X, Clarity, Hotjar and Google Tag Manager are
browser-only. Where to find each credential is on
[Destinations and platforms](/plugins/craft-tape/docs/destinations).

## Turning it on

1. Add the platform's token to the destination, as an environment variable reference.
2. Switch on **Also send from the server**.
3. Run `php craft tape/destinations/test --handle=meta`, and check the platform answered `ok`.

The test command matters more than anything else on this page. A revoked token, a retired API
version and a pixel ID belonging to another account all look exactly like success from the
browser. The tag fires, nothing errors, and no conversion ever appears in the platform's reports.
One round trip with the answer printed is the only way to know.

## Deduplication: one event, one ID

The browser tag and the server call are built from **the same tracking event with the same event
ID**. Meta, TikTok and the other platforms deduplicate on that ID, so a conversion that arrives both
ways is counted once. If the browser half was blocked, the server half still arrives and is
counted.

- **A purchase** gets an ID derived from the order. A refresh of the confirmation page, a second
  gateway webhook and a later [recovery](/plugins/craft-tape/docs/recovery-and-reports) all
  produce the same ID.
- **A trigger** gets a fresh ID in the browser every time it fires. The server half is told that
  ID, so the pair still deduplicates. See
  [Triggers](/plugins/craft-tape/docs/triggers#every-firing-gets-its-own-event-id).

Tape's own ledger also has a unique key per conversion, per destination, per channel. A conversion
is claimed by inserting its row, so two concurrent requests for the same order cannot both send.

## The queue

Server-side sends **never happen inline**. A checkout should not wait on somebody else's API.

- With **Send through the queue** on (the default), each send is a queue job. Make sure your queue
  actually runs: `php craft queue/run` on a cron, or a daemon.
- With it off, sends run after the response has been flushed to the browser, so the visitor still
  waits for nothing.
- Jobs carry identifiers, not identities. An order-backed job stores the order ID and rebuilds the
  event from the order when it runs, so no email address sits in the queue table.
- Every send is recorded in the event log with its status code and any error. **Timeout**
  (8 seconds by default) caps each call.

## Consent and matching

Server-side sends **are** consent-gated. The server reads the visitor's answer from the
[mirror cookie](/plugins/craft-tape/docs/consent#the-mirror-cookie), and an unanswered or denied
category means the send is skipped and logged as `skipped`.

With **Enhanced conversions and advanced matching** on, each call carries SHA-256 hashes of the
customer's email, phone and name, normalised the way each platform documents, plus the ad click IDs
the visitor arrived with (`gclid`, `fbclid`, `ttclid`, `msclkid` and others). A server-side
conversion without a click ID matches much less often than one with, even when it carries an email
address.

## Webhooks

A webhook destination posts every event it fires to your URL as JSON, from the server, through the
same queue.

### The body

```json
{
  "event": "purchase",
  "eventId": "9f2c1e7a-4b0d-8e3f-a1c2-5d6e7f8a9b0c",
  "occurredAt": "2026-10-04T09:41:00+00:00",
  "source": "server",
  "value": 86.5,
  "currency": "GBP",
  "transactionId": "A1B2C3",
  "tax": 14.42,
  "shipping": 4.95,
  "coupon": null,
  "isNewCustomer": true,
  "orderId": 1041,
  "elementId": null,
  "siteId": 1,
  "items": [
    { "id": "TEE-RED-M", "sku": "TEE-RED-M", "name": "Tee", "categories": ["Clothing", "Tops"], "price": 27.18, "quantity": 3, "index": 1 }
  ],
  "params": {}
}
```

`source` is `server`, `browser` or `recovery`. With **Include customer data** on, a `user` object
is added, holding `emailSha256`, `phoneSha256`, `userId`, `ip`, `userAgent` and `clickIds` where
they are known. Use `eventId` as your idempotency key: a recovered purchase arrives with the same
ID as the original.

### Verifying the signature

With a **Signing secret** set, every request carries:

```
X-Tape-Signature: sha256=<hex HMAC-SHA256 of the raw request body, keyed with the secret>
```

Tape encodes the JSON once, signs those bytes, and sends exactly those bytes. Compute the HMAC over
the **raw request body as received**. Do not parse it and re-encode it first. A parser and encoder
can change the bytes without changing the meaning, for example by escaping slashes or Unicode
differently. The signature then fails on any event containing a URL or a non-ASCII product name.
Compare with a constant-time function, not `==`.

**PHP**

```php
<?php
$raw = file_get_contents('php://input');            // the exact bytes, before any decoding
$secret = getenv('TAPE_WEBHOOK_SECRET');

$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
$given = $_SERVER['HTTP_X_TAPE_SIGNATURE'] ?? '';

if (!hash_equals($expected, $given)) {
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
// … handle it, keyed on $event['eventId']
http_response_code(204);
```

**Node (Express)**

```js
const crypto = require('node:crypto');
const express = require('express');
const app = express();

// express.raw, not express.json: the signature is over the bytes, not the parsed object
app.post('/hooks/tape', express.raw({ type: 'application/json' }), (req, res) => {
  const expected = Buffer.from(
    'sha256=' + crypto.createHmac('sha256', process.env.TAPE_WEBHOOK_SECRET).update(req.body).digest('hex')
  );
  const given = Buffer.from(req.get('X-Tape-Signature') || '');

  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) {
    return res.sendStatus(401);
  }

  const event = JSON.parse(req.body.toString('utf8'));
  // … handle it, keyed on event.eventId
  res.sendStatus(204);
});
```

Answer with any 2xx to accept. Anything else is recorded as `failed` in the event log, with the
status code.

**Extra header** adds one more header, such as `Authorization: Bearer …`, for a receiver that
expects an API key. The signature is still the stronger check: it proves the body was not changed
on the way.
