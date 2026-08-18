<?php

namespace justinholtweb\tape\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\StringHelper;
use justinholtweb\tape\platforms\PlatformInterface;
use justinholtweb\tape\Plugin;

/**
 * One configured place events are sent to — a Google Ads account, a Meta pixel, a GTM container.
 *
 * A destination is a *platform plus its credentials plus what it is allowed to fire*, and it lives
 * in project config rather than a table, because tracking configuration is a deployment concern:
 * the staging site and the production site want the same events wired to different account IDs,
 * and that is exactly the problem project config plus env vars already solves.
 *
 * Two of the same platform is normal and supported. Agencies run a client's pixel and their own;
 * a business with two brands runs two Meta pixels on one Craft install. The handle, not the
 * platform, is what identifies a destination anywhere else in the plugin.
 */
class Destination extends Model
{
    public ?int $id = null;

    public string $uid = '';

    /** Stable identifier used in templates, the ledger and `only()` filters. */
    public string $handle = '';

    public string $name = '';

    /** The platform handle — `googleAds`, `meta`, `tiktok`, … */
    public string $platform = '';

    public bool $enabled = true;

    /**
     * Platform-specific credentials, keyed by the platform's own setting names.
     *
     * Values may be env-var references (`$META_PIXEL_ID`); {@see self::setting()} resolves them,
     * and nothing else should read this array directly.
     *
     * @var array<string, mixed>
     */
    public array $settings = [];

    /** Which consent signal has to be granted before this destination may load. */
    public string $consentCategory = Consent::CATEGORY_ADS;

    /**
     * Event names this destination fires, or `['*']` for every event the platform supports.
     *
     * Defaults to everything: a pixel that has been installed and then told to fire nothing is a
     * configuration mistake nobody makes on purpose, whereas “I only want purchases going to
     * LinkedIn” is a real and common request.
     *
     * @var string[]
     */
    public array $events = ['*'];

    /**
     * Per-event extra configuration, keyed by event name.
     *
     * Google Ads conversion labels live here — one label per event is how Google models a
     * conversion action, and there is no other sensible place to hang them.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $eventSettings = [];

    /** Whether this destination also sends server-side. Pro, and only where the platform has an API. */
    public bool $serverSide = false;

    /** Whether the browser tag is suppressed, leaving only the server-side call. */
    public bool $serverSideOnly = false;

    /**
     * Site IDs this destination is active on. Empty means every site.
     *
     * @var int[]
     */
    public array $siteIds = [];

    /** Sends the platform's test/debug flag where it has one, so events land in its debug view. */
    public bool $testMode = false;

    public ?string $testCode = null;

    public int $sortOrder = 0;

    private ?PlatformInterface $platformInstance = null;

    public function init(): void
    {
        parent::init();

        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }
    }

    public function getPlatform(): ?PlatformInterface
    {
        if ($this->platformInstance === null) {
            $this->platformInstance = Plugin::getInstance()->platforms->getPlatform($this->platform);
        }

        return $this->platformInstance;
    }

    /**
     * A credential, with `$ENV_VAR` references resolved.
     *
     * Always read settings through here. An account ID typed straight into the CP works; the same
     * field holding `$GOOGLE_ADS_ID` also works, and that is the difference between project config
     * that can be committed and project config that cannot.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $parsed = App::parseEnv($value);

            return ($parsed === null || $parsed === '') ? $default : $parsed;
        }

        return $value;
    }

    public function eventSetting(string $event, string $key, mixed $default = null): mixed
    {
        $value = $this->eventSettings[$event][$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) ? (App::parseEnv($value) ?: $default) : $value;
    }

    /** Whether this destination should fire the given event, before consent is considered. */
    public function firesEvent(string $eventName): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $platform = $this->getPlatform();

        if ($platform === null || !$platform->supportsEvent($eventName)) {
            return false;
        }

        return in_array('*', $this->events, true) || in_array($eventName, $this->events, true);
    }

    public function isActiveOnSite(?int $siteId): bool
    {
        if ($this->siteIds === []) {
            return true;
        }

        return $siteId !== null && in_array($siteId, $this->siteIds, true);
    }

    /**
     * Whether the destination has enough configuration to do anything.
     *
     * Checked on render as well as on save, because a destination whose ID came from an env var
     * can be perfectly valid in project config and completely blank in this environment — which is
     * the single most common “the pixel isn't firing” support question there is.
     */
    public function isConfigured(): bool
    {
        $platform = $this->getPlatform();

        if ($platform === null) {
            return false;
        }

        foreach ($platform->requiredSettings() as $key) {
            if (($this->setting($key) ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /** @return string[] Human-readable reasons this destination is not going to fire. */
    public function getProblems(): array
    {
        $problems = [];
        $platform = $this->getPlatform();

        if ($platform === null) {
            return [Craft::t('tape', 'Unknown platform “{platform}”.', ['platform' => $this->platform])];
        }

        foreach ($platform->requiredSettings() as $key) {
            if (($this->setting($key) ?? '') === '') {
                $raw = $this->settings[$key] ?? '';
                $label = $platform->settingLabel($key);

                $problems[] = ($raw !== '' && str_starts_with((string)$raw, '$'))
                    ? Craft::t('tape', '{label} is set to {raw}, which is empty in this environment.', ['label' => $label, 'raw' => $raw])
                    : Craft::t('tape', '{label} is not set.', ['label' => $label]);
            }
        }

        if ($this->serverSide && !$platform->supportsServerSide()) {
            $problems[] = Craft::t('tape', '{platform} has no server-side API.', ['platform' => $platform->displayName()]);
        }

        if ($this->serverSide && !Plugin::getInstance()->isPro()) {
            $problems[] = Craft::t('tape', 'Server-side tracking needs Tape Pro.');
        }

        return $problems;
    }

    /** The shape written to project config. */
    public function getConfig(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'platform' => $this->platform,
            'enabled' => $this->enabled,
            'settings' => $this->settings,
            'consentCategory' => $this->consentCategory,
            'events' => array_values($this->events),
            'eventSettings' => $this->eventSettings,
            'serverSide' => $this->serverSide,
            'serverSideOnly' => $this->serverSideOnly,
            'siteIds' => array_values($this->siteIds),
            'testMode' => $this->testMode,
            'testCode' => $this->testCode,
            'sortOrder' => $this->sortOrder,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['handle', 'name', 'platform'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('tape', 'Handles may only contain letters, numbers, underscores and hyphens.')],
            [['handle'], 'string', 'max' => 64],
            [['name'], 'string', 'max' => 255],
            [['consentCategory'], 'in', 'range' => [Consent::CATEGORY_ADS, Consent::CATEGORY_ANALYTICS, Consent::CATEGORY_FUNCTIONALITY]],
            [['platform'], 'validatePlatform'],
            [['settings'], 'validatePlatformSettings', 'skipOnEmpty' => false],
        ];
    }

    public function validatePlatform(string $attribute): void
    {
        if ($this->getPlatform() === null) {
            $this->addError($attribute, Craft::t('tape', 'No such platform.'));
        }
    }

    /**
     * Runs the platform's own validation over its credentials.
     *
     * `skipOnEmpty => false` on purpose — an empty settings array is precisely the case worth
     * complaining about, and Yii skips inline validators for empty attributes otherwise.
     */
    public function validatePlatformSettings(string $attribute): void
    {
        $platform = $this->getPlatform();

        if ($platform === null) {
            return;
        }

        foreach ($platform->validateSettings($this) as $key => $message) {
            $this->addError($attribute . '.' . $key, $message);
            $this->addError($attribute, $message);
        }
    }
}
