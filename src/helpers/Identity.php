<?php

namespace justinholtweb\tape\helpers;

use Craft;
use justinholtweb\tape\models\UserData;
use justinholtweb\tape\Plugin;

/**
 * Turns what Craft knows about a visitor into what each platform will accept.
 *
 * Two separate jobs live here, and they are both fiddly enough to be worth centralising:
 *
 * **Normalisation.** Every platform hashes with SHA-256 and every platform disagrees about what to
 * hash. Lowercasing is universal; whether a phone number keeps its `+`, whether a UK postcode keeps
 * its space, whether a surname keeps its apostrophe — none of that is. Get one of them wrong and
 * the hash is simply a different hash: nothing errors, nothing warns, and the match rate is quietly
 * a third of what it should be. There is no way to detect that from inside the site, which is why
 * the rules are written down here once rather than inline in twelve adapters.
 *
 * **Click identifiers.** `gclid`, `fbclid`, `ttclid` and their friends arrive as query parameters
 * on the landing page and matter on the *conversion* page, which is three clicks later. Browser
 * tags handle that themselves by writing a cookie; server-side calls have to be handed the cookie,
 * and a server-side conversion without one is worth far less than a hashed email address — a fact
 * that surprises people, because the email address is the part that feels like the identifier.
 *
 * Nothing here is ever persisted. A hash is built to be sent and then dropped.
 */
final class Identity
{
    /** Click identifiers worth carrying, as query parameter => cookie name. */
    public const CLICK_IDS = [
        'gclid' => '_gcl_aw',
        'wbraid' => '_gcl_gb',
        'gbraid' => null,
        'fbclid' => '_fbc',
        'ttclid' => null,
        'msclkid' => null,
        'twclid' => null,
        'epik' => '_epik',
        'rdt_cid' => null,
        'ScCid' => null,
        'li_fat_id' => null,
    ];

    /** Cookies read on every request, click identifiers aside. */
    public const COOKIES = ['_fbp', '_fbc', '_ttp', '_ga', '_rdt_uuid', '_scid', '_uetmsclkid'];

    // ── Normalisation ───────────────────────────────────────────────────────────────────────

    public static function hash(?string $value): ?string
    {
        $value = self::trimmed($value);

        return $value === null ? null : hash('sha256', $value);
    }

    public static function email(?string $email): ?string
    {
        $email = self::trimmed($email);

        if ($email === null || !str_contains($email, '@')) {
            return null;
        }

        return mb_strtolower($email);
    }

    /**
     * Digits only, with a country code.
     *
     * A phone number without a country code hashes to something no platform will ever match, and a
     * site whose customers are all in one country cannot supply one per order — so the plugin's
     * `phoneCountryCode` setting fills the gap. A number long enough to already carry a country
     * code is left alone.
     */
    public static function phone(?string $phone): ?string
    {
        $phone = self::trimmed($phone);

        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');

        if (strlen($digits) < 6) {
            return null;
        }

        $code = ltrim((string)(Plugin::getInstance()->getSettings()->phoneCountryCode ?? ''), '+ ');

        if ($code !== '' && !str_starts_with($digits, $code) && strlen($digits) <= 11) {
            $digits = $code . $digits;
        }

        return $digits;
    }

    /** Lowercase, no punctuation, no digits — the rule Meta, TikTok and Google all publish. */
    public static function name(?string $name): ?string
    {
        $name = self::trimmed($name);

        if ($name === null) {
            return null;
        }

        $name = preg_replace('/[^\p{L}\p{M}]+/u', '', $name) ?? '';

        return $name === '' ? null : mb_strtolower($name);
    }

    public static function city(?string $city): ?string
    {
        $city = self::trimmed($city);

        if ($city === null) {
            return null;
        }

        $city = preg_replace('/[^\p{L}\p{M}]+/u', '', $city) ?? '';

        return $city === '' ? null : mb_strtolower($city);
    }

    /** Two letters where the input clearly is one; otherwise the whole thing, lowercased. */
    public static function region(?string $region): ?string
    {
        $region = self::trimmed($region);

        if ($region === null) {
            return null;
        }

        $region = preg_replace('/[^\p{L}]+/u', '', $region) ?? '';

        return $region === '' ? null : mb_strtolower($region);
    }

