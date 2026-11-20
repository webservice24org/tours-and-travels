import { useEffect, useId, useRef } from 'react';
import { createPortal } from 'react-dom';
import type { ActiveConfirmation } from './admin-feedback';

interface ConfirmDialogProps {
    confirmation: ActiveConfirmation | null;
    onAnswer: (id: number, accepted: boolean) => void;
}

/** Accessible, reusable confirmation dialog. Cancelling never runs the operation. */
export function ConfirmDialog({ confirmation, onAnswer }: ConfirmDialogProps) {
    const titleId = useId();
    const descriptionId = useId();
    const panelRef = useRef<HTMLDivElement>(null);
    const cancelRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!confirmation) return;
        const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const oldOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        cancelRef.current?.focus();

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                onAnswer(confirmation.id, false);
                return;
            }
            if (event.key !== 'Tab' || !panelRef.current) return;
            const buttons = Array.from(panelRef.current.querySelectorAll<HTMLButtonElement>('button:not([disabled])'));
            if (buttons.length === 0) return;
            const first = buttons[0];
            const last = buttons[buttons.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        // Capture Escape before any existing editor/drawer keydown handlers.
        document.addEventListener('keydown', onKeyDown, true);
        return () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.body.style.overflow = oldOverflow;
            previousFocus?.focus();
        };
    }, [confirmation, onAnswer]);

    if (!confirmation || typeof document === 'undefined') return null;
    const destructive = confirmation.tone === 'danger';
    return createPortal(
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-4" onMouseDown={(event) => {
            if (event.target === event.currentTarget) onAnswer(confirmation.id, false);
        }}>
            <div ref={panelRef} role="alertdialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={descriptionId}
                className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-2xl sm:p-6">
                <div className={`mb-4 flex size-11 items-center justify-center rounded-full text-xl ${destructive ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800'}`} aria-hidden="true">
                    {destructive ? '!' : '?'}
                </div>
                <h2 id={titleId} className="text-lg font-bold tracking-tight">{confirmation.title}</h2>
                <p id={descriptionId} className="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{confirmation.description}</p>
                <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button ref={cancelRef} type="button" onClick={() => onAnswer(confirmation.id, false)}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                        {confirmation.cancelLabel ?? 'Cancel'}
                    </button>
                    <button type="button" onClick={() => onAnswer(confirmation.id, true)}
                        className={`rounded-lg px-4 py-2.5 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 ${destructive ? 'bg-red-700 hover:bg-red-800 focus-visible:outline-red-700' : 'bg-teal-700 hover:bg-teal-800 focus-visible:outline-teal-700'}`}>
                        {confirmation.confirmLabel ?? 'Confirm'}
                    </button>
                </div>
            </div>
        </div>, document.body,
    );
}
