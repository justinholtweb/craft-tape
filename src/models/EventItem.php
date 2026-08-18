<?php

namespace justinholtweb\tape\models;

use craft\base\Model;

/**
 * One product inside a {@see TrackingEvent}.
 *
 * Deliberately flat and platform-agnostic. Every advertising platform wants the same six or seven
 * facts about a product and disagrees only about what to call them, so the disagreement is settled
 * once, in the platform adapters, and never leaks into whatever produced the item.
 *
 * `id` is the identifier the *merchant feed* uses, which is the only one an ad platform can match
 * against. On a Craft Commerce store that is the variant SKU by default, because that is what
 * Google Merchant Center and Meta Catalog are almost always fed — but stores that publish their
 * feed keyed on the variant ID need to say so once ({@see Settings::$productIdSource}) rather than
 * hand-patch every event.
 */
class EventItem extends Model
{
    /** Catalog identifier — matched against the merchant feed. */
    public string $id = '';

    /** The variant SKU, kept alongside `id` even when `id` is not the SKU. */
    public ?string $sku = null;

    public ?string $name = null;

    public ?string $brand = null;

    /**
     * Category path, outermost first. GA4 takes up to five levels; the adapters truncate.
     *
     * @var string[]
     */
    public array $categories = [];

    /** Variant name — “Large / Blue”. */
    public ?string $variant = null;

    /** Unit price, before quantity. */
    public ?float $price = null;

    public int $quantity = 1;

    /** Discount per unit, if the line carries one. */
    public ?float $discount = null;

    /** Position in the list this item was seen in, 1-based. */
    public ?int $index = null;

    public ?string $listId = null;

    public ?string $listName = null;

    /** The Craft element this came from, so the event log can link back to it. */
    public ?int $elementId = null;

    /** Google Ads dynamic remarketing vertical — `retail`, `education`, `hotel_rental`, … */
    public ?string $googleBusinessVertical = null;

    /** Line total, discount applied. */
    public function getLineTotal(): float
    {
        return round((($this->price ?? 0.0) - ($this->discount ?? 0.0)) * $this->quantity, 4);
    }

    /** The category path as the single delimited string most platforms want. */
    public function getCategoryPath(string $delimiter = ' > '): ?string
    {
        return $this->categories === [] ? null : implode($delimiter, $this->categories);
    }

    protected function defineRules(): array
    {
        return [
            [['id'], 'required'],
            [['quantity'], 'integer', 'min' => 0],
            [['price', 'discount'], 'number'],
        ];
    }
}
