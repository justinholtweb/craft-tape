<?php

namespace justinholtweb\tape\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings — the decisions that are true for the whole site.
 *
 * Anything a single destination might reasonably want to differ on lives on {@see Destination}
 * instead. What is left here is either a site-wide fact (what currency, what counts as revenue,
 * who is staff) or a safety rail (consent, Do Not Track, exclusions) that should not be
 * negotiable per pixel.
 *
 * Nothing is marked `required`. A required plugin setting makes a fresh install unable to save any
 * setting at all until that one field is filled in, which is a spectacular way to greet somebody.
 *
 * `excludeUris` and `excludeIps` are backed by a getter and setter rather than a public property,
 * because Craft's editable table posts rows rather than a flat list. They are declared below because
 * nothing outside the class — phpstan, an IDE, a template — can otherwise see them, and named in
 * {@see attributes()} because Craft would otherwise never save them.
 *
 * @property string[] $excludeUris
 * @property string[] $excludeIps
 */
class Settings extends Model
{
    /** Revenue counted as the full order total, tax and shipping included. */
    public const VALUE_GROSS = 'gross';
    /** Revenue counted as the order total minus tax and shipping. */
    public const VALUE_NET = 'net';
    /** Revenue counted as the line items only, before order-level discounts. */
    public const VALUE_ITEMS = 'items';

    public const CONSENT_GRANTED = 'granted';
    public const CONSENT_DENIED = 'denied';
    public const CONSENT_CMP = 'cmp';
    public const CONSENT_API = 'api';

    public const ID_SKU = 'sku';
    public const ID_VARIANT_ID = 'variantId';
    public const ID_PRODUCT_ID = 'productId';

    // ── Delivery ────────────────────────────────────────────────────────────────────────────

    /**
     * Whether Tape writes its tags into the page itself.
     *
     * On by default because the alternative is editing every layout, and off is a one-click answer
     * for anybody who would rather place `{{ tape.head() }}` exactly where they want it. Placing
     * either tag manually suppresses injection for that response regardless of this setting, so
     * turning it off is a preference and not a prerequisite.
     */
    public bool $autoInject = true;

    /** @var string[] URI patterns Tape never injects into. `*` wildcards, matched against the request URI. */
    private array $_excludeUris = [];

    // ── What gets tracked ───────────────────────────────────────────────────────────────────

    public bool $trackPageViews = true;

    /**
     * The global event switchboard. Absent means enabled.
     *
     * A destination can narrow this further, but nothing can widen it — turning `purchase` off
     * here turns it off everywhere, which is what somebody reaches for in a hurry.
     *
     * @var array<string, bool>
     */
    public array $enabledEvents = [];

    /** Automatically fire the Commerce funnel from Commerce's own events, with no template edits. */
    public bool $autoTrackCommerce = true;

    /** What `value` means on a purchase. */
    public string $valueBasis = self::VALUE_GROSS;

    /** Currency for events with no order behind them. Blank uses Commerce's, then the site's. */
    public ?string $defaultCurrency = null;

    /** Which identifier goes in `item_id`. It has to match the merchant feed or nothing matches. */
    public string $productIdSource = self::ID_SKU;

    /** Prepended to every product ID. Merchant Center feeds built by a third party often need one. */
    public ?string $productIdPrefix = null;

    /** Google Ads dynamic remarketing vertical, sent with every item. */
    public string $googleBusinessVertical = 'retail';

    /**
     * A custom field handle on the product supplying the brand.
     *
     * Commerce has no brand concept, and every platform wants one. A store that has modelled brands
     * points this at the field; a store that hasn't sends no brand, which is fine.
     */
    public ?string $brandField = null;

    /**
     * A custom field handle supplying the product category.
     *
     * A category field holding a single category is expanded to its full ancestor path, because
     * “Bikes > Road > Frames” is what a merchant feed carries and a bare leaf loses the hierarchy.
     * Blank falls back to the Commerce product type name, which every store has.
     */
    public ?string $categoryField = null;

    // ── Consent ─────────────────────────────────────────────────────────────────────────────

    /**
     * Whether Google Consent Mode v2 is emitted.
     *
     * On is the safe default even for a site with no banner: with consent granted by default the
     * emitted state is “granted”, which is exactly what happens without consent mode, and the day
     * a banner is added nothing has to be rewired.
     */
    public bool $consentMode = true;

