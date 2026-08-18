<?php

namespace justinholtweb\tape;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\tape\models\Settings;
use justinholtweb\tape\services\Commerce;
use justinholtweb\tape\services\Consent;
use justinholtweb\tape\services\Destinations;
use justinholtweb\tape\services\Dispatcher;
use justinholtweb\tape\services\Events;
use justinholtweb\tape\services\Ledger;
use justinholtweb\tape\services\Platforms;
use justinholtweb\tape\services\Recovery;
use justinholtweb\tape\services\Tags;
use justinholtweb\tape\twig\TapeExtension;
use justinholtweb\tape\twig\TapeVariable;
use Throwable;
use yii\base\Event;

/**
 * Tape — conversion tracking for Craft CMS.
 *
 * One event shape ({@see models\TrackingEvent}), one place each platform's dialect is spoken
 * ({@see platforms}), and one runtime on the page. Everything else follows from those three:
 *
 * - Adding a platform is one file with no wiring, because nothing else has heard of any platform.
 * - The browser tag and the Conversions API call are the same event with the same ID, because
 *   there is only one event to build.
 * - Commerce is optional and confined to {@see services\Commerce}, so a site with no store gets
 *   page views, triggers and custom conversions and nothing broken.
 * - Every conversion is written to a ledger with a unique key, which is what makes deduplication,
 *   recovery and the accuracy report possible at all.
 *
 * @property-read Platforms $platforms
 * @property-read Destinations $destinations
 * @property-read Events $events
 * @property-read Ledger $ledger
 * @property-read Consent $consent
 * @property-read Tags $tags
 * @property-read Dispatcher $dispatcher
 * @property-read Recovery $recovery
 * @property-read Commerce $commerce
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'tape:view';
    public const PERMISSION_MANAGE = 'tape:manage';
    public const PERMISSION_RECOVER = 'tape:recover';

    public const LOG_CATEGORY = 'tape';

    /** Session key for events that have to survive a redirect. */
    private const CARRY_OVER_KEY = 'tape.carry';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'platforms' => Platforms::class,
                'destinations' => Destinations::class,
                'events' => Events::class,
                'ledger' => Ledger::class,
                'consent' => Consent::class,
                'tags' => Tags::class,
                'dispatcher' => Dispatcher::class,
                'recovery' => Recovery::class,
                'commerce' => Commerce::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerProjectConfigHandlers();
        $this->registerPermissions();
        $this->registerCpRoutes();
        $this->registerGarbageCollection();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        $this->registerTwig();

        // Everything below only matters on a front-end page, and the checks are cheap. A control
        // panel request should not pay for a response filter it can never use.
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerPageHooks();
            $this->registerCommerceHooks();
        }
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('tape', 'Tape');

        $item['subnav'] = [
            'destinations' => ['label' => Craft::t('tape', 'Destinations'), 'url' => 'tape/destinations'],
            'triggers' => ['label' => Craft::t('tape', 'Triggers'), 'url' => 'tape/triggers'],
            'events' => ['label' => Craft::t('tape', 'Events'), 'url' => 'tape/events'],
            'accuracy' => ['label' => Craft::t('tape', 'Accuracy'), 'url' => 'tape/accuracy'],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = ['label' => Craft::t('tape', 'Settings'), 'url' => 'settings/plugins/tape'];
        }

        // A destination configured with an ID that is empty in this environment is the single most
        // common way tracking silently stops, and the nav badge is the only place somebody sees it
        // without going looking.
        try {
            $broken = count($this->destinations->getBrokenDestinations());

            if ($broken > 0) {
                $item['badgeCount'] = $broken;
            }
        } catch (Throwable) {
            // Before install, or mid-migration.
        }

        return $item;
    }

    /**
     * Clears Tape's own project config tree.
     *
     * Craft removes `plugins.tape` on uninstall and nothing else, so a plugin with a top-level key
     * of its own has to clear it — otherwise a reinstall comes back haunted by the previous
     * install's destinations, complete with credentials nobody remembers configuring.
     */
    public function beforeUninstall(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->remove(Destinations::CONFIG_DESTINATIONS_KEY, 'Uninstall Tape');
        $projectConfig->remove(Destinations::CONFIG_TRIGGERS_KEY, 'Uninstall Tape');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('tape/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'consentOptions' => $this->getSettings()->getConsentSourceOptions(),
            'cmpOptions' => $this->consent->getCmpOptions(),
            'commerce' => $this->commerce->isInstalled(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
        ]);
    }

    // ── Registration ────────────────────────────────────────────────────────────────────────

    private function registerProjectConfigHandlers(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(Destinations::CONFIG_DESTINATIONS_KEY . '.{uid}', [$this->destinations, 'handleChangedDestination'])
            ->onUpdate(Destinations::CONFIG_DESTINATIONS_KEY . '.{uid}', [$this->destinations, 'handleChangedDestination'])
            ->onRemove(Destinations::CONFIG_DESTINATIONS_KEY . '.{uid}', [$this->destinations, 'handleDeletedDestination'])
            ->onAdd(Destinations::CONFIG_TRIGGERS_KEY . '.{uid}', [$this->destinations, 'handleChangedTrigger'])
            ->onUpdate(Destinations::CONFIG_TRIGGERS_KEY . '.{uid}', [$this->destinations, 'handleChangedTrigger'])
            ->onRemove(Destinations::CONFIG_TRIGGERS_KEY . '.{uid}', [$this->destinations, 'handleDeletedTrigger']);
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('tape', 'Tape'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('tape', 'View destinations and reports'),
                        'nested' => [
                            // Separate from viewing on purpose: these screens hold access tokens
                            // for advertising accounts, and “can see whether the pixel fired” is a
                            // much smaller permission than “can change where conversions are sent”.
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('tape', 'Add and edit destinations and triggers'),
                            ],
                            self::PERMISSION_RECOVER => [
                                'label' => Craft::t('tape', 'Run conversion recovery'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'tape' => 'tape/destinations/index',
                'tape/destinations' => 'tape/destinations/index',
                'tape/destinations/new' => 'tape/destinations/edit',
                'tape/destinations/<uid:[\w\-]+>' => 'tape/destinations/edit',
                'tape/triggers' => 'tape/triggers/index',
                'tape/triggers/new' => 'tape/triggers/edit',
                'tape/triggers/<uid:[\w\-]+>' => 'tape/triggers/edit',
                'tape/events' => 'tape/reports/events',
                'tape/accuracy' => 'tape/reports/accuracy',
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('tape', TapeVariable::class);
        });

        // Also as a bare global, because `{{ tape.head() }}` is what somebody types in a layout.
        Craft::$app->getView()->registerTwigExtension(new TapeExtension());
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->destinations->garbageCollect();
                $this->ledger->prune();
            } catch (Throwable $e) {
                Craft::warning('Tape garbage collection failed: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * The front-end lifecycle: collect, inject, then send anything held back.
     */
    private function registerPageHooks(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            try {
                $this->flushCarryOver();

                if ($this->getSettings()->trackPageViews) {
                    $this->events->pageView(Craft::$app->getUrlManager()->getMatchedElement() ?: null);
                }
            } catch (Throwable $e) {
                Craft::error('Tape could not collect page events: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });

        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            try {
                /** @var Response $response */
                $response = $event->sender;
                $this->tags->injectIntoResponse($response);
            } catch (Throwable $e) {
                // A tracking tag is never worth a 500 on somebody's shop.
                Craft::error('Tape could not inject its tags: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            try {
                $this->dispatcher->flushDeferred();
            } catch (Throwable $e) {
                Craft::error('Tape could not flush deferred sends: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    // ── Commerce ────────────────────────────────────────────────────────────────────────────

    /**
     * Which Commerce event each funnel step is wired to, or null where nothing matched.
     *
     * Exposed rather than private because a silently unwired hook is the worst kind of bug this
     * plugin can have: the store looks tracked, nothing errors, and the cart events simply never
     * arrive. `tape/destinations/doctor` prints this, and the integration checks assert none of it
     * is null — which is how a Commerce upgrade that renames an event becomes a failing test
     * instead of a quiet gap.
     *
     * @var array<string, string|null>
     */
    private array $commerceHooks = [];

    /**
     * The event names Commerce might publish for each step, best first.
     *
     * The `Apply` variants fire once the change has been applied to the order, which is when the
     * line item reliably knows which order it belongs to. The plain ones fire earlier and are the
     * fallback for a Commerce version that has only those.
     */
    private const COMMERCE_EVENT_CANDIDATES = [
        'purchase' => ['EVENT_AFTER_COMPLETE_ORDER'],
        'add_to_cart' => ['EVENT_AFTER_APPLY_ADD_LINE_ITEM', 'EVENT_AFTER_ADD_LINE_ITEM', 'EVENT_AFTER_ADD_LINE_ITEM_TO_ORDER'],
        'remove_from_cart' => ['EVENT_AFTER_APPLY_REMOVE_LINE_ITEM', 'EVENT_AFTER_REMOVE_LINE_ITEM', 'EVENT_AFTER_REMOVE_LINE_ITEM_FROM_ORDER'],
    ];

    /**
     * Wires the funnel to Commerce's own events, so a store needs no template changes at all.
     *
     * Every constant is looked up rather than referenced. Commerce renames these between majors —
     * `EVENT_AFTER_ADD_LINE_ITEM_TO_ORDER` does not exist in Commerce 5, though its *value*
     * (`afterAddLineItemToOrder`) does — so a hard reference would either fatal or, worse, be
     * skipped by a `defined()` guard and leave cart tracking silently off.
     */
    private function registerCommerceHooks(): void
    {
        if (!$this->getSettings()->autoTrackCommerce || !$this->commerce->isInstalled()) {
            return;
        }

        // Deferred rather than collected. Completion happens inside the payment request, which is
        // very often a redirect to a gateway and not a page anybody sees — so the event has to
        // survive until the customer lands somewhere with a template on it. If they never do,
        // recovery picks it up instead.
        $this->onCommerceEvent('purchase', function(Event $event) {
            $this->carryOver(['n' => 'purchase', 'o' => (int)$event->sender->id]);
        });

        foreach (['add_to_cart', 'remove_from_cart'] as $step) {
            $this->onCommerceEvent($step, function(Event $event) use ($step) {
                $lineItem = $event->lineItem ?? null;

                if ($lineItem === null) {
                    return;
                }

                // The order ID comes from the sender when the line item has not got one yet, which
                // is the case for the first thing added to a brand-new cart.
                $orderId = (int)($lineItem->orderId ?: ($event->sender->id ?? 0));

                if ($orderId === 0) {
                    return;
                }

                $this->carryOver([
                    'n' => $step,
                    'o' => $orderId,
                    'v' => [[(int)$lineItem->purchasableId, (int)$lineItem->qty]],
                ]);
            });
        }
    }

    private function onCommerceEvent(string $step, callable $handler): void
    {
        $class = \craft\commerce\elements\Order::class;
        $this->commerceHooks[$step] = null;

        foreach (self::COMMERCE_EVENT_CANDIDATES[$step] as $constant) {
            if (!defined("$class::$constant")) {
                continue;
            }

            Event::on($class, constant("$class::$constant"), $handler);
            $this->commerceHooks[$step] = constant("$class::$constant");

            return;
        }

        Craft::warning(
            // Braced: a curly quote straight after an interpolated variable is read as part of the
            // variable name, because PHP allows bytes above 0x7F in identifiers.
            "Tape found no Commerce event for “{$step}”. Auto-tracking for it is off; call craft.tape.{$step}() from a template instead.",
            self::LOG_CATEGORY,
        );
    }

    /**
     * Which Commerce event each funnel step ended up wired to.
     *
     * Empty until {@see registerCommerceHooks()} has run, which it only does on a front-end
     * request. {@see resolveCommerceHooks()} answers the same question from anywhere.
     *
     * @return array<string, string|null>
     */
    public function getCommerceHooks(): array
    {
        return $this->commerceHooks;
    }

    /**
     * Resolves the same mapping without needing to be on a front-end request.
     *
     * @return array<string, string|null>
     */
    public function resolveCommerceHooks(): array
    {
        if (!$this->commerce->isInstalled()) {
            return [];
        }

        $class = \craft\commerce\elements\Order::class;
        $resolved = [];

        foreach (self::COMMERCE_EVENT_CANDIDATES as $step => $candidates) {
            $resolved[$step] = null;

            foreach ($candidates as $constant) {
                if (defined("$class::$constant")) {
                    $resolved[$step] = constant("$class::$constant");
                    break;
                }
            }
        }

        return $resolved;
    }

    // ── Events that have to survive a redirect ──────────────────────────────────────────────

    /**
     * Parks an event in the session until there is a page to put it on.
     *
     * A cart update posts and redirects; a payment leaves the site entirely. Neither of those
     * requests renders a template, so an event collected there would be built, mapped and thrown
     * away. Parking it costs one session write on a request that already has a session — a cart
     * cannot exist without one — and nothing at all on a page that has no cart.
     *
     * @param array<string, mixed> $descriptor
     */
    private function carryOver(array $descriptor): void
    {
        try {
            $session = Craft::$app->getSession();
            $queue = (array)$session->get(self::CARRY_OVER_KEY, []);
            $descriptor['t'] = time();

            // Bounded, and newest-wins. Somebody adding thirty things to a basket between page
            // loads should not carry thirty events into the next page.
            $queue[] = $descriptor;
            $session->set(self::CARRY_OVER_KEY, array_slice($queue, -10));
        } catch (Throwable $e) {
            Craft::warning('Tape could not park an event: ' . $e->getMessage(), self::LOG_CATEGORY);
        }
    }

    /**
     * Collects anything parked on an earlier request.
     *
     * Guarded on the session *cookie* rather than on the session, because `getSession()->get()`
     * opens a session, a session sets a cookie, and a cookie on every front-end response is the end
     * of full-page caching for the whole site. No cookie means there was no earlier request that
     * could have parked anything.
     */
    private function flushCarryOver(): void
    {
        $name = Craft::$app->getSession()->getName();

        if (!isset($_COOKIE[$name])) {
            return;
        }

        $session = Craft::$app->getSession();
        $queue = (array)$session->get(self::CARRY_OVER_KEY, []);

        if ($queue === []) {
            return;
        }

        $session->remove(self::CARRY_OVER_KEY);

        if (!$this->commerce->isInstalled()) {
            return;
        }

        foreach ($queue as $descriptor) {
            // A parked event that has been sitting for an hour describes something the visitor has
            // long since stopped doing.
            if ((time() - (int)($descriptor['t'] ?? 0)) > 3600) {
                continue;
            }

            try {
                $this->collectCarriedEvent($descriptor);
            } catch (Throwable $e) {
                Craft::warning('Tape could not replay a parked event: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        }
    }

    /** @param array<string, mixed> $descriptor */
    private function collectCarriedEvent(array $descriptor): void
    {
        $name = (string)($descriptor['n'] ?? '');
        $orderId = (int)($descriptor['o'] ?? 0);

        if ($name === '' || $orderId === 0) {
            return;
        }

        $order = \craft\commerce\elements\Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            return;
        }

        $event = $this->commerce->eventFromOrder($order, $name);

        // A cart event describes the *line* that changed, not everything in the basket.
        if (!empty($descriptor['v'])) {
            $items = [];

            foreach ($descriptor['v'] as [$variantId, $quantity]) {
                $variant = \craft\commerce\elements\Variant::find()->id((int)$variantId)->status(null)->one();
                $item = $variant !== null ? $this->commerce->itemFromVariant($variant, (int)$quantity) : null;

                if ($item !== null) {
                    $items[] = $item;
                }
            }

            if ($items === []) {
                return;
            }

            $event->items = $items;
            $event->value = null;
        }

        $this->events->collect($event);
    }
}
