<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Itineraries\TourItineraryManager;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Bablys\TourQaTestCase;

final class AdminTourItineraryUiTest extends TourQaTestCase
{
    public function test_editor_can_read_and_replace_ordered_days_through_http(): void
    {
        $tour = $this->tour('days-http', ['duration_days' => 3]);
        $this->actingAs($this->staff('content_editor'));
        $this->getJson('/admin/internal/tours/'.$tour->id.'/itinerary')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.duration_days', 3);

        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
            ['day_number' => 1, 'title' => 'Arrival', 'meals' => ['dinner']],
            ['day_number' => 2, 'title' => 'Hiking', 'highlights' => ['Morning trek']],
        ]])->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.title', 'Hiking')
            ->assertJsonPath('data.1.day_number', 2);
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'day_number' => 2]);
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'tour.itinerary.changed']);
    }

    public function test_reordering_day_content_is_saved_in_positional_order(): void
    {
        $tour = $this->tour('day-order', ['duration_days' => 3]);
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
            ['title' => 'Second Visit'], ['title' => 'First Visit'],
        ]])->assertOk()->assertJsonPath('data.0.title', 'Second Visit')
            ->assertJsonPath('data.1.title', 'First Visit');
    }

    public function test_clearing_entire_itinerary_succeeds_and_is_audited(): void
    {
        $tour = $this->tour('clear-days');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [['title' => 'Start']]])->assertOk();
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => []])
            ->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('travel_tour_itinerary_days', 0);
    }

    public function test_repeating_an_identical_save_does_not_write_another_audit_event(): void
    {
        $tour = $this->tour('same-days');
        $this->actingAs($this->staff('content_editor'));
        $payload = ['days' => [['title' => 'First', 'meals' => ['breakfast']]]];
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', $payload)->assertOk();
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', $payload)->assertOk();
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.itinerary.changed')->count());
    }

    public function test_invalid_day_replacement_does_not_change_existing_records(): void
    {
        $tour = $this->tour('invalid-day', ['duration_days' => 2]);
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [['title' => 'Valid']]])->assertOk();
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
            ['title' => 'New'], ['title' => '<script>Unsafe</script>'],
        ]])->assertUnprocessable();
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'title' => 'Valid']);
        $this->assertDatabaseMissing('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'title' => 'New']);
    }

    public function test_itinerary_cannot_exceed_saved_tour_duration(): void
    {
        $tour = $this->tour('too-many-days', ['duration_days' => 1]);
        $this->actingAs($this->staff('content_editor'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
                ['title' => 'One'], ['title' => 'Two'],
            ]])->assertUnprocessable()->assertJsonValidationErrors('days');
        $this->assertDatabaseCount('travel_tour_itinerary_days', 0);
    }

    public function test_tour_duration_cannot_shrink_below_last_saved_day(): void
    {
        $tour = $this->tour('shrink-guard', ['duration_days' => 4]);
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'],
        ]])->assertOk();
        $this->putJson('/admin/internal/tours/'.$tour->id, ['duration_days' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('duration_days');
        self::assertSame(4, (int) $tour->fresh()->duration_days);
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'day_number' => 3]);
    }

    public function test_tour_duration_can_shrink_after_trailing_days_are_removed(): void
    {
        $tour = $this->tour('resize-allowed', ['duration_days' => 4]);
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
            ['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'],
        ]])->assertOk();
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [
            ['title' => 'One'], ['title' => 'Two'],
        ]])->assertOk();
        $this->putJson('/admin/internal/tours/'.$tour->id, ['duration_days' => 2])
            ->assertOk()->assertJsonPath('data.duration_days', 2);
    }

    public function test_guest_and_customer_cannot_access_itinerary_endpoints(): void
    {
        $tour = $this->tour('guest-denied');
        $this->getJson('/admin/internal/tours/'.$tour->id.'/itinerary')->assertUnauthorized();
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => []])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->getJson('/admin/internal/tours/'.$tour->id.'/itinerary')->assertForbidden();
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => []])->assertForbidden();
    }

    public function test_read_only_designer_cannot_write_itinerary(): void
    {
        $tour = $this->tour('designer-denied');
        $this->actingAs($this->staff('designer'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [['title' => 'Bad']]])
            ->assertForbidden();
    }

    public function test_content_editor_cannot_update_published_itinerary(): void
    {
        $tour = $this->tour('published-day', ['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->staff('content_editor'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [['title' => 'No']]])
            ->assertForbidden();
    }

    public function test_administrator_can_update_published_itinerary(): void
    {
        $tour = $this->tour('published-admin', ['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->staff('administrator'))
            ->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', ['days' => [['title' => 'Permitted']]])
            ->assertOk()->assertJsonPath('data.0.title', 'Permitted');
    }

    public function test_permission_revocation_blocks_subsequent_writes(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('revocation');
        $this->actingAs($editor)->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', [
            'days' => [['title' => 'Initial']],
        ])->assertOk();
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->detach($editor->getKey());
        $this->putJson('/admin/internal/tours/'.$tour->id.'/itinerary', [
            'days' => [['title' => 'Not Allowed']],
        ])->assertForbidden();
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'title' => 'Initial']);
    }

    public function test_editor_page_includes_itinerary_component_without_another_sidebar(): void
    {
        $tour = $this->tour('editor-ui');
        $this->actingAs($this->staff('administrator'));
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')->where('tourId', $tour->id)
        );
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/tours/editor.tsx'));
        self::assertStringContainsString('<TourItineraryEditor', $source);
        self::assertStringContainsString('persistedDurationDays=', $source);
        self::assertStringContainsString('lg:col-span-9', $source);
        self::assertStringNotContainsString('<CmsSidebarMenu', $source);
        $component = (string) file_get_contents(base_path('resources/js/components/admin/tours/TourItineraryEditor.tsx'));
        self::assertStringContainsString('confirmAction(', $component);
        self::assertStringContainsString('toast.success(', $component);
    }

    public function test_manager_still_guards_duration_when_called_by_service(): void
    {
        $tour = $this->tour('direct-service', ['duration_days' => 3]);
        $editor = $this->staff('content_editor');
        app(TourItineraryManager::class)->replace($editor, $tour, [
            ['title' => 'First'], ['title' => 'Second'], ['title' => 'Third'],
        ]);
        $this->actingAs($editor)->putJson('/admin/internal/tours/'.$tour->id, ['duration_days' => 2])
            ->assertUnprocessable();
    }
}
