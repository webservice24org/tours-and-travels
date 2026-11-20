<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Itineraries\TourItineraryManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourItineraryDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourQaTestCase;

final class TourItineraryManagerTest extends TourQaTestCase
{
    public function test_editor_can_save_and_read_ordered_partial_days(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('garden-tour', ['duration_days' => 3]);
        $manager = app(TourItineraryManager::class);
        $manager->replace($editor, $tour, [
            ['title' => 'Arrival', 'meals' => ['dinner']],
            ['title' => 'Hill visit', 'highlights' => ['Hike']],
        ]);
        $days = $manager->forStaff($editor, $tour);
        self::assertSame([1, 2], array_column($days, 'day_number'));
        self::assertSame('Hill visit', $days[1]['title']);
        self::assertSame(2, TourItineraryDay::query()->where('tour_id', $tour->id)->count());
    }

    public function test_repeated_identical_save_does_not_duplicate_audit_or_change_ids(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('same-itinerary', ['duration_days' => 2]);
        $manager = app(TourItineraryManager::class);
        $input = [['title' => 'Arrival', 'meals' => ['dinner', 'breakfast']]];
        $manager->replace($editor, $tour, $input);
        $id = (int) TourItineraryDay::query()->firstOrFail()->id;
        $manager->replace($editor, $tour, $input);
        self::assertSame($id, (int) TourItineraryDay::query()->firstOrFail()->id);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.itinerary.changed')->count());
    }

    public function test_shrinking_a_saved_itinerary_removes_trailing_days(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('shorten', ['duration_days' => 3]);
        $manager = app(TourItineraryManager::class);
        $manager->replace($editor, $tour, [
            ['title' => 'First'], ['title' => 'Second'], ['title' => 'Third'],
        ]);
        $manager->replace($editor, $tour, [['title' => 'First']]);
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'day_number' => 1]);
        $this->assertDatabaseMissing('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'day_number' => 2]);
        $this->assertDatabaseMissing('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'day_number' => 3]);
    }

    public function test_invalid_replacement_rolls_back_existing_days_and_audit(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('rollback', ['duration_days' => 2]);
        $manager = app(TourItineraryManager::class);
        $manager->replace($editor, $tour, [['title' => 'Saved']]);
        $beforeCount = DB::table('cms_audit_events')->count();
        try {
            $manager->replace($editor, $tour, [
                ['title' => 'Changed'], ['title' => '<script>bad</script>'],
            ]);
            self::fail('Invalid HTML should have failed itinerary validation.');
        } catch (ValidationException) {
            self::assertSame('Saved', TourItineraryDay::query()->firstOrFail()->title);
            self::assertSame($beforeCount, DB::table('cms_audit_events')->count());
        }
    }

    public function test_editor_cannot_edit_published_tour_without_publish_permission(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('published', ['duration_days' => 2, 'status' => 'published', 'published_at' => now()]);
        $this->expectException(HttpException::class);
        app(TourItineraryManager::class)->replace($editor, $tour, [['title' => 'Not permitted']]);
    }

    public function test_administrator_can_edit_published_tour_itinerary(): void
    {
        $admin = $this->staff('administrator');
        $tour = $this->tour('live-tour', ['duration_days' => 2, 'status' => 'published', 'published_at' => now()]);
        app(TourItineraryManager::class)->replace($admin, $tour, [['title' => 'Authorized']]);
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id, 'title' => 'Authorized']);
    }

    public function test_revoked_staff_cannot_edit_tour_itinerary(): void
    {
        $editor = $this->staff('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $role->users()->detach($editor->getKey());
        $tour = $this->tour('revoked-tour');
        $this->expectException(HttpException::class);
        app(TourItineraryManager::class)->replace($editor, $tour, [['title' => 'Denied']]);
    }

    public function test_customer_cannot_read_draft_itinerary(): void
    {
        $customer = \App\Models\User::factory()->create();
        $tour = $this->tour('private-tour');
        $this->expectException(HttpException::class);
        app(TourItineraryManager::class)->forStaff($customer, $tour);
    }

    public function test_audit_metadata_excludes_itinerary_text(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('audit-itinerary');
        $secret = 'ConfidentialDayDetail928371';
        app(TourItineraryManager::class)->replace($editor, $tour, [
            ['title' => 'Day One', 'description' => $secret],
        ]);
        $row = DB::table('cms_audit_events')->where('action', 'tour.itinerary.changed')->first();
        self::assertNotNull($row);
        self::assertStringNotContainsString($secret, (string) json_encode($row));
        self::assertStringNotContainsString('Day One', (string) json_encode($row));
    }
}
