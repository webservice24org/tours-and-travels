<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Pricing\TourPricingRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TourPricingRulesTest extends TestCase
{
    private TourPricingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new TourPricingRules();
    }

    public function test_accepts_bdt_adult_and_child_rates_with_canonical_order(): void
    {
        $input = $this->person();
        $input['code'] = ' STANDARD_BD ';
        $input['currency'] = 'bdt';
        $input['rates'] = [$input['rates'][1], $input['rates'][0]];
        $normalized = $this->rules->normalize([$input]);
        self::assertSame('standard_bd', $normalized[0]['code']);
        self::assertSame('BDT', $normalized[0]['currency']);
        self::assertSame(['adult', 'child'], array_column($normalized[0]['rates'], 'traveler_type'));
        self::assertSame(2, $this->rules->fractionDigits('BDT'));
        self::assertSame(0, $this->rules->fractionDigits('JPY'));
    }

    public function test_rejects_floating_point_minor_units(): void
    {
        $input = $this->person();
        $input['rates'][0]['regular_minor'] = 19.99;
        $this->reject([$input], 'variants.0.rates.0.regular_minor');
    }

    public function test_rejects_numeric_string_minor_units(): void
    {
        $input = $this->person();
        $input['rates'][0]['regular_minor'] = '1900';
        $this->reject([$input], 'variants.0.rates.0.regular_minor');
    }

    public function test_rejects_unsupported_currency(): void
    {
        $input = $this->person();
        $input['currency'] = 'BTC';
        $this->reject([$input], 'variants.0.currency');
    }

    public function test_rejects_duplicate_variant_codes_case_insensitively(): void
    {
        $second = $this->person();
        $second['code'] = 'Standard';
        $this->reject([$this->person(), $second], 'variants.1.code');
    }

    public function test_rejects_per_person_rate_without_adult(): void
    {
        $input = $this->person();
        $input['rates'] = [$input['rates'][1]];
        $this->reject([$input], 'variants.0.rates');
    }

    public function test_rejects_group_rate_mixed_with_child(): void
    {
        $input = $this->person();
        $input['pricing_model'] = 'per_group';
        $input['rates'][0]['traveler_type'] = 'group';
        $this->reject([$input], 'variants.0.rates');
    }

    public function test_rejects_sale_price_above_regular(): void
    {
        $input = $this->person();
        $input['rates'][0]['sale_minor'] = 3000;
        $this->reject([$input], 'variants.0.rates.0.sale_minor');
    }

    public function test_rejects_sale_schedule_without_sale_amount(): void
    {
        $input = $this->person();
        $input['rates'][0]['sale_starts_at'] = '2027-01-01T00:00:00Z';
        $this->reject([$input], 'variants.0.rates.0.sale_minor');
    }

    public function test_rejects_sale_end_that_precedes_start(): void
    {
        $input = $this->person();
        $input['rates'][0]['sale_minor'] = 1500;
        $input['rates'][0]['sale_starts_at'] = '2027-02-01T00:00:00Z';
        $input['rates'][0]['sale_ends_at'] = '2027-01-01T00:00:00Z';
        $this->reject([$input], 'variants.0.rates.0.sale_ends_at');
    }

    public function test_rejects_invalid_group_size_range(): void
    {
        $input = $this->person();
        $input['min_travelers'] = 5;
        $input['max_travelers'] = 4;
        $this->reject([$input], 'variants.0.max_travelers');
    }

    public function test_rejects_unknown_fields_instead_of_silently_accepting_client_totals(): void
    {
        $input = $this->person();
        $input['grand_total'] = 100;
        $this->reject([$input], 'variants.0');
    }

    public function test_accepts_group_option_with_jpy_zero_decimal(): void
    {
        $input = $this->person();
        $input['currency'] = 'JPY';
        $input['pricing_model'] = 'per_group';
        $input['rates'] = [['traveler_type' => 'group', 'regular_minor' => 12345]];
        self::assertSame('JPY', $this->rules->normalize([$input])[0]['currency']);
    }

    public function test_rejects_free_adult_rates_but_allows_free_child_rates(): void
    {
        $input = $this->person();
        $input['rates'][0]['regular_minor'] = 0;
        $this->reject([$input], 'variants.0.rates.0.regular_minor');
        $valid = $this->person();
        $valid['rates'][1]['regular_minor'] = 0;
        self::assertSame(0, $this->rules->normalize([$valid])[0]['rates'][1]['regular_minor']);
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return [
            'code' => 'standard', 'name' => 'Standard Package',
            'currency' => 'USD', 'pricing_model' => 'per_person',
            'min_travelers' => 1, 'max_travelers' => 8, 'is_active' => true,
            'rates' => [
                ['traveler_type' => 'adult', 'regular_minor' => 2000],
                ['traveler_type' => 'child', 'regular_minor' => 800],
            ],
        ];
    }

    /** @param array<array-key,mixed> $payload */
    private function reject(array $payload, string $field): void
    {
        try {
            $this->rules->normalize($payload);
            self::fail('Expected validation error for '.$field);
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors());
        }
    }
}
