<?php

namespace justinholtweb\tape\transports;

use Craft;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * The real one: Guzzle, through Craft's configured client.
 *
 * `http_errors => false` on purpose. Every one of these APIs uses 4xx to mean something specific
 * and worth recording — a retired API version, a revoked token, a malformed field — and an
 * exception would throw that away in favour of a stack trace.
 */
class HttpTransport implements TransportInterface
{
    public function send(array $request, int $timeout): array
    {
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => min(5, $timeout),
            'http_errors' => false,
            'headers' => ($request['headers'] ?? []) + ['User-Agent' => 'Tape for Craft CMS'],
        ];

        if (!empty($request['query'])) {
            $options['query'] = $request['query'];
        }

        if (isset($request['body'])) {
            // A string is already encoded — and possibly signed — so it goes out byte for byte.
            $options[is_string($request['body']) ? 'body' : 'json'] = $request['body'];
        }

        try {
            $response = Craft::createGuzzleClient()->request(
                strtoupper($request['method'] ?? 'POST'),
                $request['url'],
                $options,
            );

            return [
                'status' => $response->getStatusCode(),
                'body' => (string)$response->getBody(),
                'error' => null,
            ];
        } catch (RequestException $e) {
            $response = $e->getResponse();

            return [
                'status' => $response?->getStatusCode() ?? 0,
                'body' => $response !== null ? (string)$response->getBody() : '',
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
    }
}
