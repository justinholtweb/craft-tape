<?php

namespace justinholtweb\tape\helpers;

use Craft;

/**
 * Per-minute request budgets for Tape's anonymous event endpoints — per address, under a site-wide
 * ceiling.
 *
 * The same model as Eye's, PWA's and Bed's `RateLimit`, which the family's anonymous routes share.
 */
abstract class RateLimit
{
    /** The whole site's budget for a bucket, as a multiple of one address's. */
    public const GLOBAL_FACTOR = 20;

    /**
     * Whether this client may make another request to `$bucket` this minute.
     *
     * Two budgets, and both must have room: one per address, and one for everybody. The global
     * one is what stops an attacker with many addresses — a cloud range, an IPv6 block — from
     * simply spreading the load. The per-address one is spent first, so a client already over its
     * own limit doesn't eat into everybody else's.
     */
    public static function allow(string $bucket, int $perMinute): bool
    {
        $minute = intdiv(time(), 60);

        return self::consume(sprintf('tape:rate:%s:%s:%d', $bucket, sha1(self::client()), $minute), $perMinute)
            && self::consume(sprintf('tape:rate:%s:*:%d', $bucket, $minute), $perMinute * self::GLOBAL_FACTOR);
    }

    /**
     * Like {@see allow()}, over a window of `$windowSeconds` rather than a minute — for a setting
     * that already names its own window.
     */
    public static function allowWindow(string $bucket, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(time(), max(1, $windowSeconds));

        return self::consume(sprintf('tape:rate:%s:%s:w%d:%d', $bucket, sha1(self::client()), $windowSeconds, $window), $limit, $windowSeconds)
            && self::consume(sprintf('tape:rate:%s:*:w%d:%d', $bucket, $windowSeconds, $window), $limit * self::GLOBAL_FACTOR, $windowSeconds);
    }

    /**
     * Whether `$identity` — a signed-in user, say — may make another request to `$bucket` this
     * minute. For actions that already know who is asking, where an address is the wrong unit.
     */
    public static function allowFor(string $bucket, string $identity, int $perMinute): bool
    {
        return self::consume(sprintf('tape:rate:%s:user:%s:%d', $bucket, sha1($identity), intdiv(time(), 60)), $perMinute);
    }

    /**
     * Whether `$bucket` may be used again this minute by anybody at all.
     *
     * The ceiling over every client together, for the one thing worth bounding globally: new rows
     * in a table the public can write to. A per-client limit does nothing against many clients.
     */
    public static function allowGlobal(string $bucket, int $perMinute): bool
    {
        return self::consume(sprintf('tape:rate:%s:all:%d', $bucket, intdiv(time(), 60)), $perMinute);
    }

    /**
     * Who is asking, for rate-limiting purposes.
     *
     * The connecting address, not `getUserIP()`: Craft reads that from `Client-IP`,
     * `X-Forwarded-For` and their relatives without asking who sent them, so a client that changes
     * the header on every request gets a fresh budget every time. The forwarded address is only
     * believed when the site has said which proxies to trust — Craft's default of "any" is not
     * saying so.
     */
    public static function client(): string
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return 'console';
        }

        $trusted = array_values(array_filter((array)$request->trustedHosts));
        $trustsProxies = $trusted !== [] && !in_array('any', $trusted, true);

        return self::key((string)$request->getRemoteIP(), $trustsProxies ? $request->getUserIP() : null);
    }

    /**
     * The budget key for an address. Pure, so it can be tested.
     *
     * IPv6 is grouped by /64. One subscriber line is routinely handed a whole /64, so keying on
     * the full address gives every client 2^64 budgets to rotate through.
     */
    public static function key(string $remoteIp, ?string $forwardedIp = null): string
    {
        $ip = $forwardedIp !== null && $forwardedIp !== '' ? $forwardedIp : $remoteIp;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed !== false) {
                return bin2hex(substr($packed, 0, 8)) . '::/64';
            }
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    /**
     * The count is read and written under a mutex: without it, requests sent in parallel all read
     * the same number and none is ever refused. A busy lock refuses rather than queues — a worker
     * held up waiting cannot serve anyone.
     */
    private static function consume(string $key, int $limit, int $ttl = 60): bool
    {
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 2)) {
            return false;
        }

        try {
            $count = (int)$cache->get($key);

            if ($count >= $limit) {
                return false;
            }

            $cache->set($key, $count + 1, $ttl * 2);

            return true;
        } finally {
            $mutex->release($key);
        }
    }
}