    /**
     * Lowercased, spaces removed, and US ZIP+4 cut back to five.
     *
     * The ZIP+4 rule is Meta's and Google's, and it is the one that catches people out: an order
     * carrying `90210-1234` hashes to a value that matches nothing, because the platform hashed
     * `90210`.
     */
    public static function postalCode(?string $postalCode, ?string $country = null): ?string
    {
        $postalCode = self::trimmed($postalCode);

        if ($postalCode === null) {
            return null;
        }

        $postalCode = mb_strtolower(preg_replace('/\s+/', '', $postalCode) ?? '');

        if ($postalCode === '') {
            return null;
        }

        if (str_contains($postalCode, '-') && (strtolower((string)$country) === 'us' || preg_match('/^\d{5}-\d{4}$/', $postalCode) === 1)) {
            $postalCode = explode('-', $postalCode)[0];
        }

        return $postalCode;
    }

    public static function country(?string $country): ?string
    {
        $country = self::trimmed($country);

        if ($country === null) {
            return null;
        }

        $country = preg_replace('/[^\p{L}]+/u', '', $country) ?? '';

        return strlen($country) === 2 ? mb_strtolower($country) : null;
    }

    public static function street(?string $street): ?string
    {
        $street = self::trimmed($street);

        return $street === null ? null : mb_strtolower(preg_replace('/\s+/', ' ', $street) ?? '');
    }

    // ── Per-platform shapes ─────────────────────────────────────────────────────────────────

    /**
     * Google's enhanced conversions block.
     *
     * The address sub-object is sent *unhashed* — that is Google's documented format, not an
     * oversight — which means it puts a customer's street address into the page source of the
     * order confirmation. Off unless somebody has explicitly asked for it.
     */
    public static function googleUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        $data = array_filter([
            'sha256_email_address' => self::hash(self::email($user->email)),
            'sha256_phone_number' => self::hash(self::phone($user->phone)),
        ]);

        if (!Plugin::getInstance()->getSettings()->enhancedConversionsAddress) {
            return $data;
        }

        $address = array_filter([
            'sha256_first_name' => self::hash(self::name($user->firstName)),
            'sha256_last_name' => self::hash(self::name($user->lastName)),
            'street' => self::street($user->street),
            'city' => self::city($user->city),
            'region' => self::region($user->region),
            'postal_code' => self::postalCode($user->postalCode, $user->country),
            'country' => strtoupper((string)self::country($user->country)) ?: null,
        ]);

        if ($address !== []) {
            $data['address'] = $address;
        }

