import { useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { dismissToast, getToastServerSnapshot, getToastSnapshot, subscribeToasts } from './admin-feedback';

/** Global non-blocking notifications; individual form validation stays inline. */
export function ToastViewport() {
    const items = useSyncExternalStore(subscribeToasts, getToastSnapshot, getToastServerSnapshot);
    if (!items.length || typeof document === 'undefined') return null;
    return createPortal(
        <div className="pointer-events-none fixed inset-x-4 top-4 z-[110] mx-auto flex max-w-sm flex-col gap-2 sm:inset-x-auto sm:right-5 sm:top-5 sm:w-full"
            aria-label="Notifications">
            {items.map((item) => <div key={item.id} role={item.kind === 'error' ? 'alert' : 'status'}
                aria-live={item.kind === 'error' ? 'assertive' : 'polite'}
                className={`pointer-events-auto flex items-start gap-3 rounded-xl border bg-white px-4 py-3 shadow-lg ${item.kind === 'error' ? 'border-red-200' : item.kind === 'success' ? 'border-emerald-200' : 'border-sky-200'}`}>
                <span aria-hidden="true" className={`mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${item.kind === 'error' ? 'bg-red-100 text-red-700' : item.kind === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800'}`}>
                    {item.kind === 'error' ? '!' : item.kind === 'success' ? '✓' : 'i'}
                </span>
                <p className="min-w-0 flex-1 break-words pt-0.5 text-sm text-slate-800">{item.message}</p>
                <button type="button" onClick={() => dismissToast(item.id)} aria-label="Dismiss notification"
                    className="shrink-0 rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-teal-700">×</button>
            </div>)}
        </div>, document.body,
    );
}
