<?php

namespace justinholtweb\tape\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * A browser-side condition that fires an event without anybody editing a template.
 *
 * “Count it as a lead when somebody clicks the phone number” is a marketing request, not a
 * development ticket, and every hour spent turning it into one is an hour the campaign is running
 * blind. A trigger is how that request gets answered from the control panel.
 *
 * The condition is evaluated in the browser; the *payload* is not. Tape pre-maps each trigger's
 * event for every destination server-side and ships the finished payloads with the trigger, so the
 * front-end runtime stays a dispatcher that knows nothing about any platform — and so a visitor
 * cannot talk Tape into sending a conversion worth £10,000.
 */
class Trigger extends Model
{
    public const TYPE_SCROLL = 'scroll';
    public const TYPE_CLICK = 'click';
    public const TYPE_OUTBOUND = 'outbound';
    public const TYPE_TEL = 'tel';
    public const TYPE_MAILTO = 'mailto';
    public const TYPE_FORM_SUBMIT = 'formSubmit';
    public const TYPE_VISIBLE = 'visible';
    public const TYPE_TIME = 'time';

    public const TYPES = [
        self::TYPE_SCROLL,
        self::TYPE_CLICK,
        self::TYPE_OUTBOUND,
        self::TYPE_TEL,
        self::TYPE_MAILTO,
        self::TYPE_FORM_SUBMIT,
        self::TYPE_VISIBLE,
        self::TYPE_TIME,
    ];

    public const ONCE_NEVER = 'never';
    public const ONCE_PAGE = 'page';
    public const ONCE_SESSION = 'session';

    public string $uid = '';

    public string $name = '';

    public bool $enabled = true;

    public string $type = self::TYPE_CLICK;

    /** CSS selector for the click, form and visibility types. */
    public ?string $selector = null;

    /**
     * Scroll depth in percent, or seconds for {@see self::TYPE_TIME}.
     *
     * Scroll triggers accept several thresholds; `25,50,75,90` is the conventional set and each
     * one fires its own event with `percent_scrolled` set.
     */
    public ?string $threshold = null;

    /** The event this fires. Any name is allowed, including one no platform has heard of. */
    public string $event = TrackingEvent::GENERATE_LEAD;

    public ?float $value = null;

    public ?string $currency = null;

    /** Whether a repeat of the condition fires again. */
    public string $once = self::ONCE_SESSION;

    /**
     * URI patterns this trigger is live on. Empty means every page.
     *
     * Matched against the request URI with `*` as the wildcard, the same syntax Craft uses for
     * route patterns in `redirects` and the same one everybody already expects.
     *
     * @var string[]
     */
    public array $uris = [];

    /** @var int[] */
    public array $siteIds = [];

    public int $sortOrder = 0;

    public function init(): void
    {
        parent::init();

        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }
    }

    /** @return float[] Scroll percentages, ascending and de-duplicated. */
    public function getThresholds(): array
    {
        $values = array_filter(array_map('trim', explode(',', (string)$this->threshold)), static fn($v) => $v !== '');
        $numbers = array_map('floatval', $values);
        $numbers = array_values(array_unique(array_filter($numbers, static fn(float $n) => $n > 0)));
        sort($numbers);

        return $numbers;
    }

    public function needsSelector(): bool
    {
        return in_array($this->type, [self::TYPE_CLICK, self::TYPE_FORM_SUBMIT, self::TYPE_VISIBLE], true);
    }

    public function needsThreshold(): bool
    {
        return in_array($this->type, [self::TYPE_SCROLL, self::TYPE_TIME], true);
    }

    /**
     * Whether this trigger applies to the given request URI.
     *
     * The URI arrives without a leading slash from Craft, so both forms are accepted rather than
     * making everybody remember which one this particular field wanted.
     */
    public function matchesUri(string $uri): bool
    {
        if ($this->uris === []) {
            return true;
        }

        $uri = ltrim($uri, '/');

        foreach ($this->uris as $pattern) {
            $pattern = ltrim(trim($pattern), '/');

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $uri) {
                return true;
            }

            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

            if (preg_match($regex, $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isActiveOnSite(?int $siteId): bool
    {
        return $this->siteIds === [] || ($siteId !== null && in_array($siteId, $this->siteIds, true));
    }

    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'type' => $this->type,
            'selector' => $this->selector,
            'threshold' => $this->threshold,
            'event' => $this->event,
            'value' => $this->value,
            'currency' => $this->currency,
            'once' => $this->once,
            'uris' => array_values($this->uris),
            'siteIds' => array_values($this->siteIds),
            'sortOrder' => $this->sortOrder,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'event', 'type'], 'required'],
            [['type'], 'in', 'range' => self::TYPES],
            [['once'], 'in', 'range' => [self::ONCE_NEVER, self::ONCE_PAGE, self::ONCE_SESSION]],
            [['event'], 'match', 'pattern' => '/^[a-z][a-z0-9_]{0,39}$/', 'message' => Craft::t('tape', 'Event names are lowercase letters, numbers and underscores.')],
            // A trigger fires on a visitor's say-so. A purchase or a refund has an order behind it,
            // and only Commerce is allowed to announce one.
            [['event'], 'in', 'range' => [TrackingEvent::PURCHASE, TrackingEvent::REFUND], 'not' => true, 'message' => Craft::t('tape', 'Purchases and refunds are tracked from Commerce orders, never from a trigger.')],
            [['event'], function(string $attribute) {
                if (!in_array($this->event, TrackingEvent::STANDARD_EVENTS, true) && !TrackingEvent::isCustomName($this->event)) {
                    $this->addError($attribute, Craft::t('tape', 'Use a standard event, or a custom name that does not start with `ga_`, `google_` or `firebase_`.'));
                }
            }],
            [['value'], 'number', 'min' => 0],
            [['currency'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'skipOnEmpty' => true],
            [['selector'], 'required', 'when' => fn(self $model) => $model->needsSelector(), 'message' => Craft::t('tape', 'This trigger type needs a CSS selector.')],
            [['threshold'], 'validateThreshold', 'skipOnEmpty' => false],
        ];
    }

    public function validateThreshold(string $attribute): void
    {
        if (!$this->needsThreshold()) {
            return;
        }

        if ($this->getThresholds() === []) {
            $this->addError($attribute, $this->type === self::TYPE_SCROLL
                ? Craft::t('tape', 'Give at least one scroll percentage, for example “25, 50, 75”.')
                : Craft::t('tape', 'Give a number of seconds.'));
        }
    }
}
