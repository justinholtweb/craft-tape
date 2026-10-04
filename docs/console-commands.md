---
title: Console commands
slug: console-commands
order: 150
summary: destinations, doctor, test, recover and prune, and which belong in a deploy check or a cron.
---

One of these belongs in a cron, one after every credential change, and one in every support
conversation.

| Command | What it does |
|---|---|
| `tape/destinations` | Every destination, its platform, enabled or not, server-side or not, and any reason it cannot fire. |
| `tape/destinations/doctor` | The whole configuration: edition, destinations, Commerce wiring per funnel step, injection, consent, enhanced conversions, recovery, product ID source, problems, unrecoverable destinations, and the last 30 days' tracking rate. |
| `tape/destinations/test [--handle=…]` | Sends a real `view_item` to each server-side API and prints the answer. Exits non-zero if any fails. |
| `tape/conversions/recover [--days=N] [--limit=N]` | Finds conversions nobody received and sends them. Pro. |
| `tape/conversions/prune [--days=N]` | Trims the event log to the retention window, or to `--days`. |

## doctor

```sh
php craft tape/destinations/doctor
```

Run this first when something looks wrong. Two parts of its output catch problems nothing else
will:

- **The Commerce wiring.** Each auto-tracked funnel step and the Commerce event it is wired to. A
  step shown as **NOT WIRED** means this Commerce version has none of the event names Tape knows
  for it. That step is off, nothing errors, and the line tells you which Twig call to use instead.
- **Purchases that cannot be recovered if the browser tag is blocked**: destinations on a platform
  with no server-side API, or with server-side sending switched off.

## test

```sh
php craft tape/destinations/test
php craft tape/destinations/test --handle=meta
```

Each destination prints `ok`, `FAILED` with the status and the platform's response, or `skipped`
with the reason, such as browser-only or no token. A revoked token, a retired API version and a
pixel ID from the wrong account all look exactly like success from a browser. This is the command
that reports them. Run it after every credential change, and in a deploy check:

```sh
#!/usr/bin/env bash
set -euo pipefail

php craft tape/destinations/test    # non-zero if any server-side API refuses
```

The test sends a real event. Turn on **Test mode** on a destination if you want it to land in the
platform's test view rather than its reporting.

## recover

```sh
*/15 * * * * cd /path/to/craft && php craft tape/conversions/recover
```

Prints the orders with no conversion at all, the conversions written but never confirmed, and how
many calls it sent. `--days` overrides **Look back** for one run. `--limit` caps how many orders
one sweep considers (200 by default). It needs Pro and Commerce, and does nothing if recovery is
switched off in the settings. See [Recovery and reports](/plugins/craft-tape/docs/recovery-and-reports).

## prune

```sh
php craft tape/conversions/prune
php craft tape/conversions/prune --days=90
```

Craft's garbage collection already prunes the event log. This command is for doing it now, or to a
shorter window than the setting.
