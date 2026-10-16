<?php

declare(strict_types=1);
namespace MetricPanel;

interface Transport
{
    public function post(string $url, string $token, array $payload): HttpResponse;
}
