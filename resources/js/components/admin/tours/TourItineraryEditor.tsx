import { useCallback, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';

import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';
import { tourErrorMessage, tourRequest, TourApiError } from '@/components/admin/tours/client';

const field = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200 disabled:bg-slate-100';
const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';
const meals = ['breakfast', 'lunch', 'dinner'] as const;

type TourDay = {
    day_number: number;
    title: string;
    location_label: string | null;
    description: string | null;
    overnight_location: string | null;
    meals: string[];
    highlights: string[];
};
type EditorDay = TourDay & { localKey: number };
type ItineraryResponse = { data: TourDay[]; meta: { duration_days: number; status: string } };

type Props = {
    tourId: number | null;
    persistedDurationDays: number | null;
    durationChangePending: boolean;
    status: string;
    canEdit: boolean;
    canPublish: boolean;
    tourBusy: boolean;
};

function emptyDay(key: number): EditorDay {
    return {
        localKey: key, day_number: 0, title: '', description: null,
        location_label: null, overnight_location: null, meals: [], highlights: [],
    };
}
function asDays(days: EditorDay[]): TourDay[] {
    return days.map(({ localKey: _unused, ...item }, index) => ({ ...item, day_number: index + 1 }));
}
function errorText(error: unknown): string {
    if (error instanceof TourApiError && Object.keys(error.fields).length > 0) {
        return Object.entries(error.fields).slice(0, 3).map(([name, messages]) => `${name}: ${messages[0] ?? 'Invalid input'}`).join(' · ');
    }
    return tourErrorMessage(error);
}

/** P3E.2 editor uses its own explicit save; main Tour form is never submitted by its buttons. */
export default function TourItineraryEditor({
    tourId, persistedDurationDays, durationChangePending, status, canEdit, canPublish, tourBusy,
}: Props) {
    const [days, setDays] = useState<EditorDay[]>([]);
    const [opened, setOpened] = useState<number[]>([]);
    const [loading, setLoading] = useState(tourId !== null);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saveError, setSaveError] = useState<string | null>(null);
    const nextKey = useRef(0);
    const url = tourId === null ? '' : `/admin/internal/tours/${tourId}/itinerary`;
    const canWrite = canEdit && (!['scheduled', 'published'].includes(status) || canPublish);
    const unavailable = tourId === null || !canWrite || loading || busy || tourBusy || loadError !== null;
    const limit = Math.max(0, Math.min(365, persistedDurationDays ?? 0));
    const makeKey = useCallback(() => { nextKey.current += 1; return nextKey.current; }, []);

    const load = useCallback(async (signal?: AbortSignal) => {
        if (!tourId) return;
        setLoading(true);
        setLoadError(null);
        try {
            const result = await tourRequest<ItineraryResponse>(`/admin/internal/tours/${tourId}/itinerary`, { signal });
            if (signal?.aborted) return;
            setDays(result.data.map((day) => ({ ...day, localKey: makeKey() })));
            setOpened([]);
            setDirty(false);
            setSaveError(null);
        } catch (error) {
            if (!signal?.aborted) setLoadError(errorText(error));
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [tourId, makeKey]);

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);
        return () => controller.abort();
    }, [load]);

    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener('beforeunload', beforeUnload);
        return () => window.removeEventListener('beforeunload', beforeUnload);
    }, [dirty]);

    function edit(key: number, changes: Partial<TourDay>) {
        setDays((previous) => previous.map((day) => day.localKey === key ? { ...day, ...changes } : day));
        setDirty(true);
        setSaveError(null);
    }
    function addDay() {
        if (days.length >= limit) return;
        const item = emptyDay(makeKey());
        setDays((previous) => [...previous, item]);
        setOpened((previous) => [...previous, item.localKey]);
        setDirty(true);
    }
    function move(index: number, offset: -1 | 1) {
        const target = index + offset;
        if (target < 0 || target >= days.length) return;
        const result = [...days];
        [result[index], result[target]] = [result[target], result[index]];
        setDays(result);
        setDirty(true);
    }
    function duplicate(index: number) {
        if (days.length >= limit) return;
        const copy: EditorDay = { ...days[index], meals: [...days[index].meals], highlights: [...days[index].highlights], localKey: makeKey() };
        setDays((previous) => [...previous.slice(0, index + 1), copy, ...previous.slice(index + 1)]);
        setOpened((previous) => [...previous, copy.localKey]);
        setDirty(true);
    }
    async function remove(index: number) {
        const accepted = await confirmAction({
            title: `Remove Day ${index + 1}?`,
            description: 'This day will be removed from the editor. The removal is not final until you click Save Itinerary.',
            confirmLabel: 'Remove day', tone: 'danger',
        });
        if (!accepted) return;
        const key = days[index].localKey;
        setDays((previous) => previous.filter((day) => day.localKey !== key));
        setOpened((previous) => previous.filter((value) => value !== key));
        setDirty(true);
    }
    async function save() {
        if (unavailable || durationChangePending || !dirty || !tourId) return;
        setBusy(true);
        setSaveError(null);
        try {
            const result = await tourRequest<ItineraryResponse>(url, {
                method: 'PUT', body: { days: asDays(days) },
            });
            setDays(result.data.map((day) => ({ ...day, localKey: makeKey() })));
            setOpened([]);
            setDirty(false);
            toast.success('Itinerary saved successfully.');
        } catch (error) {
            setSaveError(errorText(error));
            toast.error('Could not save itinerary. Review the day fields.');
        } finally {
            setBusy(false);
        }
    }
    async function discard() {
        if (dirty) {
            const accepted = await confirmAction({
                title: 'Discard itinerary changes?',
                description: 'Unsaved changes to itinerary days will be lost.', confirmLabel: 'Discard', tone: 'danger',
            });
            if (!accepted) return;
        }
        await load();
    }
    const keyHandler = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement) event.preventDefault();
    };

    return <section aria-labelledby="tour-itinerary-heading" className="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm" onKeyDown={keyHandler}>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div>
                <h2 id="tour-itinerary-heading" className="text-base font-bold text-slate-900">Daily Itinerary</h2>
                <p className="mt-1 text-xs text-slate-500">Day-by-day route, activities, meals and overnight stays. Saved separately from Tour Information.</p>
            </div>
            <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">
                {days.length} of {limit} days {dirty ? '· Unsaved' : ''}
            </span>
        </div>
        <div className="space-y-4 p-4">
            {tourId === null ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Create and save your tour first, then add its itinerary here on the Edit Tour page.</p> : null}
            {tourId !== null && !canWrite ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">You do not have permission to change this tour's itinerary.</p> : null}
            {durationChangePending ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Save your changed tour duration before editing the itinerary. Existing saved days cannot exceed the new duration.</p> : null}
            {loadError ? <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{loadError} <button type="button" className="ml-2 underline" onClick={() => void load()}>Retry</button></div> : null}
            {saveError ? <p role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{saveError}</p> : null}
            {loading ? <p role="status" className="text-sm text-slate-500">Loading itinerary…</p> : null}
            {!loading && tourId !== null && days.length === 0 && !loadError ? <p className="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">No itinerary days yet. Add Day 1 to start planning this tour.</p> : null}
            {days.map((day, index) => {
                const expanded = opened.includes(day.localKey);
                const locked = unavailable || durationChangePending;
                return <article key={day.localKey} className="min-w-0 overflow-hidden rounded-lg border border-slate-200">
                    <div className="flex flex-wrap items-center gap-2 bg-slate-50 p-3">
                        <button type="button" aria-expanded={expanded} className="min-w-0 flex-1 text-left text-sm font-bold text-slate-800" onClick={() => setOpened((previous) => expanded ? previous.filter((id) => id !== day.localKey) : [...previous, day.localKey])}>
                            <span className="text-teal-800">Day {index + 1}</span> · {day.title.trim() || 'Untitled day'}
                        </button>
                        <button type="button" className={button} aria-label={`Move Day ${index + 1} up`} disabled={locked || index === 0} onClick={() => move(index, -1)}>↑</button>
                        <button type="button" className={button} aria-label={`Move Day ${index + 1} down`} disabled={locked || index >= days.length - 1} onClick={() => move(index, 1)}>↓</button>
                        <button type="button" className={button} disabled={locked || days.length >= limit} onClick={() => duplicate(index)}>Copy</button>
                        <button type="button" className={`${button} text-rose-700`} disabled={locked} onClick={() => void remove(index)}>Remove</button>
                    </div>
                    {expanded ? <div className="grid min-w-0 gap-4 p-4 md:grid-cols-2">
                        <label className="block text-sm font-semibold text-slate-700 md:col-span-2">Day title *
                            <input maxLength={180} className={field} value={day.title} disabled={locked} placeholder="Arrival and city tour" onChange={(event) => edit(day.localKey, { title: event.target.value })} />
                        </label>
                        <label className="block text-sm font-semibold text-slate-700">Location
                            <input maxLength={160} className={field} disabled={locked} value={day.location_label ?? ''} onChange={(event) => edit(day.localKey, { location_label: event.target.value || null })} />
                        </label>
                        <label className="block text-sm font-semibold text-slate-700">Overnight stay
                            <input maxLength={160} className={field} disabled={locked} value={day.overnight_location ?? ''} onChange={(event) => edit(day.localKey, { overnight_location: event.target.value || null })} />
                        </label>
                        <label className="block text-sm font-semibold text-slate-700 md:col-span-2">Day description (plain text)
                            <textarea rows={4} maxLength={10000} className={field} disabled={locked} value={day.description ?? ''} onChange={(event) => edit(day.localKey, { description: event.target.value || null })} />
                        </label>
                        <fieldset className="md:col-span-2">
                            <legend className="text-sm font-semibold text-slate-700">Included meals</legend>
                            <div className="mt-2 flex flex-wrap gap-4">{meals.map((meal) => <label key={meal} className="flex items-center gap-2 text-sm capitalize text-slate-700"><input type="checkbox" className="size-4 accent-teal-800" disabled={locked} checked={day.meals.includes(meal)} onChange={(event) => edit(day.localKey, { meals: event.target.checked ? [...day.meals, meal] : day.meals.filter((entry) => entry !== meal) })} />{meal}</label>)}</div>
                        </fieldset>
                        <div className="space-y-2 md:col-span-2">
                            <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-semibold text-slate-700">Highlights (max 10)</h3><button type="button" className={button} disabled={locked || day.highlights.length >= 10} onClick={() => edit(day.localKey, { highlights: [...day.highlights, ''] })}>+ Highlight</button></div>
                            {day.highlights.map((highlight, position) => <div key={position} className="flex min-w-0 items-center gap-2"><input aria-label={`Day ${index + 1} highlight ${position + 1}`} className={field} maxLength={200} disabled={locked} value={highlight} onChange={(event) => edit(day.localKey, { highlights: day.highlights.map((old, n) => n === position ? event.target.value : old) })} /><button type="button" aria-label={`Remove highlight ${position + 1}`} className={button} disabled={locked} onClick={() => edit(day.localKey, { highlights: day.highlights.filter((_, n) => n !== position) })}>×</button></div>)}
                        </div>
                    </div> : null}
                </article>;
            })}
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className={button} disabled={unavailable || durationChangePending || days.length >= limit} onClick={addDay}>+ Add itinerary day</button>
                <button type="button" className="rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-900 disabled:opacity-40" disabled={unavailable || durationChangePending || !dirty} onClick={() => void save()}>{busy ? 'Saving itinerary…' : 'Save Itinerary'}</button>
                <button type="button" className={button} disabled={unavailable || !dirty} onClick={() => void discard()}>Discard Changes</button>
            </div>
            <p className="text-xs text-slate-500">Days are automatically numbered in their visible order when saved. For published or scheduled tours, publication permission is required. Day descriptions remain plain text in P3E.2.</p>
        </div>
    </section>;
}
