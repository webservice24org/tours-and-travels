<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** P2B.5C. Read-only audit queries with a second, owner-identity boundary. */
final class CmsAuditReader
{
    public function __construct(
        private readonly CmsAccess $access,
        private readonly CmsAuditRedactor $redactor,
    ) {}

    public function assertViewer(User $user): void
    {
        abort_unless($this->access->allows($user, 'audit.view'), 403);
        abort_unless(
            DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->where('cms_role_user.user_id', $user->getKey())
                ->where('cms_roles.key', 'super_admin')
                ->where('cms_roles.is_system', true)
                ->exists(),
            403
        );
    }

    /**
     * @return array{data: list<array<string, int|string|null>>, meta: array{current_page:int,last_page:int,per_page:int,total:int}}
     */
    public function paginate(CmsAuditFilters $filters): array
    {
        $query = $this->baseQuery();

        if ($filters->action !== null) {
            $query->where('events.action', $filters->action);
        }
        if ($filters->actorId !== null) {
            $query->where('events.actor_id', $filters->actorId);
        }
        if ($filters->subjectType !== null) {
            $query->where('events.subject_type', $filters->subjectType);
        }
        if ($filters->subjectId !== null) {
            $query->where('events.subject_id', $filters->subjectId);
        }
        if ($filters->dateFrom !== null) {
            $query->where('events.occurred_at', '>=', CarbonImmutable::parse($filters->dateFrom, 'UTC')->startOfDay());
        }
        if ($filters->dateTo !== null) {
            $query->where('events.occurred_at', '<', CarbonImmutable::parse($filters->dateTo, 'UTC')->startOfDay()->addDay());
        }
        if ($filters->search !== null) {
            // Query-builder bindings prevent SQL injection; input never becomes SQL syntax.
            $term = '%'.$filters->search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('events.action', 'like', $term)
                    ->orWhere('events.subject_type', 'like', $term)
                    ->orWhere('events.subject_id', 'like', $term)
                    ->orWhere('events.id', 'like', $term)
                    ->orWhere('actors.name', 'like', $term);
            });
        }

        $paginator = $query
            ->select([
                'events.id', 'events.actor_id', 'actors.name as actor_name',
                'events.action', 'events.subject_type', 'events.subject_id',
                'events.occurred_at',
            ])
            ->orderByDesc('events.occurred_at')
            ->orderByDesc('events.id')
            ->paginate($filters->perPage, ['*'], 'page', $filters->page);

        $records = [];
        foreach ($paginator->items() as $event) {
            $records[] = $this->publicFields($event);
        }

        return [
            'data' => $records,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{actions:list<string>,subject_types:list<string>} */
    public function options(): array
    {
        return [
            'actions' => $this->distinctValues('action'),
            'subject_types' => $this->distinctValues('subject_type'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        $event = $this->baseQuery()->where('events.id', $uuid)
            ->select([
                'events.id', 'events.actor_id', 'actors.name as actor_name',
                'events.action', 'events.subject_type', 'events.subject_id',
                'events.occurred_at', 'events.metadata_json',
                'events.ip_address', 'events.user_agent',
            ])->first();

        if ($event === null) {
            return null;
        }

        $metadata = json_decode((string) $event->metadata_json, true);
        // Never return unknown metadata keys; historical rows or future writers
        // could accidentally contain personal data the redactor cannot infer.
        // Extend this explicit allowlist only alongside a reviewed new event contract.
        $allowedKeys = [
            'role_key', 'role_keys_before', 'role_keys_after',
            'permission_keys_before', 'permission_keys_after',
            'changed_fields', 'enabled_before', 'enabled_after',
            'email_verified', 'email_verification_cleared',
        ];
        $curated = is_array($metadata)
            ? array_intersect_key($metadata, array_flip($allowedKeys))
            : [];
        $safeMetadata = $this->redactor->sanitize($curated);

        return [
            ...$this->publicFields($event),
            'metadata' => $safeMetadata,
            'ip_address' => $event->ip_address === null ? null : (string) $event->ip_address,
            'user_agent' => $event->user_agent === null ? null : (string) $event->user_agent,
        ];
    }

    private function baseQuery(): Builder
    {
        return DB::table('cms_audit_events as events')
            ->leftJoin('users as actors', 'actors.id', '=', 'events.actor_id');
    }

    /** @return list<string> */
    private function distinctValues(string $field): array
    {
        // Only caller-selected literal column names may reach this internal helper.
        $column = $field === 'action' ? 'action' : 'subject_type';
        $values = DB::table('cms_audit_events')
            ->select($column)->distinct()->orderBy($column)->limit(100)->pluck($column)
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        return array_values($values);
    }

    /** @return array{id:string, actor_id:int|null,actor_name:string,action:string,subject_type:string,subject_id:string|null,occurred_at:string} */
    private function publicFields(stdClass $event): array
    {
        $actorId = $event->actor_id === null ? null : (int) $event->actor_id;
        $actorName = is_string($event->actor_name) && $event->actor_name !== ''
            ? $event->actor_name
            : ($actorId === null ? 'System' : 'Deleted account #'.$actorId);

        return [
            'id' => (string) $event->id,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'action' => (string) $event->action,
            'subject_type' => (string) $event->subject_type,
            'subject_id' => $event->subject_id === null ? null : (string) $event->subject_id,
            'occurred_at' => CarbonImmutable::parse((string) $event->occurred_at, 'UTC')->toIso8601String(),
        ];
    }
}
