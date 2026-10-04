---
title: Permissions
slug: permissions
order: 140
summary: Viewing reports, editing destinations and triggers, running recovery, and allowAdminChanges.
---

Three permissions, under **Tape** in each user group's settings.

| Permission | Allows |
|---|---|
| **View destinations and reports** | The event log, the accuracy report, and destination settings, read-only. |
| → **Add and edit destinations and triggers** | Creating, changing, reordering and deleting them. |
| → **Run conversion recovery** | Starting a recovery sweep from the accuracy screen. |

The two arrowed permissions are nested under the first, so a user needs **View destinations and
reports** before either can be granted.

## Why viewing and editing are separate

These screens hold access tokens for advertising accounts. "Can see whether the pixel fired" is a
much smaller permission than "can change where conversions are sent". A marketer checking the
accuracy report does not need the second one.

## allowAdminChanges

Destinations and triggers are project config. On an environment with `allowAdminChanges` off,
typically production, their screens are read-only for everyone, admins included, exactly like
sections and fields. Make tracking changes in development and deploy them.

The plugin settings follow Craft's usual rule: only admins can change them, and only where admin
changes are allowed. Use `config/tape.php` for anything that has to differ in production.
