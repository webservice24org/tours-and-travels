<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Audit;

use App\Domain\Cms\Audit\CmsAuditReader;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CmsAuditPageController extends Controller
{
    public function __invoke(Request $request, CmsAuditReader $reader): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $reader->assertViewer($actor);

        return Inertia::render('admin/audit-logs/index');
    }
}
