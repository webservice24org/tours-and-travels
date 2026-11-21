<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Navigation\BablysNavigationMenus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CreateBablysNavigationMenuRequest;
use App\Http\Requests\Admin\Cms\SaveBablysNavigationMenuRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class BablysNavigationMenusController extends Controller
{
    public function index(Request $request, BablysNavigationMenus $menus): Response
    {
        Gate::authorize('settings.manage');
        return $this->privateResponse(Inertia::render('admin/navigation-menus/index', [
            'menus' => $menus->summaries(),
        ])->toResponse($request));
    }

    public function store(CreateBablysNavigationMenuRequest $request, BablysNavigationMenus $menus): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validated();
        $id = $menus->create((string) $data['menu_key'], (string) $data['name'], (int) $actor->getKey());
        return redirect()->route('admin.navigation-menus.show', ['menu' => $id])
            ->with('success', 'Navigation menu draft created. Not published.');
    }

    public function show(Request $request, BablysNavigationMenus $menus, int $menu): Response
    {
        Gate::authorize('settings.manage');
        return $this->privateResponse(Inertia::render('admin/navigation-menus/show', [
            'menu' => $menus->editor($menu),
        ])->toResponse($request));
    }

    public function update(SaveBablysNavigationMenuRequest $request, BablysNavigationMenus $menus, int $menu): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $menus->saveDraft($menu, $request->validated(), (int) $actor->getKey());
        return redirect()->route('admin.navigation-menus.show', ['menu' => $menu])
            ->with('success', 'Navigation draft saved. Public navigation unchanged.');
    }

    public function destroy(Request $request, BablysNavigationMenus $menus, int $menu): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0']]);
        $menus->deleteUnpublished($menu, (int) $data['expected_version']);
        return redirect()->route('admin.navigation-menus.index')->with('success', 'Unpublished menu deleted.');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
