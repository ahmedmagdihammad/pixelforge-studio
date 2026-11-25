<?php

namespace App\Services\Pixelforge;

use RuntimeException;

/**
 * A request Pixelforge answered with an error (or that never got an answer).
 * Branch on $errorCode, as the docs recommend.
 */
class PixelforgeException extends RuntimeException
{
    /** Errors where waiting and trying again (with the same Idempotency-Key) can succeed. */
    private const RETRYABLE = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'server_error', 'network_error'];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /**
     * True when we can't be sure whether Pixelforge created the generation (the request may have
     * reached them even though we never saw the answer).
     */
    public function outcomeUnknown(): bool
    {
        return in_array($this->errorCode, ['network_error', 'server_error'], true);
    }
}
