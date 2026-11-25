<?php

namespace App\Services\Pixelforge;

/**
 * Checks the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">".
 */
class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $payload, ?string $header, ?int $now = null): bool
    {
        if ($this->secret === '' || ! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        // Constant-time compare, and refuse old (replayed) or far-future timestamps.
        return hash_equals($expected, $parts['v1'] ?? '')
            && abs(($now ?? time()) - $timestamp) <= $this->toleranceSeconds;
    }

    /** Builds a valid header. Used by the tests. */
    public function sign(string $payload, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);
    }
}
