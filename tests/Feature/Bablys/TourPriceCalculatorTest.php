<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Pricing\TourPriceCalculator;
use App\Domain\Travel\Pricing\TourPricingRules;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TourPriceCalculatorTest extends TestCase
{
    public function test_per_person_quote_multiplies_adult_and_child_minor_units(): void
    {
        $quote = $this->quote($this->person(), 2, 1, '2027-01-15T00:00:00Z');
        self::assertSame('BDT', $quote['currency']);
        self::assertSame(2, $quote['fraction_digits']);
        self::assertSame(12000, $quote['regular_total_minor']);
        self::assertSame(12000, $quote['total_minor']);
        self::assertSame(0, $quote['discount_minor']);
        self::assertSame(2, $quote['lines'][0]['quantity']);
        self::assertSame(1, $quote['lines'][1]['quantity']);
    }

    public function test_sale_is_active_at_start_but_inactive_exactly_at_end(): void
    {
        $input = $this->person();
        $input['rates'][0]['sale_minor'] = 3500;
        $input['rates'][0]['sale_starts_at'] = '2027-01-10T00:00:00Z';
        $input['rates'][0]['sale_ends_at'] = '2027-01-20T00:00:00Z';
        $atStart = $this->quote($input, 2, 0, '2027-01-10T00:00:00Z');
        $atEnd = $this->quote($input, 2, 0, '2027-01-20T00:00:00Z');
        self::assertSame(7000, $atStart['total_minor']);
        self::assertSame(10000, $atEnd['total_minor']);
        self::assertSame(3000, $atStart['discount_minor']);
    }

    public function test_group_rate_is_one_flat_amount_and_currency_exponent_is_correct(): void
    {
        $input = $this->group();
        $quote = $this->quote($input, 2, 3, '2027-01-01T00:00:00Z');
        self::assertSame(9000, $quote['total_minor']);
        self::assertSame(1, $quote['lines'][0]['quantity']);
        self::assertSame(0, $quote['fraction_digits']);
    }

    public function test_exceeding_maximum_travelers_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->quote($this->person(), 9, 0, '2027-01-01T00:00:00Z');
    }

    public function test_child_count_without_child_rate_is_rejected(): void
    {
        $input = $this->person();
        unset($input['rates'][1]);
        $this->expectException(ValidationException::class);
        $this->quote($input, 1, 1, '2027-01-01T00:00:00Z');
    }

    public function test_inactive_option_cannot_be_quoted(): void
    {
        $input = $this->group();
        $input['is_active'] = false;
        $this->expectException(ValidationException::class);
        $this->quote($input, 2, 0, '2027-01-01T00:00:00Z');
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return [
            'code' => 'standard', 'name' => 'Standard', 'currency' => 'BDT',
            'pricing_model' => 'per_person', 'min_travelers' => 1, 'max_travelers' => 8,
            'rates' => [
                ['traveler_type' => 'adult', 'regular_minor' => 5000],
                ['traveler_type' => 'child', 'regular_minor' => 2000],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function group(): array
    {
        return [
            'code' => 'family', 'name' => 'Family Group', 'currency' => 'JPY',
            'pricing_model' => 'per_group', 'min_travelers' => 2, 'max_travelers' => 8,
            'rates' => [['traveler_type' => 'group', 'regular_minor' => 9000]],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function quote(array $payload, int $adults, int $children, string $at): array
    {
        $rules = new TourPricingRules();
        $normalized = $rules->normalize([$payload])[0];
        return (new TourPriceCalculator($rules))->quote($normalized, $adults, $children, new DateTimeImmutable($at));
    }
}
