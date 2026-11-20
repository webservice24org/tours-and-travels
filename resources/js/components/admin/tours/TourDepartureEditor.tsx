import { useCallback, useEffect, useRef, useState } from 'react';

import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';
import { tourErrorMessage, tourRequest, TourApiError } from '@/components/admin/tours/client';

/** P3E.4B: an INTERNAL inventory editor, not a public booking/reservation UI. */
const input = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200 disabled:bg-slate-100';
const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';
const primary = 'rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-900 disabled:cursor-not-allowed disabled:opacity-40';
const statuses = ['draft', 'open', 'closed', 'cancelled'] as const;
const suggestedZones = ['Asia/Dhaka', 'Asia/Kolkata', 'Asia/Kathmandu', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles', 'UTC'];

type DepartureStatus = typeof statuses[number];
type StoredDeparture = {
    code: string; name: string; timezone_name: string;
    starts_at: string; ends_at: string | null; booking_closes_at: string | null;
    capacity_total: number; seats_committed: number; remaining_capacity: number;
    status: DepartureStatus; capacity_state: string; public_bookable: false;
    meeting_point: string | null; internal_notes: string | null;
};
type DraftDeparture = {
    key: number; code: string; name: string; timezone_name: string;
    startsLocal: string; endsLocal: string; cutoffLocal: string;
    capacity: string; status: DepartureStatus;
    meeting_point: string; internal_notes: string;
    seatsCommitted: number; capacityState: string; remaining: number;
};
type SaveDeparture = {
    code: string; name: string; timezone_name: string;
    starts_at: string; ends_at: string | null; booking_closes_at: string | null;
    capacity_total: number; status: DepartureStatus;
    meeting_point: string | null; internal_notes: string | null;
};
type DepartureResponse = {
    data: StoredDeparture[];
    meta: { status: string; inventory_only: true; public_bookable: false; as_of: string };
};
type Props = { tourId: number | null; status: string; canEdit: boolean; canPublish: boolean; tourBusy: boolean };

/** Format an instant as wall-clock date/time in the selected IANA zone (never browser timezone). */
function zonedParts(date: Date, zone: string): Record<string, string> {
    const formatter = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
    return Object.fromEntries(formatter.formatToParts(date).map(({ type, value }) => [type, value]));
}
function wallAt(date: Date, zone: string): string {
    const parts = zonedParts(date, zone);
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
}
function utcToWall(value: string | null, zone: string): string {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) throw new Error('Invalid stored departure date.');
    return wallAt(date, zone);
}
function zoneOffsetMinutes(instant: Date, zone: string): number {
    const wall = wallAt(instant, zone);
    const asUtc = Date.parse(`${wall}Z`);
    return Math.round((asUtc - instant.getTime()) / 60000);
}
/**
 * Convert an IANA wall time to an ISO timestamp with an EXPLICIT agreeing offset.
 * Reject nonexistent DST times and ambiguous fall-back times instead of guessing.
 */
