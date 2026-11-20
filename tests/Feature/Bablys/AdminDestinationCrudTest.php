<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Taxonomy\PublishedTaxonomyCatalog;
use App\Models\Cms\CmsRole;
use App\Models\Travel\Destination;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class AdminDestinationCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_customer_and_unrelated_staff_cannot_use_destination_api(): void
    {
        $this->getJson('/admin/internal/destinations')->assertUnauthorized();
        $this->postJson('/admin/internal/destinations', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/admin/internal/destinations')->assertForbidden();
        $reviewer = $this->withRole('review_moderator');
        $this->actingAs($reviewer)
            ->postJson('/admin/internal/destinations', $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('travel_destinations', ['slug' => 'coxs-bazar']);
    }

    public function test_editor_creates_draft_and_writes_safe_audit_event(): void
    {
        $editor = $this->withRole('content_editor');
        $payload = $this->payload();
        $payload['slug'] = ' COXS-BAZAR ';
        $payload['country_code'] = 'bd';
        $response = $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'coxs-bazar')
            ->assertJsonPath('data.country_code', 'BD')
            ->assertJsonPath('data.status', 'draft');

        $id = (int) $response->json('data.id');
        $this->assertDatabaseHas('travel_destinations', ['id' => $id, 'country_code' => 'BD']);
        $this->assertDatabaseHas('cms_audit_events', [
            'actor_id' => $editor->getKey(),
            'action' => 'taxonomy.destination.created',
            'subject_type' => 'destination',
            'subject_id' => (string) $id,
        ]);
    }

    public function test_destination_listing_filters_search_country_and_pagination(): void
    {
        $editor = $this->withRole('content_editor');
        $this->destination('coxs-bazar', 'BD', 'published');
        $this->destination('sylhet', 'BD', 'draft');
        $this->destination('phuket', 'TH', 'published');
        $this->destination('archived-one', 'TH', 'archived');

        $this->actingAs($editor)
            ->getJson('/admin/internal/destinations?country_code=bd&status=published&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'coxs-bazar');
        $this->actingAs($editor)->getJson('/admin/internal/destinations?search=phu')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($editor)->getJson('/admin/internal/destinations/options')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_unauthorized_and_invalid_filters_are_rejected(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->getJson('/admin/internal/destinations?per_page=100')
            ->assertUnprocessable();
        $this->actingAs($editor)->getJson('/admin/internal/destinations?status=scheduled')
            ->assertUnprocessable();
        $this->actingAs($editor)->getJson('/admin/internal/destinations?country_code=USA')
            ->assertUnprocessable();
    }

    public function test_bad_country_and_out_of_range_coordinates_are_rejected(): void
    {
        $editor = $this->withRole('content_editor');
        $payload = $this->payload();
        $payload['country_code'] = 'BD1';
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $payload = $this->payload();
        $payload['latitude'] = 91;
        $payload['longitude'] = 45;
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $payload = $this->payload();
        $payload['latitude'] = 12;
        $payload['longitude'] = 181;
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        self::assertSame(0, (int) DB::table('cms_audit_events')->count());
    }

    public function test_coordinates_are_optional_but_must_be_provided_together(): void
    {
        $editor = $this->withRole('content_editor');
        $payload = $this->payload();
        $payload['latitude'] = 21.4272;
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
        $payload['longitude'] = 92.0058;
        $response = $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertCreated();
        $id = (int) $response->json('data.id');
        $this->actingAs($editor)->putJson('/admin/internal/destinations/'.$id, ['latitude' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->actingAs($editor)->putJson('/admin/internal/destinations/'.$id, [
            'latitude' => null, 'longitude' => null,
        ])->assertOk()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
    }

    public function test_coordinate_filter_works_with_both_states(): void
    {
        $editor = $this->withRole('content_editor');
        $this->destination('dry-place');
        Destination::query()->create([
            'name' => 'Mapped Place', 'slug' => 'mapped-place', 'country_code' => 'BD',
            'latitude' => 23.8000, 'longitude' => 90.4000,
        ]);
        $this->actingAs($editor)->getJson('/admin/internal/destinations?has_coordinates=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'mapped-place');
        $this->actingAs($editor)->getJson('/admin/internal/destinations?has_coordinates=0')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'dry-place');
    }

    public function test_duplicate_slug_even_if_soft_deleted_and_reserved_slug_are_rejected(): void
    {
        $editor = $this->withRole('content_editor');
        $this->destination('coxs-bazar')->delete();
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $payload = $this->payload();
        $payload['slug'] = 'admin';
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_slug_cannot_be_changed_on_update(): void
    {
        $editor = $this->withRole('content_editor');
        $destination = $this->destination('coxs-bazar');
        $this->actingAs($editor)
            ->putJson('/admin/internal/destinations/'.$destination->id, ['slug' => 'other-place'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        self::assertSame('coxs-bazar', $destination->refresh()->slug);
    }

    public function test_partial_update_audits_changes_but_not_description(): void
    {
        $editor = $this->withRole('content_editor');
        $destination = $this->destination('coxs-bazar');
        $url = '/admin/internal/destinations/'.$destination->id;
        $this->actingAs($editor)->putJson($url, [
            'name' => 'Updated Name', 'description' => 'Sensitive draft content never belongs in the audit metadata.',
            'country_code' => 'th',
        ])->assertOk()->assertJsonPath('data.country_code', 'TH');
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'taxonomy.destination.updated']);
        $metadata = (string) DB::table('cms_audit_events')->value('metadata_json');
        self::assertStringNotContainsString('Sensitive draft content', $metadata);
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
    }

    public function test_no_op_update_does_not_write_audit_event(): void
    {
        $editor = $this->withRole('content_editor');
        $destination = $this->destination('coxs-bazar');
        $this->actingAs($editor)->putJson('/admin/internal/destinations/'.$destination->id, [
            'name' => 'Coxs Bazar',
        ])->assertOk();
        self::assertSame(0, (int) DB::table('cms_audit_events')->count());
    }

    public function test_published_destination_cannot_be_soft_deleted_until_archived(): void
    {
        $editor = $this->withRole('content_editor');
        $destination = $this->destination('coxs-bazar', 'BD', 'published');
        $url = '/admin/internal/destinations/'.$destination->id;
        $this->actingAs($editor)->deleteJson($url)->assertUnprocessable()
            ->assertJsonValidationErrors('destination');
        self::assertNull($destination->refresh()->deleted_at);
        $this->actingAs($editor)->putJson($url, ['status' => 'archived'])->assertOk();
        $this->actingAs($editor)->deleteJson($url)->assertNoContent();
        $this->actingAs($editor)->getJson($url)->assertNotFound();
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'taxonomy.destination.deleted']);
    }

    public function test_public_taxonomy_catalog_only_sees_published_destination(): void
    {
        $this->destination('visible', 'BD', 'published');
        $this->destination('hidden');
        $this->destination('archived', 'BD', 'archived');
        self::assertSame(['visible'], array_column(app(PublishedTaxonomyCatalog::class)->destinations('bd'), 'slug'));
        $this->get('/')->assertOk();
    }

    public function test_revoked_permission_blocks_further_updates_without_relogin(): void
    {
        $editor = $this->withRole('content_editor');
        $destination = $this->destination('coxs-bazar');
        self::assertTrue(Gate::forUser($editor)->allows('taxonomy.manage'));
        $editor->forceFill(['cms_staff_disabled_at' => now()])->save();
        self::assertFalse(Gate::forUser($editor)->allows('taxonomy.manage'));
        $this->actingAs($editor)->putJson('/admin/internal/destinations/'.$destination->id, [
            'name' => 'Blocked Change',
        ])->assertForbidden();
        self::assertSame('Coxs Bazar', $destination->refresh()->name);
    }

    public function test_non_existing_and_deleted_destinations_are_not_retrievable(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->getJson('/admin/internal/destinations/99999')->assertNotFound();
        $destination = $this->destination('coxs-bazar');
        $destination->delete();
        $this->actingAs($editor)->getJson('/admin/internal/destinations/'.$destination->id)
            ->assertNotFound();
    }

    /** @return array{name:string,slug:string,country_code:string,status:string,sort_order:int} */
    private function payload(): array
    {
        return [
            'name' => 'Coxs Bazar',
            'slug' => 'coxs-bazar',
            'country_code' => 'BD',
            'status' => 'draft',
            'sort_order' => 10,
        ];
    }

    private function destination(string $slug, string $country = 'BD', string $status = 'draft'): Destination
    {
        return Destination::query()->create([
            'name' => $slug === 'coxs-bazar' ? 'Coxs Bazar' : ucfirst($slug),
            'slug' => $slug,
            'country_code' => $country,
            'status' => PublicationStatus::from($status),
        ]);
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