    /**
     * Where the consent answer comes from.
     *
     * - `granted` — no banner; everything fires. The default, because a plugin that silently
     *   tracks nothing on install is worse than one that tracks and is told to stop.
     * - `denied` — nothing fires until something calls `tape.consent.update()`.
     * - `cmp` — read from a consent platform, via {@see self::$consentCmp}.
     * - `api` — Tape's own cookie, written by `tape.consent.update()` from whatever banner you have.
     */
    public string $consentSource = self::CONSENT_GRANTED;

    /** A known CMP handle, or `custom` to use {@see self::$consentExpression}. */
    public ?string $consentCmp = null;

    /** A JavaScript expression returning a consent object. Only read when `consentCmp` is `custom`. */
    public ?string $consentExpression = null;

    public string $consentCookieName = 'tape_consent';

    public int $consentCookieDays = 180;

    /**
     * Per-region consent defaults, keyed by ISO region code (`EU`, `GB`, `US-CA`).
     *
     * `EU` expands to the EEA member states plus the UK and Switzerland when the tag is written —
     * Google's `region` parameter takes country codes and nothing else, and expanding it here means
     * nobody has to paste 30 country codes into a text field.
     *
     * @var array<string, array<string, string>>
     */
    public array $consentRegionDefaults = [];

    /** Milliseconds Google's tags wait for a consent update before giving up and running denied. */
    public int $consentWaitForUpdate = 500;

    /** Pass ad click IDs through the URL when cookies are denied. Part of Consent Mode. */
    public bool $urlPassthrough = true;

    /** Redact ad click identifiers in requests when `ad_storage` is denied. */
    public bool $adsDataRedaction = true;

    /** Treat `DNT: 1` and Global Privacy Control as a denial of everything but security storage. */
    public bool $respectDoNotTrack = false;

    // ── Who is not tracked ──────────────────────────────────────────────────────────────────

    /**
     * Do not track logged-in users.
     *
     * Off by default, and worth explaining: on a membership site the logged-in visitors *are* the
     * customers. Turn it on for a staff-heavy install, or use {@see self::$excludeUserGroups},
     * which is the answer people actually want.
     */
    public bool $excludeLoggedIn = false;

    /** @var string[] User group handles whose members are never tracked. */
    public array $excludeUserGroups = [];

    /** @var string[] IPs and CIDR ranges never tracked — the office, the agency, the QA runner. */
    private array $_excludeIps = [];

    // ── Server-side ─────────────────────────────────────────────────────────────────────────

    /** Send server-side calls through the queue instead of inline. Keeps checkout fast. */
    public bool $queueServerSide = true;

    public int $serverSideTimeout = 8;

    /** Hash and send customer data for enhanced conversions and advanced matching. Pro. */
    public bool $enhancedConversions = true;

    /**
     * Also send the billing address with enhanced conversions.
     *
     * Off, and worth the extra switch: Google's enhanced-conversions format sends the street,
     * city, region and postal code **unhashed**, which means turning this on writes a customer's
     * home address into the page source of their order confirmation. Match rates improve a little.
     * Nobody should discover that trade-off by accident.
     */
    public bool $enhancedConversionsAddress = false;

    /**
     * Country calling code assumed for phone numbers that have none — `1`, `44`, `61`.
     *
     * A phone number hashed without its country code matches nothing, anywhere, silently. Most
     * stores serve one country and cannot supply a code per order, so this is the answer.
     */
    public ?string $phoneCountryCode = null;

    // ── Recovery ────────────────────────────────────────────────────────────────────────────

    /**
     * Look for completed orders whose purchase event never fired and send them server-side.
     *
     * The single most valuable thing in the plugin on a store using a redirect-style payment
     * gateway, where a customer closing the tab at the bank's page loses the conversion even though
     * the order completed perfectly.
     */
    public bool $recoveryEnabled = true;

    /** How long after an order completes before an untracked purchase counts as missed. */
    public int $recoveryDelayMinutes = 30;

    /** How far back a recovery sweep looks. Platforms reject events much older than a week. */
    public int $recoveryLookbackDays = 3;

