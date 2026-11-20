import { useCallback, useEffect, useRef, useState } from 'react';

import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';
import { tourErrorMessage, tourRequest, TourApiError } from '@/components/admin/tours/client';

/** P3E.3B: staff-only pricing; all server-side amounts are integer minor units. */
const field = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200 disabled:bg-slate-100';
const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';
const primary = 'rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-900 disabled:cursor-not-allowed disabled:opacity-40';
const digits: Record<Currency, number> = { BDT: 2, USD: 2, EUR: 2, GBP: 2, JPY: 0 };
const MAX_MINOR = 1_000_000_000;

type Currency = 'BDT' | 'USD' | 'EUR' | 'GBP' | 'JPY';
type Traveler = 'adult' | 'child' | 'group';
type PriceModel = 'per_person' | 'per_group';
type StoredRate = { traveler_type: Traveler; regular_minor: number; sale_minor: number | null; sale_starts_at: string | null; sale_ends_at: string | null };
type StoredVariant = {
    code: string; name: string; currency: Currency; pricing_model: PriceModel;
    min_travelers: number; max_travelers: number | null; is_active: boolean; sort_order: number;
    rates: StoredRate[];
};
type SaveVariant = Omit<StoredVariant, 'sort_order'>;
type DraftRate = { traveler_type: Traveler; regular: string; sale: string; starts: string; ends: string };
type DraftVariant = {
    key: number; code: string; name: string; currency: Currency; pricing_model: PriceModel;
    minimum: string; maximum: string; active: boolean; rates: DraftRate[];
};
type PricingResponse = { data: StoredVariant[]; meta: { currencies: Record<string, number>; status: string } };
type QuoteLine = { traveler_type: Traveler; quantity: number; regular_unit_minor: number; effective_unit_minor: number; line_regular_minor: number; line_total_minor: number; sale_active: boolean };
type Quote = {
    currency: Currency; fraction_digits: number; regular_total_minor: number;
    total_minor: number; discount_minor: number; adults: number; children: number; lines: QuoteLine[];
};
type QuoteResponse = { data: Quote; meta: { quoted_at: string; not_a_booking: boolean } };
type Props = { tourId: number | null; status: string; canEdit: boolean; canPublish: boolean; tourBusy: boolean };

