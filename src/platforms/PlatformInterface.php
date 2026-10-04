<?php

namespace justinholtweb\tape\platforms;

use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;

/**
 * One advertising or analytics platform.
 *
 * A platform is stateless and knows nothing about Craft. It answers three questions and no others:
 * what do I need configuring, what script boots me, and what does *this* {@see TrackingEvent} look
 * like in my own vocabulary. Everything else — consent, deduplication, ordering, exclusions,
 * queueing, the ledger — is decided before a platform is ever asked.
 *
 * That is why adding a platform is a single file with no wiring, and why a bug in one platform's
 * mapping cannot affect any other.
 */
interface PlatformInterface
{
    /** Stable handle stored in project config. Never change one once it has shipped. */
    public static function handle(): string;

    public function displayName(): string;

    /** One sentence, shown when picking a platform. */
    public function description(): string;

    /** `Plugin::EDITION_LITE` or `Plugin::EDITION_PRO`. */
    public function requiresEdition(): string;

    public function defaultConsentCategory(): string;

    /**
     * Credential fields, in the order they should be shown.
     *
     * @return array<string, array{label: string, instructions?: string, placeholder?: string, type?: string, options?: array, required?: bool, pattern?: string, patternMessage?: string}>
     */
    public function settingFields(): array;

    /** @return string[] Setting keys without which this platform cannot fire at all. */
    public function requiredSettings(): array;

    public function settingLabel(string $key): string;

    /**
     * Platform-specific validation of a destination's credentials.
     *
     * @return array<string, string> Setting key => error message. Empty means valid.
     */
    public function validateSettings(Destination $destination): array;

    /** @return string[] Event names this platform can do something useful with. */
    public function supportedEvents(): array;

    public function supportsEvent(string $eventName): bool;

    /** Whether events under names outside {@see TrackingEvent::STANDARD_EVENTS} are accepted. */
    public function supportsCustomEvents(): bool;

    public function supportsServerSide(): bool;

    /**
     * Extra per-event configuration — Google Ads conversion labels, X event IDs.
     *
     * @return array<string, array{label: string, instructions?: string, placeholder?: string, required?: bool}>
     */
    public function eventSettingFields(string $eventName): array;

    /**
     * What has to run in the browser before any event can be dispatched.
     *
     * @param array<string, mixed> $context Request facts a loader may need — `siteId`, `uri`, `userData`.
     * @return array{scripts?: array<int, array{src: string, async?: bool}>, js?: string, noscript?: string, shared?: array<string, array{scripts?: array, js?: string}>, loadWithoutConsent?: bool}
     */
    public function boot(Destination $destination, Consent $consent, array $context): array;

    /**
     * The event, in this platform's own vocabulary.
     *
     * @return array<int, array{fn: string, args: array}> Dotted function path resolved against
     *         `window`, plus its arguments. Empty means “this platform has nothing to say about
     *         this event”, which is normal and not an error.
     */
    public function mapEvent(TrackingEvent $event, Destination $destination): array;

    /**
     * The server-side call for this event, or null when there is not one.
     *
     * @return array{url: string, method?: string, headers?: array<string, string>, body?: array|string, query?: array}|null
     */
    public function serverRequest(TrackingEvent $event, Destination $destination): ?array;

    /** Whether a server-side response body means success. Platforms disagree wildly about this. */
    public function isServerResponseOk(int $statusCode, string $body): bool;
}
