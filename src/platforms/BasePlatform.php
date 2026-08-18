<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * Shared machinery for platform adapters.
 *
 * The interesting content of every adapter is its {@see mapEvent()}; everything a platform would
 * otherwise have to reimplement — field metadata, validation from a pattern, event-name lookup,
 * item shaping — is here so that a new adapter is a table of event names and a mapping function.
 */
abstract class BasePlatform implements PlatformInterface
{
    public function description(): string
    {
        return '';
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_LITE;
    }

    public function defaultConsentCategory(): string
    {
        return Consent::CATEGORY_ADS;
    }

    public function settingFields(): array
    {
        return [];
    }

    public function requiredSettings(): array
    {
        $required = [];

        foreach ($this->settingFields() as $key => $field) {
            if ($field['required'] ?? false) {
                $required[] = $key;
            }
        }

        return $required;
    }

    public function settingLabel(string $key): string
    {
        return $this->settingFields()[$key]['label'] ?? $key;
    }

    /**
     * Validates each field against the `pattern` in its own definition.
     *
     * The patterns are worth having. `AW-1234` typed where `AW-123456789` belongs produces a tag
     * that loads, runs, throws nothing and reports zero conversions forever, and the only place
     * that can ever be caught is the moment somebody types it.
     */
    public function validateSettings(Destination $destination): array
    {
        $errors = [];

        foreach ($this->settingFields() as $key => $field) {
            $raw = $destination->settings[$key] ?? '';

            if (!is_string($raw) || $raw === '' || str_starts_with($raw, '$')) {
                continue;
            }

            if (isset($field['pattern']) && preg_match($field['pattern'], trim($raw)) !== 1) {
                $errors[$key] = $field['patternMessage'] ?? Craft::t('tape', '{label} does not look right.', ['label' => $field['label']]);
            }
        }

        return $errors;
    }

    public function supportedEvents(): array
    {
        return array_merge([
            TrackingEvent::PAGE_VIEW,
            TrackingEvent::SEARCH,
            TrackingEvent::GENERATE_LEAD,
            TrackingEvent::SIGN_UP,
            TrackingEvent::LOGIN,
            TrackingEvent::CONTACT,
            TrackingEvent::SUBSCRIBE,
            TrackingEvent::SCROLL,
            TrackingEvent::CLICK,
        ], TrackingEvent::COMMERCE_EVENTS);
    }

    public function supportsEvent(string $eventName): bool
    {
        return in_array($eventName, $this->supportedEvents(), true);
    }

    public function supportsServerSide(): bool
    {
        return false;
    }

    public function eventSettingFields(string $eventName): array
    {
        return [];
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        return null;
    }

    /**
     * Most APIs answer 200 with a body describing a partial failure, so status alone is not enough.
     *
     * The default here is deliberately strict about the status and silent about the body; adapters
     * whose API buries errors in a 200 override it.
     */
    public function isServerResponseOk(int $statusCode, string $body): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }

    // ── Helpers for adapters ────────────────────────────────────────────────────────────────

    /**
     * Rounds a monetary amount the way every ad platform wants it: a number, not a string, and not
     * more precision than a currency has.
     */
    protected function money(?float $value): float
    {
        return round((float)$value, 2);
    }

    protected function currency(TrackingEvent $event): string
    {
        return $event->currency ?: (Plugin::getInstance()->getSettings()->defaultCurrency ?: 'USD');
    }

    /**
     * Drops nulls and empty strings from a payload.
     *
     * Several platforms reject a payload outright for containing a key whose value is null —
     * Pinterest and Snapchat both do — so this is not tidiness, it is a requirement.
     */
    protected function clean(array $payload): array
    {
        return array_filter($payload, static fn($value) => $value !== null && $value !== '' && $value !== []);
    }

    /** @return array<int, array<string, mixed>> */
    protected function mapItems(TrackingEvent $event, callable $mapper): array
    {
        $mapped = [];

        foreach ($event->items as $index => $item) {
            /** @var EventItem $item */
            $mapped[] = $this->clean($mapper($item, $index));
        }

        return $mapped;
    }

    /** A dispatch instruction for the front-end runtime. */
    protected function call(string $fn, array $args): array
    {
        return ['fn' => $fn, 'args' => array_values($args)];
    }
}
