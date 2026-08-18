<?php

namespace justinholtweb\tape\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\User;
use justinholtweb\tape\events\CollectEvent;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\EventItem;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\models\UserData;
use justinholtweb\tape\Plugin;
use Throwable;

/**
 * Collects the events a request produces and decides what happens to each one.
 *
 * The lifecycle is deliberately three separate steps, because conflating them is how tracking
 * plugins end up firing things twice:
 *
 * 1. **Collect.** Anything can add an event — a Twig call, a Commerce hook, an Ajax endpoint. This
 *    is cheap and does nothing but queue.
 * 2. **Decide.** {@see shouldTrack()} answers once per request whether this visitor is tracked at
 *    all, and the answer is memoised. It is asked before anything expensive, and it is careful not
 *    to touch the session unless a setting actually requires knowing who the visitor is — because
 *    reading the user starts a session, a session sets a cookie, and a cookie is the end of full-page
 *    caching for the whole site.
 * 3. **Process.** {@see process()} turns one event into per-destination browser payloads, claims a
 *    ledger row for anything that must not fire twice, and queues the server-side half.
 */
class Events extends Component
{
    /** @event CollectEvent Fires before an event is queued; set `isValid` to false to drop it. */
    public const EVENT_BEFORE_COLLECT = 'beforeCollect';

    /** @var TrackingEvent[] */
    private array $queue = [];

    private ?bool $tracking = null;

    private ?UserData $userData = null;

    // ── Collecting ──────────────────────────────────────────────────────────────────────────

    public function collect(TrackingEvent $event): bool
    {
        if (!$this->shouldTrack() || !$this->isEventEnabled($event->name)) {
            return false;
        }

        $event->siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $event->userData ??= $this->getUserData();

        $collectEvent = new CollectEvent(['trackingEvent' => $event]);
        $this->trigger(self::EVENT_BEFORE_COLLECT, $collectEvent);

        if (!$collectEvent->isValid) {
            return false;
        }

        // A page can only ever have collected one of a given conversion. Two `purchase` events in
        // one response is always a bug — usually a template that calls the Twig helper as well as
        // leaving auto-tracking on — and it is much better caught here than in a revenue report.
        if ($event->isConversion()) {
            foreach ($this->queue as $queued) {
                if ($queued->name === $event->name && $queued->orderId === $event->orderId) {
                    return false;
                }
            }
        }

        $this->queue[] = $event;

        if (Plugin::getInstance()->getSettings()->debug) {
            Craft::info('Collected ' . $event->name . ' (' . $event->eventId . ')', Plugin::LOG_CATEGORY);
        }

        return true;
    }

    /** @return TrackingEvent[] */
    public function getQueue(): array
    {
        return $this->queue;
    }

    public function clearQueue(): void
    {
        $this->queue = [];
    }

    // ── Deciding ────────────────────────────────────────────────────────────────────────────

    /**
     * Whether this request is tracked at all.
     *
     * The order of the checks is the point. Everything free comes first; the two that cost
     * something — reading the user, and therefore opening a session — happen only if a setting
     * says the answer depends on them.
     */
    public function shouldTrack(): bool
    {
        if ($this->tracking !== null) {
            return $this->tracking;
        }

        return $this->tracking = $this->decideTracking();
    }

    /** Forces the answer for the rest of the request — used by the console and by tests. */
    public function setShouldTrack(?bool $tracking): void
    {
        $this->tracking = $tracking;
    }

    public function isEventEnabled(string $name): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($name === TrackingEvent::PAGE_VIEW && !$settings->trackPageViews) {
            return false;
        }

