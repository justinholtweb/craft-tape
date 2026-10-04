<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\Settings;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\UserData;
use justinholtweb\tape\Plugin;
use Throwable;

/**
 * Everything that knows Craft Commerce exists.
 *
 * Tape works perfectly well on a site with no store — page views, form conversions, triggers and
 * custom events need nothing from Commerce — so Commerce is confined to this one class and reached
 * only through {@see isInstalled()}. Nothing else in the plugin imports a Commerce class, which is
 * what makes “does this work without Commerce?” a question with a one-word answer.
 *
 * The class is written defensively about Commerce's own API surface as well: event constants are
 * checked with `defined()` before being subscribed to, because a store that upgrades Commerce
 * should get degraded tracking at worst, never a fatal on every page.
 */
class Commerce extends Component
{
    private ?bool $installed = null;

    public function isInstalled(): bool
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        return $this->installed = class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    // ── Building items ──────────────────────────────────────────────────────────────────────

    /**
     * The identifier that goes in `item_id`.
     *
     * This is the single most consequential setting in the plugin and the one that most often
     * silently breaks dynamic remarketing: it has to be the *same* identifier the Merchant Center
     * or Meta catalogue feed uses, and there is no way for Tape to find that out. A mismatch
     * produces a tag that fires perfectly and matches no product at all.
     */
    public function productId(mixed $variant): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $id = match ($settings->productIdSource) {
            Settings::ID_VARIANT_ID => (string)$variant->id,
            Settings::ID_PRODUCT_ID => (string)($variant->getOwner()->id ?? $variant->id),
            default => (string)($variant->sku ?: $variant->id),
        };

