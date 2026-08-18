<?php

namespace justinholtweb\tape\models;

use craft\base\Model;

/**
 * A visitor's consent state, in Google Consent Mode v2's vocabulary.
 *
 * Consent Mode's seven signals are used as Tape's internal vocabulary even for platforms that have
 * never heard of it, because they are the only published taxonomy that distinguishes *storing* an
 * ad identifier from *sending user data* from *personalising* — a distinction the GDPR cares about
 * and “marketing: yes/no” cannot express.
 *
 * A signal is one of `granted`, `denied` or null. **Null is not “denied”**: it means nothing is
 * known yet, which is what a page looks like before a CMP has answered, and the difference decides
 * whether a tag waits or gives up.
 */
class Consent extends Model
{
    public const GRANTED = 'granted';
    public const DENIED = 'denied';

    public const AD_STORAGE = 'ad_storage';
    public const AD_USER_DATA = 'ad_user_data';
    public const AD_PERSONALIZATION = 'ad_personalization';
    public const ANALYTICS_STORAGE = 'analytics_storage';
    public const FUNCTIONALITY_STORAGE = 'functionality_storage';
    public const PERSONALIZATION_STORAGE = 'personalization_storage';
    public const SECURITY_STORAGE = 'security_storage';

    /** The signals, in the order Google documents them. */
    public const SIGNALS = [
        self::AD_STORAGE,
        self::AD_USER_DATA,
        self::AD_PERSONALIZATION,
        self::ANALYTICS_STORAGE,
        self::FUNCTIONALITY_STORAGE,
        self::PERSONALIZATION_STORAGE,
        self::SECURITY_STORAGE,
    ];

    /**
     * The signals Tape groups destinations under. A destination declares one of these, not one of
     * the seven, because “this is an ads pixel” is a decision about the *tag* while the seven are
     * decisions about the *visitor*.
     */
    public const CATEGORY_ADS = 'ads';
    public const CATEGORY_ANALYTICS = 'analytics';
    public const CATEGORY_FUNCTIONALITY = 'functionality';

    /** @var array<string, string|null> */
    private array $signals = [];

    /**
     * @param array<string, string|null> $signals
     */
    public function __construct(array $signals = [], array $config = [])
    {
        foreach (self::SIGNALS as $signal) {
            $this->signals[$signal] = self::normalizeValue($signals[$signal] ?? null);
        }

        parent::__construct($config);
    }

    /** Everything granted — the state of a site that has decided it does not need a banner. */
    public static function allGranted(): self
    {
        return new self(array_fill_keys(self::SIGNALS, self::GRANTED));
    }

    /**
     * Everything denied except `security_storage`, which is never a marketing decision.
     *
     * `array_merge`, not `+`. The union operator keeps the *left* operand's value for a duplicate
     * key, so `array_fill_keys(SIGNALS, DENIED) + [SECURITY_STORAGE => GRANTED]` denies security
     * storage — which no consent framework treats as a real choice, and which quietly breaks fraud
     * prevention and login on any site that honours it.
     */
    public static function allDenied(): self
    {
        return new self(array_merge(
            array_fill_keys(self::SIGNALS, self::DENIED),
            [self::SECURITY_STORAGE => self::GRANTED],
        ));
    }

    /** Nothing known yet. */
    public static function unknown(): self
    {
        return new self();
    }

    public function get(string $signal): ?string
    {
        return $this->signals[$signal] ?? null;
    }

    public function isGranted(string $signal): bool
    {
        return $this->get($signal) === self::GRANTED;
    }

    /** Explicitly refused, as opposed to merely unanswered. */
    public function isDenied(string $signal): bool
    {
        return $this->get($signal) === self::DENIED;
    }

    public function with(string $signal, ?string $value): self
    {
        $clone = clone $this;
        $clone->signals[$signal] = self::normalizeValue($value);

        return $clone;
    }

    /** Whether a destination in the given category may load and fire. */
    public function allowsCategory(string $category): bool
    {
        return match ($category) {
            self::CATEGORY_ADS => $this->isGranted(self::AD_STORAGE),
            self::CATEGORY_ANALYTICS => $this->isGranted(self::ANALYTICS_STORAGE),
            self::CATEGORY_FUNCTIONALITY => $this->isGranted(self::FUNCTIONALITY_STORAGE),
            default => false,
        };
    }

    /** @return array<string, string|null> */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return $this->signals;
    }

    /**
     * The form `gtag('consent', …)` wants: no nulls, because an absent key already means “unset”
     * and sending a literal null throws in the tag.
     *
     * @return array<string, string>
     */
    public function toConsentModeArray(): array
    {
        return array_filter($this->signals, static fn($value) => $value !== null);
    }

    private static function normalizeValue(mixed $value): ?string
    {
        if ($value === true || $value === self::GRANTED || $value === 1 || $value === '1') {
            return self::GRANTED;
        }

        if ($value === false || $value === self::DENIED || $value === 0 || $value === '0') {
            return self::DENIED;
        }

        return null;
    }
}
