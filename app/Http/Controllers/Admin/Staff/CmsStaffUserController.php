<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Staff;

use App\Domain\Cms\Staff\CmsStaffUserManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Staff\SetCmsStaffAccessRequest;
use App\Http\Requests\Admin\Staff\StoreCmsStaffUserRequest;
use App\Http\Requests\Admin\Staff\SyncCmsStaffRolesRequest;
use App\Http\Requests\Admin\Staff\UpdateCmsStaffUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** JSON-only staff endpoints: the Inertia/TSX management UI arrives in P2B.3B. */
final class CmsStaffUserController extends Controller
{
    public function __construct(private readonly CmsStaffUserManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $this->manager->assertOwner($this->actor($request));
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', 'string', 'max:100', Rule::exists('cms_roles', 'key')],
            'status' => ['sometimes', Rule::in(['all', 'active', 'pending', 'disabled', 'customer'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = User::query()->select([
            'id', 'name', 'email', 'email_verified_at', 'cms_staff_disabled_at', 'created_at',
        ]);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            if ($search !== '') {
                $query->where(static function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            }
        }

        if (! empty($filters['role'])) {
            $query->whereIn('id', DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->where('cms_roles.key', $filters['role'])
                ->select('cms_role_user.user_id'));
        }

        $roleUsers = DB::table('cms_role_user')->select('user_id');
        $status = $filters['status'] ?? 'all';
        if ($status === 'customer') {
            $query->whereNotIn('id', $roleUsers);
        } elseif ($status === 'disabled') {
            $query->whereNotNull('cms_staff_disabled_at')->whereIn('id', $roleUsers);
        } elseif ($status === 'pending') {
            $query->whereNull('email_verified_at')->whereIn('id', $roleUsers);
        } elseif ($status === 'active') {
            $query->whereNotNull('email_verified_at')
                ->whereNull('cms_staff_disabled_at')
                ->whereIn('id', $roleUsers);
        }

        $paginator = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));
        $users = $paginator->getCollection();
        $ids = array_values($users->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $roles = $this->roleMap($ids);

        return response()->json([
            'data' => $users->map(fn (User $user): array => $this->publicFields($user, $roles[(int) $user->getKey()] ?? []))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->manager->assertOwner($this->actor($request));
        return response()->json(['data' => $this->publicFields($user)]);
    }

    public function store(StoreCmsStaffUserRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, role_keys: list<string>} $payload */
        $payload = $request->validated();
        $user = $this->manager->createPending($this->actor($request), $payload);
        return response()->json([
            'data' => $this->publicFields($user),
            'message' => 'Pending staff account created. It cannot access the CMS until secure password setup and email verification.',
        ], 201);
    }

    public function update(UpdateCmsStaffUserRequest $request, User $user): JsonResponse
    {
        /** @var array{name: string, email: string} $payload */
        $payload = $request->validated();
        $updated = $this->manager->updateProfile($this->actor($request), $user, $payload);
        return response()->json(['data' => $this->publicFields($updated)]);
    }

    public function syncRoles(SyncCmsStaffRolesRequest $request, User $user): JsonResponse
    {
        $updated = $this->manager->replaceRoles($this->actor($request), $user, $request->validated('role_keys'));
        return response()->json(['data' => $this->publicFields($updated)]);
    }

    public function setAccess(SetCmsStaffAccessRequest $request, User $user): JsonResponse
    {
        $updated = $this->manager->setStaffAccess($this->actor($request), $user, $request->boolean('enabled'));
        return response()->json(['data' => $this->publicFields($updated)]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        return $user;
    }

    /**
     * @param list<string>|null $roleKeys
     * @return array<string, mixed>
     */
    private function publicFields(User $user, ?array $roleKeys = null): array
    {
        $roleKeys ??= $this->roleMap([(int) $user->getKey()])[(int) $user->getKey()] ?? [];

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'cms_staff_enabled' => $user->cms_staff_disabled_at === null,
            'role_keys' => $roleKeys,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<string>>
     */
    private function roleMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        $rows = DB::table('cms_role_user')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
            ->whereIn('cms_role_user.user_id', $ids)
            ->orderBy('cms_roles.key')
            ->get(['cms_role_user.user_id', 'cms_roles.key']);
        foreach ($rows as $row) {
            $map[(int) $row->user_id][] = (string) $row->key;
        }
        return $map;
    }
}
