---
title: Recovery and reports
slug: recovery-and-reports
order: 100
summary: The event ledger, the accuracy report, and sending the conversions browsers lost.
---

Browser conversion tracking loses somewhere between a tenth and nearly half of real orders. The ad
platforms cannot see that loss. They only know what reached them, so the campaign simply looks worse
than it is and gets its budget cut.

## Where conversions go missing

- **Redirect payment gateways.** The customer pays on the bank's page and closes the tab instead of
  coming back. The order exists, but the confirmation page never rendered and no tag fired.
- **Ad and tracking blockers**, which a large share of a young audience uses.
- **Tabs closed early**, before the vendor script finished loading.

## The ledger

Tape records every conversion it handles in one table: one row per conversion, per destination,
per channel. The rows hold what fired and what happened to it, and nothing about the customer. See
[Privacy](/plugins/craft-tape/docs/privacy).

| Status | Means |
|---|---|
| `emitted` | Written into a page for the browser to fire. Tape knows it wrote the tag, not that an ad blocker let it run. |
| `sent` | The platform accepted it. For the browser channel, the runtime reported that the payload actually dispatched. |
| `pending` | Queued for a server-side send. |
| `failed` | The send was refused, or the vendor script never loaded. The status code and error are recorded. |
| `skipped` | Deliberately not sent: consent was denied or not given, the visitor was excluded, or the edition did not allow it. |

The channel is `browser`, `server` or `recovery`. A unique key on each row is what stops a
refreshed confirmation page, a gateway posting twice, or two concurrent requests for the same order
from sending a conversion twice.

**The browser reports back.** About two and a half seconds after a purchase's payloads have fired,
the runtime tells Craft which destinations dispatched and which scripts failed to load. It uses
`sendBeacon`, so the report still arrives if the visitor closes the tab. That is what turns
`emitted` into `sent`, and it is how Tape can tell a conversion that reached Meta from one an ad
blocker stopped.

## The event log

**Tape → Events** lists ledger rows, newest first, filterable by event, status, channel and
destination. Use it to answer "did the purchase for order 1041 go to Meta?"

## The accuracy report (Pro)

**Tape → Accuracy** shows, per day, the orders you completed against how many of those same orders
had a purchase conversion recorded, by the date of the order. You can look back up to 90 days and filter to a single destination. If it
reads 62%, that is worth more than any amount of campaign tuning.

The same screen shows each destination's health (sent, failed, emitted, pending), any destination
that is misconfigured, and any destination whose lost conversions **cannot be recovered**. That
means a platform with no server-side API, such as Google Ads, or a destination with server-side
sending switched off.

On Lite, the accuracy screen shows an upgrade note instead of the report. The ledger keeps
recording every conversion either way, so upgrading fills the report in from the day the first one
was sent.

## Recovery (Pro)

Recovery finds conversions that never reached anyone and sends them from the server. It runs two
sweeps, because the two failures look different in the ledger:

1. **Orders with no conversion at all.** The customer never reached a confirmation page, usually
   because of a redirect gateway.
2. **Conversions written but never confirmed.** The page rendered, but the runtime never reported
   back, because the tab closed or something blocked it.

Each recovered purchase goes to every destination that can take it server-side, **carrying the same
event ID the browser tag would have carried**. A platform that did receive the browser event
deduplicates the recovered one away. Over-sending is harmless, which is why the sweep does not need
to be certain before it sends.

### Running it

```sh
*/15 * * * * cd /path/to/craft && php craft tape/conversions/recover
```

Every fifteen minutes is a reasonable schedule. Users with the **Run conversion recovery**
permission can also start a sweep from the accuracy screen, which queues it. The command prints how
many orders had no conversion, how many were never confirmed, and how many calls it sent.

| Setting | Default | |
|---|---|---|
| **Wait before recovering** | 30 minutes | An order younger than this is in progress, not lost: the confirmation page may still be open. Set it too low and recovery starts racing the browser tag it exists to back up. |
| **Look back** | 3 days | At most 7. Platforms reject events much older than a week. `--days` overrides it for one run. |

Recovery needs Commerce, Pro, and at least one destination with **Also send from the server** on.

### What recovery will not do

**Recovery repairs failures. It never overrides a decision.** An order whose ledger shows a
`skipped` row, because the visitor withheld consent or was excluded, is left alone. Recovery only
acts on orders with no decision recorded at all.

## Retention

Rows for orders are kept for **Keep the event log for** (400 days by default). Everything else,
such as page views and leads, is kept for a tenth of that. Craft's garbage collection prunes the
log, and `php craft tape/conversions/prune` does it now. The ledger cannot be turned off, because
deduplication and recovery depend on it.
