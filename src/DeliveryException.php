<?php

declare(strict_types=1);
namespace MetricPanel;

final class DeliveryException extends \RuntimeException
{
    public function __construct(
        public readonly bool $retryable,
        public readonly int $status = 0,
        public readonly string $errorCode = 'transport_error',
        public readonly ?int $retryAfterSeconds = null,
    ) {
        // Intentionally exclude tokens, URLs, player IDs and response bodies from logs.
        parent::__construct("MetricPanel delivery failed: {$errorCode} (HTTP {$status})");
    }
}
