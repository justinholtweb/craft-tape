<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use justinholtweb\tape\models\Consent as ConsentModel;
use justinholtweb\tape\models\Settings;
use justinholtweb\tape\Plugin;

/**
 * Works out what this visitor has agreed to, and tells the browser how to keep working it out.
 *
 * Consent is answered in two places and they have to agree. The browser is authoritative — it is
 * where the banner is and where the CMP's answer arrives — but the *server* needs an answer too,
 * because a server-side conversion is sent from PHP with no browser in the loop at all. So Tape's
 * runtime mirrors whatever it resolves into a first-party cookie, and the server reads that.
 *
 * The mirror is the only reason server-side tracking on a CMP-managed site is possible without
 * either ignoring consent or reimplementing a dozen consent platforms in PHP. It also means the
 * very first request of a brand-new session has no answer available server-side, which is why
 * “unknown” is a distinct state from “denied” everywhere in this plugin.
 */
class Consent extends Component
{
    /** Cookie encoding version, so the format can change without misreading old cookies. */
    private const COOKIE_VERSION = 'v1';

    /**
     * Consent platforms the runtime knows how to read.
     *
     * Each is a bridge in `tape.js` that reads the platform's current state and subscribes to its
     * change event. Adding one is a dozen lines there and a line here.
     */
    public const CMPS = [
        'cookiebot' => 'Cookiebot',
        'cookieyes' => 'CookieYes',
        'complianz' => 'Complianz',
        'iubenda' => 'Iubenda',
        'onetrust' => 'OneTrust / CookiePro',
        'osano' => 'Osano',
        'termly' => 'Termly',
        'klaro' => 'Klaro',
        'usercentrics' => 'Usercentrics',
        'civic' => 'Civic Cookie Control',
        'custom' => 'Something else — I’ll supply an expression',
    ];

