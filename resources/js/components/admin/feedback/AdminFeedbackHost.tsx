import { useEffect, useSyncExternalStore } from 'react';
import { ConfirmDialog } from './ConfirmDialog';
import { ToastViewport } from './ToastViewport';
import { answerConfirmation, cancelOutstandingConfirmations, getConfirmationServerSnapshot, getConfirmationSnapshot, subscribeConfirmation } from './admin-feedback';

/** Mount exactly once in BablysAdminLayout; pages only call confirmAction/toast. */
export function AdminFeedbackHost() {
    const confirmation = useSyncExternalStore(subscribeConfirmation, getConfirmationSnapshot, getConfirmationServerSnapshot);
    useEffect(() => () => cancelOutstandingConfirmations(), []);
    return <>
        <ConfirmDialog confirmation={confirmation} onAnswer={answerConfirmation} />
        <ToastViewport />
    </>;
}
