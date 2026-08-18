<?php

namespace justinholtweb\tape\platforms;

use Craft;
use craft\helpers\Html;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * Google Tag Manager — the container, plus a data layer that is actually worth reading.
 *
 * Plenty of Craft sites already have GTM in a layout template. What they usually do not have is a
 * data layer: the container loads, fires a page view, and every tag inside it is flying blind
 * because nothing on the site ever pushed a `purchase` with items in it. This destination is
 * therefore mostly about the second half — GTM's own snippet is four lines, and the useful part is
 * that every event Tape builds arrives in `dataLayer` in the exact GA4 ecommerce shape that GTM's
 * built-in ecommerce variables already know how to read.
 *
 * The `ecommerce: null` push before each event is not superstition. GTM merges pushes into one
 * model, so without it the previous event's `items` array survives into the next one and a
 * `remove_from_cart` reports whatever was in the cart before it.
 */
class GoogleTagManager extends BasePlatform
{
    public static function handle(): string
    {
        return 'gtm';
    }

    public function displayName(): string
    {
        return 'Google Tag Manager';
    }

    public function description(): string
    {
        return Craft::t('tape', 'Loads a GTM container and gives it a complete GA4-shaped data layer to read — including a server-side container URL if you run one.');
    }

    public function defaultConsentCategory(): string
    {
        return Consent::CATEGORY_ANALYTICS;
    }

    public function settingFields(): array
    {
        return [
            'containerId' => [
                'label' => Craft::t('tape', 'Container ID'),
                'placeholder' => 'GTM-XXXXXXX',
                'required' => true,
                'pattern' => '/^GTM-[A-Z0-9]{4,10}$/i',
                'patternMessage' => Craft::t('tape', 'A GTM container ID looks like `GTM-XXXXXXX`.'),
            ],
            'serverUrl' => [
                'label' => Craft::t('tape', 'Server container URL'),
                'instructions' => Craft::t('tape', 'If you run server-side tagging, the first-party URL your container is served from — `https://gtm.example.com`. Leave blank to load from Google.'),
                'placeholder' => 'https://gtm.example.com',
            ],
            'dataLayerName' => [
                'label' => Craft::t('tape', 'Data layer name'),
                'instructions' => Craft::t('tape', 'Only change this if something else on the site already owns `dataLayer`.'),
                'placeholder' => 'dataLayer',
                'pattern' => '/^[A-Za-z_$][A-Za-z0-9_$]*$/',
            ],
            'environment' => [
                'label' => Craft::t('tape', 'Environment parameters'),
                'instructions' => Craft::t('tape', 'The `gtm_auth=…&gtm_preview=…&gtm_cookies_win=x` string from a GTM environment snippet, if you use environments.'),
            ],
        ];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $containerId = $destination->setting('containerId');

        if ($containerId === null) {
            return [];
        }

        $host = rtrim((string)$destination->setting('serverUrl', 'https://www.googletagmanager.com'), '/');
        $dataLayer = (string)$destination->setting('dataLayerName', 'dataLayer');
        $environment = trim((string)$destination->setting('environment', ''), '&? ');

        $src = $host . '/gtm.js?id=' . rawurlencode($containerId);

        if ($dataLayer !== 'dataLayer') {
            $src .= '&l=' . rawurlencode($dataLayer);
        }

        if ($environment !== '') {
            $src .= '&' . $environment;
        }

        return [
            'loader' => 'gtm',
            'config' => [
                'id' => $containerId,
                'src' => $src,
                'dataLayer' => $dataLayer,
            ],
            // The container itself is consent-aware — Consent Mode signals are pushed before it
            // loads, and every tag inside it can be gated on them. Holding the container back
            // instead would also hold back tags that need no consent at all.
            'loadWithoutConsent' => Plugin::getInstance()->getSettings()->consentMode,
            'noscript' => Html::tag('noscript', Html::tag('iframe', '', [
                'src' => $host . '/ns.html?id=' . rawurlencode($containerId) . ($environment !== '' ? '&' . $environment : ''),
                'height' => '0',
                'width' => '0',
                'style' => 'display:none;visibility:hidden',
                'title' => 'Google Tag Manager',
            ])),
        ];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        $dataLayer = (string)$destination->setting('dataLayerName', 'dataLayer');
        $ga4 = new GoogleAnalytics4();
        $params = $ga4->eventParams($event);
        unset($params['send_to']);

        $push = [
            'event' => $event->name,
            'tape_event_id' => $event->eventId,
        ];

        if ($event->isCommerceEvent()) {
            $push['ecommerce'] = $this->clean($params);

            return [
                // Clear first, or the previous event's items survive the merge into this one.
                $this->call($dataLayer . '.push', [['ecommerce' => null]]),
                $this->call($dataLayer . '.push', [$push]),
            ];
        }

        return [$this->call($dataLayer . '.push', [$push + $this->clean($params)])];
    }
}
