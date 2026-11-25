<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge HTTP API. Every error comes out as a PixelforgeException.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * POST /v1/generations. Safe to repeat with the same idempotency key: Pixelforge returns
     * the generation it already created instead of making (and billing) a new one.
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->request()->get("/generations/{$id}"));
    }

    /**
     * GET /v1/pricing, cached briefly. Rates can change, so we never hardcode them.
     */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), fn () => $this->send(
            fn () => Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('/pricing')
        ));
    }

    /**
     * GET /v1/usage: what Pixelforge billed this API key, per UTC day.
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PixelforgeException('network_error', 'Could not reach Pixelforge: '.$e->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            errorCode: $error['code'] ?? ($response->serverError() ? 'server_error' : 'unknown_error'),
            message: $error['message'] ?? "Pixelforge returned HTTP {$response->status()}.",
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
