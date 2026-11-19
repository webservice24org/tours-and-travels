<?php

declare(strict_types=1);

namespace App\Domain\Cms\Staff;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * P2B.3A: owner-only changes to existing users and staff assignments.
 * The UI / invitation dispatch belongs to P2B.3B. Never accepts a caller-asserted role.
 */
final class CmsStaffUserManager
{
    public function __construct(private readonly CmsAccess $access)
    {
    }

    /** @param array{name:string,email:string,role_keys:list<string>} $data */
    public function createPending(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $this->assertOwner($actor);
            $roleIds = $this->roleIds($data['role_keys']);

            // No shared or administrator-chosen temporary password is returned.
            // Mail invitation/password setup is a deliberate P2B.3B workflow.
            $user = new User();
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = Hash::make(Str::random(64));
            $user->email_verified_at = null;
            $user->save();

            $this->writeRoles($user, $roleIds);
            app(\App\Domain\Cms\Audit\CmsMutationAudit::class)->record(
                'staff.created', $actor, 'staff_user', $user,
                ['role_keys_after' => app(\App\Domain\Cms\Audit\CmsMutationAudit::class)
                    ->roleKeysForUser($user), 'email_verified' => false],
            );

            return $user->refresh();
        }, 3);
    }

    /** @param array{name:string,email:string} $data */
    public function updateProfile(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data): User {
            $this->assertOwner($actor);
            $locked = $this->lockEditableTarget($actor, $target);
            $emailChanged = $locked->email !== $data['email'];
            $changedFields = [];
            if ($locked->name !== $data['name']) {
                $changedFields[] = 'name';
            }
            if ($emailChanged) {
                $changedFields[] = 'email';
            }
            $locked->name = $data['name'];
            $locked->email = $data['email'];
            if ($emailChanged) {
                // Any new email must be reverified before CMS access resumes.
                $locked->email_verified_at = null;
            }
            $locked->save();
            if ($changedFields !== []) {
                app(\App\Domain\Cms\Audit\CmsMutationAudit::class)->record(
                    'staff.updated', $actor, 'staff_user', $locked,
                    ['changed_fields' => $changedFields, 'email_verification_cleared' => $emailChanged],
                );
            }

            return $locked->refresh();
        }, 3);
    }

    /** @param list<string> $keys */
    public function replaceRoles(User $actor, User $target, array $keys): User
    {
        return DB::transaction(function () use ($actor, $target, $keys): User {
            $this->assertOwner($actor);
            $locked = $this->lockEditableTarget($actor, $target);
            $ids = $this->roleIds($keys);
            $audit = app(\App\Domain\Cms\Audit\CmsMutationAudit::class);
            $before = $audit->roleKeysForUser($locked);
            $after = $audit->sortedKeys($keys);
            if ($before !== $after) {
                // [] deliberately turns a regular staff account back into a customer.
                DB::table('cms_role_user')->where('user_id', $locked->getKey())->delete();
                $this->writeRoles($locked, $ids);
                $audit->record('staff.roles.changed', $actor, 'staff_user', $locked, [
                    'role_keys_before' => $before,
                    'role_keys_after' => $after,
                ]);
            }

            return $locked->refresh();
        }, 3);
    }

    public function setStaffAccess(User $actor, User $target, bool $enabled): User
    {
        return DB::transaction(function () use ($actor, $target, $enabled): User {
            $this->assertOwner($actor);
            $locked = $this->lockEditableTarget($actor, $target);

            if (!DB::table('cms_role_user')->where('user_id', $locked->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'user' => 'This account has no staff role. Assign a permitted role first.',
                ]);
            }

            $wasEnabled = $locked->cms_staff_disabled_at === null;
            if ($wasEnabled !== $enabled) {
                $locked->forceFill([
                    'cms_staff_disabled_at' => $enabled ? null : now(),
                ])->save();
                app(\App\Domain\Cms\Audit\CmsMutationAudit::class)->record(
                    $enabled ? 'staff.restored' : 'staff.suspended',
                    $actor, 'staff_user', $locked,
                    ['enabled_before' => $wasEnabled, 'enabled_after' => $enabled],
                );
            }

            return $locked->refresh();
        }, 3);
    }

    public function assertOwner(User $actor): void
    {
        // Recheck authorization inside every write transaction, not just middleware.
        abort_unless($this->access->allows($actor, 'roles.manage'), 403);
        abort_unless(
            DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->where('cms_role_user.user_id', $actor->getKey())
                ->where('cms_roles.key', 'super_admin')
                ->exists(),
            403
        );
    }

    private function lockEditableTarget(User $actor, User $target): User
    {
        // Serializes privilege-related writes with other P2B.3A mutations.
        CmsRole::query()->where('key', 'super_admin')->lockForUpdate()->firstOrFail();
        $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

        if ((string) $actor->getKey() === (string) $locked->getKey()) {
            throw ValidationException::withMessages([
                'user' => 'You cannot change your own CMS staff access here.',
            ]);
        }
        if (
            DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->where('cms_role_user.user_id', $locked->getKey())
                ->where('cms_roles.key', 'super_admin')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'user' => 'Super Administrator accounts are protected from staff API changes.',
            ]);
        }

        return $locked;
    }


    /**
     * Resolve assignable CMS role IDs.
     *
     * @param list<string> $keys
     * @return list<int>
     */
    private function roleIds(array $keys): array
    {
        return app(
            \App\Domain\Cms\Staff\CmsAssignableRoles::class
        )->idsForKeys($keys);
    }


    /** @param list<int> $roleIds */
    private function writeRoles(User $user, array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }
        $time = now();
        DB::table('cms_role_user')->insert(array_map(
            static fn(int $roleId): array => [
                'role_id' => $roleId,
                'user_id' => $user->getKey(),
                'created_at' => $time,
                'updated_at' => $time,
            ],
            $roleIds
        ));
    }
}
