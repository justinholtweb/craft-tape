---
title: Extending Tape
slug: extending
order: 160
summary: Enrich or suppress events, register your own platform adapter, and swap the server-side transport.
---

For a module that needs to add something to every event, drop some, or teach Tape a platform it has
never heard of.

## Enriching or suppressing an event

Every event passes through `Events::EVENT_BEFORE_COLLECT` before anything platform-specific
happens, whatever it came from: Twig, Commerce, the browser or a trigger.

```php
use justinholtweb\tape\events\CollectEvent;
use justinholtweb\tape\services\Events;
use yii\base\Event;

Event::on(Events::class, Events::EVENT_BEFORE_COLLECT, function(CollectEvent $event) {
    // A parameter every destination that can carry one will receive.
    $event->trackingEvent->params['store_region'] = 'north';

    // Or drop the event entirely.
    if ($event->trackingEvent->name === 'view_item_list') {
        $event->isValid = false;
    }
});
```

## Adding a platform

Extend `justinholtweb\tape\platforms\BasePlatform` (or implement `PlatformInterface`) and register
the class:

```php
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\tape\services\Platforms;
use yii\base\Event;

Event::on(Platforms::class, Platforms::EVENT_REGISTER_PLATFORMS, function(RegisterComponentTypesEvent $event) {
    $event->types[] = MyAdNetwork::class;
});
```

A platform answers three questions and no others:

- **What needs configuring**: `settingFields()`, each with a label, instructions and optionally a
  validation pattern.
- **What script boots it**: `boot()`.
- **What an event looks like in its vocabulary**: `mapEvent()`, and `serverRequest()` if it has a
  server-side API.

Consent, deduplication, ordering, exclusions, queueing and the ledger are all handled before a
platform is asked. It only ever reads the tracking event it is handed.

`boot()` returns a `loader` name, and the browser runtime must have a loader with that name. If you
need to load a script Tape does not know, use the
[custom snippet](/plugins/craft-tape/docs/destinations#custom-snippet-pro) destination instead. It
takes your own HTML and pushes every event onto a global array for your code to read.

## Swapping the transport

Every server-side call goes through one `TransportInterface`. To route them through a proxy, or
capture them in tests:

```php
Plugin::getInstance()->dispatcher->transport = new MyProxyTransport();
```
