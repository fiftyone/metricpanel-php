<?php

declare(strict_types=1);
namespace MetricPanel;

final class Client
{
    private readonly string $baseUrl;
    public function __construct(
        private readonly string $token,
        string $baseUrl = 'https://api.metricpanel.io',
        private readonly Transport $transport = new CurlTransport(),
    ) {
        $url = parse_url($baseUrl);
        $local = in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        if (!$url || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && $local))) {
            throw new \InvalidArgumentException('Use an HTTPS API URL (HTTP is allowed only on loopback).');
        }
        if (trim($token) === '' || preg_match('/[\r\n]/', $token)) {
            throw new \InvalidArgumentException('A server API token is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Persist this entire event in your transaction's outbox before delivery. */
    public function igaming(array $event): array
    {
        foreach (['eventId', 'operatorId', 'playerId', 'transactionId', 'timestamp'] as $field) {
            if (!is_string($event[$field] ?? null) || trim($event[$field]) === '') {
                throw new \InvalidArgumentException("{$field} is required and must be stable across retries.");
            }
        }
        if (($event['schemaVersion'] ?? null) !== 1 || !in_array($event['type'] ?? null, [
            'deposit.completed', 'deposit.failed', 'deposit.refunded', 'deposit.chargeback',
            'withdrawal.requested', 'withdrawal.paid', 'withdrawal.canceled',
        ], true)) {
            throw new \InvalidArgumentException('Unsupported iGaming schema version or event type.');
        }
        foreach (['cashAmount', 'bonusAmount'] as $field) {
            if (!is_string($event[$field] ?? null) || !is_string($event['currency'] ?? null) || !is_int($event['precision'] ?? null)) {
                throw new \InvalidArgumentException('Amounts must be strings with explicit currency and precision.');
            }
            new Money($event[$field], $event['currency'], $event['precision']);
        }
        return $this->post('/api/v1/igaming/events', $event);
    }

    public function event(array $event): array
    {
        if (!is_string($event['eventId'] ?? null) || strlen($event['eventId']) < 16 || !isset($event['timestamp'])) {
            throw new \InvalidArgumentException('Provide a stable event ID (16+ characters) and occurrence timestamp.');
        }
        if (!in_array($event['type'] ?? null, ['event', 'pageview', 'goal'], true) || str_starts_with($event['name'] ?? '', 'igaming.')) {
            throw new \InvalidArgumentException('Use the dedicated iGaming endpoint for financial outcomes.');
        }
        return $this->post('/api/events', $event);
    }

    public function goal(array $event): array
    {
        $event['type'] = 'goal';
        return $this->event($event);
    }

    /** Commerce revenue only. Payment volumes and corrections belong in igaming(). */
    public function revenue(array $event): array
    {
        if (!is_int($event['amount'] ?? null) || $event['amount'] <= 0 || $event['amount'] > 9007199254740991 ||
            !is_string($event['transactionId'] ?? null) || trim($event['transactionId']) === '' || !isset($event['timestamp'])) {
            throw new \InvalidArgumentException('Revenue requires positive safe integer minor units, a stable transaction ID and timestamp.');
        }
        return $this->post('/api/v1/revenue/events', $event);
    }

    private function post(string $path, array $payload): array
    {
        $response = $this->transport->post($this->baseUrl.$path, $this->token, $payload);
        $data = json_decode($response->body, true);
        $retryable = in_array($response->status, [408, 425, 429], true) || $response->status >= 500;
        $delay = null;
        if ($response->retryAfter !== null) {
            $seconds = ctype_digit($response->retryAfter) ? (int) $response->retryAfter : max(0, (strtotime($response->retryAfter) ?: time()) - time());
            $delay = max(1, $seconds);
        }
        if ($response->status < 200 || $response->status >= 300) {
            $code = $data['error']['code'] ?? 'http_error';
            if (!is_string($code) || !preg_match('/\A[a-z_]{1,80}\z/', $code)) $code = 'http_error';
            throw new DeliveryException($retryable, $response->status, $code, $delay);
        }
        $accepted = $path === '/api/events' ? ($data['success'] ?? false) === true : ($data['status'] ?? null) === 'success';
        if (!is_array($data) || !$accepted) {
            throw new DeliveryException(true, $response->status, 'invalid_response');
        }
        if ($path === '/api/v1/igaming/events' &&
            (!is_bool($data['data']['created'] ?? null) || ($data['data']['eventId'] ?? null) !== trim($payload['eventId']))) {
            throw new DeliveryException(true, $response->status, 'invalid_acknowledgement');
        }
        return $data;
    }
}
