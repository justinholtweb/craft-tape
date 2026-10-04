/**
 * Tape — the front-end runtime.
 *
 * This file makes no decisions. Every payload it fires was built in PHP, by a platform adapter,
 * from an event Craft already knew about; nothing here knows what a `purchase` is or what Meta
 * would like to be told about one. What it does own is the three things that can only be answered
 * in a browser:
 *
 *   1. **Consent** — reading a CMP's answer, watching for it to change, and mirroring it into a
 *      first-party cookie so PHP can read it on the next request.
 *   2. **Loading** — each vendor's own snippet, transcribed, run only once the destination's
 *      consent category has been granted, and reported back if it fails.
 *   3. **Triggers** — scroll depth, clicks, form submissions, visibility, time on page.
 *
 * Events that arrive before their destination is allowed to load are held, not dropped. That is
 * what makes accepting a cookie banner start tracking immediately rather than on the next page.
 */
(function (window, document) {
  'use strict';

  var CONFIG = window.__TAPE__;
  if (!CONFIG || !CONFIG.destinations) return;

  var ADS = 'ads', ANALYTICS = 'analytics', FUNCTIONALITY = 'functionality';
  var GRANTED = 'granted', DENIED = 'denied';

  var state = {
    consent: {},          // signal -> 'granted' | 'denied' | undefined
    booted: {},           // destination handle -> true once its loader has run
    failed: {},           // destination handle -> true if its script did not load
    held: [],             // dispatch entries waiting on consent
    firedTriggers: {},
    gtagStarted: false
  };

  // ── Utilities ─────────────────────────────────────────────────────────────────────────────

  function log() {
    if (!CONFIG.debug || !window.console) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[Tape]');
    window.console.log.apply(window.console, args);
  }

  /**
   * Loads a vendor script.
   *
   * `onerror` is the whole reason this is not one line. A blocked pixel is the single most common
   * cause of missing conversions, and it is invisible to everyone involved — the site owner sees
   * a tag installed, the platform sees nothing. Reporting the failure back is what lets Tape
   * recover the conversion from the server instead.
   */
  function loadScript(src, handle, onload) {
    var el = document.createElement('script');
    el.async = true;
    el.src = src;
    el.onload = function () { if (onload) onload(); };
    el.onerror = function () {
      if (handle) {
        state.failed[handle] = true;
        log('blocked:', handle, src);
      }
    };
    var first = document.getElementsByTagName('script')[0];
    if (first && first.parentNode) {
      first.parentNode.insertBefore(el, first);
    } else {
      (document.head || document.documentElement).appendChild(el);
    }
    return el;
  }

  /** Resolves a dotted path like `ttq.track` against `window`, keeping its receiver. */
  function resolve(path) {
    var parts = String(path).split('.');
    var context = window;
    for (var i = 0; i < parts.length - 1; i++) {
      context = context[parts[i]];
      if (!context) return null;
    }
    var fn = context[parts[parts.length - 1]];
    if (typeof fn !== 'function' && !(fn && typeof fn.push === 'function')) return null;
    return { context: context, fn: fn };
  }

  /** A v4 UUID — the same shape PHP's `StringHelper::UUID()` produces, which the endpoints check. */
  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    var bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    var hex = '';
    for (var j = 0; j < 16; j++) hex += (bytes[j] + 0x100).toString(16).slice(1);
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }

  /** A deep copy of `value` with every string equal to `from` replaced by `to`. */
  function rekey(value, from, to) {
    if (value === from) return to;
    if (Array.isArray(value)) return value.map(function (v) { return rekey(v, from, to); });
    if (value && typeof value === 'object') {
      var copy = {};
      for (var key in value) {
        if (Object.prototype.hasOwnProperty.call(value, key)) copy[key] = rekey(value[key], from, to);
      }
      return copy;
    }
    return value;
  }

  function readCookie(name) {
    var match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return match ? decodeURIComponent(match.pop()) : null;
  }

  function writeCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    var secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + expires + ';path=/;SameSite=Lax' + secure;
  }

  function post(url, body, keepalive) {
    var payload = JSON.stringify(body);
    // `sendBeacon` survives the page being closed, which is exactly when a confirmation is most
    // worth having — the visitor who navigates away the instant the order lands.
    if (keepalive && navigator.sendBeacon) {
      try {
        return navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
      } catch (e) { /* fall through to fetch */ }
    }
    if (!window.fetch) return false;
    return window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        // Craft's `requireAcceptsJson()` reads this header and nothing else. Without it the map
        // endpoint answers 400 and `tape.track()` silently never works.
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: payload,
      keepalive: !!keepalive
    });
  }

  // ── Consent ───────────────────────────────────────────────────────────────────────────────

  var CMP = {
    cookiebot: function () {
      var c = window.Cookiebot && window.Cookiebot.consent;
      if (!c) return null;
      return { ads: !!c.marketing, analytics: !!c.statistics, functionality: !!c.preferences };
    },
    cookieyes: function () {
      var raw = readCookie('cookieyes-consent');
      if (!raw) return null;
      return {
        ads: raw.indexOf('advertisement:yes') > -1,
        analytics: raw.indexOf('analytics:yes') > -1,
        functionality: raw.indexOf('functional:yes') > -1
      };
    },
    complianz: function () {
      if (typeof window.cmplz_has_consent !== 'function') return null;
      return {
        ads: !!window.cmplz_has_consent('marketing'),
        analytics: !!window.cmplz_has_consent('statistics'),
        functionality: !!window.cmplz_has_consent('functional')
      };
    },
    iubenda: function () {
      var cs = window._iub && window._iub.cs;
      var purposes = cs && cs.consent && cs.consent.purposes;
      if (!purposes) return null;
      // Iubenda's fixed purposes: 4 is measurement, 5 is targeting, 3 is functionality.
      return { ads: !!purposes[5], analytics: !!purposes[4], functionality: !!purposes[3] };
    },
    onetrust: function () {
      var groups = window.OnetrustActiveGroups || window.OptanonActiveGroups;
      if (!groups) return null;
      return {
        ads: groups.indexOf('C0004') > -1,
        analytics: groups.indexOf('C0002') > -1,
        functionality: groups.indexOf('C0003') > -1
      };
    },
    osano: function () {
      var cm = window.Osano && window.Osano.cm;
      var consent = cm && typeof cm.getConsent === 'function' ? cm.getConsent() : null;
      if (!consent) return null;
      var yes = function (v) { return v === 'ACCEPT' || v === true; };
      return { ads: yes(consent.MARKETING), analytics: yes(consent.ANALYTICS), functionality: yes(consent.PERSONALIZATION) };
    },
    termly: function () {
      var t = window.Termly;
      var s = t && typeof t.getConsentState === 'function' ? t.getConsentState() : null;
      if (!s) return null;
      return { ads: !!s.advertising, analytics: !!s.analytics, functionality: !!s.performance };
    },
    klaro: function () {
      var manager = window.klaro && window.klaro.getManager ? window.klaro.getManager() : null;
      var consents = manager && manager.consents;
      if (!consents) return null;
      // Klaro's service names are chosen per site, so this matches on what they are usually called.
      var any = function (needles) {
        for (var key in consents) {
          if (!Object.prototype.hasOwnProperty.call(consents, key)) continue;
          for (var i = 0; i < needles.length; i++) {
            if (key.toLowerCase().indexOf(needles[i]) > -1 && consents[key]) return true;
          }
        }
        return false;
      };
      return {
        ads: any(['ad', 'marketing', 'facebook', 'pixel']),
        analytics: any(['analytic', 'matomo', 'statistic']),
        functionality: any(['function', 'preference'])
      };
    },
    usercentrics: function () {
      var ui = window.UC_UI;
      if (!ui || typeof ui.getServicesBaseInfo !== 'function') return null;
      var services = ui.getServicesBaseInfo() || [];
      var granted = function (needles) {
        for (var i = 0; i < services.length; i++) {
          var name = (services[i].name || '').toLowerCase();
          for (var j = 0; j < needles.length; j++) {
            if (name.indexOf(needles[j]) > -1 && services[i].consent && services[i].consent.status) return true;
          }
        }
        return false;
      };
      return {
        ads: granted(['ads', 'facebook', 'pixel', 'tiktok']),
        analytics: granted(['analytics', 'ga4', 'matomo']),
        functionality: granted(['functional'])
      };
    },
    civic: function () {
      var cc = window.CookieControl;
      if (!cc || typeof cc.getCategoryConsent !== 'function') return null;
      return { ads: !!cc.getCategoryConsent(2), analytics: !!cc.getCategoryConsent(1), functionality: !!cc.getCategoryConsent(0) };
    },
    custom: function () {
      var expression = CONFIG.consent && CONFIG.consent.expression;
      if (!expression) return null;
      try {
        // Evaluated because it is written by a site administrator in the control panel — the same
        // level of trust as a template. Never anything a visitor can influence.
        /* jshint evil:true */
        return new Function('return (' + expression + ');')();
      } catch (e) {
        log('custom consent expression failed:', e);
        return null;
      }
    }
  };

  /** Expands a three-category answer into Consent Mode's seven signals. */
  function expand(categories) {
    if (!categories) return null;
    if (categories.ad_storage || categories.analytics_storage) return categories; // already expanded
    var flag = function (v) { return v ? GRANTED : DENIED; };
    return {
      ad_storage: flag(categories.ads),
      ad_user_data: flag(categories.ads),
      ad_personalization: flag(categories.ads),
      analytics_storage: flag(categories.analytics),
      functionality_storage: flag(categories.functionality),
      personalization_storage: flag(categories.functionality),
      security_storage: GRANTED
    };
  }

  function decodeCookie(value) {
    var config = CONFIG.consent;
    if (!value || value.indexOf(config.cookieVersion + ':') !== 0) return null;
    var flags = value.slice(config.cookieVersion.length + 1);
    if (flags.length !== config.signals.length) return null;
    var signals = {};
    for (var i = 0; i < config.signals.length; i++) {
      if (flags[i] === '1') signals[config.signals[i]] = GRANTED;
      else if (flags[i] === '0') signals[config.signals[i]] = DENIED;
    }
    return signals;
  }

  function encodeCookie(signals) {
    var config = CONFIG.consent;
    var out = '';
    for (var i = 0; i < config.signals.length; i++) {
      var value = signals[config.signals[i]];
      out += value === GRANTED ? '1' : (value === DENIED ? '0' : '-');
    }
    return config.cookieVersion + ':' + out;
  }

  function allows(category) {
    if (!CONFIG.consent.mode) return true;
    if (category === ADS) return state.consent.ad_storage === GRANTED;
    if (category === ANALYTICS) return state.consent.analytics_storage === GRANTED;
    if (category === FUNCTIONALITY) return state.consent.functionality_storage === GRANTED;
    return false;
  }

  /**
   * Applies a new consent state.
   *
   * Three things happen, in this order and for a reason: Google's tags are told first (they are
   * the ones that model the gap and need to hear about an upgrade as soon as it happens), the
   * answer is mirrored into a cookie so the *server* can honour it on the next request, and then
   * any destination that has just become permitted is booted and its held events released.
   */
  function updateConsent(partial, mirror) {
    var signals = expand(partial);
    if (!signals) return;

    var changed = false;
    for (var key in signals) {
      if (!Object.prototype.hasOwnProperty.call(signals, key)) continue;
      if (state.consent[key] !== signals[key]) { state.consent[key] = signals[key]; changed = true; }
    }
    if (!changed) return;

    log('consent', state.consent);

    if (CONFIG.consent.mode && window.gtag) {
      window.gtag('consent', 'update', state.consent);
    }

    if (mirror !== false) {
      writeCookie(CONFIG.consent.cookie, encodeCookie(state.consent), CONFIG.consent.cookieDays);
    }

    bootDestinations();
    activateSnippets();
    flushHeld();
  }

  /** Reads whatever answer is available now, and subscribes to it changing. */
  function startConsent() {
    var config = CONFIG.consent;

    if (!config.mode) {
      state.consent = expand({ ads: true, analytics: true, functionality: true });
      return;
    }

    // Read from the mirror cookie rather than from the payload. The page may have come out of a
    // full-page cache, in which case anything the server knew about a visitor is somebody else's.
    var cookie = decodeCookie(readCookie(config.cookie));

    state.consent = cookie || (config.source === 'granted'
      ? expand({ ads: true, analytics: true, functionality: true })
      : expand({ ads: false, analytics: false, functionality: false }));

    if (config.respectDnt && (navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true)) {
      state.consent = expand({ ads: false, analytics: false, functionality: false });
      return;
    }

    if (config.source !== 'cmp') return;

    var bridge = CMP[config.cmp];
    if (!bridge) { log('unknown consent platform:', config.cmp); return; }

    var poll = function () {
      var answer = bridge();
      if (answer) updateConsent(answer);
    };

    poll();

    // Every one of these platforms announces itself differently and several announce twice, so the
    // bridge is idempotent and simply listens for all of them.
    var events = [
      'CookiebotOnAccept', 'CookiebotOnDecline', 'cookieyes_consent_update', 'cmplz_status_change',
      'OneTrustGroupsUpdated', 'osano-cm-consent-saved', 'osano-cm-consent-changed',
      'termly:consent-updated', 'klaro:consent-change', 'ucEvent', 'UC_UI_CMP_EVENT',
      'CookieControlOnAccept', 'consent.onetrust', 'iubenda-consent-given'
    ];
    for (var i = 0; i < events.length; i++) {
      window.addEventListener(events[i], poll, false);
      document.addEventListener(events[i], poll, false);
    }

    // A CMP that answers only after its own script loads gets a short grace period rather than a
    // permanent interval — a timer running for the life of the page to watch a value that changes
    // twice is not worth the battery.
    var attempts = 0;
    var timer = window.setInterval(function () {
      poll();
      if (++attempts > 20) window.clearInterval(timer);
    }, 500);
  }

  // ── Loaders ───────────────────────────────────────────────────────────────────────────────

  var LOADERS = {
    gtag: function (config, handle) {
      window.dataLayer = window.dataLayer || [];
      window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
      if (!state.gtagStarted) {
        state.gtagStarted = true;
        window.gtag('js', new Date());
        loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.id), handle);
      }
      window.gtag('config', config.id, config.params || {});
    },

    gtm: function (config, handle) {
      var name = config.dataLayer || 'dataLayer';
      window[name] = window[name] || [];
      window[name].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
      loadScript(config.src, handle);
    },

    fbq: function (config, handle) {
      if (!window.fbq) {
        var n = window.fbq = function () {
          n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
        };
        if (!window._fbq) window._fbq = n;
        n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
        loadScript('https://connect.facebook.net/en_US/fbevents.js', handle);
      }
      var matching = config.matching && Object.keys(config.matching).length ? config.matching : undefined;
      window.fbq('init', config.id, matching);
    },

    ttq: function (config, handle) {
      var t = 'ttq';
      window.TiktokAnalyticsObject = t;
      var ttq = window[t] = window[t] || [];
      if (!ttq.methods) {
        ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie'];
        ttq.setAndDefer = function (target, method) {
          target[method] = function () {
            target.push([method].concat(Array.prototype.slice.call(arguments, 0)));
          };
        };
        for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]);
        ttq.instance = function (id) {
          var instance = ttq._i[id] || [];
          for (var j = 0; j < ttq.methods.length; j++) ttq.setAndDefer(instance, ttq.methods[j]);
          return instance;
        };
        ttq.load = function (id, options) {
          var url = 'https://analytics.tiktok.com/i18n/pixel/events.js';
          ttq._i = ttq._i || {}; ttq._i[id] = []; ttq._i[id]._u = url;
          ttq._t = ttq._t || {}; ttq._t[id] = +new Date();
          ttq._o = ttq._o || {}; ttq._o[id] = options || {};
          loadScript(url + '?sdkid=' + encodeURIComponent(id) + '&lib=' + t, handle);
        };
      }
      ttq.load(config.id);
      if (config.identify && Object.keys(config.identify).length) ttq.identify(config.identify);
    },

    pintrk: function (config, handle) {
      if (!window.pintrk) {
        window.pintrk = function () { window.pintrk.queue.push(Array.prototype.slice.call(arguments)); };
        window.pintrk.queue = [];
        window.pintrk.version = '3.0';
        loadScript('https://s.pinimg.com/ct/core.js', handle);
      }
      window.pintrk('load', config.id, config.matching || {});
      window.pintrk('page');
    },

    snaptr: function (config, handle) {
      if (!window.snaptr) {
        var a = window.snaptr = function () {
          a.handleRequest ? a.handleRequest.apply(a, arguments) : a.queue.push(arguments);
        };
        a.queue = [];
        loadScript('https://sc-static.net/scevent.min.js', handle);
      }
      window.snaptr('init', config.id, config.matching || {});
    },

    rdt: function (config, handle) {
      if (!window.rdt) {
        var p = window.rdt = function () {
          p.sendEvent ? p.sendEvent.apply(p, arguments) : p.callQueue.push(arguments);
        };
        p.callQueue = [];
        loadScript('https://www.redditstatic.com/ads/pixel.js', handle);
      }
      window.rdt('init', config.id, config.matching || {});
    },

    twq: function (config, handle) {
      if (!window.twq) {
        var s = window.twq = function () {
          s.exe ? s.exe.apply(s, arguments) : s.queue.push(arguments);
        };
        s.version = '1.1'; s.queue = [];
        loadScript('https://static.ads-twitter.com/uwt.js', handle);
      }
      window.twq('config', config.id);
    },

    lintrk: function (config, handle) {
      window._linkedin_partner_id = String(config.id);
      window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || [];
      window._linkedin_data_partner_ids.push(String(config.id));
      if (!window.lintrk) {
        window.lintrk = function (a, b) { window.lintrk.q.push([a, b]); };
        window.lintrk.q = [];
        loadScript('https://snap.licdn.com/li.lms-analytics/insight.min.js', handle);
      }
    },

    uetq: function (config, handle) {
      window.uetq = window.uetq || [];
      window.uetq.push('consent', 'default', { ad_storage: allows(ADS) ? GRANTED : DENIED });
      loadScript('https://bat.bing.com/bat.js', handle, function () {
        try {
          var options = { ti: config.id, enableAutoSpaTracking: false };
          options.q = window.uetq;
          /* global UET */
          window.uetq = new UET(options);
          window.uetq.push('pageLoad');
          if (config.enhanced && Object.keys(config.enhanced).length) {
            window.uetq.push('set', { pid: config.enhanced });
          }
        } catch (e) { log('UET init failed:', e); }
      });
    },

    clarity: function (config, handle) {
      window.clarity = window.clarity || function () {
        (window.clarity.q = window.clarity.q || []).push(arguments);
      };
      loadScript('https://www.clarity.ms/tag/' + encodeURIComponent(config.id), handle);
      window.clarity('consent', allows(ANALYTICS));
    },

    hotjar: function (config, handle) {
      window.hj = window.hj || function () { (window.hj.q = window.hj.q || []).push(arguments); };
      window._hjSettings = { hjid: config.id, hjsv: 6 };
      loadScript('https://static.hotjar.com/c/hotjar-' + config.id + '.js?sv=6', handle);
    },

    /** The custom-snippet destination: a plain array somebody else's code reads. */
    queue: function (config) {
      var name = config.queue || 'tapeEvents';
      window[name] = window[name] || [];
    }
  };

  function bootDestinations() {
    for (var i = 0; i < CONFIG.destinations.length; i++) {
      var destination = CONFIG.destinations[i];
      if (state.booted[destination.h]) continue;
      if (!destination.now && !allows(destination.c)) continue;

      var loader = LOADERS[destination.loader];
      if (!loader) { log('no loader for', destination.loader); continue; }

      try {
        loader(destination.config || {}, destination.h);
        state.booted[destination.h] = true;
        log('booted', destination.h);
      } catch (e) {
        state.failed[destination.h] = true;
        log('boot failed for', destination.h, e);
      }
    }
  }

  // ── Dispatch ──────────────────────────────────────────────────────────────────────────────

  /**
   * Fires one pre-built payload, or holds it.
   *
   * A destination that is booted but whose consent has not been granted still cannot fire — the
   * Google tags are the only ones loaded ahead of consent, and they enforce it themselves.
   */
  function dispatchOne(entry) {
    if (!allows(entry.c)) {
      var isGoogleTag = state.booted[entry.d] && entry.fn && entry.fn.indexOf('gtag') === 0;
      if (!isGoogleTag) { state.held.push(entry); return false; }
    }

    if (!state.booted[entry.d]) { state.held.push(entry); return false; }

    var target = resolve(entry.fn);
    if (!target) { state.held.push(entry); return false; }

    try {
      if (typeof target.fn === 'function') {
        target.fn.apply(target.context, entry.args);
      } else {
        target.fn.push.apply(target.fn, entry.args);
      }
      log('→', entry.d, entry.fn, entry.args);
      return true;
    } catch (e) {
      log('dispatch failed', entry.d, e);
      return false;
    }
  }

  function flushHeld() {
    if (!state.held.length) return;
    var queued = state.held;
    state.held = [];
    for (var i = 0; i < queued.length; i++) dispatchOne(queued[i]);
  }

  /**
   * Fires an event's payloads and, for a conversion, reports what happened.
   *
   * The report is what turns `emitted` into `sent` in Craft's ledger, and it is the difference
   * between "a purchase tag was written into the page" and "the platform got it". Recovery uses
   * exactly that difference.
   */
  function dispatchEvent(item) {
    var delivered = {}, blocked = {};
    var entries = item.d || [];

    for (var i = 0; i < entries.length; i++) {
      var entry = entries[i];
      if (dispatchOne(entry)) delivered[entry.d] = true;
    }

    if (item.n !== 'purchase' && item.n !== 'refund') return;

    // Give blocked scripts a moment to fail. `onerror` fires asynchronously, and reporting before
    // it has would mark an ad-blocked pixel as delivered.
    window.setTimeout(function () {
      var ok = [], bad = [];
      for (var handle in delivered) {
        if (!Object.prototype.hasOwnProperty.call(delivered, handle)) continue;
        (state.failed[handle] ? bad : ok).push(handle);
      }
      for (var failedHandle in state.failed) {
        if (Object.prototype.hasOwnProperty.call(state.failed, failedHandle) && bad.indexOf(failedHandle) === -1 && delivered[failedHandle]) {
          bad.push(failedHandle);
        }
      }
      if (!ok.length && !bad.length) return;
      post(CONFIG.endpoints.confirm, { eventId: item.i, delivered: ok, blocked: bad }, true);
    }, 2500);
  }

  function dispatchAll(items) {
    for (var i = 0; i < items.length; i++) dispatchEvent(items[i]);
  }

  // ── Triggers ──────────────────────────────────────────────────────────────────────────────

  function triggerFired(trigger, suffix) {
    var key = trigger.u + (suffix || '');

    if (trigger.once === 'page' && state.firedTriggers[key]) return false;
    if (trigger.once === 'session') {
      var storageKey = 'tape.t.' + key;
      try {
        if (window.sessionStorage.getItem(storageKey)) return false;
        window.sessionStorage.setItem(storageKey, '1');
      } catch (e) { /* private mode; fall back to per-page */ }
    }

    state.firedTriggers[key] = true;
    return true;
  }

  function fireTrigger(trigger, suffix, extra) {
    if (!triggerFired(trigger, suffix)) return;

    // The payloads were mapped when the page rendered, so the event ID inside them is the same for
    // every visitor a cached page is served to — and for every firing on this one. Platforms
    // deduplicate on it, so left alone they would count all of those as one conversion. Each firing
    // gets its own, here, and the server half is told the same one so the pair still deduplicates.
    var eventId = uuid();

    log('trigger', trigger.t, trigger.n);
    dispatchEvent({ n: trigger.n, i: eventId, d: rekey(trigger.d, trigger.i, eventId) });

    // The server-side half only exists for destinations configured for it, and only then is a
    // round trip worth making.
    if (trigger.s) {
      post(CONFIG.endpoints.trigger, { trigger: trigger.u, eventId: eventId, params: extra || {} }, false);
    }
  }

  function matches(element, selector) {
    if (!element || element.nodeType !== 1) return null;
    var node = element;
    while (node && node.nodeType === 1) {
      if (node.matches && node.matches(selector)) return node;
      node = node.parentNode;
    }
    return null;
  }

  function startTriggers() {
    var triggers = CONFIG.triggers || [];
    if (!triggers.length) return;

    var scrollTriggers = [], timeTriggers = [], visibleTriggers = [], clickTriggers = [], formTriggers = [];

    for (var i = 0; i < triggers.length; i++) {
      var trigger = triggers[i];
      switch (trigger.t) {
        case 'scroll': scrollTriggers.push(trigger); break;
        case 'time': timeTriggers.push(trigger); break;
        case 'visible': visibleTriggers.push(trigger); break;
        case 'formSubmit': formTriggers.push(trigger); break;
        default: clickTriggers.push(trigger);
      }
    }

    if (clickTriggers.length) {
      document.addEventListener('click', function (e) {
        for (var i = 0; i < clickTriggers.length; i++) {
          var trigger = clickTriggers[i];
          var target = null;

          if (trigger.t === 'click') {
            target = matches(e.target, trigger.sel);
          } else if (trigger.t === 'tel') {
            target = matches(e.target, 'a[href^="tel:"]');
          } else if (trigger.t === 'mailto') {
            target = matches(e.target, 'a[href^="mailto:"]');
          } else if (trigger.t === 'outbound') {
            var link = matches(e.target, 'a[href]');
            if (link && link.hostname && link.hostname !== window.location.hostname) target = link;
          }

          if (target) {
            fireTrigger(trigger, '', { link_url: target.href || null, link_text: (target.textContent || '').trim().slice(0, 100) });
          }
        }
      }, true);
    }

    if (formTriggers.length) {
      document.addEventListener('submit', function (e) {
        for (var i = 0; i < formTriggers.length; i++) {
          if (matches(e.target, formTriggers[i].sel)) fireTrigger(formTriggers[i], '');
        }
      }, true);
    }

    if (scrollTriggers.length) {
      var onScroll = function () {
        var doc = document.documentElement;
        var height = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0) - window.innerHeight;
        if (height <= 0) return;
        var percent = Math.min(100, Math.round((window.pageYOffset / height) * 100));

        for (var i = 0; i < scrollTriggers.length; i++) {
          var trigger = scrollTriggers[i];
          for (var j = 0; j < trigger.th.length; j++) {
            if (percent >= trigger.th[j]) {
              fireTrigger(trigger, ':' + trigger.th[j], { percent_scrolled: trigger.th[j] });
            }
          }
        }
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }

    for (var t = 0; t < timeTriggers.length; t++) {
      (function (trigger) {
        for (var j = 0; j < trigger.th.length; j++) {
          (function (seconds) {
            window.setTimeout(function () {
              fireTrigger(trigger, ':' + seconds, { seconds: seconds });
            }, seconds * 1000);
          })(trigger.th[j]);
        }
      })(timeTriggers[t]);
    }

    if (visibleTriggers.length && window.IntersectionObserver) {
      for (var v = 0; v < visibleTriggers.length; v++) {
        (function (trigger) {
          var elements = document.querySelectorAll(trigger.sel);
          if (!elements.length) return;
          var observer = new IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
              if (entries[i].isIntersecting) {
                fireTrigger(trigger, '');
                observer.unobserve(entries[i].target);
              }
            }
          }, { threshold: 0.5 });
          for (var e = 0; e < elements.length; e++) observer.observe(elements[e]);
        })(visibleTriggers[v]);
      }
    }
  }

  // ── Custom snippets ───────────────────────────────────────────────────────────────────────

  /**
   * Brings a custom snippet to life once its consent category allows it.
   *
   * The HTML sits in the page inside a `<template>`, which is inert: nothing loads, nothing runs,
   * nothing is requested. That is what lets an admin-written third-party snippet be consent-gated
   * *and* live on a page served from a full-page cache — the alternative, deciding server-side
   * whether to print it, bakes one visitor's answer into everybody's page.
   *
   * Script elements have to be rebuilt rather than cloned: a `<script>` moved out of a template
   * never executes.
   */
  function activateSnippets() {
    var templates = document.querySelectorAll('template[data-tape-snippet]');

    for (var i = 0; i < templates.length; i++) {
      (function (template) {
        var category = template.getAttribute('data-tape-consent') || ADS;
        if (template.getAttribute('data-tape-done')) return;
        if (!allows(category)) return;

        template.setAttribute('data-tape-done', '1');
        var fragment = template.content.cloneNode(true);
        var scripts = fragment.querySelectorAll('script');

        for (var s = 0; s < scripts.length; s++) {
          var original = scripts[s];
          var replacement = document.createElement('script');
          for (var a = 0; a < original.attributes.length; a++) {
            replacement.setAttribute(original.attributes[a].name, original.attributes[a].value);
          }
          replacement.text = original.text;
          original.parentNode.replaceChild(replacement, original);
        }

        template.parentNode.insertBefore(fragment, template);
        log('snippet activated', template.getAttribute('data-tape-snippet'));
      })(templates[i]);
    }
  }

  // ── Public API ────────────────────────────────────────────────────────────────────────────

  var api = {
    /**
     * Fires an event that was not on the page when it rendered — an Ajax add-to-cart, a step in a
     * single-page checkout, a custom conversion.
     *
     * The payload is built on the server, because that is where the platform adapters live and
     * because it is the only way a visitor cannot dictate what gets sent.
     */
    track: function (name, params) {
      var body = { event: name, params: params || {} };
      var request = post(CONFIG.endpoints.map, body, false);
      if (!request || !request.then) return;
      return request.then(function (response) { return response.json(); }).then(function (data) {
        if (data && data.events) dispatchAll(data.events);
        return data;
      }).catch(function (e) { log('track failed', e); });
    },

    /** Hands Tape a consent answer from whatever banner the site uses. */
    consent: {
      update: function (categories) { updateConsent(categories); },
      grantAll: function () { updateConsent({ ads: true, analytics: true, functionality: true }); },
      denyAll: function () { updateConsent({ ads: false, analytics: false, functionality: false }); },
      get: function () {
        var copy = {};
        for (var key in state.consent) {
          if (Object.prototype.hasOwnProperty.call(state.consent, key)) copy[key] = state.consent[key];
        }
        return copy;
      }
    },

    /** What the runtime currently believes, for debugging from a console. */
    debug: function () {
      return { consent: api.consent.get(), booted: state.booted, failed: state.failed, held: state.held.length };
    }
  };

  // Anything queued against `window.tape` before this file ran — `window.tape = window.tape || []`
  // in a template, then `tape.push(['track', 'x'])` — is replayed now.
  var early = window.tape;
  window.tape = api;

  if (early && early.length) {
    for (var q = 0; q < early.length; q++) {
      var call = early[q];
      if (call && call.length && typeof api[call[0]] === 'function') {
        api[call[0]].apply(api, call.slice(1));
      }
    }
  }

  // ── Start ─────────────────────────────────────────────────────────────────────────────────

  startConsent();
  bootDestinations();

  activateSnippets();
  dispatchAll(CONFIG.events || []);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startTriggers);
  } else {
    startTriggers();
  }
})(window, document);
