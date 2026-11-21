<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\UpdateBablysSiteSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class BablysSiteSettingsController extends Controller
{
    public function index(Request $request, BablysSiteSettings $settings): Response
    {
        Gate::authorize('settings.manage');
        $response = Inertia::render('admin/site-settings/index', [
            ...$settings->editorSnapshot(),
            'can_publish' => false, // P4A.3 adds the audited publish/revert workflow.
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }

    public function update(UpdateBablysSiteSettingsRequest $request, BablysSiteSettings $settings): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $settings->saveDraft($request->validated(), (int) $actor->getKey());
        return redirect()->route('admin.site-settings.index')->with('success', 'Site settings draft saved. Nothing has been published.');
    }
}
