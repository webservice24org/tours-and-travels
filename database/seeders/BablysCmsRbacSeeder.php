<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Cms\Access\RbacCatalog;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class BablysCmsRbacSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(static function (): void {
            $roles = [];
            $newRoles = [];

            foreach (RbacCatalog::roles() as $key => $definition) {
                $role = CmsRole::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'is_system' => true,
                    ],
                );

                $roles[$key] = $role;
                $newRoles[$key] = $role->wasRecentlyCreated;
            }

            $permissionIdsForRole = [];
            foreach (RbacCatalog::permissions() as $key => $definition) {
                $permission = CmsPermission::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'module' => $definition['module'],
                        'action' => $definition['action'],
                        'scope' => 'admin',
                        'label' => $definition['label'],
                        'description' => $definition['description'],
                    ],
                );

                foreach ($definition['roles'] as $roleKey) {
                    $permissionIdsForRole[$roleKey][] = $permission->getKey();
                }
            }

            // First-run defaults only: later seeding never restores a grant
            // that an authorized administrator intentionally removed.
            foreach ($roles as $key => $role) {
                if ($newRoles[$key]) {
                    $role->permissions()->sync($permissionIdsForRole[$key] ?? []);
                }
            }
        });
    }
}
