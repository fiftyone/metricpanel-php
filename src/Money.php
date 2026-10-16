<?php

declare(strict_types=1);
namespace MetricPanel;

/** Exact minor units; never construct this value via floating point arithmetic. */
final class Money
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly int $precision,
    ) {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,37})\z/', $amount) ||
            !preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/', $currency) || $precision < 0 || $precision > 18) {
            throw new \InvalidArgumentException('Use unsigned minor-unit strings, an uppercase currency, and precision from 0 to 18.');
        }
    }
}