function wallToOffsetIso(raw: string, zone: string, label: string): string {
    const wall = raw.length === 16 ? `${raw}:00` : raw;
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(wall)) {
        throw new Error(`${label}: enter a complete date and time.`);
    }
    let desired: number;
    try {
        // Throws RangeError if zone isn't a recognized IANA name.
        new Intl.DateTimeFormat('en', { timeZone: zone });
        desired = Date.parse(`${wall}Z`);
    } catch {
        throw new Error(`${label}: invalid timezone. Choose an IANA timezone.`);
    }
    if (!Number.isFinite(desired) || new Date(desired).toISOString().slice(0, 19) !== wall) {
        throw new Error(`${label}: invalid calendar date.`);
    }
    const candidates = new Set<number>();
    for (let hours = -36; hours <= 36; hours += 6) {
        candidates.add(zoneOffsetMinutes(new Date(desired + hours * 3_600_000), zone));
    }
    const matching: number[] = [];
    for (const offset of candidates) {
        const instant = new Date(desired - offset * 60_000);
        if (wallAt(instant, zone) === wall && zoneOffsetMinutes(instant, zone) === offset) {
            matching.push(offset);
        }
    }
    if (matching.length !== 1) {
        throw new Error(`${label}: ${matching.length === 0 ? 'this local time does not exist' : 'this local time is ambiguous'} in ${zone} due to a timezone transition. Choose another time.`);
    }
    const offset = matching[0];
    const sign = offset < 0 ? '-' : '+';
    const absolute = Math.abs(offset);
    return `${wall}${sign}${String(Math.floor(absolute / 60)).padStart(2, '0')}:${String(absolute % 60).padStart(2, '0')}`;
}
function readDraft(item: StoredDeparture, key: number): DraftDeparture {
    return {
        key, code: item.code, name: item.name, timezone_name: item.timezone_name,
        startsLocal: utcToWall(item.starts_at, item.timezone_name),
        endsLocal: utcToWall(item.ends_at, item.timezone_name),
        cutoffLocal: utcToWall(item.booking_closes_at, item.timezone_name),
        capacity: String(item.capacity_total), status: item.status,
        meeting_point: item.meeting_point ?? '', internal_notes: item.internal_notes ?? '',
        seatsCommitted: item.seats_committed, capacityState: item.capacity_state, remaining: item.remaining_capacity,
    };
}
function serialize(items: DraftDeparture[]): SaveDeparture[] {
    if (items.length > 200) throw new Error('Maximum 200 departure dates per tour.');
    const unique = new Set<string>();
    return items.map((item, index) => {
        const label = `Departure ${index + 1}`;
        const code = item.code.trim().toLowerCase();
        if (!/^[a-z][a-z0-9_-]{1,59}$/.test(code) || unique.has(code)) {
            throw new Error(`${label}: enter a unique 2–60 character departure code starting with a letter.`);
        }
        unique.add(code);
        if (!/^\d+$/.test(item.capacity.trim())) throw new Error(`${label}: capacity must be a whole number.`);
        const capacity = Number(item.capacity);
        if (!Number.isSafeInteger(capacity) || capacity < 1 || capacity > 10000 || capacity < item.seatsCommitted) {
            throw new Error(`${label}: capacity must be 1–10,000 and never below committed seats.`);
        }
        if (!item.name.trim()) throw new Error(`${label}: name is required.`);
        const zone = item.timezone_name.trim();
        const starts = wallToOffsetIso(item.startsLocal, zone, `${label} start`);
        const ends = item.endsLocal ? wallToOffsetIso(item.endsLocal, zone, `${label} end`) : null;
        const cutoff = item.cutoffLocal ? wallToOffsetIso(item.cutoffLocal, zone, `${label} booking cutoff`) : null;
        const startTime = Date.parse(starts);
        if (ends && Date.parse(ends) <= startTime) throw new Error(`${label}: end must be after start.`);
        if (cutoff && Date.parse(cutoff) > startTime) throw new Error(`${label}: booking cutoff must not be after start.`);
        if (item.status === 'open' && startTime <= Date.now()) throw new Error(`${label}: only future departures can be opened.`);
        if (item.seatsCommitted > 0 && ['draft', 'cancelled'].includes(item.status)) {
            throw new Error(`${label}: committed seats prevent returning to draft or cancelling.`);
        }
        return {
            code, name: item.name.trim(), timezone_name: zone, starts_at: starts,
            ends_at: ends, booking_closes_at: cutoff, capacity_total: capacity, status: item.status,
            meeting_point: item.meeting_point.trim() || null, internal_notes: item.internal_notes.trim() || null,
        };
    });
}
function codeFrom(name: string, existing: string[]): string {
    const base = name.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 45);
    const safe = base.length > 1 && /^[a-z]/.test(base) ? base : 'departure';
    let result = safe;
    let counter = 2;
    while (existing.includes(result)) result = `${safe}-${counter++}`;
    return result;
}
function errorText(error: unknown): string {
    if (error instanceof TourApiError && Object.keys(error.fields).length) {
        return Object.entries(error.fields).slice(0, 3).map(([name, messages]) => `${name}: ${messages[0] ?? 'Invalid'}`).join(' · ');
    }
    return tourErrorMessage(error);
}