    // ── Housekeeping ────────────────────────────────────────────────────────────────────────

    /**
     * How long ledger rows are kept, in days.
     *
     * The ledger is what makes deduplication, recovery and the accuracy report possible, so it
     * cannot be turned off — but a purchase from two years ago cannot be deduplicated against
     * anything and is not worth a row. Rows for orders are kept longer than the rest.
     */
    public int $ledgerRetentionDays = 400;

    /** Log every event Tape builds to the browser console and to Craft's log. */
    public bool $debug = false;

    /**
     * Craft's editable table posts `[['uri' => 'a'], ['uri' => 'b']]`, not `['a', 'b']`.
     *
     * Normalising means a getter and a setter, and a private backing property — which in turn means
     * overriding {@see attributes()}, because Craft persists plugin settings by iterating that list
     * and a getter/setter pair is otherwise **silently never saved**.
     *
     * @return string[]
     */
    public function getExcludeUris(): array
    {
        return $this->_excludeUris;
    }

    public function setExcludeUris(mixed $value): void
    {
        $this->_excludeUris = self::flatten($value);
    }

    /** @return string[] */
    public function getExcludeIps(): array
    {
        return $this->_excludeIps;
    }

    public function setExcludeIps(mixed $value): void
    {
        $this->_excludeIps = self::flatten($value);
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['excludeUris', 'excludeIps']);
    }

    /**
     * Accepts a plain list, a list of single-column table rows, or a newline-separated string.
     *
     * @return string[]
     */
    private static function flatten(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                $row = reset($row);
            }

            if (is_string($row) && trim($row) !== '') {
                $out[] = trim($row);
            }
        }

        return array_values(array_unique($out));
    }

    public function getValueBasisOptions(): array
    {
        return [
            self::VALUE_GROSS => Craft::t('tape', 'Order total — including tax and shipping'),
            self::VALUE_NET => Craft::t('tape', 'Order total minus tax and shipping'),
            self::VALUE_ITEMS => Craft::t('tape', 'Line items only'),
        ];
    }

    public function getProductIdOptions(): array
    {
        return [
            self::ID_SKU => Craft::t('tape', 'Variant SKU'),
            self::ID_VARIANT_ID => Craft::t('tape', 'Variant ID'),
            self::ID_PRODUCT_ID => Craft::t('tape', 'Product ID'),
        ];
    }

    public function getConsentSourceOptions(): array
    {
        return [
            self::CONSENT_GRANTED => Craft::t('tape', 'Granted — no consent banner on this site'),
            self::CONSENT_DENIED => Craft::t('tape', 'Denied until something grants it'),
            self::CONSENT_CMP => Craft::t('tape', 'Read from a consent management platform'),
            self::CONSENT_API => Craft::t('tape', 'Tape’s own cookie, set by your banner'),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['valueBasis'], 'in', 'range' => [self::VALUE_GROSS, self::VALUE_NET, self::VALUE_ITEMS]],
            [['productIdSource'], 'in', 'range' => [self::ID_SKU, self::ID_VARIANT_ID, self::ID_PRODUCT_ID]],
            [['consentSource'], 'in', 'range' => [self::CONSENT_GRANTED, self::CONSENT_DENIED, self::CONSENT_CMP, self::CONSENT_API]],
            [['defaultCurrency'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'skipOnEmpty' => true],
            [['consentWaitForUpdate'], 'integer', 'min' => 0, 'max' => 10000],
            [['serverSideTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['recoveryDelayMinutes'], 'integer', 'min' => 1],
            [['recoveryLookbackDays'], 'integer', 'min' => 1, 'max' => 7],
            [['ledgerRetentionDays'], 'integer', 'min' => 7],
            [['consentCookieDays'], 'integer', 'min' => 1, 'max' => 400],
            [['phoneCountryCode'], 'match', 'pattern' => '/^\\+?[0-9]{1,4}$/', 'skipOnEmpty' => true],
            [['consentCookieName'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]{1,64}$/', 'skipOnEmpty' => true],
            [['consentExpression'], 'required', 'when' => fn(self $model) => $model->consentSource === self::CONSENT_CMP && $model->consentCmp === 'custom', 'message' => Craft::t('tape', 'Give a JavaScript expression that returns the consent state.')],
        ];
    }
}
