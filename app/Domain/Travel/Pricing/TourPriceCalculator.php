<?php

declare(strict_types=1);

namespace App\Domain\Travel\Pricing;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Pure server-authoritative preview of tour pricing, not a booking guarantee.
 * No currency conversion, taxes, shipping, booking hold or FX assumptions.
 *
 * @phpstan-import-type PricingVariant from TourPricingRules
 * @phpstan-import-type PricingRate from TourPricingRules
 */
final class TourPriceCalculator
{
    public function __construct(private readonly TourPricingRules $rules) {}

    /**
     * @param PricingVariant $variant A normalized variant from TourPricingRules.
     * @return array{currency:string,fraction_digits:int,regular_total_minor:int,total_minor:int,discount_minor:int,adults:int,children:int,lines:list<array{traveler_type:string,quantity:int,regular_unit_minor:int,effective_unit_minor:int,line_regular_minor:int,line_total_minor:int,sale_active:bool}>}
     */
    public function quote(array $variant, int $adults, int $children, DateTimeInterface $at): array
    {
        if (! $variant['is_active']) {
            $this->reject('variant', 'This pricing option is inactive.');
        }
        if ($adults < 1 || $children < 0 || $adults + $children > 1000) {
            $this->reject('travelers', 'At least one adult is required; group size must be 1–1000.');
        }
        $travelers = $adults + $children;
        if ($travelers < $variant['min_travelers'] ||
            ($variant['max_travelers'] !== null && $travelers > $variant['max_travelers'])) {
            $this->reject('travelers', 'The number of travelers is outside this pricing option’s limits.');
        }

        /** @var array<string,PricingRate> $byType */
        $byType = [];
        foreach ($variant['rates'] as $rate) {
            $byType[$rate['traveler_type']] = $rate;
        }
        /** @var list<array{traveler_type:string,quantity:int,regular_unit_minor:int,effective_unit_minor:int,line_regular_minor:int,line_total_minor:int,sale_active:bool}> $lines */
        $lines = [];
        $regularTotal = 0;
        $total = 0;
        if ($variant['pricing_model'] === 'per_group') {
            if (! isset($byType['group'])) {
                $this->reject('rates', 'The group rate is missing.');
            }
            $entries = [['rate' => $byType['group'], 'quantity' => 1]];
        } else {
            if (! isset($byType['adult'])) {
                $this->reject('rates', 'The adult rate is missing.');
            }
            $entries = [['rate' => $byType['adult'], 'quantity' => $adults]];
            if ($children > 0) {
                if (! isset($byType['child'])) {
                    $this->reject('children', 'This pricing option does not provide a child rate.');
                }
                $entries[] = ['rate' => $byType['child'], 'quantity' => $children];
            }
        }
        foreach ($entries as $entry) {
            /** @var PricingRate $rate */
            $rate = $entry['rate'];
            $quantity = $entry['quantity'];
            $active = $this->saleActive($rate, $at);
            $effectiveUnit = $active && $rate['sale_minor'] !== null
                ? $rate['sale_minor'] : $rate['regular_minor'];
            $lineRegular = $rate['regular_minor'] * $quantity;
            $lineTotal = $effectiveUnit * $quantity;
            $regularTotal += $lineRegular;
            $total += $lineTotal;
            $lines[] = [
                'traveler_type' => $rate['traveler_type'],
                'quantity' => $quantity,
                'regular_unit_minor' => $rate['regular_minor'],
                'effective_unit_minor' => $effectiveUnit,
                'line_regular_minor' => $lineRegular,
                'line_total_minor' => $lineTotal,
                'sale_active' => $active,
            ];
        }
        return [
            'currency' => $variant['currency'],
            'fraction_digits' => $this->rules->fractionDigits($variant['currency']),
            'regular_total_minor' => $regularTotal,
            'total_minor' => $total,
            'discount_minor' => $regularTotal - $total,
            'adults' => $adults,
            'children' => $children,
            'lines' => $lines,
        ];
    }

    /** @param PricingRate $rate */
    private function saleActive(array $rate, DateTimeInterface $at): bool
    {
        if ($rate['sale_minor'] === null) {
            return false;
        }
        $timestamp = $at->getTimestamp();
        if ($rate['sale_starts_at'] !== null &&
            $timestamp < (new DateTimeImmutable($rate['sale_starts_at']))->getTimestamp()) {
            return false;
        }
        // Exclusive end makes neighboring sales deterministic without overlap.
        if ($rate['sale_ends_at'] !== null &&
            $timestamp >= (new DateTimeImmutable($rate['sale_ends_at']))->getTimestamp()) {
            return false;
        }
        return true;
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
