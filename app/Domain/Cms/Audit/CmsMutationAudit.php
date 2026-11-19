<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * P2B.5B: safe, deliberately curated mutation events and current DB snapshots.
 * Never persist complete models, request payloads, email addresses, or credentials.
 * The caller MUST invoke record() inside its existing business transaction.
 */
final class CmsMutationAudit
{
    public function __construct(private readonly CmsAuditRecorder $recorder)
    {
    }

    /** @return list<string> */
    public function roleKeysForUser(User $user): array
    {
        $keys = DB::table('cms_role_user')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
            ->where('cms_role_user.user_id', $user->getKey())
            ->pluck('cms_roles.key')
            ->map(static fn(mixed $key): string => (string) $key)
            ->all();

        return $this->sortedKeys(array_values($keys));
    }

    /** @return list<string> */
    public function permissionKeysForRole(CmsRole $role): array
    {
        $keys = DB::table('cms_permission_role')
            ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_permission_role.permission_id')
            ->where('cms_permission_role.role_id', $role->getKey())
            ->pluck('cms_permissions.key')
            ->map(static fn(mixed $key): string => (string) $key)
            ->all();

        return $this->sortedKeys(array_values($keys));
    }


    /**
     * @param list<string> $keys
     * @return list<string>
     */
    public function sortedKeys(array $keys): array
    {
        $ordered = $keys;

        sort($ordered, SORT_STRING);

        return $ordered;
    }


    /**
     * @param array<string, mixed> $metadata Curated fields only; no raw model or request data.
     */
    public function record(
        string $action,
        User $actor,
        string $subjectType,
        Model $subject,
        array $metadata = [],
    ): void {
        $key = $subject->getKey();
        if ($key === null) {
            throw new LogicException('Cannot record a CMS mutation without a persisted subject.');
        }

        // Do not swallow errors: a failed audit insert MUST abort the business transaction.
        $this->recorder->record($action, $actor, $subjectType, (string) $key, $metadata);
    }
}
