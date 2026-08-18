<?php

namespace justinholtweb\tape\twig;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;
use Twig\Markup;

/**
 * `craft.tape` — and the global `tape`.
 *
 * Two kinds of method here. {@see head()} and {@see body()} place the tags by hand for anybody who
 * would rather not have them injected. Everything else queues an event, and every one of those
 * returns `null` so it can be called with `{% do %}` or interpolated harmlessly:
 * `{{ craft.tape.viewItem(variant) }}` renders nothing, which is what you want in the middle of a
 * product template.
 *
 * With Commerce installed and auto-tracking left on, none of the funnel methods are necessary —
 * they exist for stores whose checkout does something unusual, and for the events Commerce has no
 * opinion about.
 */
class TapeVariable
{
    /** The `<head>` tags, placed by hand. Suppresses auto-injection for this response. */
    public function head(): Markup
    {
        return new Markup(Plugin::getInstance()->tags->getHeadHtml(), Craft::$app->charset);
    }

    /** The `<noscript>` pixels and any custom body HTML, placed by hand. */
    public function body(): Markup
    {
        return new Markup(Plugin::getInstance()->tags->getBodyHtml(), Craft::$app->charset);
    }

    /** Whether this visitor is being tracked at all. */
    public function isTracking(): bool
    {
        return Plugin::getInstance()->events->shouldTrack();
    }

    /** @return Destination[] */
    public function destinations(): array
    {
        return Plugin::getInstance()->destinations->getActiveDestinations();
    }

    public function consent(): Consent
    {
        return Plugin::getInstance()->consent->resolve();
    }

    // ── Events ──────────────────────────────────────────────────────────────────────────────

    /**
     * Any event, with any parameters.
     *
     * `{% do craft.tape.track('generate_lead', { value: 50, currency: 'GBP' }) %}`
     *
     * Recognised keys — `value`, `currency`, `transactionId`, `coupon`, `listId`, `listName`,
     * `searchTerm`, `items`, `only` — are lifted onto the event; anything else travels as an extra
     * parameter to whichever destinations can carry one.
     */
    public function track(string $name, array $params = []): void
    {
        Plugin::getInstance()->events->track($name, $params);
    }

    public function pageView(?ElementInterface $element = null): void
    {
        Plugin::getInstance()->events->pageView($element);
    }

    public function search(string $term, array $params = []): void
    {
        $this->track(TrackingEvent::SEARCH, $params + ['searchTerm' => $term]);
    }

    /** @param mixed $variant A Commerce variant or product. */
    public function viewItem(mixed $variant, array $params = []): void
    {
        $this->itemEvent(TrackingEvent::VIEW_ITEM, [$variant], $params);
    }

    /**
     * A product listing.
     *
     * `{% do craft.tape.viewItemList(products, 'summer-sale', 'Summer sale') %}`
     *
     * @param iterable<mixed> $variants
     */
    public function viewItemList(iterable $variants, ?string $listId = null, ?string $listName = null, array $params = []): void
    {
        $this->itemEvent(TrackingEvent::VIEW_ITEM_LIST, $variants, $params + array_filter([
            'listId' => $listId,
            'listName' => $listName,
        ]));
    }

    public function selectItem(mixed $variant, ?string $listId = null, ?string $listName = null, array $params = []): void
    {
        $this->itemEvent(TrackingEvent::SELECT_ITEM, [$variant], $params + array_filter([
            'listId' => $listId,
            'listName' => $listName,
        ]));
    }

    public function addToCart(mixed $variant, int $quantity = 1, array $params = []): void
    {
        $this->itemEvent(TrackingEvent::ADD_TO_CART, [$variant], $params, $quantity);
    }

    public function removeFromCart(mixed $variant, int $quantity = 1, array $params = []): void
    {
        $this->itemEvent(TrackingEvent::REMOVE_FROM_CART, [$variant], $params, $quantity);
    }

    public function addToWishlist(mixed $variant, array $params = []): void
    {
        $this->itemEvent(TrackingEvent::ADD_TO_WISHLIST, [$variant], $params);
    }

    /** @param mixed $order A Commerce order. Defaults to the current cart, if there already is one. */
    public function viewCart(mixed $order = null): void
    {
        $this->orderEvent(TrackingEvent::VIEW_CART, $order);
    }

    public function beginCheckout(mixed $order = null): void
    {
        $this->orderEvent(TrackingEvent::BEGIN_CHECKOUT, $order);
    }

    public function addShippingInfo(mixed $order = null): void
    {
        $this->orderEvent(TrackingEvent::ADD_SHIPPING_INFO, $order);
    }

    public function addPaymentInfo(mixed $order = null): void
    {
        $this->orderEvent(TrackingEvent::ADD_PAYMENT_INFO, $order);
    }

    /**
     * The purchase.
     *
     * Safe to call even with auto-tracking on: the ledger's unique key means the second attempt at
     * the same order is dropped rather than doubled, and Tape refuses two purchase events in one
     * response outright.
     */
    public function purchase(mixed $order): void
    {
        $this->orderEvent(TrackingEvent::PURCHASE, $order);
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    private function orderEvent(string $name, mixed $order): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->commerce->isInstalled()) {
            return;
        }

        $order ??= $plugin->commerce->getExistingCart();

        if ($order === null) {
            return;
        }

        $plugin->events->collect($plugin->commerce->eventFromOrder($order, $name));
    }

    /** @param iterable<mixed> $variants */
    private function itemEvent(string $name, iterable $variants, array $params, int $quantity = 1): void
    {
        $plugin = Plugin::getInstance();
        $items = [];
        $index = 1;

        foreach ($variants as $variant) {
            $item = $variant instanceof EventItem
                ? $variant
                : ($plugin->commerce->isInstalled() ? $plugin->commerce->itemFromVariant($variant, $quantity) : null);

            if ($item !== null) {
                $item->index ??= $index++;
                $item->listId ??= $params['listId'] ?? null;
                $item->listName ??= $params['listName'] ?? null;
                $items[] = $item;
            }
        }

        if ($items === []) {
            return;
        }

        $plugin->events->track($name, $params + ['items' => $items]);
    }
}
