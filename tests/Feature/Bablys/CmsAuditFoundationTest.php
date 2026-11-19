<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** P2B.5A — additive audit schema, safe writes, and transaction integrity. */
final class CmsAuditFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_schema_exists_with_immutable_event_fields(): void
    {
        self::assertTrue(Schema::hasTable('cms_audit_events'));
        foreach (['id', 'actor_id', 'action', 'subject_type', 'subject_id',
            'metadata_json', 'ip_address', 'user_agent', 'occurred_at'] as $column) {
            self::assertTrue(Schema::hasColumn('cms_audit_events', $column), $column);
        }
        self::assertFalse(Schema::hasColumn('cms_audit_events', 'updated_at'));
    }

    public function test_recorder_writes_attributed_append_only_event_with_curated_metadata(): void
    {
        $actor = User::factory()->create();
        $id = app(CmsAuditRecorder::class)->record(
            'staff.roles_changed',
            $actor,
            'staff_user',
            28,
            ['role_keys_before' => ['booking_agent'], 'role_keys_after' => ['content_editor']],
        );

        $event = DB::table('cms_audit_events')->where('id', $id)->first();
        self::assertNotNull($event);
        self::assertSame((int) $actor->getKey(), (int) $event->actor_id);
        self::assertSame('staff.roles_changed', $event->action);
        self::assertSame('staff_user', $event->subject_type);
        self::assertSame('28', $event->subject_id);
        self::assertNotNull($event->occurred_at);
        self::assertSame(
            ['role_keys_before' => ['booking_agent'], 'role_keys_after' => ['content_editor']],
            json_decode($event->metadata_json, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_sensitive_fields_and_nested_secrets_are_redacted_before_storage(): void
    {
        $id = app(CmsAuditRecorder::class)->record('staff.invited', null, 'staff_user', 42, [
            'reason_code' => 'manual_invitation',
            'password' => 'super-secret-password',
            'nested' => [
                'reset_token' => 'extremely-secret-reset-token',
                'apiKey' => 'extremely-secret-api-key',
                'note' => 'Bearer private-token',
                'safe' => true,
            ],
        ]);

        $raw = DB::table('cms_audit_events')->where('id', $id)->value('metadata_json');
        self::assertIsString($raw);
        self::assertStringNotContainsString('super-secret-password', $raw);
        self::assertStringNotContainsString('extremely-secret-reset-token', $raw);
        self::assertStringNotContainsString('extremely-secret-api-key', $raw);
        self::assertStringNotContainsString('private-token', $raw);
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('manual_invitation', $payload['reason_code']);
        self::assertSame('[REDACTED]', $payload['password']);
        self::assertSame('[REDACTED]', $payload['nested']['apiKey']);
        self::assertTrue($payload['nested']['safe']);
    }

    public function test_audit_event_is_rolled_back_with_failed_business_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                DB::table('cms_audit_events')->count();
                app(CmsAuditRecorder::class)->record('role.created', null, 'cms_role', 'custom_ops');
                throw new RuntimeException('Simulated business failure');
            });
            self::fail('Expected a simulated transaction rollback');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated business failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('cms_audit_events')->count());
    }

    public function test_invalid_event_key_does_not_insert_a_row(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            app(CmsAuditRecorder::class)->record('unknown action', null, 'cms_role', 1);
        } finally {
            self::assertSame(0, DB::table('cms_audit_events')->count());
        }
    }

    public function test_oversized_or_object_metadata_is_rejected(): void
    {
        $recorder = app(CmsAuditRecorder::class);
        try {
            $recorder->record('role.created', null, 'cms_role', 1, ['note' => str_repeat('A', 1001)]);
            self::fail('Oversized values should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('cms_audit_events')->count());
        }

        try {
            $recorder->record('role.created', null, 'cms_role', 1, ['model' => new \stdClass()]);
            self::fail('Objects should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('cms_audit_events')->count());
        }
    }

    public function test_ip_and_user_agent_are_recorded_without_trusting_embedded_credentials(): void
    {
        $request = Request::create('/admin', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.20',
            'HTTP_USER_AGENT' => 'Bablys-QA-Agent',
        ]);
        $this->app->instance('request', $request);

        $recorder = app(CmsAuditRecorder::class);
        $id = $recorder->record('role.created', null, 'cms_role', 99);
        $event = DB::table('cms_audit_events')->where('id', $id)->first();
        self::assertNotNull($event);
        self::assertSame('203.0.113.20', $event->ip_address);
        self::assertSame('Bablys-QA-Agent', $event->user_agent);

        $request->headers->set('User-Agent', 'token=private-value');
        $second = $recorder->record('role.updated', null, 'cms_role', 99);
        self::assertSame('[REDACTED]', DB::table('cms_audit_events')->where('id', $second)->value('user_agent'));
    }
}
