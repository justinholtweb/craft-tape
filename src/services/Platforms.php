<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\tape\platforms\Clarity;
use justinholtweb\tape\platforms\CustomSnippet;
use justinholtweb\tape\platforms\GoogleAds;
use justinholtweb\tape\platforms\GoogleAnalytics4;
use justinholtweb\tape\platforms\GoogleTagManager;
use justinholtweb\tape\platforms\Hotjar;
use justinholtweb\tape\platforms\LinkedIn;
use justinholtweb\tape\platforms\Meta;
use justinholtweb\tape\platforms\MicrosoftAds;
use justinholtweb\tape\platforms\Pinterest;
use justinholtweb\tape\platforms\PlatformInterface;
use justinholtweb\tape\platforms\Reddit;
use justinholtweb\tape\platforms\Snapchat;
use justinholtweb\tape\platforms\TikTok;
use justinholtweb\tape\platforms\Webhook;
use justinholtweb\tape\platforms\XAds;
use justinholtweb\tape\Plugin;

/**
 * The platform registry.
 *
 * Ordered by how likely somebody is to want it rather than alphabetically, because this list is
 * mostly read as a menu. Third-party platforms register through
 * {@see self::EVENT_REGISTER_PLATFORMS} and are then indistinguishable from the built-in ones —
 * there is no “core platform” concept anywhere else in the plugin.
 */
class Platforms extends Component
{
    /** @event RegisterComponentTypesEvent Adds platform classes. */
    public const EVENT_REGISTER_PLATFORMS = 'registerPlatforms';

    private const CORE = [
        GoogleAds::class,
        GoogleAnalytics4::class,
        GoogleTagManager::class,
        Meta::class,
        MicrosoftAds::class,
        TikTok::class,
        Pinterest::class,
        LinkedIn::class,
        Snapchat::class,
        Reddit::class,
        XAds::class,
        Clarity::class,
        Hotjar::class,
        CustomSnippet::class,
        Webhook::class,
    ];

    /** @var array<string, PlatformInterface>|null */
    private ?array $platforms = null;

    /** @return array<string, PlatformInterface> Keyed by handle. */
    public function getAllPlatforms(): array
    {
        if ($this->platforms !== null) {
            return $this->platforms;
        }

        $event = new RegisterComponentTypesEvent(['types' => self::CORE]);
        $this->trigger(self::EVENT_REGISTER_PLATFORMS, $event);

        $this->platforms = [];

        foreach ($event->types as $class) {
            if (!is_subclass_of($class, PlatformInterface::class)) {
                Craft::warning("Ignoring $class: not a Tape platform.", Plugin::LOG_CATEGORY);
                continue;
            }

            /** @var PlatformInterface $platform */
            $platform = new $class();
            $this->platforms[$platform::handle()] = $platform;
        }

        return $this->platforms;
    }

    public function getPlatform(string $handle): ?PlatformInterface
    {
        return $this->getAllPlatforms()[$handle] ?? null;
    }

    /**
     * Platforms this installation may actually use.
     *
     * A Pro platform is *listed* on Lite rather than hidden, with its edition shown — hiding it
     * would mean somebody comparing Tape against the pixel list they need would conclude it cannot
     * do TikTok at all.
     *
     * @return array<string, PlatformInterface>
     */
    public function getAvailablePlatforms(): array
    {
        if (Plugin::getInstance()->isPro()) {
            return $this->getAllPlatforms();
        }

        return array_filter(
            $this->getAllPlatforms(),
            static fn(PlatformInterface $platform) => $platform->requiresEdition() === Plugin::EDITION_LITE,
        );
    }

    public function isAvailable(string $handle): bool
    {
        return isset($this->getAvailablePlatforms()[$handle]);
    }

    /** @return array<string, string> Handle => display name, for a select field. */
    public function getPlatformOptions(): array
    {
        $options = [];

        foreach ($this->getAllPlatforms() as $handle => $platform) {
            $options[$handle] = $platform->displayName();
        }

        return $options;
    }
}
