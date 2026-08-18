<?php

namespace justinholtweb\tape\web\assets\tape;

use craft\web\AssetBundle;

/**
 * The front-end runtime.
 *
 * One file, no dependencies, no build step. It is registered as an asset bundle so that Craft
 * publishes and versions it like anything else, but {@see \justinholtweb\tape\services\Tags} asks
 * the asset manager for the published URL rather than registering the bundle — a bundle registered
 * on a front-end response injects itself wherever the template happens to call `head()`, and the
 * runtime has to come after the configuration object.
 *
 * Editing `dist/tape.js` needs `php craft clear-caches/cp-resources`, or Craft keeps serving the
 * previously published copy.
 */
class TapeAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['tape.js'];

        parent::init();
    }
}
