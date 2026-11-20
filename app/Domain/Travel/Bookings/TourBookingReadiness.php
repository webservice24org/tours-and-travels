<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Pricing\TourPriceCalculator;
use App\Domain\Travel\Pricing\TourPricingRules;
use App\Models\Travel\Tour;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourPriceVariant;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Internal readiness assessment only. It does not enable public bookings, create
 * checkout sessions, guarantee a quote, or hold any inventory. Use this again
 * under a DB transaction and row locks for actual allocation.
 *
 * @phpstan-import-type PricingVariant from TourPricingRules
 * @phpstan-type ReadinessSnapshot array{ready_for_internal_allocation:bool,public_bookable:bool,remaining_seats:int,reasons:list<string>,quote:?array<string,mixed>}
 */
final class TourBookingReadiness
{
    public function __construct(
        private readonly TourPricingRules $rules,
        private readonly TourPriceCalculator $calculator,
    ) {}

    /** @return ReadinessSnapshot */
    public function inspect(
        Tour $tour,
        TourDeparture $departure,
        string $variantCode,
        int $adults,
        int $children,
        DateTimeInterface $at,
    ): array {
        $reasons = [];
        $seats = $adults + $children;
        if ($adults < 1 || $children < 0 || $seats > 1000) {
            $reasons[] = 'invalid_party_size';
        }
        if ($tour->trashed() || $tour->status !== PublicationStatus::Published ||
            $tour->published_at === null || $tour->published_at->getTimestamp() > $at->getTimestamp()) {
            $reasons[] = 'tour_not_published';
        }
        if (! $tour->categories()->where('status', PublicationStatus::Published->value)->exists()) {
            $reasons[] = 'no_published_category';
        }
        if (! $tour->destinations()->where('status', PublicationStatus::Published->value)->exists()) {
            $reasons[] = 'no_published_destination';
        }
        $remaining = max(0, (int) $departure->capacity_total - (int) $departure->seats_committed);
        if ((int) $departure->tour_id !== (int) $tour->getKey()) {
            $reasons[] = 'departure_tour_mismatch';
        }
        if ((string) $departure->status !== 'open') {
            $reasons[] = 'departure_not_open';
        }
        if ($this->utcTimestamp((string) $departure->starts_at) <= $at->getTimestamp()) {
            $reasons[] = 'departure_started';
        }
        if ($departure->booking_closes_at !== null &&
            $this->utcTimestamp((string) $departure->booking_closes_at) <= $at->getTimestamp()) {
            $reasons[] = 'booking_cutoff_passed';
        }
        if ($seats > 0 && $remaining < $seats) {
            $reasons[] = 'insufficient_capacity';
        }
        $quote = null;
        $variant = TourPriceVariant::query()
            ->where('tour_id', (int) $tour->getKey())
            ->where('code', $variantCode)
            ->with('rates')
            ->first();
        if ($variant === null) {
            $reasons[] = 'pricing_option_missing';
        } else {
            try {
                $quote = $this->calculator->quote($this->normalizedPrice($variant), $adults, $children, $at);
            } catch (ValidationException) {
                $reasons[] = 'pricing_not_eligible';
            }
        }

        return [
            'ready_for_internal_allocation' => $reasons === [],
            // Public booking routes, payment and booking lifecycle are NOT provided in P3E.5A.
            'public_bookable' => false,
            'remaining_seats' => $remaining,
            'reasons' => $reasons,
            'quote' => $quote,
        ];
    }

    /** @return PricingVariant */
    private function normalizedPrice(TourPriceVariant $variant): array
    {
        $rates = [];
        foreach ($variant->rates as $rate) {
            $rates[] = [
                'traveler_type' => (string) $rate->traveler_type,
                'regular_minor' => (int) $rate->regular_minor,
                'sale_minor' => $rate->sale_minor === null ? null : (int) $rate->sale_minor,
                'sale_starts_at' => $this->isoUtc($rate->sale_starts_at),
                'sale_ends_at' => $this->isoUtc($rate->sale_ends_at),
            ];
        }
        // Reuse P3E.3A authoritative normalization; never trust DB rates implicitly.
        $normalized = $this->rules->normalize([[
            'code' => (string) $variant->code,
            'name' => (string) $variant->name,
            'currency' => (string) $variant->currency,
            'pricing_model' => (string) $variant->pricing_model,
            'min_travelers' => (int) $variant->min_travelers,
            'max_travelers' => $variant->max_travelers === null ? null : (int) $variant->max_travelers,
            'is_active' => (bool) $variant->is_active,
            'rates' => $rates,
        ]]);

        return $normalized[0];
    }

    private function isoUtc(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp());
    }

    private function utcTimestamp(string $value): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \LogicException('Invalid stored departure UTC timestamp.');
        }
        return $parsed->getTimestamp();
    }
}