        return ($settings->productIdPrefix ?? '') . $id;
    }

    /** @param mixed $variant A `craft\commerce\elements\Variant`. */
    public function itemFromVariant(mixed $variant, int $quantity = 1, ?float $price = null): ?EventItem
    {
        if ($variant === null) {
            return null;
        }

        $product = $this->owningProduct($variant);

        $item = new EventItem();
        $item->id = $this->productId($variant);
        $item->sku = $variant->sku ?: null;
        $item->name = $product?->title ?: $variant->title;
        $item->variant = ($product && $variant->title !== $product->title) ? $variant->title : null;
        $item->price = $price ?? (float)($variant->price ?? 0);
        $item->quantity = max(1, $quantity);
        $item->elementId = $variant->id;
        $item->brand = $this->customFieldValue($product ?? $variant, Plugin::getInstance()->getSettings()->brandField);
        $item->categories = $this->categories($product ?? $variant);
        $item->googleBusinessVertical = Plugin::getInstance()->getSettings()->googleBusinessVertical;

        return $item;
    }

    /** @param mixed $lineItem A `craft\commerce\models\LineItem`. */
    public function itemFromLineItem(mixed $lineItem): ?EventItem
    {
        $purchasable = $lineItem->getPurchasable();

        if ($purchasable === null) {
            return null;
        }

        $item = $this->itemFromVariant($purchasable, (int)$lineItem->qty, (float)$lineItem->price);

        if ($item === null) {
            return null;
        }

        // The *sale* price is what the customer paid, and it is what a platform's ROAS should be
        // computed against. `price` on the purchasable is the list price and is often not it.
        $item->price = (float)($lineItem->salePrice ?? $lineItem->price);
        $discount = (float)($lineItem->price ?? 0) - (float)($lineItem->salePrice ?? $lineItem->price ?? 0);
        $item->discount = $discount > 0 ? round($discount, 4) : null;

        return $item;
    }

    /**
     * @param mixed $order A `craft\commerce\elements\Order`.
     * @return EventItem[]
     */
    public function itemsFromOrder(mixed $order): array
    {
        $items = [];

        foreach ($order->getLineItems() as $index => $lineItem) {
            $item = $this->itemFromLineItem($lineItem);

            if ($item !== null) {
                $item->index = $index + 1;
                $items[] = $item;
            }
        }

        return $items;
    }

    // ── Building events ─────────────────────────────────────────────────────────────────────

    /**
     * A funnel event for an order or cart.
     *
     * @param mixed $order A `craft\commerce\elements\Order`.
     */
    public function eventFromOrder(mixed $order, string $name): TrackingEvent
    {
        $settings = Plugin::getInstance()->getSettings();

        $event = new TrackingEvent([
            'name' => $name,
            'items' => $this->itemsFromOrder($order),
            'currency' => $this->currency($order),
            'orderId' => (int)$order->id,
            'siteId' => (int)($order->orderSiteId ?? $order->siteId ?? Craft::$app->getSites()->getCurrentSite()->id),
            'coupon' => $order->couponCode ?: null,
        ]);

        // A conversion's event ID has to be stable across the browser tag, the server-side call and
        // any later recovery of the same order. Deriving it from the order rather than minting a
        // UUID is what makes those three collapse into one conversion instead of three.
        if ($name === TrackingEvent::PURCHASE) {
            $event->eventId = $this->conversionEventId($order, $name);
        }

        $tax = (float)($order->getTotalTax() ?? 0);
        $shipping = (float)($order->getTotalShippingCost() ?? 0);

        if (in_array($name, [TrackingEvent::PURCHASE, TrackingEvent::REFUND], true)) {
            $event->transactionId = (string)($order->reference ?: $order->number);
            $event->tax = round($tax, 4);
            $event->shipping = round($shipping, 4);
        }

        $event->value = match ($settings->valueBasis) {
            Settings::VALUE_NET => round((float)$order->getTotalPrice() - $tax - $shipping, 4),
            Settings::VALUE_ITEMS => round((float)$order->getItemSubtotal(), 4),
            default => round((float)$order->getTotalPrice(), 4),
        };

        if ($name === TrackingEvent::ADD_PAYMENT_INFO) {
            $event->paymentType = $this->paymentType($order);
        }

        if ($name === TrackingEvent::ADD_SHIPPING_INFO) {
            $event->shippingTier = $this->shippingTier($order);
        }

        if ($name === TrackingEvent::PURCHASE) {
            $event->isNewCustomer = $this->isNewCustomer($order);
        }

        $event->userData = $this->userDataFromOrder($order);

        return $event;
    }

    /**
     * A conversion ID that is the same every time it is derived for this order.
     *
     * Not the order number: order numbers are sometimes sequential, and a conversion ID that can be
     * guessed is one somebody else can send. Hashed with the site's security key, so it is stable
     * within an installation and meaningless outside it.
     */
    public function conversionEventId(mixed $order, string $name): string
    {
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;
        $hash = hash('sha256', $key . '|tape|' . $name . '|' . $order->id . '|' . ($order->uid ?? ''));

        // Formatted as a UUID because several platforms validate the shape of the field.
        return implode('-', [
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        ]);
    }

    /** @param mixed $order A `craft\commerce\elements\Order`. */
    public function userDataFromOrder(mixed $order): UserData
    {
        $user = new UserData();
        $user->email = $order->getEmail() ?: null;
        $user->userId = $order->getCustomer()?->id;

        $address = $order->getBillingAddress() ?? $order->getShippingAddress();

        if ($address !== null) {
            $user->firstName = $address->firstName ?: null;
            $user->lastName = $address->lastName ?: null;
            $user->street = $address->addressLine1 ?: null;
            $user->city = $address->locality ?: null;
            $user->region = $address->administrativeArea ?: null;
            $user->postalCode = $address->postalCode ?: null;
            $user->country = $address->countryCode ?: null;
            $user->phone = $this->addressPhone($address) ?? $user->phone;
        }

        return $user;
    }

    /**
     * The visitor's live cart, if there already is one.
     *
     * Never `getCart()`, which *creates* a cart and writes a session cookie. On a page that has no
     * cart yet — every product page for a first-time visitor — that would turn a cacheable response
     * into an uncacheable one, on every page, for the sake of an event with nothing in it.
     *
     * @return mixed A `craft\commerce\elements\Order`, or null.
     */
    public function getExistingCart(): mixed
    {
        if (!$this->isInstalled()) {
            return null;
        }

        try {
            $carts = \craft\commerce\Plugin::getInstance()->getCarts();
            $number = $carts->getSessionCartNumber();

            if (!$number) {
                return null;
            }

            return \craft\commerce\elements\Order::find()->number($number)->isCompleted(false)->one();
        } catch (Throwable $e) {
            Craft::warning('Could not read the Commerce cart: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /** @param mixed $order A `craft\commerce\elements\Order`. */
    public function currency(mixed $order): string
    {
        foreach (['currency', 'paymentCurrency'] as $attribute) {
            $value = $order->$attribute ?? null;

            if (is_string($value) && strlen($value) === 3) {
                return strtoupper($value);
            }
        }

        return Plugin::getInstance()->getSettings()->defaultCurrency ?: 'USD';
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    /**
     * Whether this is the customer's first completed order.
     *
     * Google Shopping's new-customer acquisition bidding is the reason to bother, and it is the
     * highest-value optional parameter on a purchase event for anybody running Performance Max.
     * Matched on email rather than user ID, because most stores take guest orders.
     */
    private function isNewCustomer(mixed $order): ?bool
    {
        $email = $order->getEmail();

        if (!$email) {
            return null;
        }

        try {
            $earlier = (new Query())
                ->from(['{{%commerce_orders}}'])
                ->where(['isCompleted' => true, 'email' => $email])
                ->andWhere(['<', 'id', (int)$order->id])
                ->exists();

            return !$earlier;
        } catch (Throwable) {
            return null;
        }
    }

    private function paymentType(mixed $order): ?string
    {
        try {
            return $order->getGateway()?->name ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function shippingTier(mixed $order): ?string
    {
        try {
            return $order->getShippingMethod()?->getName() ?: ($order->shippingMethodName ?: null);
        } catch (Throwable) {
            return $order->shippingMethodName ?: null;
        }
    }

    private function addressPhone(mixed $address): ?string
    {
        foreach (['phone', 'phoneNumber'] as $handle) {
            try {
                $value = $address->getFieldValue($handle);

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            } catch (Throwable) {
                // Address field layouts are per-site configuration; a missing phone field is normal.
            }
        }

        return null;
    }

    private function owningProduct(mixed $variant): mixed
    {
        try {
            return method_exists($variant, 'getOwner') ? $variant->getOwner() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The item's category path.
     *
     * A product type name is the only thing every Commerce store has, so it is the fallback. A
     * store with a real taxonomy points the `categoryField` setting at it and gets the full path.
     *
     * @return string[]
     */
    private function categories(mixed $element): array
    {
        $handle = Plugin::getInstance()->getSettings()->categoryField;

        if ($handle) {
            $path = $this->categoryPath($element, $handle);

            if ($path !== []) {
                return $path;
            }
        }

        try {
            $type = method_exists($element, 'getType') ? $element->getType() : null;

            return $type?->name ? [$type->name] : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return string[] */
    private function categoryPath(mixed $element, string $handle): array
    {
        try {
            $value = $element->getFieldValue($handle);
        } catch (Throwable) {
            return [];
        }

        if (is_string($value)) {
            return $value === '' ? [] : array_map('trim', explode('>', $value));
        }

        if (is_object($value) && method_exists($value, 'all')) {
            $categories = $value->all();

            // An ancestor chain is more useful than a flat list — “Bikes > Road > Frames” is what
            // a merchant feed carries, and a single leaf category loses the hierarchy.
            $title = static fn(mixed $element): string => is_object($element) ? (string)($element->title ?? '') : '';

            if (count($categories) === 1 && is_object($categories[0]) && method_exists($categories[0], 'getAncestors')) {
                $names = array_map($title, $categories[0]->getAncestors()->all());
                $names[] = $title($categories[0]);

                return array_values(array_filter($names));
            }

            return array_values(array_filter(array_map($title, $categories)));
        }

        return [];
    }

    private function customFieldValue(mixed $element, ?string $handle): ?string
    {
        if (!$handle || $element === null) {
            return null;
        }

        try {
            $value = $element->getFieldValue($handle);
        } catch (Throwable) {
            return null;
        }

        if (is_string($value)) {
            return $value ?: null;
        }

        if (is_object($value) && method_exists($value, 'one')) {
            return (string)($value->one()->title ?? '') ?: null;
        }

        return null;
    }
}
