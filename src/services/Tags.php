<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Response;
use justinholtweb\tape\models\Consent as ConsentModel;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\Settings;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\Trigger;
use justinholtweb\tape\Plugin;
use justinholtweb\tape\web\assets\tape\TapeAsset;
use Throwable;

/**
 * Composes what actually goes on the page.
 *
 * There is exactly one script and one inline configuration object, whatever is configured. Twelve
 * destinations do not mean twelve vendor snippets pasted into the head; they mean twelve entries in
 * a JSON object that a 9 KB runtime reads. That is the difference between a page with tracking on
 * it and a page ruined by tracking.
 *
 * Order in the head is load-bearing and worth stating, because getting it wrong is silent:
 *
 * 1. **Consent defaults.** These must execute before any Google tag exists, or the tag has already
 *    decided it may write cookies before being told it may not.
 * 2. **Custom snippets.** Admin-authored HTML, which people expect to be near the top.
 * 3. **The configuration object**, then the runtime, which loads every pixel itself.
 *
 * Injection restamps `Content-Length`. Craft stamps that header during `prepare()`, which runs
 * *before* the event this hook listens on, so a response that grew by 4 KB and kept its old length
 * is truncated at exactly the byte the injection started — presenting as a broken template rather
 * than a broken header.
 */
class Tags extends Component
{
    private bool $headPlaced = false;

    private bool $bodyPlaced = false;

    private ?array $payload = null;

    /** @var array<string, array<string, mixed>> Boot output, memoised — it is asked for three times per response. */
    private array $boots = [];

    /** Marks manual placement, so auto-injection stands down for this response. */
    public function markHeadPlaced(): void
    {
        $this->headPlaced = true;
    }

    public function markBodyPlaced(): void
    {
        $this->bodyPlaced = true;
    }

    public function invalidateCache(): void
    {
        $this->payload = null;
        $this->boots = [];
    }

    // ── Rendering ───────────────────────────────────────────────────────────────────────────