function minorToMajor(value: number, fraction: number): string {
    // No floating point, even when formatting a price.
    const factor = 10n ** BigInt(fraction);
    const minor = BigInt(value);
    if (fraction === 0) return minor.toString();
    return `${minor / factor}.${(minor % factor).toString().padStart(fraction, '0')}`;
}
function majorToMinor(value: string, currency: Currency, label: string, allowZero: boolean): number {
    const fraction = digits[currency];
    const trimmed = value.trim();
    const pattern = fraction === 0 ? /^\d+$/ : /^\d+(?:\.\d{1,2})?$/;
    if (!pattern.test(trimmed)) throw new Error(`${label}: enter ${currency} amount with ${fraction} or fewer decimal places.`);
    const [whole, decimal = ''] = trimmed.split('.');
    const minor = BigInt(whole) * (10n ** BigInt(fraction)) + BigInt(decimal.padEnd(fraction, '0') || '0');
    if (minor > BigInt(MAX_MINOR) || (!allowZero && minor === 0n)) {
        throw new Error(`${label}: amount must be ${allowZero ? 'zero or greater' : 'greater than zero'} and within the allowed limit.`);
    }
    return Number(minor);
}
function wholeNumber(value: string, label: string, optional = false): number | null {
    if (optional && value.trim() === '') return null;
    if (!/^\d+$/.test(value) || Number(value) < 1 || Number(value) > 1000) {
        throw new Error(`${label} must be a whole number between 1 and 1,000.`);
    }
    return Number(value);
}
function toLocal(utc: string | null): string {
    if (!utc) return '';
    const date = new Date(utc);
    if (Number.isNaN(date.getTime())) return '';
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

function toUtc(local: string, label: string): string | null {
    if (!local.trim()) {
        return null;
    }

    const date = new Date(local);

    if (Number.isNaN(date.getTime())) {
        throw new Error(
            `${label} is not a valid date and time.`
        );
    }

    // Convert local time to UTC with seconds and timezone.
    // Backend requires YYYY-MM-DDTHH:mm:ssZ
    // without fractional milliseconds.
    return date.toISOString().slice(0, 19) + 'Z';
}

function initialRate(type: Traveler, currency: Currency = 'BDT'): DraftRate {
    return { traveler_type: type, regular: digits[currency] === 0 ? '0' : '0.00', sale: '', starts: '', ends: '' };
}
function asDraft(variant: StoredVariant, key: number): DraftVariant {
    return {
        key, code: variant.code, name: variant.name, currency: variant.currency,
        pricing_model: variant.pricing_model, minimum: String(variant.min_travelers),
        maximum: variant.max_travelers === null ? '' : String(variant.max_travelers),
        active: variant.is_active,
        rates: variant.rates.map((rate) => ({
            traveler_type: rate.traveler_type,
            regular: minorToMajor(rate.regular_minor, digits[variant.currency]),
            sale: rate.sale_minor === null ? '' : minorToMajor(rate.sale_minor, digits[variant.currency]),
            starts: toLocal(rate.sale_starts_at), ends: toLocal(rate.sale_ends_at),
        })),
    };
}
function serialize(variants: DraftVariant[]): SaveVariant[] {
    if (variants.length > 30) throw new Error('Maximum 30 pricing options per tour.');
    return variants.map((variant) => {
        const min_travelers = wholeNumber(variant.minimum, `${variant.name || 'Option'} minimum travelers`);
        const max_travelers = wholeNumber(variant.maximum, `${variant.name || 'Option'} maximum travelers`, true);
        const rates: StoredRate[] = variant.rates.map((rate) => {
            const label = `${variant.name || 'Option'} ${rate.traveler_type}`;
            const sale_minor = rate.sale.trim() === '' ? null : majorToMinor(rate.sale, variant.currency, `${label} sale`, rate.traveler_type === 'child');
            if (sale_minor === null && (rate.starts || rate.ends)) throw new Error(`${label}: clear sale dates or enter a sale price.`);
            return {
                traveler_type: rate.traveler_type,
                regular_minor: majorToMinor(rate.regular, variant.currency, `${label} regular`, rate.traveler_type === 'child'),
                sale_minor,
                sale_starts_at: sale_minor === null ? null : toUtc(rate.starts, `${label} sale start`),
                sale_ends_at: sale_minor === null ? null : toUtc(rate.ends, `${label} sale end`),
            };
        });
        return {
            code: variant.code.trim().toLowerCase(), name: variant.name.trim(), currency: variant.currency,
            pricing_model: variant.pricing_model, min_travelers: min_travelers ?? 1,
            max_travelers, is_active: variant.active, rates,
        };
    });
}
function detailedError(error: unknown): string {
    if (error instanceof TourApiError && Object.keys(error.fields).length > 0) {
        return Object.entries(error.fields).slice(0, 4).map(([key, messages]) => `${key}: ${messages[0] ?? 'Invalid value'}`).join(' · ');
    }
    return tourErrorMessage(error);
}
function codeSuggestion(name: string, taken: string[]): string {
    const base = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48) || 'option';
    const safe = /^[a-z]/.test(base) && base.length > 1 ? base : `price-${base}`;
    let code = safe;
    let number = 2;
    while (taken.includes(code)) { code = `${safe.slice(0, 48)}-${number}`; number++; }
    return code;
}

