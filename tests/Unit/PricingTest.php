<?php

namespace Tests\Unit;

use App\Services\Pricing;
use App\Support\Money;
use Tests\TestCase;

class PricingTest extends TestCase
{
    public function test_provider_cost_matches_the_docs_usage_example(): void
    {
        // GET /v1/usage example in the docs: 41 text, 3300 output, 2950 reasoning tokens = "0.169080000".
        $cost = (new Pricing)->providerCost(
            ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950],
            self::rates(),
        );

        $this->assertSame(Money::fromDecimal('0.169080000'), $cost);
    }

    public function test_user_charge_adds_25_percent_markup(): void
    {
        $pricing = new Pricing(25);

        $this->assertSame(Money::fromDecimal('0.211350000'), $pricing->userCharge(Money::fromDecimal('0.169080000')));
        $this->assertSame(2, $pricing->userCharge(1)); // 1.25 nanodollars rounds up, never down
    }

    public function test_estimate_matches_the_docs_worst_case_example(): void
    {
        // Docs: high, 1536x1024, 400-character prompt, 2 reference images costs at most $0.373875.
        $estimate = (new Pricing(25))->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $this->pricingResponse());

        $this->assertSame(Money::fromDecimal('0.46734375'), $estimate['max']); // 0.373875 + 25%

        // Cheapest: 85 text tokens, 1000 reasoning tokens.
        $cheapest = 85 * 5_000 + 1530 * 10_000 + 6450 * 40_000 + 1000 * 12_500;
        $this->assertSame((new Pricing(25))->userCharge($cheapest), $estimate['min']);
    }

    public function test_text_tokens_count_characters_not_bytes(): void
    {
        $pricing = new Pricing(25);

        // 8 emoji = 8 characters = 2 text tokens, even though it's 32 bytes.
        $this->assertSame(
            $pricing->estimate('abcdefgh', '1024x1024', 'low', 0, $this->pricingResponse()),
            $pricing->estimate(str_repeat('🎨', 8), '1024x1024', 'low', 0, $this->pricingResponse()),
        );
    }
}
