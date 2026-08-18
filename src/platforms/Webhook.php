<?php

namespace justinholtweb\tape\platforms;

use Craft;
use justinholtweb\tape\helpers\Identity;
use justinholtweb\tape\models\Consent;
use justinholtweb\tape\models\Destination;
use justinholtweb\tape\models\TrackingEvent;
use justinholtweb\tape\Plugin;

/**
 * A server-side POST of the normalised event to a URL you control.
 *
 * The counterpart to {@see CustomSnippet}: that one is the browser escape hatch, this one is the
 * server escape hatch. It is what you reach for to feed a data warehouse, a Zapier hook, a CRM, or
 * a Conversions API Tape has no adapter for — the payload is {@see TrackingEvent} as JSON, which is
 * the whole point of there being one event shape.
 *
 * No browser tag at all, so it is unaffected by ad blockers and needs no consent category beyond
 * whichever one the receiving system's purpose falls under.
 */
class Webhook extends BasePlatform
{
    public static function handle(): string
    {
        return 'webhook';
    }

    public function displayName(): string
    {
        return Craft::t('tape', 'Webhook');
    }

    public function description(): string
    {
        return Craft::t('tape', 'Posts each event to a URL of yours as JSON — a warehouse, a CRM, an automation, or a platform Tape has no adapter for.');
    }

    public function requiresEdition(): string
    {
        return Plugin::EDITION_PRO;
    }

    public function defaultConsentCategory(): string
    {
        return Consent::CATEGORY_ANALYTICS;
    }

    public function settingFields(): array
    {
        return [
            'url' => [
                'label' => Craft::t('tape', 'Endpoint'),
                'instructions' => Craft::t('tape', 'An HTTPS URL. Use an environment variable so it can differ between staging and production.'),
                'placeholder' => 'https://example.com/hooks/tape',
                'required' => true,
                'type' => 'autosuggest',
            ],
            'secret' => [
                'label' => Craft::t('tape', 'Signing secret'),
                'instructions' => Craft::t('tape', 'If set, each request carries `X-Tape-Signature`: an HMAC-SHA256 of the body, so the receiver can prove it came from you.'),
                'type' => 'autosuggest',
            ],
            'header' => [
                'label' => Craft::t('tape', 'Extra header'),
                'instructions' => Craft::t('tape', 'An additional request header, as `Name: value`. For an API key the receiver expects.'),
            ],
            'includeUserData' => [
                'label' => Craft::t('tape', 'Include customer data'),
                'instructions' => Craft::t('tape', 'Sends hashed email, phone and address alongside the event. Off unless the receiver needs it.'),
                'type' => 'lightswitch',
            ],
        ];
    }

    public function supportsServerSide(): bool
    {
        return true;
    }

    /** There is no browser half — a webhook destination is server-side or it is nothing. */
    public function boot(Destination $destination, Consent $consent, array $context): array
    {
        return [];
    }

    public function mapEvent(TrackingEvent $event, Destination $destination): array
    {
        return [];
    }

    public function serverRequest(TrackingEvent $event, Destination $destination): ?array
    {
        $url = $destination->setting('url');

        if ($url === null) {
            return null;
        }

        $body = [
            'event' => $event->name,
            'eventId' => $event->eventId,
            'occurredAt' => gmdate('c', $event->occurredAt ?? time()),
            'source' => $event->source,
            'value' => $this->money($event->getValue()),
            'currency' => $this->currency($event),
            'transactionId' => $event->transactionId,
            'tax' => $event->tax,
            'shipping' => $event->shipping,
            'coupon' => $event->coupon,
            'isNewCustomer' => $event->isNewCustomer,
            'orderId' => $event->orderId,
            'elementId' => $event->elementId,
            'siteId' => $event->siteId,
            'items' => array_map(static fn($item) => array_filter($item->toArray(), static fn($v) => $v !== null && $v !== []), $event->items),
            'params' => $event->params,
        ];

        if ($destination->setting('includeUserData')) {
            $body['user'] = Identity::webhookUserData($event->userData);
        }

        $headers = ['Content-Type' => 'application/json'];

        if ($extra = (string)$destination->setting('header', '')) {
            [$name, $value] = array_pad(explode(':', $extra, 2), 2, '');

            if (trim($name) !== '' && trim($value) !== '') {
                $headers[trim($name)] = trim($value);
            }
        }

        if ($secret = $destination->setting('secret')) {
            $headers['X-Tape-Signature'] = 'sha256=' . hash_hmac('sha256', json_encode($body, JSON_UNESCAPED_SLASHES), (string)$secret);
        }

        return [
            'url' => (string)$url,
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body,
        ];
    }
}
