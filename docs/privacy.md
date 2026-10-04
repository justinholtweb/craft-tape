---
title: Privacy
slug: privacy
order: 120
summary: What Tape stores, what it hashes, what it sends, and what it never writes down.
---

A tracking plugin should be specific about what it stores and what it sends.

## What Tape stores

- **The event ledger holds nothing about the customer.** No email, no hashes, no payloads. A row
  records the event name, its ID, the destination, the channel, the status, and for a conversion
  the order ID, value, currency and transaction ID. It records what fired, not who it fired for.
- **The queue carries identifiers, not identities.** An order-backed job stores the order ID and
  rebuilds everything from the order when it runs. A job with no order behind it carries a copy of
  the event with the customer data removed. Ad click IDs travel with the job, because they belong
  to the request and cannot be recovered from the order later.
- **One cookie**, `tape_consent`, holding the visitor's consent choice as seven flags. Nothing
  else. See [the mirror cookie](/plugins/craft-tape/docs/consent#the-mirror-cookie).
- Session storage, under keys beginning `tape.t.`, to remember which *once per session* triggers
  have fired.

## Matching data

Enhanced conversions and advanced matching are a **Pro** feature. With **Enhanced conversions and
advanced matching** on, conversions carry the customer's email, phone and name so platforms can
match them to an account:

- **Hashed in Craft** with SHA-256, after normalising each value the way each platform documents.
  A hash is built to be sent and then dropped. It is never stored.
- **Never written into a page in the clear.**
- **Only put on a page that carries a conversion.** Never on a product page, where it could be
  cached and served to other visitors.
- Sent server-side only if the visitor's consent allows that destination.

On Lite, no matching data is hashed or sent, whatever the setting says.

### The billing address

Google's enhanced conversions format sends the street, city, region and postcode **unhashed**.
Turning on **Also send the billing address** therefore writes a customer's home address into the
page source of their order confirmation. Match rates improve a little. Tape leaves it off unless
you explicitly turn it on, and the setting explains the trade-off.

## What each server-side call contains

Besides the event and its items: the hashed matching fields above, the visitor's IP address and
user agent, the page URL, and any ad click identifiers and platform cookies (`_fbp`, `_ga`, `_ttp`
and similar) the visitor already has. These are what make a server-side conversion matchable. Each
platform takes the subset it documents.

A webhook with **Include customer data** on also receives the IP address and user agent unhashed.
Leave it off unless the receiver needs them.

## Who is not tracked

- The control panel, previews and Live Preview, always.
- IPs and CIDR ranges in **Exclude these addresses**.
- Members of **Exclude these user groups**, or every logged-in user with **Exclude logged-in
  users**.
- With **Honour Do Not Track and Global Privacy Control** on, visitors sending `DNT: 1` or
  `Sec-GPC: 1` are treated as having denied everything except security storage.

## Your privacy notice

Tape sends data to whichever platforms you configure, and each of them is a data processor or
controller in its own right. List them in your privacy notice and your consent banner.
`php craft tape/destinations` gives you the current list.
