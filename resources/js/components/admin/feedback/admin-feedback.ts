/**
 * Bablys CMS shared feedback channels. No HTML is accepted in messages.
 * Use from admin event handlers: await confirmAction(...), toast.success(...).
 */
export type ConfirmTone = 'danger' | 'warning' | 'normal';

export interface ConfirmOptions {
    title: string;
    description: string;
    confirmLabel?: string;
    cancelLabel?: string;
    tone?: ConfirmTone;
}

export interface ActiveConfirmation extends ConfirmOptions {
    id: number;
}

type ConfirmationJob = ActiveConfirmation & { resolve: (accepted: boolean) => void };
const confirmListeners = new Set<() => void>();
let activeJob: ConfirmationJob | null = null;
let visibleConfirmation: ActiveConfirmation | null = null;
const pendingJobs: ConfirmationJob[] = [];
let nextDialogId = 1;

function emitConfirmation(): void {
    for (const listener of confirmListeners) listener();
}

function nextConfirmation(): void {
    if (activeJob || pendingJobs.length === 0) return;
    activeJob = pendingJobs.shift() ?? null;
    if (!activeJob) return;
    const { id, title, description, confirmLabel, cancelLabel, tone } = activeJob;
    visibleConfirmation = { id, title, description, confirmLabel, cancelLabel, tone };
    emitConfirmation();
}

export function confirmAction(options: ConfirmOptions): Promise<boolean> {
    return new Promise<boolean>((resolve) => {
        pendingJobs.push({ ...options, id: nextDialogId++, resolve });
        nextConfirmation();
    });
}

export function answerConfirmation(id: number, accepted: boolean): void {
    if (!activeJob || activeJob.id !== id) return;
    const completed = activeJob;
    activeJob = null;
    visibleConfirmation = null;
    emitConfirmation();
    completed.resolve(accepted);
    nextConfirmation();
}

/** Only call on admin layout unmount: pending operations must not outlive their page. */
export function cancelOutstandingConfirmations(): void {
    const all = activeJob ? [activeJob, ...pendingJobs] : [...pendingJobs];
    activeJob = null;
    pendingJobs.length = 0;
    visibleConfirmation = null;
    emitConfirmation();
    for (const job of all) job.resolve(false);
}

export function subscribeConfirmation(listener: () => void): () => void {
    confirmListeners.add(listener);
    return () => { confirmListeners.delete(listener); };
}
export function getConfirmationSnapshot(): ActiveConfirmation | null { return visibleConfirmation; }
export function getConfirmationServerSnapshot(): ActiveConfirmation | null { return null; }

export type ToastKind = 'success' | 'error' | 'info';
export interface ToastEntry { id: number; kind: ToastKind; message: string; }
const toastListeners = new Set<() => void>();
const EMPTY_TOASTS: readonly ToastEntry[] = [];
let currentToasts: readonly ToastEntry[] = EMPTY_TOASTS;
let nextToastId = 1;
const dismissTimers = new Map<number, ReturnType<typeof setTimeout>>();

function emitToasts(): void {
    for (const listener of toastListeners) listener();
}

export function dismissToast(id: number): void {
    const handle = dismissTimers.get(id);
    if (handle) clearTimeout(handle);
    dismissTimers.delete(id);
    if (!currentToasts.some((item) => item.id === id)) return;
    currentToasts = currentToasts.filter((item) => item.id !== id);
    emitToasts();
}

function showToast(kind: ToastKind, message: string): void {
    const id = nextToastId++;
    currentToasts = [...currentToasts.slice(-3), { id, kind, message }];
    // Clear timers belonging to evicted toasts.
    for (const [key, handle] of dismissTimers) {
        if (!currentToasts.some((item) => item.id === key)) {
            clearTimeout(handle);
            dismissTimers.delete(key);
        }
    }
    emitToasts();
    dismissTimers.set(id, setTimeout(() => dismissToast(id), kind === 'error' ? 8000 : 5000));
}

export const toast = {
    success: (message: string): void => showToast('success', message),
    error: (message: string): void => showToast('error', message),
    info: (message: string): void => showToast('info', message),
};
export function subscribeToasts(listener: () => void): () => void {
    toastListeners.add(listener);
    return () => { toastListeners.delete(listener); };
}
export function getToastSnapshot(): readonly ToastEntry[] { return currentToasts; }
export function getToastServerSnapshot(): readonly ToastEntry[] { return EMPTY_TOASTS; }
