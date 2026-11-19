<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Domain\Cms\Access\RbacCatalog;
use App\Domain\Cms\Roles\CmsCustomRoleManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Roles\StoreCmsCustomRoleRequest;
use App\Http\Requests\Admin\Roles\SyncCmsCustomRolePermissionsRequest;
use App\Http\Requests\Admin\Roles\UpdateCmsCustomRoleRequest;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** JSON-only role manager; React TSX management screens arrive in P2B.4B. */
final class CmsCustomRoleController extends Controller
{
    public function __construct(private readonly CmsCustomRoleManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $this->manager->assertOwner($this->actor($request));
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', Rule::in(['all', 'system', 'custom'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = CmsRole::query()->with('permissions:id,key')->withCount('users');
        if (($filters['type'] ?? 'all') === 'system') {
            $query->where('is_system', true);
        } elseif (($filters['type'] ?? 'all') === 'custom') {
            $query->where('is_system', false);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(static function ($q) use ($search): void {
                $q->where('key', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        }
        $paginator = $query->orderByDesc('is_system')
            ->orderBy('key')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (CmsRole $role): array => $this->fields($role))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $this->manager->assertOwner($this->actor($request));
        $ownerOnly = CmsCustomRoleManager::OWNER_ONLY_PERMISSIONS;
        $definitions = [];
        foreach (RbacCatalog::permissions() as $key => $definition) {
            $definitions[] = [
                'key' => $key,
                'module' => $definition['module'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'assignable' => ! in_array($key, $ownerOnly, true),
            ];
        }
        return response()->json(['data' => $definitions]);
    }

    public function show(Request $request, CmsRole $role): JsonResponse
    {
        $this->manager->assertOwner($this->actor($request));
        return response()->json(['data' => $this->fields($role->load('permissions')->loadCount('users'))]);
    }

    public function store(StoreCmsCustomRoleRequest $request): JsonResponse
    {
        /** @var array{key: string, name: string, description?: ?string, permission_keys: list<string>} $payload */
        $payload = $request->validated();
        $role = $this->manager->create($this->actor($request), $payload);
        return response()->json(['data' => $this->fields($role)], 201);
    }

    public function update(UpdateCmsCustomRoleRequest $request, CmsRole $role): JsonResponse
    {
        /** @var array{name: string, description?: ?string} $payload */
        $payload = $request->validated();
        $updated = $this->manager->update($this->actor($request), $role, $payload);
        return response()->json(['data' => $this->fields($updated)]);
    }

    public function syncPermissions(SyncCmsCustomRolePermissionsRequest $request, CmsRole $role): JsonResponse
    {
        $updated = $this->manager->syncPermissions(
            $this->actor($request), $role, $request->validated('permission_keys')
        );
        return response()->json(['data' => $this->fields($updated)]);
    }

    public function destroy(Request $request, CmsRole $role): Response
    {
        $this->manager->delete($this->actor($request), $role);
        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    /** @return array{id:int,key:string,name:string,description:?string,is_system:bool,user_count:int,permission_keys:list<string>,can_edit:bool} */
    private function fields(CmsRole $role): array
    {
        $role->loadMissing('permissions')->loadCount('users');
        $protected = (bool) $role->is_system || array_key_exists((string) $role->key, RbacCatalog::roles());
        return [
            'id' => (int) $role->getKey(),
            'key' => (string) $role->key,
            'name' => (string) $role->name,
            'description' => $role->description === null ? null : (string) $role->description,
            'is_system' => (bool) $role->is_system,
            'user_count' => (int) $role->users_count,
            'permission_keys' => array_values($role->permissions->pluck('key')->map(static fn (mixed $key): string => (string) $key)->sort()->values()->all()),
            'can_edit' => ! $protected,
        ];
    }
}
