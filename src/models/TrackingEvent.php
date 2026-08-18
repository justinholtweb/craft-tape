<?php

namespace justinholtweb\tape\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * The one event shape.
 *
 * Everything Tape can ever send — a product view rendered by a Twig tag, a cart line added over
 * Ajax, a completed Commerce order, a scroll-depth trigger fired in the browser, a refund raised
 * three weeks later by a console command — becomes one of these first. Platform adapters are the
 * only code that has heard of `fbq`, `ttq` or `send_to`, and they only ever read this.
 *
 * That is what makes the browser and server halves genuinely the same feature rather than two
 * implementations that drift: a Meta Conversions API call and a `fbq('track', …)` are built from
 * the same object, in the same request, carrying the same {@see self::$eventId} — which is exactly
 * what Meta needs in order to deduplicate them.
 *
 * The vocabulary is GA4's, because it is the only one with a published name for every step of a
 * checkout and because roughly half the destinations speak it natively.
 */
class TrackingEvent extends Model
{
    public const PAGE_VIEW = 'page_view';
    public const VIEW_ITEM = 'view_item';
    public const VIEW_ITEM_LIST = 'view_item_list';
    public const SELECT_ITEM = 'select_item';
    public const SEARCH = 'search';
    public const ADD_TO_WISHLIST = 'add_to_wishlist';
    public const ADD_TO_CART = 'add_to_cart';
    public const REMOVE_FROM_CART = 'remove_from_cart';
    public const VIEW_CART = 'view_cart';
    public const BEGIN_CHECKOUT = 'begin_checkout';
    public const ADD_SHIPPING_INFO = 'add_shipping_info';
    public const ADD_PAYMENT_INFO = 'add_payment_info';
    public const PURCHASE = 'purchase';
    public const REFUND = 'refund';
    public const GENERATE_LEAD = 'generate_lead';
    public const SIGN_UP = 'sign_up';
    public const LOGIN = 'login';
    public const CONTACT = 'contact';
    public const SUBSCRIBE = 'subscribe';
    public const SCROLL = 'scroll';
    public const CLICK = 'click';

    /** Events with a checkout funnel behind them. Off entirely when Commerce is not installed. */
    public const COMMERCE_EVENTS = [
        self::VIEW_ITEM,
        self::VIEW_ITEM_LIST,
        self::SELECT_ITEM,
        self::ADD_TO_WISHLIST,
        self::ADD_TO_CART,
        self::REMOVE_FROM_CART,
        self::VIEW_CART,
        self::BEGIN_CHECKOUT,
        self::ADD_SHIPPING_INFO,
        self::ADD_PAYMENT_INFO,
        self::PURCHASE,
        self::REFUND,
    ];

    /**
     * Events a browser may ask Tape to map for it.
     *
     * `purchase` and `refund` are missing on purpose. Their value comes from an order Tape looked
     * up itself; accepting one over an endpoint would let anyone with a keyboard write revenue
     * into somebody's Google Ads account.
     */
    public const REQUESTABLE_EVENTS = [
        self::VIEW_ITEM,
        self::VIEW_ITEM_LIST,
        self::SELECT_ITEM,
        self::SEARCH,
        self::ADD_TO_WISHLIST,
        self::ADD_TO_CART,
        self::REMOVE_FROM_CART,
        self::VIEW_CART,
        self::BEGIN_CHECKOUT,
        self::ADD_SHIPPING_INFO,
        self::ADD_PAYMENT_INFO,
        self::GENERATE_LEAD,
        self::SIGN_UP,
        self::LOGIN,
        self::CONTACT,
        self::SUBSCRIBE,
        self::SCROLL,
        self::CLICK,
    ];

    public const SOURCE_SERVER = 'server';
    public const SOURCE_BROWSER = 'browser';
    public const SOURCE_RECOVERY = 'recovery';

    public string $name = self::PAGE_VIEW;

    /**
     * Shared between the browser tag and the server-side call for the same conversion.
     *
     * Every platform with a Conversions API deduplicates on this and nothing else, so it is
     * generated once, server-side, and travels with the event rather than being minted per
     * transport. A purchase recovered by {@see \justinholtweb\tape\services\Recovery} reuses the
     * *order's* id for the same reason: the recovery must collapse into the browser event if the
     * browser event turns up late.
     */
    public string $eventId = '';

    public ?float $value = null;

    public ?string $currency = null;

    /** Order number, not order ID — it is what appears in the platform's reporting. */
    public ?string $transactionId = null;

    public ?float $tax = null;

    public ?float $shipping = null;

    public ?string $coupon = null;

    /** @var EventItem[] */
    public array $items = [];

    public ?string $listId = null;

    public ?string $listName = null;

    public ?string $searchTerm = null;

    public ?string $paymentType = null;

    public ?string $shippingTier = null;

    /**
     * Whether this is the customer's first order.
     *
     * Google Shopping's new-customer acquisition bidding needs it, and it is the single most
     * valuable optional parameter on the purchase event for anybody running Performance Max.
     */
    public ?bool $isNewCustomer = null;

    /** Free-form extras, merged into the payload by adapters that can carry them. */
    public array $params = [];

    public ?UserData $userData = null;

    /** The Commerce order this came from, when there is one. */
    public ?int $orderId = null;

    /** The Craft element the event happened on — an entry, a product, a category. */
    public ?int $elementId = null;

    public ?int $siteId = null;

    public string $source = self::SOURCE_SERVER;

    /** Unix timestamp. Server-side APIs will not accept an event more than a few days old. */
    public ?int $occurredAt = null;

    /**
     * Destination handles this event is restricted to. Empty means every destination that has the
     * event enabled — which is the normal case.
     *
     * @var string[]
     */
    public array $onlyDestinations = [];

    public function init(): void
    {
        parent::init();

        if ($this->eventId === '') {
            $this->eventId = StringHelper::UUID();
        }

        if ($this->occurredAt === null) {
            $this->occurredAt = time();
        }
    }

    /** Total units across every item. Several platforms want it and none of them compute it. */
    public function getItemCount(): int
    {
        return array_sum(array_map(static fn(EventItem $item) => $item->quantity, $this->items));
    }

    /** @return string[] */
    public function getItemIds(): array
    {
        return array_values(array_map(static fn(EventItem $item) => $item->id, $this->items));
    }

    /**
     * The event's value, computed from its items when nothing set one explicitly.
     *
     * A `view_item` with a price and no value is the common case and every remarketing platform
     * wants a value, so deriving it here saves every adapter from doing it differently.
     */
    public function getValue(): float
    {
        if ($this->value !== null) {
            return round($this->value, 4);
        }

        $total = 0.0;

        foreach ($this->items as $item) {
            $total += $item->getLineTotal();
        }

        return round($total, 4);
    }

    public function isCommerceEvent(): bool
    {
        return in_array($this->name, self::COMMERCE_EVENTS, true);
    }

    /** Whether this event is worth recording in the ledger and deduplicating. */
    public function isConversion(): bool
    {
        return in_array($this->name, [self::PURCHASE, self::REFUND], true);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'eventId'], 'required'],
            [['name'], 'match', 'pattern' => '/^[a-z][a-z0-9_]{0,39}$/'],
            [['value', 'tax', 'shipping'], 'number'],
            [['currency'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'skipOnEmpty' => true],
            [['source'], 'in', 'range' => [self::SOURCE_SERVER, self::SOURCE_BROWSER, self::SOURCE_RECOVERY]],
        ];
    }
}