        // Absent means enabled: a new event name added in a later release should start working,
        // not stay silently off because nobody went back to the settings screen.
        return (bool)($settings->enabledEvents[$name] ?? true);
    }

    private function decideTracking(): bool
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return false;
        }

        if ($request->getIsCpRequest() || $request->getIsActionRequest() === false && $request->getIsPreview()) {
            return false;
        }

        // Previews and share tokens render real templates for an editor, not a customer.
        if ($request->getIsPreview() || $request->getIsLivePreview()) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->excludeIps !== [] && $this->ipIsExcluded((string)$request->getUserIP())) {
            return false;
        }

        if (!$settings->excludeLoggedIn && $settings->excludeUserGroups === []) {
            return true;
        }

        // Only now is it worth knowing who the visitor is — and this is the call that opens a
        // session, so nothing above it may depend on the answer.
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return true;
        }

        if ($settings->excludeLoggedIn) {
            return false;
        }

        foreach ($settings->excludeUserGroups as $handle) {
            if ($user->isInGroup($handle)) {
                return false;
            }
        }

        return true;
    }

    /** Supports single addresses and CIDR ranges, IPv4 and IPv6. */
    private function ipIsExcluded(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach (Plugin::getInstance()->getSettings()->excludeIps as $pattern) {
            $pattern = trim((string)$pattern);

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $ip) {
                return true;
            }

            if (str_contains($pattern, '/') && $this->ipInCidr($ip, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $binaryIp = @inet_pton($ip);
        $binarySubnet = @inet_pton((string)$subnet);

        if ($binaryIp === false || $binarySubnet === false || strlen($binaryIp) !== strlen($binarySubnet)) {
            return false;
        }

        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($binaryIp, $binarySubnet, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($binaryIp[$bytes]) & $mask) === (ord($binarySubnet[$bytes]) & $mask);
    }

    // ── The visitor ─────────────────────────────────────────────────────────────────────────

    /**
     * What is known about this visitor, click identifiers included.
     *
     * Does not open a session on its own: the logged-in user is only read if the tracking decision
     * already required it, which means a purely anonymous, fully cacheable page stays that way.
     */
    public function getUserData(): UserData
    {
        if ($this->userData !== null) {
            return $this->userData;
        }

        $request = Craft::$app->getRequest();
        $user = new UserData();
        $user->clickIds = Identity::clickIdsFromRequest();

        if (!$request->getIsConsoleRequest()) {
            $user->ip = $request->getUserIP();
            $user->userAgent = $request->getUserAgent();
            $user->sourceUrl = $request->getAbsoluteUrl();
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->enhancedConversions && ($settings->excludeLoggedIn || $settings->excludeUserGroups !== [])) {
            $identity = Craft::$app->getUser()->getIdentity();

            if ($identity instanceof User) {
                $user->userId = $identity->id;
                $user->email = $identity->email;
                $user->firstName = $identity->firstName;
                $user->lastName = $identity->lastName;
            }
        }

        return $this->userData = $user;
    }

    public function setUserData(?UserData $userData): void
    {
        $this->userData = $userData;
    }

    // ── Processing ──────────────────────────────────────────────────────────────────────────

    /**
     * Turns one event into browser payloads, ledger rows and queued server-side calls.
     *
     * The browser payloads are *not* gated on consent here. That is intentional: consent can be
     * granted after the page has rendered, and a payload that was never written into the page can
     * never be fired later. The runtime holds each destination's events until that destination's
     * consent category is granted, which is both correct and the only arrangement in which
     * accepting a banner starts tracking without a reload.
     *
     * Server-side calls *are* gated, because there is no later.
     *
     * @param array{record?: bool, serverSide?: bool, destinations?: Destination[]} $options
     *        `record` and `serverSide` are turned off when *pre-mapping* an event that has not
     *        happened yet — a trigger's payload is built at render time and may never fire, and a
     *        ledger row for a conversion nobody made is worse than no row at all.
     * @return array{name: string, eventId: string, dispatch: array<int, array<string, mixed>>, server: bool}
     */
    public function process(TrackingEvent $event, array $options = []): array
    {
        $plugin = Plugin::getInstance();
        $record = $options['record'] ?? true;
        $serverSide = $options['serverSide'] ?? true;
        $destinations = $options['destinations'] ?? $plugin->destinations->getActiveDestinations($event->siteId);
        $dispatch = [];
        $hasServerSide = false;

        foreach ($destinations as $destination) {
            if (!$this->destinationWants($destination, $event)) {
                continue;
            }

            $platform = $destination->getPlatform();

            if ($platform === null) {
                continue;
            }

            if (!$destination->serverSideOnly) {
                try {
                    $calls = $platform->mapEvent($event, $destination);
                } catch (Throwable $e) {
                    Craft::error("Tape could not map {$event->name} for {$destination->handle}: " . $e->getMessage(), Plugin::LOG_CATEGORY);
                    $calls = [];
                }

                foreach ($calls as $call) {
                    $dispatch[] = $call + [
                        'd' => $destination->handle,
                        'c' => $destination->consentCategory,
                    ];
                }

                if ($record && $calls !== [] && $event->isConversion()) {
                    $plugin->ledger->record($event, $destination, Ledger::CHANNEL_BROWSER, Ledger::STATUS_EMITTED);
                }
            }

            if ($this->canSendServerSide($destination)) {
                $hasServerSide = true;

                if ($serverSide) {
                    $this->scheduleServerSide($event, $destination);
                }
            }
        }

        return [
            'name' => $event->name,
            'eventId' => $event->eventId,
            'dispatch' => $dispatch,
            'server' => $hasServerSide,
        ];
    }

    /** Whether this destination has a working server-side half at all. */
    private function canSendServerSide(Destination $destination): bool
    {
        if (!$destination->serverSide || !Plugin::getInstance()->isPro()) {
            return false;
        }

        $platform = $destination->getPlatform();

        return $platform !== null && $platform->supportsServerSide();
    }

    private function destinationWants(Destination $destination, TrackingEvent $event): bool
    {
        if (!$destination->firesEvent($event->name)) {
            return false;
        }

        return $event->onlyDestinations === [] || in_array($destination->handle, $event->onlyDestinations, true);
    }

    /**
     * Queues the server-side half of an event, if this destination has one.
     *
     * The queue rather than an inline HTTP call, because the alternative is adding somebody else's
     * API latency to the response time of a checkout — and every one of these APIs has bad days.
     * `queueServerSide` can be turned off for a site whose queue does not run reliably, and then
     * the call is made inline after the response has been sent.
     */
    private function scheduleServerSide(TrackingEvent $event, Destination $destination): void
    {
        $plugin = Plugin::getInstance();

        $platform = $destination->getPlatform();

        if ($platform === null || $platform->serverRequest($event, $destination) === null) {
            return;
        }

        if (!$plugin->consent->allowsServerSide($destination->consentCategory)) {
            if ($event->isConversion()) {
                $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_SKIPPED);
            }

            return;
        }

        $ledgerId = $plugin->ledger->record($event, $destination, Ledger::CHANNEL_SERVER, Ledger::STATUS_PENDING);

        if ($ledgerId === null && $event->isConversion()) {
            // Already claimed by another request. Sending again would double the conversion.
            return;
        }

        $plugin->dispatcher->dispatch($event, $destination, $ledgerId);
    }

    // ── Convenience builders ────────────────────────────────────────────────────────────────

    public function pageView(?ElementInterface $element = null): ?TrackingEvent
    {
        $request = Craft::$app->getRequest();

        $event = new TrackingEvent([
            'name' => TrackingEvent::PAGE_VIEW,
            'elementId' => $element?->id,
            'params' => array_filter([
                'page_location' => $request->getIsConsoleRequest() ? null : $request->getAbsoluteUrl(),
                'page_title' => $element?->title,
            ]),
        ]);

        return $this->collect($event) ? $event : null;
    }

    /**
     * A free-form event from a template or a controller.
     *
     * @param array<string, mixed> $params
     */
    public function track(string $name, array $params = []): ?TrackingEvent
    {
        $event = new TrackingEvent(['name' => $name]);

        foreach (['value', 'currency', 'transactionId', 'coupon', 'listId', 'listName', 'searchTerm'] as $key) {
            if (array_key_exists($key, $params)) {
                $event->$key = $params[$key];
                unset($params[$key]);
            }
        }

        if (isset($params['items']) && is_array($params['items'])) {
            $event->items = array_map(
                static fn($item) => $item instanceof EventItem ? $item : new EventItem((array)$item),
                $params['items'],
            );
            unset($params['items']);
        }

        if (isset($params['only'])) {
            $event->onlyDestinations = (array)$params['only'];
            unset($params['only']);
        }

        $event->params = $params;

        return $this->collect($event) ? $event : null;
    }
}