/** Save departures independently of Tour Information, Pricing and Daily Itinerary. */
export default function TourDepartureEditor({ tourId, status, canEdit, canPublish, tourBusy }: Props) {
    const [rows, setRows] = useState<DraftDeparture[]>([]);
    const [expanded, setExpanded] = useState<number[]>([]);
    const [loading, setLoading] = useState(tourId !== null);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saveError, setSaveError] = useState<string | null>(null);
    const nextKey = useRef(0);
    const newKey = () => ++nextKey.current;
    const url = tourId === null ? '' : `/admin/internal/tours/${tourId}/departures`;
    const canWrite = canEdit && (!['published', 'scheduled'].includes(status) || canPublish);
    const disabled = tourId === null || !canWrite || busy || loading || tourBusy || loadError !== null;

    const load = useCallback(async (signal?: AbortSignal) => {
        if (tourId === null) return;
        setLoading(true); setLoadError(null);
        try {
            const result = await tourRequest<DepartureResponse>(`/admin/internal/tours/${tourId}/departures`, { signal });
            if (signal?.aborted) return;
            setRows(result.data.map((row) => readDraft(row, ++nextKey.current)));
            setExpanded([]); setDirty(false); setSaveError(null);
        } catch (error) {
            if (!signal?.aborted) setLoadError(errorText(error));
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [tourId]);

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);
        return () => controller.abort();
    }, [load]);

    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => { if (dirty) event.preventDefault(); };
        window.addEventListener('beforeunload', beforeUnload);
        return () => window.removeEventListener('beforeunload', beforeUnload);
    }, [dirty]);

    function edit(key: number, value: Partial<DraftDeparture>) {
        setRows((previous) => previous.map((row) => row.key === key ? { ...row, ...value } : row));
        setDirty(true); setSaveError(null);
    }
    function add() {
        if (disabled || rows.length >= 200) return;
        const key = newKey();
        const zone = 'Asia/Dhaka';
        const inOneWeek = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
        const row: DraftDeparture = {
            key, code: codeFrom(`departure-${rows.length + 1}`, rows.map((item) => item.code)),
            name: `Departure ${rows.length + 1}`, timezone_name: zone,
            startsLocal: wallAt(inOneWeek, zone), endsLocal: '', cutoffLocal: '',
            capacity: '20', status: 'draft', meeting_point: '', internal_notes: '',
            seatsCommitted: 0, remaining: 20, capacityState: 'draft',
        };
        setRows((previous) => [...previous, row]);
        setExpanded((previous) => [...previous, key]);
        setDirty(true);
    }
    function duplicate(index: number) {
        if (disabled || rows.length >= 200) return;
        const key = newKey();
        const original = rows[index];
        const copy: DraftDeparture = {
            ...original, key, code: codeFrom(`${original.code}-copy`, rows.map((item) => item.code)),
            name: `${original.name} Copy`.slice(0, 120), status: 'draft', seatsCommitted: 0, remaining: Number(original.capacity), capacityState: 'draft',
        };
        setRows((previous) => [...previous.slice(0, index + 1), copy, ...previous.slice(index + 1)]);
        setExpanded((previous) => [...previous, key]);
        setDirty(true);
    }
    async function remove(index: number) {
        if (disabled || rows[index].seatsCommitted > 0) return;
        const accepted = await confirmAction({
            title: `Remove “${rows[index].name}”?`,
            description: 'The departure will be removed only after Save Departures. Departures with committed seats cannot be removed.',
            confirmLabel: 'Remove departure', tone: 'danger',
        });
        if (!accepted) return;
        const key = rows[index].key;
        setRows((previous) => previous.filter((row) => row.key !== key));
        setExpanded((previous) => previous.filter((item) => item !== key));
        setDirty(true);
    }
    async function save() {
        if (disabled || tourId === null || !dirty) return;
        let payload: SaveDeparture[];
        try { payload = serialize(rows); }
        catch (error) { toast.error(error instanceof Error ? error.message : 'Invalid departure details.'); return; }
        setBusy(true); setSaveError(null);
        try {
            const result = await tourRequest<DepartureResponse>(url, { method: 'PUT', body: { departures: payload } });
            setRows(result.data.map((row) => readDraft(row, newKey())));
            setExpanded([]); setDirty(false);
            toast.success('Tour departures saved successfully.');
        } catch (error) {
            setSaveError(errorText(error));
            toast.error('Could not save departures. Review the highlighted details.');
        } finally { setBusy(false); }
    }
    async function discard() {
        if (dirty) {
            const accepted = await confirmAction({
                title: 'Discard unsaved departures?', description: 'All changes to departure schedules since the last save will be lost.',
                confirmLabel: 'Discard changes', tone: 'danger',
            });
            if (!accepted) return;
        }
        await load();
    }

    return <section aria-labelledby="tour-departure-heading" className="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div>
                <h2 id="tour-departure-heading" className="text-base font-bold text-slate-900">Departure Dates & Capacity</h2>
                <p className="mt-1 text-xs text-slate-500">Staff-only inventory. Saved independently from Tour Details, Itinerary and Pricing.</p>
            </div>
            <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">{rows.length} of 200 departures {dirty ? '· Unsaved' : ''}</span>
        </div>
        <div className="space-y-4 p-4">
            <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><strong>Not bookable yet.</strong> An “Open” departure only indicates staff-managed capacity. No seats can be reserved through this screen until the booking-readiness phase.</p>
            {tourId === null ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Save the tour first, then add departures on its Edit page.</p> : null}
            {tourId !== null && !canWrite ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">You cannot edit departures for this tour. Published or scheduled tours require publishing permission.</p> : null}
            {loadError ? <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{loadError} <button type="button" className="ml-2 underline" onClick={() => void load()}>Retry</button></p> : null}
            {saveError ? <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{saveError}</p> : null}
            {loading ? <p role="status" className="text-sm text-slate-500">Loading departures…</p> : null}
            {!loading && rows.length === 0 && tourId !== null && !loadError ? <p className="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">No departures defined. Add a date to start planning capacity.</p> : null}
            {rows.map((row, index) => {
                const isOpen = expanded.includes(row.key);
                const locked = row.seatsCommitted > 0;
                return <article key={row.key} className="min-w-0 overflow-hidden rounded-lg border border-slate-200">
                    <div className="flex flex-wrap items-center gap-2 bg-slate-50 p-3">
                        <button type="button" aria-expanded={isOpen} className="min-w-0 flex-1 text-left text-sm font-semibold text-slate-800"
                            onClick={() => setExpanded((previous) => isOpen ? previous.filter((item) => item !== row.key) : [...previous, row.key])}>
                            <span className="text-teal-800">#{index + 1}</span> · {row.name || 'Unnamed departure'}
                            <span className="ml-2 text-xs font-normal text-slate-500">{row.status} · {row.capacityState}</span>
                        </button>
                        <button type="button" className={button} disabled={disabled || rows.length >= 200} onClick={() => duplicate(index)}>Duplicate</button>
                        <button type="button" className={`${button} text-rose-700`} disabled={disabled || locked} onClick={() => void remove(index)}>Remove</button>
                    </div>
                    {isOpen ? <div className="space-y-4 p-4">
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm font-semibold text-slate-700">Departure name *
                                <input className={input} maxLength={120} disabled={disabled} value={row.name} onChange={(event) => edit(row.key, { name: event.target.value })}/>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Stable code *
                                <input className={input} maxLength={60} disabled={disabled || locked} value={row.code} onChange={(event) => edit(row.key, { code: event.target.value.toLowerCase() })}/>
                                <span className="mt-1 block text-xs font-normal text-slate-500">Keep this code unchanged to retain the saved departure and its committed seats.</span>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">IANA timezone *
                                <input className={input} list="bablys-departure-timezones" maxLength={64} disabled={disabled || locked} value={row.timezone_name} onChange={(event) => edit(row.key, { timezone_name: event.target.value })}/>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Departure status
                                <select className={input} disabled={disabled} value={row.status} onChange={(event) => edit(row.key, { status: event.target.value as DepartureStatus })}>
                                    {statuses.filter((value) => !locked || !['draft', 'cancelled'].includes(value)).map((value) => <option key={value} value={value}>{value[0].toUpperCase() + value.slice(1)}</option>)}
                                </select>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Starts at ({row.timezone_name}) *
                                <input className={input} type="datetime-local" step={1} disabled={disabled || locked} value={row.startsLocal} onChange={(event) => edit(row.key, { startsLocal: event.target.value })}/>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Ends at ({row.timezone_name})
                                <input className={input} type="datetime-local" step={1} disabled={disabled || locked} value={row.endsLocal} onChange={(event) => edit(row.key, { endsLocal: event.target.value })}/>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Booking closes at ({row.timezone_name})
                                <input className={input} type="datetime-local" step={1} disabled={disabled} value={row.cutoffLocal} onChange={(event) => edit(row.key, { cutoffLocal: event.target.value })}/>
                                <span className="mt-1 block text-xs font-normal text-slate-500">Informational cutoff only until bookings are implemented.</span>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Total capacity *
                                <input className={input} type="number" min={Math.max(1, row.seatsCommitted)} max={10000} step={1} disabled={disabled} value={row.capacity} onChange={(event) => edit(row.key, { capacity: event.target.value })}/>
                            </label>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm font-semibold text-slate-700">Meeting point
                                <input className={input} maxLength={180} disabled={disabled} value={row.meeting_point} onChange={(event) => edit(row.key, { meeting_point: event.target.value })}/>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Internal notes (staff only)
                                <textarea className={input} rows={3} maxLength={2000} disabled={disabled} value={row.internal_notes} onChange={(event) => edit(row.key, { internal_notes: event.target.value })}/>
                            </label>
                        </div>
                        <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            <strong>Inventory snapshot:</strong> {row.seatsCommitted} committed · {row.remaining} remaining · {row.capacityState}.
                            {locked ? <span className="ml-2 font-semibold text-amber-800">Dates, timezone, code and removal locked because seats are committed.</span> : null}
                            {dirty ? <span className="ml-2 text-slate-500">Counts refresh after saving.</span> : null}
                        </div>
                    </div> : null}
                </article>;
            })}
            <datalist id="bablys-departure-timezones">{suggestedZones.map((zone) => <option key={zone} value={zone}/>)}</datalist>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                <button type="button" className={button} disabled={disabled || rows.length >= 200} onClick={add}>+ Add departure</button>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className={button} disabled={busy || loading || tourBusy || tourId === null || !dirty} onClick={() => void discard()}>Discard changes</button>
                    <button type="button" className={primary} disabled={disabled || !dirty} onClick={() => void save()}>{busy ? 'Saving…' : 'Save Departures'}</button>
                </div>
            </div>
        </div>
    </section>;
}
