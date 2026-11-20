<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Pricing\TourPricingManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourPriceRate;
use App\Models\Travel\TourPriceVariant;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourQaTestCase;

final class TourPricingManagerTest extends TourQaTestCase
{
    public function test_editor_saves_and_reads_multiple_currency_variants(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('pricing-draft');
        $manager = app(TourPricingManager::class);
        $manager->replace($editor, $tour, [$this->person(), $this->group()]);
        $stored = $manager->forStaff($editor, $tour);
        self::assertSame(['bdt-standard', 'usd-group'], array_column($stored, 'code'));
        self::assertSame(['BDT', 'USD'], array_column($stored, 'currency'));
        self::assertSame(2, TourPriceVariant::query()->where('tour_id', $tour->id)->count());
        self::assertSame(3, TourPriceRate::query()->count());
    }

    public function test_repeating_identical_save_preserves_ids_and_does_not_repeat_audit(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('pricing-idempotent');
        $manager = app(TourPricingManager::class);
        $input = [$this->person()];
        $manager->replace($editor, $tour, $input);
        $variantId = (int) TourPriceVariant::query()->firstOrFail()->id;
        $rateId = (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->id;
        $manager->replace($editor, $tour, $input);
        self::assertSame($variantId, (int) TourPriceVariant::query()->firstOrFail()->id);
        self::assertSame($rateId, (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->id);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.pricing.changed')->count());
    }

    public function test_updating_one_rate_preserves_its_row_and_records_single_audit(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('pricing-updated');
        $manager = app(TourPricingManager::class);
        $input = [$this->person()];
        $manager->replace($editor, $tour, $input);
        $id = (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->id;
        $input[0]['rates'][0]['regular_minor'] = 5600;
        $manager->replace($editor, $tour, $input);
        self::assertSame($id, (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->id);
        self::assertSame(5600, (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->regular_minor);
        self::assertSame(2, DB::table('cms_audit_events')->where('action', 'tour.pricing.changed')->count());
    }

    public function test_invalid_replacement_preserves_prior_prices_and_audit(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('pricing-rollback');
        $manager = app(TourPricingManager::class);
        $manager->replace($editor, $tour, [$this->person()]);
        $before = DB::table('cms_audit_events')->count();
        $bad = $this->person();
        $bad['rates'][0]['regular_minor'] = 55.9;
        try {
            $manager->replace($editor, $tour, [$bad]);
            self::fail('Expected invalid monetary amount to fail.');
        } catch (ValidationException) {
            self::assertSame(5000, (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->regular_minor);
            self::assertSame($before, DB::table('cms_audit_events')->count());
        }
    }

    public function test_editor_cannot_change_live_tour_pricing_without_publish_permission(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('live-prices', ['status' => 'published', 'published_at' => now()]);
        $this->expectException(HttpException::class);
        app(TourPricingManager::class)->replace($editor, $tour, [$this->person()]);
    }

    public function test_administrator_can_price_live_tour_and_preview_a_quote(): void
    {
        $admin = $this->staff('administrator');
        $tour = $this->tour('live-prices-admin', ['status' => 'published', 'published_at' => now()]);
        $manager = app(TourPricingManager::class);
        $manager->replace($admin, $tour, [$this->person()]);
        $quote = $manager->previewForStaff($admin, $tour, 'bdt-standard', 2, 1, new DateTimeImmutable('2027-01-01T00:00:00Z'));
        self::assertSame(12000, $quote['total_minor']);
    }

    public function test_removed_variant_cascades_its_rates_without_affecting_another_tour(): void
    {
        $editor = $this->staff('content_editor');
        $first = $this->tour('first-prices');
        $other = $this->tour('other-prices');
        $manager = app(TourPricingManager::class);
        $manager->replace($editor, $first, [$this->person(), $this->group()]);
        $manager->replace($editor, $other, [$this->person()]);
        $manager->replace($editor, $first, []);
        self::assertSame(0, TourPriceVariant::query()->where('tour_id', $first->id)->count());
        self::assertSame(1, TourPriceVariant::query()->where('tour_id', $other->id)->count());
        self::assertSame(2, TourPriceRate::query()->count());
    }

    public function test_revoked_editor_permissions_take_effect_immediately(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('revoke-pricing');
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->detach($editor->getKey());
        $this->expectException(HttpException::class);
        app(TourPricingManager::class)->replace($editor, $tour, [$this->person()]);
    }

    public function test_audit_metadata_contains_no_prices_or_sale_dates(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('audit-pricing');
        $manager = app(TourPricingManager::class);
        $input = $this->person();
        $input['rates'][0]['sale_minor'] = 4200;
        $input['rates'][0]['sale_starts_at'] = '2027-01-01T00:00:00Z';
        $manager->replace($editor, $tour, [$input]);
        $event = DB::table('cms_audit_events')->where('action', 'tour.pricing.changed')->first();
        self::assertNotNull($event);
        $metadata = json_encode($event);
        self::assertIsString($metadata);
        self::assertStringNotContainsString('4200', $metadata);
        self::assertStringNotContainsString('2027-01-01', $metadata);
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return [
            'code' => 'bdt-standard', 'name' => 'Standard', 'currency' => 'BDT',
            'pricing_model' => 'per_person', 'min_travelers' => 1, 'max_travelers' => 10,
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
            'code' => 'usd-group', 'name' => 'Private Group', 'currency' => 'USD',
            'pricing_model' => 'per_group', 'min_travelers' => 2, 'max_travelers' => 6,
            'rates' => [['traveler_type' => 'group', 'regular_minor' => 10000]],
        ];
    }
}
