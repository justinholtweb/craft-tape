<?php

namespace justinholtweb\tape\models;

use craft\base\Model;

/**
 * The customer facts an ad platform can match a conversion against.
 *
 * Held in the clear only for as long as it takes to build a payload. {@see Normalize} lowercases,
 * strips and hashes on the way out, and nothing here is ever written to the event ledger — the
 * ledger stores what fired, not who it fired for.
 *
 * Every field is optional. Enhanced conversions and advanced matching are strictly better with
 * more, and strictly harmless with less.
 */
class UserData extends Model
{
    public ?string $email = null;

    /** Any format; normalised to E.164 before hashing. */
    public ?string $phone = null;

    public ?string $firstName = null;

    public ?string $lastName = null;

    public ?string $street = null;

    public ?string $city = null;

    /** Region/state. Two-letter code where one exists. */
    public ?string $region = null;

    public ?string $postalCode = null;

    /** Two-letter ISO country code. */
    public ?string $country = null;

    /** Craft user ID, used as the platform-agnostic external ID. */
    public ?int $userId = null;

    /** The visitor's IP, needed by every server-side API and by none of the browser tags. */
    public ?string $ip = null;

    public ?string $userAgent = null;

    /** The page the conversion happened on. Server-side APIs require it. */
    public ?string $sourceUrl = null;

    /**
     * Platform click identifiers read from cookies — `_fbc`, `_fbp`, `ttclid`, `gclid`, …
     *
     * Keyed by cookie/parameter name. These are what actually close the attribution loop for
     * server-side events, far more than hashed email ever does.
     *
     * @var array<string, string>
     */
    public array $clickIds = [];

    /** Whether anything at all is known. Saves building an empty matching block. */
    public function isEmpty(): bool
    {
        foreach (['email', 'phone', 'firstName', 'lastName', 'street', 'city', 'region', 'postalCode', 'country'] as $attribute) {
            if (($this->$attribute ?? '') !== '') {
                return false;
            }
        }

        return $this->userId === null && $this->clickIds === [];
    }

    public function getClickId(string $name): ?string
    {
        $value = $this->clickIds[$name] ?? null;

        return ($value ?? '') === '' ? null : $value;
    }
}