    /** Everything that belongs in `<head>`. */
    public function getHeadHtml(): string
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->events->shouldTrack()) {
            return '';
        }

        $payload = $this->getPayload();

        if ($payload['destinations'] === []) {
            return '';
        }

        $parts = [];
        $settings = $plugin->getSettings();

        if ($settings->consentMode) {
            $parts[] = Html::script($this->consentDefaultsJs(), ['type' => 'text/javascript']);
        }

        foreach ($this->getSnippets('html') as $snippet) {
            $parts[] = $snippet;
        }

        $parts[] = Html::script('window.__TAPE__=' . self::scriptJson($payload) . ';', ['type' => 'text/javascript']);
        $parts[] = Html::tag('script', '', ['src' => $this->getRuntimeUrl(), 'async' => true]);

        $this->headPlaced = true;

        return implode("\n", array_filter($parts));
    }

    /** Everything that belongs just before `</body>` — `<noscript>` pixels and custom body HTML. */
    public function getBodyHtml(): string
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->events->shouldTrack()) {
            return '';
        }

        $parts = [];

        foreach ($plugin->destinations->getActiveDestinations() as $destination) {
            $boot = $this->boot($destination);

            // A `<noscript>` pixel fires only where there is no JavaScript, which is exactly where
            // consent cannot be asked about and where nothing can be held back and released later.
            // So it is written only on a site that has declared it needs no consent at all; on a
            // site with a banner, the visitor with JavaScript disabled is simply not tracked, which
            // is the right way round.
            if (!empty($boot['noscript']) && $this->mayWriteNoscript($destination)) {
                $parts[] = $boot['noscript'];
            }
        }

        foreach ($this->getSnippets('bodyHtml') as $snippet) {
            $parts[] = $snippet;
        }

        $this->bodyPlaced = true;

        return implode("\n", array_filter($parts));
    }

    /**
     * The `window.__TAPE__` object.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();
        $request = Craft::$app->getRequest();
        $uri = $request->getIsConsoleRequest() ? '' : (string)$request->getPathInfo();

        $destinations = $plugin->destinations->getActiveDestinations($site->id);

        $payload = [
            'v' => 1,
            'debug' => $settings->debug,
            'consent' => $plugin->consent->getRuntimeConfig(),
            'destinations' => [],
            'events' => [],
            'triggers' => [],
            'endpoints' => [
                'map' => UrlHelper::actionUrl('tape/events/map'),
                'confirm' => UrlHelper::actionUrl('tape/events/confirm'),
                'trigger' => UrlHelper::actionUrl('tape/events/trigger'),
            ],
        ];

        foreach ($destinations as $destination) {
            $boot = $this->boot($destination);

            if ($boot === [] || !isset($boot['loader'])) {
                continue;
            }

            $payload['destinations'][] = [
                'h' => $destination->handle,
                'p' => $destination->platform,
                'c' => $destination->consentCategory,
                'loader' => $boot['loader'],
                'config' => $boot['config'] ?? [],
                // Google's tags are designed to load *before* consent is known and throttle
                // themselves — that is what makes conversion modelling possible. Holding them
                // back would be less private and less accurate at the same time.
                'now' => (bool)($boot['loadWithoutConsent'] ?? false),
            ];
        }

        $payload['events'] = $this->getProcessedEvents($destinations);

        foreach ($plugin->destinations->getActiveTriggers($site->id, $uri) as $trigger) {
            $payload['triggers'][] = $this->buildTrigger($trigger, $destinations);
        }

        return $this->payload = $payload;
    }

    /**
     * The queued events, mapped for every destination.
     *
     * @param Destination[] $destinations
     * @return array<int, array<string, mixed>>
     */
    public function getProcessedEvents(?array $destinations = null): array
    {
        $plugin = Plugin::getInstance();
        $destinations ??= $plugin->destinations->getActiveDestinations();
        $processed = [];

        foreach ($plugin->events->getQueue() as $event) {
            $result = $plugin->events->process($event, ['destinations' => $destinations]);

            if ($result['dispatch'] !== []) {
                $processed[] = ['n' => $result['name'], 'i' => $result['eventId'], 'd' => $result['dispatch']];
            }
        }

        return $processed;
    }

    // ── Injection ───────────────────────────────────────────────────────────────────────────

    /**
     * Writes the tags into a response that is about to go out.
     *
     * Guarded on the response's `Content-Type` rather than its format. Craft does not render
     * front-end templates as `FORMAT_HTML` — it uses its own `template` format — so a check on the
     * format matches nothing at all, silently, and the header is the correct test anyway: that
     * formatter takes the MIME type from the template's extension, so `feed.rss.twig` and
     * `manifest.json.twig` are template responses that must be left alone.
     */
    public function injectIntoResponse(Response $response): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->autoInject || ($this->headPlaced && $this->bodyPlaced)) {
            return;
        }

        if (!is_string($response->content) || $response->content === '') {
            return;
        }

        $contentType = $response->getHeaders()->get('content-type');

        if ($contentType === null || !str_contains(strtolower($contentType), 'text/html')) {
            return;
        }

        if ($this->uriIsExcluded()) {
            return;
        }

        $content = $response->content;
        $before = strlen($content);

        if (!$this->headPlaced) {
            $head = $this->getHeadHtml();

            if ($head !== '') {
                $content = $this->insertBefore($content, '</head>', $head);
            }
        }

        if (!$this->bodyPlaced) {
            $body = $this->getBodyHtml();

            if ($body !== '') {
                $content = $this->insertBefore($content, '</body>', $body, true);
            }
        }

        if (strlen($content) === $before) {
            return;
        }

        $response->content = $content;

        // Restamp, or the page is cut off at exactly the byte the injection started.
        if ($response->getHeaders()->has('content-length')) {
            $response->getHeaders()->set('content-length', (string)strlen($content));
        }
    }

    private function insertBefore(string $content, string $needle, string $html, bool $last = false): string
    {
        $position = $last ? strripos($content, $needle) : stripos($content, $needle);

        if ($position === false) {
            return $content;
        }

        return substr($content, 0, $position) . $html . "\n" . substr($content, $position);
    }

    private function uriIsExcluded(): bool
    {
        $patterns = Plugin::getInstance()->getSettings()->excludeUris;

        if ($patterns === []) {
            return false;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return true;
        }

        $uri = ltrim((string)$request->getPathInfo(), '/');

        foreach ($patterns as $pattern) {
            $pattern = ltrim(trim((string)$pattern), '/');

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $uri) {
                return true;
            }

            if (preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i', $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function boot(Destination $destination): array
    {
        if (isset($this->boots[$destination->uid])) {
            return $this->boots[$destination->uid];
        }

        return $this->boots[$destination->uid] = $this->buildBoot($destination);
    }

    /** @return array<string, mixed> */
    private function buildBoot(Destination $destination): array
    {
        $platform = $destination->getPlatform();

        if ($platform === null) {
            return [];
        }

        try {
            return $platform->boot($destination, Plugin::getInstance()->consent->resolve(), [
                // Matching data is passed only on a page that carries a conversion. It is derived
                // from *this* visitor, so putting it on an ordinary page would hand one person's
                // hashed email to everybody the cache serves that page to afterwards — and a
                // product page cannot use it for anything anyway.
                'userData' => $this->queueHasConversion() ? Plugin::getInstance()->events->getUserData() : null,
                'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
            ]);
        } catch (Throwable $e) {
            Craft::error("Tape could not boot {$destination->handle}: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }
    }

    /**
     * Admin-authored snippets, wrapped so the runtime can hold them until consent allows.
     *
     * A `<template>` is inert — nothing inside it loads, runs or is requested — so the snippet can
     * be in the page for every visitor, cached along with it, and still not execute until this
     * visitor's consent says so. Deciding server-side whether to print it would bake one visitor's
     * answer into a page served to everybody who came after.
     *
     * When consent is not in play at all the snippet is printed directly, because a template that
     * is always activated is a pointless indirection.
     *
     * @return string[]
     */
    private function getSnippets(string $key): array
    {
        $plugin = Plugin::getInstance();
        $gated = $plugin->getSettings()->consentMode;
        $out = [];

        foreach ($plugin->destinations->getActiveDestinations() as $destination) {
            $boot = $this->boot($destination);
            $snippet = trim((string)($boot[$key] ?? ''));

            if ($snippet === '') {
                continue;
            }

            if (!$gated) {
                $out[] = $snippet;
                continue;
            }

            $out[] = Html::tag('template', $snippet, [
                'data-tape-snippet' => $destination->handle,
                'data-tape-consent' => $destination->consentCategory,
            ]);
        }

        return $out;
    }

    /**
     * Whether a `<noscript>` pixel may be written for this destination.
     *
     * Two conditions, and the order matters. First the *site* has to have declared that consent is
     * never in question — no banner, no region defaults — because a noscript pixel fires where
     * there is no JavaScript, which is exactly where nothing can be held back and released later.
     * That check is per-site and therefore safe to cache.
     *
     * Then, if this visitor has nonetheless explicitly refused, the pixel is left out. That second
     * check does vary per visitor, so on a site behind a full-page cache it is only as good as the
     * cached copy — but it can only ever err on the side of writing fewer pixels, and it is exactly
     * right on the great majority of Craft sites, which run no such cache.
     */
    private function mayWriteNoscript(Destination $destination): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->consentMode
            && !($settings->consentSource === Settings::CONSENT_GRANTED && $settings->consentRegionDefaults === [])) {
            return false;
        }

        return !Plugin::getInstance()->consent->resolve()->isDenied(
            match ($destination->consentCategory) {
                ConsentModel::CATEGORY_ANALYTICS => ConsentModel::ANALYTICS_STORAGE,
                ConsentModel::CATEGORY_FUNCTIONALITY => ConsentModel::FUNCTIONALITY_STORAGE,
                default => ConsentModel::AD_STORAGE,
            },
        );
    }

    /**
     * Pre-maps a trigger's event so the browser has a finished payload to fire.
     *
     * Nothing is recorded and nothing is queued: the trigger may never fire. If any destination
     * wants the event server-side, the payload says so and the runtime posts back when it does.
     *
     * @param Destination[] $destinations
     * @return array<string, mixed>
     */
    private function buildTrigger(Trigger $trigger, array $destinations): array
    {
        $event = new TrackingEvent([
            'name' => $trigger->event,
            'value' => $trigger->value,
            'currency' => $trigger->currency,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]);

        $result = Plugin::getInstance()->events->process($event, [
            'destinations' => $destinations,
            'record' => false,
            'serverSide' => false,
        ]);

        return [
            'u' => $trigger->uid,
            't' => $trigger->type,
            'sel' => $trigger->selector,
            'th' => $trigger->getThresholds(),
            'once' => $trigger->once,
            'n' => $trigger->event,
            'i' => $result['eventId'],
            'd' => $result['dispatch'],
            's' => $result['server'],
        ];
    }

    /**
     * The consent-mode preamble.
     *
     * `window.gtag` is assigned rather than declared, because this may run inside a bundler's
     * wrapper or a CSP-nonced block where a `function` declaration would not reach `window` — and a
     * `gtag` that is not global is a `gtag` every Google tag on the page silently ignores.
     */
    private function consentDefaultsJs(): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $consent = $plugin->consent;

        $js = "window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){window.dataLayer.push(arguments)};";

        $defaults = $consent->defaultState()->toConsentModeArray();
        $defaults['wait_for_update'] = $settings->consentWaitForUpdate;
        $js .= 'gtag("consent","default",' . self::scriptJson($defaults) . ');';

        foreach ($consent->regionDefaults() as $group) {
            $state = $group['state'];
            $state['region'] = $group['region'];
            $state['wait_for_update'] = $settings->consentWaitForUpdate;
            $js .= 'gtag("consent","default",' . self::scriptJson($state) . ');';
        }

        if ($settings->urlPassthrough) {
            $js .= 'gtag("set","url_passthrough",true);';
        }

        if ($settings->adsDataRedaction) {
            $js .= 'gtag("set","ads_data_redaction",true);';
        }

        return $js;
    }

    private function queueHasConversion(): bool
    {
        foreach (Plugin::getInstance()->events->getQueue() as $event) {
            if ($event->isConversion()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The published URL of the runtime.
     *
     * No version query of its own: `getPublishedUrl()` with `$publish` already appends a cache
     * buster derived from the file, and adding a second `?` produces a URL that works by accident
     * rather than on purpose.
     */
    private function getRuntimeUrl(): string
    {
        $asset = new TapeAsset();

        return Craft::$app->getAssetManager()->getPublishedUrl($asset->sourcePath, true, 'tape.js');
    }

    /**
     * JSON that is safe to write inside a `<script>` element.
     *
     * The payload carries strings that are not ours — a search term lifted from the query string, a
     * coupon code a customer typed, a product title — and `JSON_UNESCAPED_SLASHES` alone leaves a
     * `</script>` in any of them intact, which ends the element and starts the visitor's own markup.
     * Hex-escaping `<`, `>` and `&` makes that impossible without changing what the JSON decodes to.
     */
    public static function scriptJson(mixed $value): string
    {
        return Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
