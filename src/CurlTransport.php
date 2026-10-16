<?php

declare(strict_types=1);
namespace MetricPanel;

final class CurlTransport implements Transport
{
    public function post(string $url, string $token, array $payload): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new DeliveryException(true);
        }
        $retryAfter = null;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 10000,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($header, 12));
                }
                return strlen($header);
            },
        ]);
        try {
            $body = curl_exec($handle);
            if ($body === false) {
                throw new DeliveryException(true);
            }
            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $retryAfter);
        } finally {
            curl_close($handle);
        }
    }
}