/** Independent save panel. Buttons are explicit type=button because the parent page contains Tour Details form. */
export default function TourPricingEditor({ tourId, status, canEdit, canPublish, tourBusy }: Props) {
    const [variants, setVariants] = useState<DraftVariant[]>([]);
    const nextKey = useRef(0);
    const [opened, setOpened] = useState<number[]>([]);
    const [loading, setLoading] = useState(tourId !== null);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [previewCode, setPreviewCode] = useState('');
    const [adults, setAdults] = useState('2');
    const [children, setChildren] = useState('0');
    const [preview, setPreview] = useState<QuoteResponse | null>(null);
    const [previewBusy, setPreviewBusy] = useState(false);
    const canWrite = canEdit && (!['published', 'scheduled'].includes(status) || canPublish);
    const disabled = tourId === null || !canWrite || loading || busy || tourBusy || loadError !== null;
    const url = tourId === null ? '' : `/admin/internal/tours/${tourId}/pricing`;
    const makeKey = () => { nextKey.current += 1; return nextKey.current; };

    const load = useCallback(async (signal?: AbortSignal) => {
        if (tourId === null) return;
        setLoading(true); setLoadError(null);
        try {
            const result = await tourRequest<PricingResponse>(`/admin/internal/tours/${tourId}/pricing`, { signal });
            if (signal?.aborted) return;
            const list = result.data.map((variant) => asDraft(variant, ++nextKey.current));
            setVariants(list);
            setOpened([]); setDirty(false); setSaveError(null); setPreview(null);
            setPreviewCode((current) => result.data.some((variant) => variant.code === current) ? current : result.data[0]?.code ?? '');
        } catch (error) {
            if (!signal?.aborted) setLoadError(detailedError(error));
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
        const preventLoss = (event: BeforeUnloadEvent) => { if (dirty) event.preventDefault(); };
        window.addEventListener('beforeunload', preventLoss);
        return () => window.removeEventListener('beforeunload', preventLoss);
    }, [dirty]);
    function change(key: number, patch: Partial<DraftVariant>) {
        setVariants((current) => current.map((variant) => variant.key === key ? { ...variant, ...patch } : variant));
        setDirty(true); setPreview(null); setSaveError(null);
    }
    function changeRate(key: number, type: Traveler, patch: Partial<DraftRate>) {
        setVariants((current) => current.map((variant) => variant.key === key ? {
            ...variant, rates: variant.rates.map((rate) => rate.traveler_type === type ? { ...rate, ...patch } : rate),
        } : variant));
        setDirty(true); setPreview(null); setSaveError(null);
    }
    function add() {
        if (variants.length >= 30) return;
        const key = makeKey();
        const name = `Option ${variants.length + 1}`;
        const code = codeSuggestion(name, variants.map((variant) => variant.code));
        setVariants((previous) => [...previous, {
            key, code, name, currency: 'BDT', pricing_model: 'per_person', minimum: '1',
            maximum: '', active: true, rates: [initialRate('adult')],
        }]);
        setOpened((current) => [...current, key]);
        setDirty(true);
    }
    function reorder(index: number, direction: -1 | 1) {
        const target = index + direction;
        if (target < 0 || target >= variants.length) return;
        const reordered = [...variants];
        [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
        setVariants(reordered); setDirty(true); setPreview(null);
    }
    function duplicate(index: number) {
        if (variants.length >= 30) return;
        const selected = variants[index];
        const key = makeKey();
        const clone: DraftVariant = {
            ...selected, key, name: `${selected.name} Copy`.slice(0, 120),
            code: codeSuggestion(`${selected.code}-copy`, variants.map((variant) => variant.code)),
            rates: selected.rates.map((rate) => ({ ...rate })),
        };
        setVariants((current) => [...current.slice(0, index + 1), clone, ...current.slice(index + 1)]);
        setOpened((current) => [...current, key]);
        setDirty(true); setPreview(null);
    }
    async function remove(index: number) {
        const accepted = await confirmAction({
            title: `Remove “${variants[index].name}”?`,
            description: 'This pricing option will be removed from the editor. Click Save Pricing to commit the removal.',
            confirmLabel: 'Remove option', tone: 'danger',
        });
        if (!accepted) return;
        const key = variants[index].key;
        setVariants((current) => current.filter((variant) => variant.key !== key));
        setOpened((current) => current.filter((item) => item !== key));
        setDirty(true); setPreview(null);
    }
    async function save() {
        if (disabled || !dirty || tourId === null) return;
        let payload: SaveVariant[];
        try { payload = serialize(variants); }
        catch (error) { toast.error(error instanceof Error ? error.message : 'Invalid pricing value.'); return; }
        setBusy(true); setSaveError(null);
        try {
            const result = await tourRequest<PricingResponse>(url, { method: 'PUT', body: { variants: payload } });
            setVariants(result.data.map((variant) => asDraft(variant, makeKey())));
            setOpened([]); setDirty(false); setPreview(null);
            setPreviewCode(result.data[0]?.code ?? '');
            toast.success('Tour pricing saved successfully.');
        } catch (error) {
            setSaveError(detailedError(error));
            toast.error('Could not save pricing. Review the invalid fields.');
        } finally { setBusy(false); }
    }
    async function discard() {
        if (dirty) {
            const accepted = await confirmAction({
                title: 'Discard unsaved pricing changes?',
                description: 'All changes made since the last save will be lost.',
                confirmLabel: 'Discard changes', tone: 'danger',
            });
            if (!accepted) return;
        }
        await load();
    }
    async function quote() {
        if (!tourId || !previewCode || dirty || loading || previewBusy) return;
        const a = Number(adults); const c = Number(children);
        if (!/^\d+$/.test(adults) || !/^\d+$/.test(children) || a < 1 || c < 0 || a + c > 1000) {
            toast.error('Preview requires at least one adult and a total of 1–1,000 travelers.'); return;
        }
        setPreviewBusy(true); setPreview(null);
        try {
            const result = await tourRequest<QuoteResponse>(`${url}/preview`, {
                method: 'POST', body: { code: previewCode, adults: a, children: c },
            });
            setPreview(result);
        } catch (error) { toast.error(detailedError(error)); }
        finally { setPreviewBusy(false); }
    }

    return <section className="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Tour pricing editor">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <div>
                <h2 className="text-sm font-bold text-slate-900">Pricing & Currency Variants</h2>
                <p className="mt-1 text-xs text-slate-500">Save prices independently from Tour Information and Daily Itinerary.</p>
            </div>
            <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">
                {variants.length} of 30 options {dirty ? '· Unsaved' : ''}
            </span>
        </div>
        <div className="space-y-4 p-4">
            {tourId === null ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Create and save this tour first, then configure its pricing on the Edit Tour page.</p> : null}
            {tourId !== null && !canWrite ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">You cannot change pricing for this tour. Published or scheduled tours require publishing permission.</p> : null}
            {loadError ? <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{loadError} <button type="button" className="underline" onClick={() => void load()}>Retry</button></p> : null}
            {saveError ? <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{saveError}</p> : null}
            {loading ? <p role="status" className="text-sm text-slate-500">Loading saved pricing…</p> : null}
            {!loading && variants.length === 0 && tourId !== null && !loadError ? <p className="rounded-lg border border-dashed border-slate-200 p-5 text-center text-sm text-slate-500">No pricing options yet. Add your first per-person or group price.</p> : null}
            {variants.map((variant, index) => {
                const expanded = opened.includes(variant.key);
                return <article key={variant.key} className="min-w-0 overflow-hidden rounded-lg border border-slate-200">
                    <div className="flex flex-wrap items-center gap-2 bg-slate-50 p-3">
                        <button type="button" className="min-w-0 flex-1 text-left text-sm font-semibold text-slate-800" aria-expanded={expanded}
                            onClick={() => setOpened((current) => expanded ? current.filter((value) => value !== variant.key) : [...current, variant.key])}>
                            <span className="text-teal-800">#{index + 1}</span> · {variant.name || 'Unnamed option'} · {variant.currency}
                            {!variant.active ? <span className="ml-2 text-xs text-amber-700">Inactive</span> : null}
                        </button>
                        <button type="button" className={button} aria-label={`Move option ${index + 1} up`} disabled={disabled || index === 0} onClick={() => reorder(index, -1)}>↑</button>
                        <button type="button" className={button} aria-label={`Move option ${index + 1} down`} disabled={disabled || index === variants.length - 1} onClick={() => reorder(index, 1)}>↓</button>
                        <button type="button" className={button} disabled={disabled || variants.length >= 30} onClick={() => duplicate(index)}>Duplicate</button>
                        <button type="button" className={`${button} text-rose-700`} disabled={disabled} onClick={() => void remove(index)}>Remove</button>
                    </div>
                    {expanded ? <div className="space-y-4 p-4">
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm font-semibold text-slate-700">Option name *
                                <input className={field} disabled={disabled} maxLength={120} value={variant.name} onChange={(event) => change(variant.key, { name: event.target.value })} />
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Stable code *
                                <input className={field} disabled={disabled} maxLength={60} value={variant.code} onChange={(event) => change(variant.key, { code: event.target.value.toLowerCase() })} />
                                <span className="mt-1 block text-xs font-normal text-slate-500">Changing a saved code replaces the old pricing option.</span>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Currency
                                <select className={field} value={variant.currency} disabled={disabled} onChange={(event) => change(variant.key, { currency: event.target.value as Currency })}>
                                    {Object.keys(digits).map((code) => <option key={code} value={code}>{code} ({digits[code as Currency]} decimal places)</option>)}
                                </select>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Pricing model
                                <select className={field} value={variant.pricing_model} disabled={disabled} onChange={(event) => {
                                    const model = event.target.value as PriceModel;
                                    change(variant.key, { pricing_model: model, rates: model === 'per_group' ? [initialRate('group', variant.currency)] : [initialRate('adult', variant.currency)] });
                                }}>
                                    <option value="per_person">Per person (adult + optional child)</option>
                                    <option value="per_group">Fixed price per group</option>
                                </select>
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Minimum travelers
                                <input className={field} type="number" min={1} max={1000} step={1} disabled={disabled} value={variant.minimum} onChange={(event) => change(variant.key, { minimum: event.target.value })} />
                            </label>
                            <label className="text-sm font-semibold text-slate-700">Maximum travelers (optional)
                                <input className={field} type="number" min={1} max={1000} step={1} disabled={disabled} value={variant.maximum} onChange={(event) => change(variant.key, { maximum: event.target.value })} />
                            </label>
                        </div>
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" className="size-4 accent-teal-800" checked={variant.active} disabled={disabled} onChange={(event) => change(variant.key, { active: event.target.checked })} /> Available for future pricing disclosure
                        </label>
                        {variant.pricing_model === 'per_person' ? <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input type="checkbox" className="size-4 accent-teal-800" disabled={disabled} checked={variant.rates.some((rate) => rate.traveler_type === 'child')}
                                onChange={(event) => change(variant.key, {
                                    rates: event.target.checked ? [...variant.rates, initialRate('child', variant.currency)] : variant.rates.filter((rate) => rate.traveler_type !== 'child'),
                                })} /> Include a child rate (zero means free)
                        </label> : null}
                        {variant.rates.map((rate) => <div key={rate.traveler_type} className="rounded-lg border border-slate-200 p-3">
                            <h3 className="mb-3 text-sm font-bold capitalize text-slate-800">{rate.traveler_type} price · {variant.currency}</h3>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="text-sm font-semibold text-slate-700">Regular price *
                                    <input inputMode="decimal" className={field} disabled={disabled} maxLength={20} value={rate.regular}
                                        onChange={(event) => changeRate(variant.key, rate.traveler_type, { regular: event.target.value })} placeholder={digits[variant.currency] ? '15000.00' : '15000'} />
                                </label>
                                <label className="text-sm font-semibold text-slate-700">Sale price (optional)
                                    <input inputMode="decimal" className={field} disabled={disabled} maxLength={20} value={rate.sale}
                                        onChange={(event) => changeRate(variant.key, rate.traveler_type, { sale: event.target.value })} placeholder="Leave blank for no discount" />
                                </label>
                                <label className="text-sm font-semibold text-slate-700">Sale starts (local time, optional)
                                    <input type="datetime-local" className={field} disabled={disabled} value={rate.starts}
                                        onChange={(event) => changeRate(variant.key, rate.traveler_type, { starts: event.target.value })} />
                                </label>
                                <label className="text-sm font-semibold text-slate-700">Sale ends (local time, optional)
                                    <input type="datetime-local" className={field} disabled={disabled} value={rate.ends}
                                        onChange={(event) => changeRate(variant.key, rate.traveler_type, { ends: event.target.value })} />
                                </label>
                            </div>
                            <p className="mt-2 text-xs text-slate-500">Without dates, a saved sale price is immediately eligible. The backend controls the active sale window.</p>
                        </div>)}
                    </div> : null}
                </article>;
            })}
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className={button} disabled={disabled || variants.length >= 30} onClick={add}>+ Add pricing option</button>
                <button type="button" className={primary} disabled={disabled || !dirty} onClick={() => void save()}>{busy ? 'Saving pricing…' : 'Save Pricing'}</button>
                <button type="button" className={button} disabled={disabled || !dirty} onClick={() => void discard()}>Discard Changes</button>
            </div>
            <p className="text-xs text-slate-500">All amounts are entered in currency major units and stored as exact integer minor units. Prices do not include taxes, exchange conversion or booking guarantees.</p>
            {tourId !== null && <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <h3 className="text-sm font-bold text-slate-800">Staff-only price quote preview</h3>
                <p className="text-xs text-slate-500">Uses SAVED pricing and the server's current time. Preview does not reserve places or create a booking.</p>
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="text-sm font-semibold text-slate-700">Saved option
                        <select className={field} value={previewCode} disabled={loading || dirty || previewBusy} onChange={(event) => { setPreviewCode(event.target.value); setPreview(null); }}>
                            <option value="">Choose an option</option>
                            {variants.map((variant) => <option key={variant.key} value={variant.code}>{variant.name} ({variant.currency})</option>)}
                        </select>
                    </label>
                    <label className="text-sm font-semibold text-slate-700">Adults
                        <input type="number" min={1} max={1000} step={1} className={field} value={adults} onChange={(event) => { setAdults(event.target.value); setPreview(null); }} />
                    </label>
                    <label className="text-sm font-semibold text-slate-700">Children
                        <input type="number" min={0} max={1000} step={1} className={field} value={children} onChange={(event) => { setChildren(event.target.value); setPreview(null); }} />
                    </label>
                </div>
                <button type="button" className={button} disabled={!previewCode || dirty || previewBusy || loading || tourBusy} onClick={() => void quote()}>{previewBusy ? 'Calculating…' : 'Preview saved price'}</button>
                {dirty ? <p className="text-xs text-amber-800">Save or discard changes before previewing this option.</p> : null}
                {preview && <div role="status" className="rounded-lg bg-white p-3 text-sm text-slate-800">
                    <p className="font-bold">Total: {preview.data.currency} {minorToMajor(preview.data.total_minor, preview.data.fraction_digits)}</p>
                    <p className="text-xs text-slate-500">Regular: {preview.data.currency} {minorToMajor(preview.data.regular_total_minor, preview.data.fraction_digits)} · Discount: {preview.data.currency} {minorToMajor(preview.data.discount_minor, preview.data.fraction_digits)}</p>
                    {preview.data.lines.map((line) => <p key={line.traveler_type} className="mt-1 text-xs capitalize">{line.traveler_type}: {line.quantity} × {minorToMajor(line.effective_unit_minor, preview.data.fraction_digits)} = {minorToMajor(line.line_total_minor, preview.data.fraction_digits)} {preview.data.currency}</p>)}
                    <p className="mt-2 text-xs text-slate-500">Quote time: {preview.meta.quoted_at} · For staff reference only; not a booking or final payable price.</p>
                </div>}
            </div>}
        </div>
    </section>;
}
