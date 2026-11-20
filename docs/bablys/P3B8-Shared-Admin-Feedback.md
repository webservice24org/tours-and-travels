# P3B.8 — Shared Confirmation Dialog & Toast UX

## Scope

- One global feedback host mounted in `BablysAdminLayout` (existing single Laravel sidebar remains unchanged).
- Reusable, accessible `<ConfirmDialog />` with focus handling, Escape/cancel, and action-specific labels.
- Reusable `<ToastViewport />` for success/error/info, stacking, dismissal, and automatic expiry.
- Category and destination DELETE actions use the same confirmation UI; role DELETE and high-impact staff/role changes also confirm.
- Create/edit/delete/role/status/invitation operations show success and error toasts, while field-level validation stays inline.
- The API authorizations, audit events, protection rules, and soft-delete semantics remain unchanged.

## Usage for future Bablys admin modules

```tsx
import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';

async function removeItem(id: number, label: string) {
    const approved = await confirmAction({
        title: 'Delete record?',
        description: `Delete ${label}? This cannot be undone.`,
        confirmLabel: 'Delete',
        tone: 'danger',
    });
    if (!approved) return;
    try {
        await deleteOnServer(id); // your existing authorized API
        toast.success(`${label} deleted.`);
    } catch {
        toast.error('Unable to delete this record.');
    }
}
```

Keep server-side authorization and audit logging; a dialog is not a security boundary.
Never render untrusted HTML inside messages; the shared dialog and toast use normal React text interpolation.

## Manual QA

1. Verify exactly one Laravel sidebar (no nested CMS sidebar).
2. Create, update and soft-delete a category/destination: dialog appears, Cancel does not issue DELETE, Confirm does, success toast replaces persistent success banner.
3. Verify system roles are non-deletable, assigned custom roles cannot be deleted, and suspended staff cannot access CMS.
4. Test role removal, revoke-all-permissions, suspend/restore and setup-link confirmations.
5. Check keyboard Tab cycle, Escape cancellation, restored focus, mobile viewport, screen reader status messages.
6. Run tests, PHPStan, `npm run types:check` and `npm run build`.

No migrations, API changes, routing changes, packages, or new sidebar are required.
