<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * P2B.5A: the only application-level write API for cms_audit_events.
 *
 * Call from inside the SAME DB transaction as the business mutation. This
 * service intentionally does not swallow DB errors: failed audit writes must
 * roll back the business operation instead of silently losing evidence.
 * No middleware/listeners or business-domain integrations are added in P2B.5A.
 */
final class CmsAuditRecorder
{
    public function __construct(private readonly CmsAuditRedactor $redactor)
    {
    }

    /**
     * @param array<string, mixed> $metadata Intentionally curated, non-secret fields only.
     * @return string The unique audit event UUID.
     * @throws JsonException
     */
    public function record(
        string $action,
        ?User $actor,
        string $subjectType,
        int|string|null $subjectId,
        array $metadata = [],
    ): string {
        $this->assertEventKey($action, 100, true);
        $this->assertEventKey($subjectType, 80, false);

        $subjectIdString = $subjectId === null ? null : (string) $subjectId;
        if (
            $subjectIdString !== null && (
                $subjectIdString === '' || strlen($subjectIdString) > 64
            )
        ) {
            throw new InvalidArgumentException('Audit subject ID must be 1-64 bytes.');
        }


        $safeMetadata = $this->redactor->sanitize($metadata);

        $encoded = json_encode(
            $safeMetadata,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if (strlen($encoded) > 16384) {
            throw new InvalidArgumentException('Audit metadata exceeds 16 KiB.');
        }

        $request = app()->bound('request') ? app('request') : null;
        $ip = null;
        $agent = null;
        if ($request instanceof Request) {
            $candidate = $request->ip();
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidate;
            }
            $rawAgent = $request->userAgent();
            if (is_string($rawAgent) && $rawAgent !== '') {
                // User-Agent is untrusted input. Never retain embedded credential-like content.
                $agent = preg_match('/(?:\\bbearer\\s+\\S+|\\b(?:token|password|secret)\\s*[:=])/i', $rawAgent) === 1
                    ? '[REDACTED]'
                    : mb_substr($rawAgent, 0, 255);
            }
        }

        $id = (string) Str::uuid();

        DB::table('cms_audit_events')->insert([
            'id' => $id,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectIdString,
            'metadata_json' => $encoded,
            'ip_address' => $ip,
            'user_agent' => $agent,
            'occurred_at' => now('UTC'),
        ]);

        return $id;
    }

    private function assertEventKey(string $value, int $maxBytes, bool $dotted): void
    {
        $pattern = $dotted
            ? '/\\A[a-z][a-z0-9_]*(?:\\.[a-z][a-z0-9_]*)+\\z/D'
            : '/\\A[a-z][a-z0-9_]*\\z/D';

        if ($value === '' || strlen($value) > $maxBytes || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('Invalid CMS audit event action or subject type.');
        }
    }
}
