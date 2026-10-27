# metricpanel/php

PHP 8.2+ server SDK. Requires cURL and JSON.
See [the iGaming guide](https://docs.metricpanel.io/integrations/igaming) for the contract and reporting.

```sh
composer require metricpanel/php:^0.1
```

```php
$client = new \MetricPanel\Client($serverToken);
$client->igaming($immutableOutboxPayload);
```

Persist the event ID, timestamp and entire payload in the payment transaction's outbox. Invoke
the client from a delivery worker. The client makes one attempt with a two-second connection
and ten-second total timeout; the outbox owns retries. `DeliveryException` exposes `retryable`,
`status`, `errorCode` and `retryAfterSeconds` without exposing credentials or financial payloads.
HTTP 409 is a permanent conflict, not a duplicate acknowledgement.

Amounts use exact minor-unit strings and explicit currency precision. The `Money` value object
validates this representation. No method converts floats into money. `event()` and `goal()`
use the existing general events API; `revenue()` uses the positive-integer commerce API.
They do not share the dedicated iGaming ledger's durable deduplication contract.

Install locally through a Composer path repository with an explicit development version:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/path/to/metricpanel/packages/php",
      "options": { "versions": { "metricpanel/php": "0.1.0" } }
    }
  ],
  "require": { "metricpanel/php": "0.1.0" }
}
```

Run `php tests/run.php`. Tests use only an in-memory fake HTTP transport and make no network
or database requests. Applications can inject their own `Transport` implementation.

## User identification (0.2.0)

Requires the identity-enabled MetricPanel API and website cookieless/GDPR modes disabled.
Use the visitor and session IDs supplied by your frontend SDK; do not substitute the user ID
for either. Persist the event ID and timestamp with the payload and reuse them on retries.

```php
$identity = [
    'eventId' => 'stable-identify-event-123',
    'websiteId' => 'your-public-website-id',
    'visitorId' => $browserVisitorId,
    'sessionId' => $browserSessionId,
    'timestamp' => '2026-10-27T10:00:00Z',
    'userId' => 'user_123',
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
];
$client->identify($identity);
```

Name and email are optional; `null` clears a trait. Identification records no conversion or
revenue. It links existing visitor activity to a website-scoped profile. A different user cannot
reuse the same visitor ID (HTTP 409); reset the frontend SDK on logout/account switching first.
This is not an authentication mechanism. The API token requires `events:write` access.
