<?php

namespace justinholtweb\tape\transports;

/**
 * The seam between Tape and the network.
 *
 * Exists so that the whole server-side half can be exercised in tests without a network, and so
 * that a site behind an outbound proxy can replace it. {@see \justinholtweb\tape\services\Dispatcher}
 * holds one and never calls Guzzle directly.
 */
interface TransportInterface
{
    /**
     * A `body` array is sent as JSON; a `body` string is sent exactly as given.
     *
     * @param array{url: string, method?: string, headers?: array<string, string>, body?: array|string, query?: array} $request
     * @return array{status: int, body: string, error: string|null}
     */
    public function send(array $request, int $timeout): array;
}