    /**
     * The EEA, plus the UK and Switzerland.
     *
     * Google's `region` parameter takes country codes and nothing else, so “EU” has to be expanded
     * somewhere. Doing it here means nobody has to paste thirty country codes into a text field and
     * nobody's defaults quietly miss Croatia.
     */
    public const EU_REGIONS = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'IS', 'LI', 'NO', 'CH', 'GB',
    ];

    /** US states with a comprehensive privacy law that a default-denied posture suits. */
    public const US_PRIVACY_REGIONS = [
        'US-CA', 'US-CO', 'US-CT', 'US-DE', 'US-IA', 'US-IN', 'US-MT', 'US-NE', 'US-NH',
        'US-NJ', 'US-OR', 'US-TN', 'US-TX', 'US-UT', 'US-VA',
    ];

    private ?ConsentModel $resolved = null;

    /**
     * This request's consent state as far as the server can tell.
     *
     * Ordered from cheapest and most certain to least: an explicit site-wide answer, then Do Not
     * Track, then the mirrored cookie. Reading the cookie does *not* start a session — it is a
     * plain request cookie — which matters because anything that touched the session here would
     * make every page uncacheable.
     */
    public function resolve(): ConsentModel
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->respectDoNotTrack && $this->doNotTrackIsSet()) {
            return $this->resolved = ConsentModel::allDenied();
        }

        $cookie = $this->readCookie();

        if ($cookie !== null) {
            return $this->resolved = $cookie;
        }

        return $this->resolved = match ($settings->consentSource) {
            Settings::CONSENT_GRANTED => ConsentModel::allGranted(),
            Settings::CONSENT_DENIED => ConsentModel::allDenied(),
            // A CMP's answer lives in JavaScript. Until the runtime has mirrored it, the server
            // genuinely does not know — and pretending otherwise in either direction is worse.
            default => ConsentModel::unknown(),
        };
    }

    /** Forces a state for the rest of the request. Used by the console and by tests. */
    public function setResolved(?ConsentModel $consent): void
    {
        $this->resolved = $consent;
    }

    /**
     * Whether a server-side call may go out for this destination.
     *
     * Unknown counts as “no”. A server-side conversion is the one place where sending on an
     * unanswered consent state cannot be undone or modelled away, so it waits — and on any real
     * session the runtime has already mirrored an answer by the time a purchase happens.
     */
    public function allowsServerSide(string $category): bool
    {
        if (!Plugin::getInstance()->getSettings()->consentMode) {
            return true;
        }

        return $this->resolve()->allowsCategory($category);
    }

    /**
     * The consent configuration the front-end runtime needs.
     *
     * Deliberately contains no answer for *this* visitor. Everything here is site configuration and
     * identical for every request, which is what makes a page carrying it safe to put in a
     * full-page cache — a payload that carried the current visitor's consent state would be served
     * to the next hundred people as their own.
     *
     * @return array<string, mixed>
     */
    public function getRuntimeConfig(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return [
            'mode' => $settings->consentMode,
            'source' => $settings->consentSource,
            'cmp' => $settings->consentCmp,
            'expression' => $settings->consentCmp === 'custom' ? $settings->consentExpression : null,
            'cookie' => $settings->consentCookieName,
            'cookieDays' => $settings->consentCookieDays,
            'cookieVersion' => self::COOKIE_VERSION,
            'signals' => ConsentModel::SIGNALS,
            'defaults' => $this->defaultState()->toConsentModeArray(),
            'regions' => $this->regionDefaults(),
            'waitForUpdate' => $settings->consentWaitForUpdate,
            'urlPassthrough' => $settings->urlPassthrough,
            'adsDataRedaction' => $settings->adsDataRedaction,
            'respectDnt' => $settings->respectDoNotTrack,
        ];
    }

    /**
     * The default state a page starts in, before any banner has answered.
     *
     * `security_storage` is always granted: it covers fraud prevention and authentication, and no
     * consent framework treats refusing it as a meaningful choice.
     */
    public function defaultState(): ConsentModel
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->consentSource) {
            Settings::CONSENT_GRANTED => ConsentModel::allGranted(),
            default => ConsentModel::allDenied(),
        };
    }

    /**
     * Per-region overrides, with `EU` and `US` expanded into country and state codes.
     *
     * @return array<int, array{region: string[], state: array<string, string>}>
     */
    public function regionDefaults(): array
    {
        $configured = Plugin::getInstance()->getSettings()->consentRegionDefaults;
        $groups = [];

        foreach ($configured as $region => $signals) {
            $codes = match (strtoupper((string)$region)) {
                'EU', 'EEA' => self::EU_REGIONS,
                'US-PRIVACY', 'USP' => self::US_PRIVACY_REGIONS,
                default => [strtoupper((string)$region)],
            };

            $state = (new ConsentModel($signals))->toConsentModeArray();

            if ($state === []) {
                continue;
            }

            $groups[] = ['region' => $codes, 'state' => $state];
        }

        return $groups;
    }

    /**
     * Encodes a state for the mirror cookie.
     *
     * Seven characters plus a version prefix, in the order {@see ConsentModel::SIGNALS} lists them.
     * A JSON cookie would be five times the size and is sent on every request to the site,
     * including every image.
     */
    public function encode(ConsentModel $consent): string
    {
        $flags = '';

        foreach (ConsentModel::SIGNALS as $signal) {
            $flags .= match ($consent->get($signal)) {
                ConsentModel::GRANTED => '1',
                ConsentModel::DENIED => '0',
                default => '-',
            };
        }

        return self::COOKIE_VERSION . ':' . $flags;
    }

    public function decode(string $value): ?ConsentModel
    {
        if (!str_starts_with($value, self::COOKIE_VERSION . ':')) {
            return null;
        }

        $flags = substr($value, strlen(self::COOKIE_VERSION) + 1);

        if (strlen($flags) !== count(ConsentModel::SIGNALS)) {
            return null;
        }

        $signals = [];

        foreach (ConsentModel::SIGNALS as $index => $signal) {
            $signals[$signal] = match ($flags[$index]) {
                '1' => ConsentModel::GRANTED,
                '0' => ConsentModel::DENIED,
                default => null,
            };
        }

        return new ConsentModel($signals);
    }

    /** @return array<string, string> CMP handle => label, for a select field. */
    public function getCmpOptions(): array
    {
        return self::CMPS;
    }

    private function readCookie(): ?ConsentModel
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        $name = Plugin::getInstance()->getSettings()->consentCookieName;
        // Raw, not `getCookies()`: the runtime writes this cookie from JavaScript, so it carries no
        // Craft validation hash and the validated collection silently drops it. It holds seven
        // flags and is decoded strictly, so there is nothing a forged one could do that the
        // visitor could not do by clicking the banner.
        $value = $request->getRawCookies()->getValue($name);

        return is_string($value) && $value !== '' ? $this->decode($value) : null;
    }

    /**
     * Do Not Track, and Global Privacy Control.
     *
     * GPC is the one that matters now — it is the header California's law actually recognises,
     * while DNT is honoured by roughly nobody. Both are checked because a browser sending either
     * has been explicit.
     */
    private function doNotTrackIsSet(): bool
    {
        return ($_SERVER['HTTP_DNT'] ?? null) === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? null) === '1';
    }
}
