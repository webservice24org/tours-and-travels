<?php

declare(strict_types=1);

namespace App\Domain\Travel\Pricing;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourPriceRate;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Transaction-bound pricing replacement with stable variant and rate IDs.
 * Price amounts/sale schedules are not written to audit metadata.
 * This is a staff-only domain API; P3E.3B will add HTTP and React TSX controls.
 *
 * @phpstan-import-type PricingVariant from TourPricingRules
 * @phpstan-import-type PricingRate from TourPricingRules
 */
final class TourPricingManager
{
    public function __construct(
        private readonly TourPricingRules $rules,
        private readonly TourPriceCalculator $calculator,
        private readonly CmsMutationAudit $audit,
    ) {}

    /** @param array<array-key,mixed> $variants */
    public function replace(User $actor, Tour $tour, array $variants): void
    {
        DB::transaction(function () use ($actor, $tour, $variants): void {
            $this->authorize($actor, 'tours.edit');
            $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status->value, ['published', 'scheduled'], true)) {
                $this->authorize($actor, 'tours.publish');
            }
            $normalized = $this->rules->normalize($variants);
            $before = $this->rows((int) $locked->getKey());
            if ($before === $normalized) {
                return;
            }
            $tourId = (int) $locked->getKey();
            $codes = [];
            foreach ($normalized as $variant) {
                $codes[] = $variant['code'];
                $row = TourPriceVariant::query()->firstOrNew([
                    'tour_id' => $tourId, 'code' => $variant['code'],
                ]);
                $row->fill([
                    'name' => $variant['name'],
                    'currency' => $variant['currency'],
                    'pricing_model' => $variant['pricing_model'],
                    'min_travelers' => $variant['min_travelers'],
                    'max_travelers' => $variant['max_travelers'],
                    'is_active' => $variant['is_active'],
                    'sort_order' => $variant['sort_order'],
                ]);
                $row->save();
                $types = [];
                foreach ($variant['rates'] as $rate) {
                    $types[] = $rate['traveler_type'];
                    TourPriceRate::query()->updateOrCreate([
                        'variant_id' => (int) $row->getKey(),
                        'traveler_type' => $rate['traveler_type'],
                    ], $rate);
                }
                TourPriceRate::query()
                    ->where('variant_id', (int) $row->getKey())
                    ->whereNotIn('traveler_type', $types)
                    ->delete();
            }
            TourPriceVariant::query()
                ->where('tour_id', $tourId)
                ->whereNotIn('code', $codes)
                ->delete();

            $beforeMap = [];
            foreach ($before as $variant) {
                $beforeMap[$variant['code']] = $variant;
            }
            $afterMap = [];
            foreach ($normalized as $variant) {
                $afterMap[$variant['code']] = $variant;
            }
            $changedCodes = [];
            foreach (array_unique(array_merge(array_keys($beforeMap), array_keys($afterMap))) as $code) {
                if (($beforeMap[$code] ?? null) !== ($afterMap[$code] ?? null)) {
                    $changedCodes[] = $code;
                }
            }
            sort($changedCodes, SORT_STRING);
            $this->audit->record('tour.pricing.changed', $actor, 'tour', $locked, [
                'variants_before' => count($before),
                'variants_after' => count($normalized),
                'changed_variant_codes' => $changedCodes,
            ]);
        }, 3);
    }

    /** @return list<PricingVariant> */
    public function forStaff(User $actor, Tour $tour): array
    {
        $this->authorize($actor, 'tours.view');
        $existing = Tour::query()->whereKey($tour->getKey())->firstOrFail();
        return $this->rows((int) $existing->getKey());
    }

    /**
     * Staff-only quote preview. It does not reserve capacity or create a booking.
     * @return array{currency:string,fraction_digits:int,regular_total_minor:int,total_minor:int,discount_minor:int,adults:int,children:int,lines:list<array{traveler_type:string,quantity:int,regular_unit_minor:int,effective_unit_minor:int,line_regular_minor:int,line_total_minor:int,sale_active:bool}>}
     */
    public function previewForStaff(
        User $actor,
        Tour $tour,
        string $code,
        int $adults,
        int $children,
        DateTimeInterface $at,
    ): array {
        foreach ($this->forStaff($actor, $tour) as $variant) {
            if ($variant['code'] === $code) {
                return $this->calculator->quote($variant, $adults, $children, $at);
            }
        }
        throw ValidationException::withMessages(['code' => 'Pricing option not found for this tour.']);
    }

    /** @return list<PricingVariant> */
    private function rows(int $tourId): array
    {
        $variants = TourPriceVariant::query()
            ->where('tour_id', $tourId)
            ->with('rates')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $output = [];
        foreach ($variants as $position => $variant) {
            /** @var array<string,PricingRate> $ratesByType */
            $ratesByType = [];
            foreach ($variant->rates as $rate) {
                $ratesByType[$rate->traveler_type] = [
                    'traveler_type' => (string) $rate->traveler_type,
                    'regular_minor' => (int) $rate->regular_minor,
                    'sale_minor' => $rate->sale_minor === null ? null : (int) $rate->sale_minor,
                    'sale_starts_at' => $this->utc($rate->sale_starts_at),
                    'sale_ends_at' => $this->utc($rate->sale_ends_at),
                ];
            }
            $rates = [];
            foreach (['adult', 'child', 'group'] as $type) {
                if (isset($ratesByType[$type])) {
                    $rates[] = $ratesByType[$type];
                }
            }
            $output[] = [
                'code' => (string) $variant->code,
                'name' => (string) $variant->name,
                'currency' => (string) $variant->currency,
                'pricing_model' => (string) $variant->pricing_model,
                'min_travelers' => (int) $variant->min_travelers,
                'max_travelers' => $variant->max_travelers === null ? null : (int) $variant->max_travelers,
                'is_active' => (bool) $variant->is_active,
                'sort_order' => $position,
                'rates' => $rates,
            ];
        }
        return $output;
    }

    private function utc(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp());
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless(Gate::forUser($actor)->allows($permission), 403);
    }
}
