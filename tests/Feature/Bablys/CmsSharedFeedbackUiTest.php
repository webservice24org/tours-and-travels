<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\TestCase;

final class CmsSharedFeedbackUiTest extends TestCase
{
    public function test_shared_admin_layout_mounts_single_global_feedback_host(): void
    {
        $source = (string) file_get_contents(resource_path('js/layouts/bablys-admin-layout.tsx'));
        self::assertStringContainsString('<AdminFeedbackHost />', $source);
        self::assertSame(1, substr_count($source, '<AdminFeedbackHost />'));
        self::assertStringNotContainsString('<CmsSidebarMenu', $source);
    }

    public function test_common_dialog_supports_accessibility_and_cancellation(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/admin/feedback/ConfirmDialog.tsx'));
        self::assertStringContainsString('role="alertdialog"', $source);
        self::assertStringContainsString('aria-modal="true"', $source);
        self::assertStringContainsString("event.key === 'Escape'", $source);
        self::assertStringContainsString('onAnswer(confirmation.id, false)', $source);
    }

    public function test_toast_viewport_supports_success_error_and_dismissal(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/admin/feedback/ToastViewport.tsx'));
        self::assertStringContainsString('dismissToast(item.id)', $source);
        self::assertStringContainsString("item.kind === 'error' ? 'alert' : 'status'", $source);
        $store = (string) file_get_contents(resource_path('js/components/admin/feedback/admin-feedback.ts'));
        self::assertStringContainsString('success:', $store);
        self::assertStringContainsString('error:', $store);
        self::assertStringContainsString('confirmAction(', $store);
    }

    public function test_all_existing_mutation_pages_use_shared_feedback_instead_of_native_confirm(): void
    {
        foreach (['staff-users', 'roles', 'tour-categories', 'destinations'] as $page) {
            $source = (string) file_get_contents(resource_path("js/pages/admin/{$page}/index.tsx"));
            self::assertStringContainsString('confirmAction(', $source, $page);
            self::assertStringContainsString('toast.success(', $source, $page);
            self::assertStringContainsString('toast.error(', $source, $page);
            self::assertStringNotContainsString('window.confirm(', $source, $page);
            self::assertStringNotContainsString('setNotice(', $source, $page);
        }
    }

    public function test_no_changes_to_read_only_audit_and_sidebar_modules_are_required(): void
    {
        $source = (string) file_get_contents(resource_path('js/layouts/bablys-admin-layout.tsx'));
        self::assertStringContainsString('id="bablys-admin-content"', $source);
        self::assertStringNotContainsString('<aside', $source);
    }
}
