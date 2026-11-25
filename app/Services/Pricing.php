<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Works out what a generation costs, following "Pricing and token usage" in the Pixelforge docs.
 *
 * Two kinds of number come out of here, both in nanodollars:
 *  - provider cost: what Pixelforge charges us (tokens x rates, exact, never rounded);
 *  - user charge:   what we charge the user (provider cost + markup).
 */
class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent = 25) {}

    /**
     * Exact provider cost of a finished generation, from its `usage` and `rates`.
     *
     * @param  array<string, int>  $usage  token counts, keyed like Pixelforge's `usage` object
     * @param  array<string, string>  $rates  dollars per 1M tokens, keyed like Pixelforge's `rates` object
     */
    public function providerCost(array $usage, array $rates): int
    {
        // Sum tokens x (nanodollars per 1M tokens) first, then divide once, so nothing is lost to rounding.
        $numerator = 0;
        foreach (self::TOKEN_TYPES as $type) {
            $numerator += (int) ($usage[$type] ?? 0) * Money::fromDecimal($rates[$type]);
        }

        if ($numerator % 1_000_000 !== 0) {
            // Can't happen with Pixelforge's published rates (costs have at most 9 decimals),
            // but if it ever does we round up rather than lose money, and leave a trace.
            Log::warning('Pixelforge cost has sub-nanodollar precision; rounding up.', compact('usage', 'rates'));
        }

        return self::divideRoundingUp($numerator, 1_000_000);
    }

    /**
     * What we charge the user for a provider cost: cost + markup, rounded up to the nanodollar.
     */
    public function userCharge(int $providerCost): int
    {
        return self::divideRoundingUp($providerCost * (100 + $this->markupPercent), 100);
    }

    /**
     * Cheapest and most expensive user charge a request can end up costing, before it runs.
     *
     * Output and image tokens are exact. Text tokens are ceil(chars / 4) +/- 15%, and reasoning tokens
     * fall within a range per quality, so the real cost always lands between `min` and `max`.
     *
     * @param  array  $pricing  the body of GET /v1/pricing
     * @return array{min: int, max: int} user charge in nanodollars
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $variancePercent = (int) round($tokens['text_token_variance'] * 100);
        $textEstimate = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);

        $fixed = [
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
        ];

        $cheapest = $fixed + [
            'input_text_tokens' => intdiv($textEstimate * (100 - $variancePercent), 100),
            'reasoning_tokens' => $tokens['reasoning'][$quality]['min'],
        ];

        $dearest = $fixed + [
            'input_text_tokens' => self::divideRoundingUp($textEstimate * (100 + $variancePercent), 100),
            'reasoning_tokens' => $tokens['reasoning'][$quality]['max'],
        ];

        return [
            'min' => $this->userCharge($this->providerCost($cheapest, $pricing['rates'])),
            'max' => $this->userCharge($this->providerCost($dearest, $pricing['rates'])),
        ];
    }

    private static function divideRoundingUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
