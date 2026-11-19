<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P2B.5D: privacy and actual owner-boundary regression, not UI-only checks. */
final class CmsAuditPrivacyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_customer_and_editor_cannot_read_any_audit_surface(): void
    {
        $id = $this->record(null);
        $this->get('/admin/audit-logs')->assertRedirect('/login');
        $this->getJson('/admin/internal/audit-logs')->assertUnauthorized();
        $this->getJson('/admin/internal/audit-logs/options')->assertUnauthorized();
        $this->getJson('/admin/internal/audit-logs/'.$id)->assertUnauthorized();

        foreach ([$this->customer(), $this->withRole('content_editor')] as $user) {
            $this->actingAs($user)->get('/admin/audit-logs')->assertForbidden();
            $this->actingAs($user)->getJson('/admin/internal/audit-logs')->assertForbidden();
            $this->actingAs($user)->getJson('/admin/internal/audit-logs/options')->assertForbidden();
            $this->actingAs($user)->getJson('/admin/internal/audit-logs/'.$id)->assertForbidden();
        }
    }

    public function test_accidental_permission_grant_does_not_override_system_owner_identity(): void
    {
        $editor = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'audit.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([(int) $permission->getKey()]);

        $id = $this->record(null);
        $this->actingAs($editor)->getJson('/admin/internal/audit-logs')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/audit-logs/'.$id)->assertForbidden();
    }

    public function test_suspended_super_admin_loses_access_immediately_even_on_same_session(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertOk();
        DB::table('users')->where('id', $owner->id)->update(['cms_staff_disabled_at' => now()]);
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertForbidden();
        $this->actingAs($owner)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_unverified_super_admin_loses_access_immediately(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertOk();
        DB::table('users')->where('id', $owner->id)->update(['email_verified_at' => null]);
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertForbidden();
    }

    public function test_super_admin_role_revocation_denies_viewer_without_relogin(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertOk();
        DB::table('cms_role_user')->where('user_id', $owner->id)->delete();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertForbidden();
    }

    public function test_list_never_returns_metadata_ip_or_user_agent_even_when_recorded(): void
    {
        $owner = $this->withRole('super_admin');
        $id = $this->record($owner, [
            'role_keys_after' => ['content_editor'],
            'password' => 'secret-not-for-browser',
            'authorization' => 'Bearer secret-bearer',
        ]);
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.ip_address')
            ->assertJsonMissingPath('data.0.user_agent')
            ->assertDontSee('secret-not-for-browser')
            ->assertDontSee('secret-bearer');
    }

    public function test_recorder_redacts_nested_sensitive_fields_at_rest(): void
    {
        $owner = $this->withRole('super_admin');
        $id = $this->record($owner, [
            'role_keys_after' => ['content_editor'],
            'private' => [
                'api_key' => 'secret-api-key-value',
                'session_token' => 'secret-session-value',
                'note' => 'Authorization: Bearer secret-bearer-value',
            ],
        ]);
        $stored = (string) DB::table('cms_audit_events')->where('id', $id)->value('metadata_json');
        self::assertStringContainsString('[REDACTED]', $stored);
        self::assertStringNotContainsString('secret-api-key-value', $stored);
        self::assertStringNotContainsString('secret-session-value', $stored);
        self::assertStringNotContainsString('secret-bearer-value', $stored);
    }

    public function test_detail_allowlists_legacy_metadata_and_redacts_embedded_secrets(): void
    {
        $owner = $this->withRole('super_admin');
        // P2B.6B: an immutable audit table cannot be UPDATEd, even in tests.
        // INSERT a deliberately unredacted historical fixture instead so the
        // viewer's legacy-data allowlist/redaction behavior remains covered.
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('cms_audit_events')->insert([
            'id' => $id,
            'actor_id' => $owner->getKey(),
            'action' => 'staff.roles.changed',
            'subject_type' => 'staff_user',
            'subject_id' => '321',
            'metadata_json' => json_encode([
                'role_key' => 'Bearer legacy-secret-123',
                'role_keys_after' => ['booking_agent'],
                'old_customer_email' => 'private-person@bablys.test',
                'password' => 'legacy-password-123',
            ], JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'occurred_at' => now('UTC'),
        ]);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/'.$id)
            ->assertOk()
            ->assertJsonPath('data.metadata.role_key', '[REDACTED]')
            ->assertJsonPath('data.metadata.role_keys_after.0', 'booking_agent')
            ->assertJsonMissingPath('data.metadata.old_customer_email')
            ->assertJsonMissingPath('data.metadata.password')
            ->assertDontSee('legacy-secret-123')
            ->assertDontSee('private-person@bablys.test')
            ->assertDontSee('legacy-password-123');
    }

    /** @param array<string, mixed> $metadata */
    private function record(?User $actor, array $metadata = []): string
    {
        return app(CmsAuditRecorder::class)->record('staff.roles.changed', $actor, 'staff_user', 321, $metadata);
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
