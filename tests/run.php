<?php

declare(strict_types=1);
// No external services, dependencies or database: fake transport only.
spl_autoload_register(function (string $class): void {
    $prefix = 'MetricPanel\\';
    if (str_starts_with($class, $prefix)) require __DIR__.'/../src/'.substr($class, strlen($prefix)).'.php';
});
use MetricPanel\{Client, DeliveryException, HttpResponse, Money, Transport};
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
final class FakeTransport implements Transport {
    public array $calls = [];
    public HttpResponse $response;
    public function __construct() { $this->response = new HttpResponse(201, '{"status":"success","data":{"created":true,"eventId":"deposit-001"}}'); }
    public function post(string $url, string $token, array $payload): HttpResponse {
        $this->calls[] = [$url, $token, $payload]; return $this->response;
    }
}
$transport = new FakeTransport();
$client = new Client('server-token', 'https://api.metricpanel.test', $transport);
$event = [
    'schemaVersion' => 1, 'eventId' => 'deposit-001', 'operatorId' => 'operator', 'playerId' => 'pseudonym',
    'transactionId' => 'transaction-001', 'type' => 'deposit.completed', 'timestamp' => '2026-01-01T12:00:00Z',
    'currency' => 'EUR', 'precision' => 2, 'cashAmount' => '90071992547409931234', 'bonusAmount' => '0',
];
$client->igaming($event); $client->igaming($event);
check($transport->calls[0] === $transport->calls[1], 'Retries must preserve the entire payload');
check($transport->calls[0][2]['cashAmount'] === '90071992547409931234', 'Exact units must stay strings');
foreach ([408, 425, 429, 500, 503, 400, 401, 403, 409] as $status) {
    $transport->response = new HttpResponse($status, '{"status":"error","error":{"code":"test_error"}}', '31');
    try { $client->igaming($event); throw new RuntimeException('Expected failure'); }
    catch (DeliveryException $e) {
        check($e->retryable === (in_array($status, [408, 425, 429]) || $status >= 500), 'Wrong retry classification');
        check($e->retryAfterSeconds === 31, 'Retry-After lost');
        check(!str_contains($e->getMessage(), 'server-token'), 'Credentials leaked');
    }
}
$transport->response = new HttpResponse(200, '<html>Proxy error</html>');
try { $client->igaming($event); throw new RuntimeException('Malformed success accepted'); }
catch (DeliveryException $e) { check($e->retryable, 'Ambiguous response must retry'); }
foreach (['-1', '1.01', '1e2', '01'] as $amount) {
    try { new Money($amount, 'EUR', 2); throw new RuntimeException('Invalid amount accepted'); }
    catch (InvalidArgumentException) {}
}
try { $client->igaming([...$event, 'cashAmount' => 100]); throw new RuntimeException('Integer amount accepted'); }
catch (InvalidArgumentException) {}
try { new Client('token', 'http://example.com'); throw new RuntimeException('Insecure remote URL accepted'); }
catch (InvalidArgumentException) {}
try { $client->revenue(['amount' => 9007199254740992, 'transactionId' => 'one', 'timestamp' => $event['timestamp']]); throw new RuntimeException('Unsafe integer accepted'); }
catch (InvalidArgumentException) {}
try { $client->goal(['eventId' => 'long-stable-event-id', 'timestamp' => $event['timestamp'], 'name' => 'igaming.first_deposit']); throw new RuntimeException('Reserved goal accepted'); }
catch (InvalidArgumentException) {}
$transport->response = new HttpResponse(202, '{"success":true,"processingMode":"queue"}');
$client->event(['eventId' => 'long-stable-event-id', 'timestamp' => $event['timestamp'], 'type' => 'event', 'name' => 'registration.completed']);
$transport->response = new HttpResponse(200, '{"status":"success","data":{"created":false,"eventId":"wrong-event"}}');
try { $client->igaming($event); throw new RuntimeException('Mismatched acknowledgement accepted'); }
catch (DeliveryException $e) { check($e->errorCode === 'invalid_acknowledgement', 'Wrong acknowledgement handling'); }
echo "PHP SDK transport, exact amount, idempotency and retry checks passed.\n";
