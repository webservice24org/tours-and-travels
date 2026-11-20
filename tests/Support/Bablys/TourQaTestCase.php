<?php

declare(strict_types=1);

namespace Tests\Support\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shared fixtures for P3D.4 only; no production application changes. */
abstract class TourQaTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    protected function staff(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $role)->firstOrFail()->users()->attach($user->getKey());

        return $user;
    }

    protected function category(string $slug, string $status = 'draft'): TourCategory
    {
        return TourCategory::query()->create([
            'name' => 'Category '.$slug, 'slug' => $slug, 'status' => $status,
        ]);
    }

    protected function destination(string $slug, string $status = 'draft'): Destination
    {
        return Destination::query()->create([
            'name' => 'Destination '.$slug, 'slug' => $slug,
            'country_code' => 'BD', 'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function tour(string $slug, array $extra = []): Tour
    {
        return Tour::query()->create(array_merge([
            'title' => 'Tour '.$slug, 'slug' => $slug,
        ], $extra));
    }

    /** @return array<string, mixed> */
    protected function payload(string $slug): array
    {
        return [
            'title' => 'Tour '.$slug,
            'slug' => $slug,
            'status' => 'draft',
            'duration_days' => 3,
            'duration_nights' => 2,
            'summary' => 'An editable Bablys journey.',
        ];
    }
}