        return $data;
    }

    /** Meta's browser advanced matching — flat, single-valued, already hashed. */
    public static function metaUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'em' => self::hash(self::email($user->email)),
            'ph' => self::hash(self::phone($user->phone)),
            'fn' => self::hash(self::name($user->firstName)),
            'ln' => self::hash(self::name($user->lastName)),
            'ct' => self::hash(self::city($user->city)),
            'st' => self::hash(self::region($user->region)),
            'zp' => self::hash(self::postalCode($user->postalCode, $user->country)),
            'country' => self::hash(self::country($user->country)),
            'external_id' => $user->userId !== null ? self::hash((string)$user->userId) : null,
        ]);
    }

    /** Meta's Conversions API — the same fields, but every one an array. */
    public static function metaServerUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        $hashed = array_map(static fn(string $value) => [$value], self::metaUserData($user));

        return array_filter($hashed + [
            'client_ip_address' => $user->ip,
            'client_user_agent' => $user->userAgent,
            'fbc' => $user->getClickId('_fbc'),
            'fbp' => $user->getClickId('_fbp'),
        ]);
    }

    public static function tiktokUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'email' => self::hash(self::email($user->email)),
            'phone_number' => self::hash(self::phone($user->phone)),
            'external_id' => $user->userId !== null ? self::hash((string)$user->userId) : null,
        ]);
    }

    public static function tiktokServerUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter(self::tiktokUserData($user) + [
            'phone' => self::hash(self::phone($user->phone)),
            'ip' => $user->ip,
            'user_agent' => $user->userAgent,
            'ttclid' => $user->getClickId('ttclid'),
            'ttp' => $user->getClickId('_ttp'),
        ]);
    }

    public static function pinterestUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter(['em' => self::hash(self::email($user->email))]);
    }

    public static function pinterestServerUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'em' => array_filter([self::hash(self::email($user->email))]) ?: null,
            'ph' => array_filter([self::hash(self::phone($user->phone))]) ?: null,
            'external_id' => $user->userId !== null ? [self::hash((string)$user->userId)] : null,
            'client_ip_address' => $user->ip,
            'client_user_agent' => $user->userAgent,
            'click_id' => $user->getClickId('epik') ?? $user->getClickId('_epik'),
        ]);
    }

    public static function microsoftUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'em' => self::hash(self::email($user->email)),
            'ph' => self::hash(self::phone($user->phone)),
        ]);
    }

    public static function snapchatUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'user_hashed_email' => self::hash(self::email($user->email)),
            'user_hashed_phone_number' => self::hash(self::phone($user->phone)),
        ]);
    }

    public static function snapchatServerUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'em' => array_filter([self::hash(self::email($user->email))]) ?: null,
            'ph' => array_filter([self::hash(self::phone($user->phone))]) ?: null,
            'client_ip_address' => $user->ip,
            'client_user_agent' => $user->userAgent,
            'sc_click_id' => $user->getClickId('ScCid'),
            'external_id' => $user->userId !== null ? [self::hash((string)$user->userId)] : null,
        ]);
    }

    public static function redditUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'email' => self::hash(self::email($user->email)),
            'externalId' => $user->userId !== null ? self::hash((string)$user->userId) : null,
        ]);
    }

    /** Reddit is the only platform that wants the IP address hashed as well. */
    public static function redditServerUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'email' => self::hash(self::email($user->email)),
            'external_id' => $user->userId !== null ? self::hash((string)$user->userId) : null,
            'ip_address' => self::hash($user->ip),
            'user_agent' => $user->userAgent,
            'uuid' => $user->getClickId('_rdt_uuid'),
        ]);
    }

    /**
     * X hashes in the pixel and rejects a value that is already hashed, so this one really is the
     * address in the clear. Gated behind a per-destination switch that says so.
     */
    public static function xUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'email_address' => self::email($user->email),
            'phone_number' => self::phone($user->phone),
        ]);
    }

    public static function webhookUserData(?UserData $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'emailSha256' => self::hash(self::email($user->email)),
            'phoneSha256' => self::hash(self::phone($user->phone)),
            'userId' => $user->userId,
            'ip' => $user->ip,
            'userAgent' => $user->userAgent,
            'clickIds' => $user->clickIds,
        ], static fn($value) => $value !== null && $value !== []);
    }

    // ── GA4's identifiers ───────────────────────────────────────────────────────────────────

    /**
     * The GA4 client ID, read out of the `_ga` cookie.
     *
     * The cookie is `GA1.1.<client_id>`, where the client ID is itself two dot-separated numbers.
     * Minting a fresh one instead would make every server-side event a brand-new user with no
     * session and no acquisition source, which does more damage to the reports than sending
     * nothing would.
     */
    public static function ga4ClientId(?UserData $user): ?string
    {
        $cookie = $user?->getClickId('_ga');

        if ($cookie === null) {
            return null;
        }

        $parts = explode('.', $cookie);

        return count($parts) >= 4 ? $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1] : null;
    }

    /**
     * The session ID from the per-stream `_ga_<CONTAINER>` cookie.
     *
     * Without it the Measurement Protocol event starts a new session, which double-counts sessions
     * and detaches the conversion from the traffic source that earned it.
     */
    public static function ga4SessionId(?UserData $user, string $measurementId): ?string
    {
        $container = str_replace('G-', '', strtoupper($measurementId));
        $cookie = $user?->getClickId('_ga_' . $container);

        if ($cookie === null) {
            return null;
        }

        // GS1.1.<session_id>.<session_number>.<…>
        return preg_match('/^GS\d\.\d\.(\d+)/', $cookie, $matches) === 1 ? $matches[1] : null;
    }

    // ── Reading the request ─────────────────────────────────────────────────────────────────

    /**
     * Click identifiers and platform cookies from the current request.
     *
     * `_fbc` gets special treatment: Meta's format is `fb.1.<milliseconds>.<fbclid>`, and the
     * browser pixel writes it on the landing page — but a server-side event fired on that same
     * landing page runs before the pixel has, so the cookie does not exist yet. Building it from
     * the query parameter is what Meta documents, and it is the difference between the first
     * conversion of a session being attributed and being anonymous.
     *
     * @return array<string, string>
     */
    public static function clickIdsFromRequest(): array
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return [];
        }

        $found = [];

        foreach (self::COOKIES as $name) {
            $value = $request->getCookies()->getValue($name);

            if (is_string($value) && $value !== '') {
                $found[$name] = $value;
            }
        }

        // The per-stream GA4 session cookie has a variable name, so it cannot be listed.
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value) && $value !== '' && str_starts_with($name, '_ga_')) {
                $found[$name] = $value;
            }
        }

        foreach (array_keys(self::CLICK_IDS) as $param) {
            $value = $request->getQueryParam($param);

            if (is_string($value) && $value !== '' && strlen($value) <= 512) {
                $found[$param] = $value;
            }
        }

        if (!isset($found['_fbc']) && isset($found['fbclid'])) {
            $found['_fbc'] = 'fb.1.' . (time() * 1000) . '.' . $found['fbclid'];
        }

        return $found;
    }

    private static function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
