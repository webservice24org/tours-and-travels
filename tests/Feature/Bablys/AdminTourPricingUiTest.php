<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\Travel\TourPriceRate;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Bablys\TourQaTestCase;

final class AdminTourPricingUiTest extends TourQaTestCase
{
    public function test_editor_reads_empty_pricing_and_supported_currency_precisions(): void
    {
        $tour = $this->tour('pricing-read-empty');
        $this->actingAs($this->staff('content_editor'))
            ->getJson('/admin/internal/tours/'.$tour->id.'/pricing')
            ->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.currencies.BDT', 2)
            ->assertJsonPath('meta.currencies.JPY', 0)
            ->assertHeader('Cache-Control');
    }

    public function test_editor_saves_adult_child_and_group_variants(): void
    {
        $tour = $this->tour('pricing-multi');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/pricing', [
            'variants' => [$this->person(), $this->group()],
        ])->assertOk()->assertJsonPath('data.0.code', 'standard-bdt')
            ->assertJsonPath('data.0.rates.1.traveler_type', 'child')
            ->assertJsonPath('data.1.currency', 'USD');

        $this->assertDatabaseHas('travel_tour_price_variants', ['tour_id' => $tour->id, 'currency' => 'BDT']);
        self::assertSame(2, TourPriceVariant::query()->where('tour_id', $tour->id)->count());
        self::assertSame(3, TourPriceRate::query()->count());
    }

    public function test_stable_ids_and_single_audit_on_identical_http_replacement(): void
    {
        $tour = $this->tour('stable-pricing');
        $this->actingAs($this->staff('content_editor'));
        $input = ['variants' => [$this->person()]];
        $this->putJson('/admin/internal/tours/'.$tour->id.'/pricing', $input)->assertOk();
        $id = (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->id;
        $this->putJson('/admin/internal/tours/'.$tour->id.'/pricing', $input)->assertOk();
        self::assertSame($id, (int) TourPriceRate::query()->where('traveler_type', 'adult')->firstOrFail()->id);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.pricing.changed')->count());
    }

    public function test_clear_pricing_with_explicit_empty_list(): void
    {
        $tour = $this->tour('clear-pricing');
        $this->actingAs($this->staff('content_editor'));
        $url = '/admin/internal/tours/'.$tour->id.'/pricing';
        $this->putJson($url, ['variants' => [$this->person()]])->assertOk();
        $this->putJson($url, ['variants' => []])->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('travel_tour_price_variants', 0);
    }

    public function test_invalid_float_money_rejected_without_partial_writes(): void
    {
        $tour = $this->tour('money-reject');
        $this->actingAs($this->staff('content_editor'));
        $url = '/admin/internal/tours/'.$tour->id.'/pricing';
        $this->putJson($url, ['variants' => [$this->person()]])->assertOk();
        $invalid = $this->person();
        $invalid['rates'][0]['regular_minor'] = 2500.01;
        $this->putJson($url, ['variants' => [$invalid]])
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.rates.0.regular_minor');
        $this->assertDatabaseHas('travel_tour_price_rates', ['traveler_type' => 'adult', 'regular_minor' => 1500000]);
    }

    public function test_price_quote_uses_saved_variant_and_current_server_time(): void
    {
        $tour = $this->tour('preview-now');
        $this->actingAs($this->staff('content_editor'));
        $url = '/admin/internal/tours/'.$tour->id.'/pricing';
        $this->putJson($url, ['variants' => [$this->person()]])->assertOk();
        $this->postJson($url.'/preview', ['code' => 'standard-bdt', 'adults' => 2, 'children' => 1])
            ->assertOk()->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.regular_total_minor', 3800000)
            ->assertJsonPath('data.total_minor', 3800000)
            ->assertJsonPath('meta.not_a_booking', true);
    }

    public function test_quote_rejects_an_option_belonging_to_a_different_tour(): void
    {
        $first = $this->tour('first-quote');
        $second = $this->tour('second-quote');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$first->id.'/pricing', ['variants' => [$this->person()]])->assertOk();
        $this->postJson('/admin/internal/tours/'.$second->id.'/pricing/preview', [
            'code' => 'standard-bdt', 'adults' => 2, 'children' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_quote_never_accepts_client_supplied_price_totals(): void
    {
        $tour = $this->tour('quote-client-total');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => [$this->person()]])->assertOk();
        $this->postJson('/admin/internal/tours/'.$tour->id.'/pricing/preview', [
            'code' => 'standard-bdt', 'adults' => 1, 'children' => 0, 'total_minor' => 1,
        ])->assertOk()->assertJsonPath('data.total_minor', 1500000);
    }

    public function test_guest_and_customer_cannot_read_write_or_preview_prices(): void
    {
        $tour = $this->tour('unauthorized-prices');
        $base = '/admin/internal/tours/'.$tour->id.'/pricing';
        $this->getJson($base)->assertUnauthorized();
        $this->putJson($base, ['variants' => []])->assertUnauthorized();
        $this->postJson($base.'/preview', ['code' => 'standard-bdt', 'adults' => 1, 'children' => 0])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->getJson($base)->assertForbidden();
        $this->putJson($base, ['variants' => []])->assertForbidden();
    }

    public function test_designer_cannot_mutate_pricing(): void
    {
        $tour = $this->tour('readonly-pricing');
        $this->actingAs($this->staff('designer'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => [$this->person()]])
            ->assertForbidden();
    }

    public function test_editor_cannot_update_published_pricing(): void
    {
        $tour = $this->tour('published-price', ['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->staff('content_editor'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => [$this->person()]])
            ->assertForbidden();
    }

    public function test_administrator_can_update_published_pricing(): void
    {
        $tour = $this->tour('published-price-admin', ['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->staff('administrator'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => [$this->person()]])
            ->assertOk()->assertJsonPath('data.0.code', 'standard-bdt');
    }

    public function test_revoked_permission_blocks_next_price_update(): void
    {
        $tour = $this->tour('permission-revoke-pricing');
        $editor = $this->staff('content_editor');
        $this->actingAs($editor)->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => [$this->person()]])->assertOk();
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->detach($editor->getKey());
        $this->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => []])->assertForbidden();
        $this->assertDatabaseCount('travel_tour_price_variants', 1);
    }

    public function test_audit_does_not_include_prices_or_sale_windows(): void
    {
        $tour = $this->tour('audit-price-privacy');
        $price = $this->person();
        $price['rates'][0]['sale_minor'] = 1100000;
        $price['rates'][0]['sale_starts_at'] = '2027-01-01T00:00:00Z';
        $this->actingAs($this->staff('content_editor'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/pricing', ['variants' => [$price]])->assertOk();
        $event = DB::table('cms_audit_events')->where('action', 'tour.pricing.changed')->first();
        self::assertNotNull($event);
        $text = (string) json_encode($event);
        self::assertStringNotContainsString('1100000', $text);
        self::assertStringNotContainsString('2027-01-01', $text);
    }

    public function test_full_page_tour_editor_includes_pricing_without_second_sidebar(): void
    {
        $tour = $this->tour('pricing-ui');
        $this->actingAs($this->staff('administrator'));
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')->where('tourId', $tour->id)
        );
        $editor = (string) file_get_contents(base_path('resources/js/pages/admin/tours/editor.tsx'));
        $pricing = (string) file_get_contents(base_path('resources/js/components/admin/tours/TourPricingEditor.tsx'));
        self::assertStringContainsString('<TourPricingEditor', $editor);
        self::assertStringContainsString('<TourItineraryEditor', $editor);
        self::assertStringContainsString('lg:col-span-9', $editor);
        self::assertStringNotContainsString('<CmsSidebarMenu', $editor);
        self::assertStringContainsString('confirmAction(', $pricing);
        self::assertStringContainsString('toast.success(', $pricing);
        self::assertStringContainsString('type="button"', $pricing);
        self::assertStringContainsString('BigInt(', $pricing);
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return [
            'code' => 'standard-bdt', 'name' => 'Standard BDT', 'currency' => 'BDT',
            'pricing_model' => 'per_person', 'min_travelers' => 1, 'max_travelers' => 10,
            'is_active' => true,
            'rates' => [
                ['traveler_type' => 'adult', 'regular_minor' => 1500000],
                ['traveler_type' => 'child', 'regular_minor' => 800000],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function group(): array
    {
        return [
            'code' => 'private-usd', 'name' => 'Private Group', 'currency' => 'USD',
            'pricing_model' => 'per_group', 'min_travelers' => 2, 'max_travelers' => 6,
            'is_active' => true,
            'rates' => [['traveler_type' => 'group', 'regular_minor' => 125000]],
        ];
    }
}
