<?php

namespace justinholtweb\tape\platforms;

use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\Plugin;

/**
 * Shared behaviour for the three platforms that ride on `gtag.js` — GA4, Google Ads and Google Tag.
 *
 * They share one library, one global and one consent implementation, and they must share one copy
 * of the script: loading `gtag.js` twice is not two tags, it is one tag and one wasted request,
 * and the second `config` call is how the second account is actually registered. The front-end
 * runtime's `gtag` loader is therefore idempotent, and every adapter here simply declares an ID
 * and its config parameters.
 *
 * These destinations are also the reason `loadWithoutConsent` exists. Under Consent Mode the
 * Google tag is *supposed* to load before an answer is known — that is the entire mechanism by
 * which it sends cookieless pings and models the gap afterwards. Holding it back until consent is
 * granted does not make the site more private; it just throws away the modelling.
 */
abstract class GtagPlatform extends BasePlatform
{
    /** The account/measurement ID this destination configures. */
    abstract protected function tagId(Destination $destination): ?string;

    /** Extra parameters for this destination's `gtag('config', …)` call. */
    protected function configParams(Destination $destination): array
    {
        return [];
    }

    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        $id = $this->tagId($destination);

        if ($id === null) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $params = $this->configParams($destination);

        // Tape sends every event explicitly. Leaving automatic page views on as well means the
        // first hit of every page is counted twice, which is the commonest way a GA4 property ends
        // up with a bounce rate of four percent and nobody able to explain it.
        $params['send_page_view'] = false;

        if ($destination->testMode && $destination->testCode) {
            $params['debug_mode'] = true;
        }

        return [
            'loader' => 'gtag',
            'config' => [
                'id' => $id,
                'params' => $this->clean($params) + ['send_page_view' => false],
            ],
            'loadWithoutConsent' => $settings->consentMode,
        ];
    }
}
