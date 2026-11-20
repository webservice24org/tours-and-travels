import { Head, Link, router } from '@inertiajs/react';
import { TiptapEditor } from '@microweb/laravel-tiptap-editor';
import '@microweb/laravel-tiptap-editor/editor.css';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';

import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';
import { tourErrorMessage, tourRequest, TourApiError } from '@/components/admin/tours/client';
import TourItineraryEditor from '@/components/admin/tours/TourItineraryEditor';
import TourPricingEditor from '@/components/admin/tours/TourPricingEditor';
import TourDepartureEditor from '@/components/admin/tours/TourDepartureEditor';
import type {
    FieldErrors, TaxonomyOption, TourCapabilities, TourDetailResponse, TourDraft, TourStatus,
    TourTaxonomyOptionsResponse,
} from '@/components/admin/tours/types';
import BablysAdminLayout from '@/layouts/bablys-admin-layout';

const API = '/admin/internal/tours';
const field = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200 disabled:bg-slate-100';
const primary = 'inline-flex items-center justify-center rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-900 disabled:opacity-50';
const secondary = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50';
const statuses: TourStatus[] = ['draft', 'in_review', 'scheduled', 'published', 'archived'];
const emptyOptions: TourTaxonomyOptionsResponse = { categories: [], destinations: [] };

type MediaItem = {
    id: number; tour_id: number; url: string; path: string; alt_text: string | null;
    sort_order: number; is_featured: boolean; width: number; height: number;
};
type TagSuggestion = { id: number; name: string };

type DraftWithTags = TourDraft & { tag_names: string[] };
function blank(): DraftWithTags {
    return {
        title: '', slug: '', summary: '', description: '', status: 'draft',
        duration_days: '1', duration_nights: '', is_featured: false, sort_order: '0',
        scheduled_for: '', category_ids: [], destination_ids: [], tag_names: [],
    };
}
function toSlug(title: string): string {
    return title.normalize('NFKD').toLowerCase().replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 160);
}
function fromIsoToLocal(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}
function validation(errors: FieldErrors, key: string) {
    const message = errors[key]?.[0];
    return message ? <p role="alert" className="mt-1 text-xs text-rose-700">{message}</p> : null;
}
function Panel({ title, children }: { title: string; children: ReactNode }) {
    return <section className="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm">
        <h2 className="border-b border-slate-100 px-4 py-3 text-sm font-bold text-slate-900">{title}</h2>
        <div className="space-y-3 p-4">{children}</div>
    </section>;
}
function TaxonomySelector({ label, options, values, onChange, disabled }: {
    label: string; options: TaxonomyOption[]; values: number[];
    onChange: (ids: number[]) => void; disabled: boolean;
}) {
    const [filter, setFilter] = useState('');
    const visible = useMemo(() => options.filter((option) =>
        `${option.name} ${option.slug}`.toLowerCase().includes(filter.toLowerCase())), [options, filter]);
    return <fieldset disabled={disabled} className="min-w-0">
        <legend className="sr-only">{label}</legend>
        <input className={field} value={filter} onChange={(event) => setFilter(event.target.value)} placeholder={`Search ${label.toLowerCase()}`} aria-label={`Search ${label.toLowerCase()}`} />
        <div className="mt-2 max-h-44 space-y-1 overflow-y-auto rounded-lg border border-slate-100 p-2">
            {visible.map((option) => <label key={option.id} className="flex min-w-0 cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" className="size-4 accent-teal-800" checked={values.includes(option.id)}
                    onChange={(event) => {
                        if (event.target.checked && values.length >= 30) { toast.error('Maximum 30 selections.'); return; }
                        onChange(event.target.checked ? [...values, option.id] : values.filter((id) => id !== option.id));
                    }} />
                <span className="min-w-0 flex-1 truncate">{option.name}</span>
                {option.status !== 'published' && <span className="text-[10px] text-amber-700">{option.status}</span>}
            </label>)}
            {visible.length === 0 && <p className="text-xs text-slate-500">No matching options in the loaded list.</p>}
        </div>
        <p className="mt-1 text-xs text-slate-500">{values.length} selected · first 200 taxonomy options currently available</p>
    </fieldset>;
}
function readCookie(name: string): string | null {
    const entry = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));
    if (!entry) return null;
    try { return decodeURIComponent(entry.slice(name.length + 1)); } catch { return null; }
}
function csrfHeaders(): Record<string, string> {
    const xsrf = readCookie('XSRF-TOKEN');
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    return xsrf ? { 'X-XSRF-TOKEN': xsrf } : csrf ? { 'X-CSRF-TOKEN': csrf } : {};
}
async function uploadMedia(tourId: number, file: File, featured: boolean): Promise<{ data: MediaItem }> {
    const form = new FormData();
    form.append('image', file);
    form.append('featured', featured ? '1' : '0');
    const response = await fetch(`${API}/${tourId}/media`, {
        method: 'POST', credentials: 'same-origin', body: form,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
    });
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok || !payload || typeof payload !== 'object') {
        const detail = payload as { message?: string; errors?: Record<string, string[]> } | null;
        throw new TourApiError(detail?.errors?.image?.[0] ?? detail?.message ?? `Upload failed (${response.status}).`, response.status);
    }
    return payload as { data: MediaItem };
}

export default function TourEditor({ mode, tourId, can }: { mode: 'create' | 'edit'; tourId: number | null; can: TourCapabilities }) {
    const [draft, setDraft] = useState<DraftWithTags>(blank);
    const [slugTouched, setSlugTouched] = useState(mode === 'edit');
    const [originalStatus, setOriginalStatus] = useState<TourStatus>('draft');
    const [persistedDurationDays, setPersistedDurationDays] = useState<number | null>(null);
    const [options, setOptions] = useState<TourTaxonomyOptionsResponse>(emptyOptions);
    const [media, setMedia] = useState<MediaItem[]>([]);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [imageBusy, setImageBusy] = useState(false);
    const [tagInput, setTagInput] = useState('');
    const [suggestions, setSuggestions] = useState<TagSuggestion[]>([]);
    const [dirty, setDirty] = useState(false);
    const isEdit = mode === 'edit' && tourId !== null;

    const loadMedia = useCallback(async (id: number, signal?: AbortSignal) => {
        const result = await tourRequest<{ data: MediaItem[] }>(`${API}/${id}/media`, { signal });
        setMedia(result.data);
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        (async () => {
            try {
                const taxonomy = await tourRequest<TourTaxonomyOptionsResponse>(`${API}/taxonomy-options`, { signal: controller.signal });
                if (controller.signal.aborted) return;
                setOptions(taxonomy);
                if (isEdit && tourId !== null) {
                    const response = await tourRequest<TourDetailResponse & { data: { tag_names?: string[] } }>(`${API}/${tourId}`, { signal: controller.signal });
                    if (controller.signal.aborted) return;
                    const tour = response.data;
                    setDraft({
                        title: tour.title, slug: tour.slug, summary: tour.summary ?? '', description: tour.description ?? '',
                        status: tour.status, duration_days: String(tour.duration_days),
                        duration_nights: tour.duration_nights === null ? '' : String(tour.duration_nights),
                        is_featured: tour.is_featured, sort_order: String(tour.sort_order),
                        scheduled_for: fromIsoToLocal(tour.scheduled_for),
                        category_ids: tour.category_ids, destination_ids: tour.destination_ids,
                        tag_names: tour.tag_names ?? [],
                    });
                    setOriginalStatus(tour.status);
                    setPersistedDurationDays(tour.duration_days);
                    await loadMedia(tourId, controller.signal);
                }
            } catch (error) {
                if (!controller.signal.aborted) toast.error(tourErrorMessage(error));
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        })();
        return () => controller.abort();
    }, [isEdit, tourId, loadMedia]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            tourRequest<{ data: TagSuggestion[] }>(`/admin/internal/tour-tags/suggest?q=${encodeURIComponent(tagInput)}`, { signal: controller.signal })
                .then((result) => { if (!controller.signal.aborted) setSuggestions(result.data); })
                .catch(() => { if (!controller.signal.aborted) setSuggestions([]); });
        }, 170);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [tagInput]);

    useEffect(() => {
        const handler = (event: BeforeUnloadEvent) => { if (dirty) event.preventDefault(); };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    function change<K extends keyof DraftWithTags>(key: K, value: DraftWithTags[K]) {
        setDraft((current) => ({ ...current, [key]: value }));
        setDirty(true);
    }
    function addTag(text: string) {
        const name = text.trim().replace(/\s+/g, ' ');
        if (!name) return;
        if (name.length > 80) { toast.error('Tag name must not exceed 80 characters.'); return; }
        if (draft.tag_names.some((tag) => tag.toLowerCase() === name.toLowerCase())) { setTagInput(''); return; }
        if (draft.tag_names.length >= 20) { toast.error('Maximum 20 tags per tour.'); return; }
        change('tag_names', [...draft.tag_names, name]);
        setTagInput('');
    }
    function handleTagKey(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            addTag(tagInput);
        }
    }
    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy || loading || (isEdit ? !can.edit : !can.create)) return;
        if (!can.publish && (['published', 'scheduled'].includes(draft.status) || ['published', 'scheduled'].includes(originalStatus))) {
            toast.error('Publishing permission is required.'); return;
        }
        let scheduledFor: string | null = null;
        if (draft.status === 'scheduled') {
            const date = new Date(draft.scheduled_for);
            if (!draft.scheduled_for || Number.isNaN(date.getTime()) || date.getTime() <= Date.now()) {
                setErrors({ scheduled_for: ['Choose a future date and time.'] }); return;
            }
            scheduledFor = date.toISOString();
        }
        setBusy(true); setErrors({});
        try {
            const payload = {
                title: draft.title.trim(), summary: draft.summary.trim() || null,
                description: draft.description.trim() || null, status: draft.status,
                duration_days: Number(draft.duration_days),
                duration_nights: draft.duration_nights.trim() ? Number(draft.duration_nights) : null,
                is_featured: draft.is_featured, sort_order: Number(draft.sort_order),
                scheduled_for: scheduledFor, category_ids: draft.category_ids,
                destination_ids: draft.destination_ids, tag_names: draft.tag_names,
                ...(!isEdit ? { slug: draft.slug.trim() } : {}),
            };
            const response = await tourRequest<TourDetailResponse>(isEdit ? `${API}/${tourId}` : API, {
                method: isEdit ? 'PUT' : 'POST', body: payload,
            });
            setDirty(false);
            toast.success(`Tour “${response.data.title}” ${isEdit ? 'updated' : 'created'} successfully.`);
            if (isEdit) {
                setOriginalStatus(response.data.status);
                setPersistedDurationDays(response.data.duration_days);
            } else {
                router.visit(`/admin/tours/${response.data.id}/edit`);
            }
        } catch (error) {
            setErrors(error instanceof TourApiError ? error.fields : {});
            toast.error(tourErrorMessage(error));
        } finally { setBusy(false); }
    }
    async function addImage(file: File, featured: boolean): Promise<MediaItem> {
        if (!tourId || !isEdit) throw new Error('Save this tour first, then upload images.');
        setImageBusy(true);
        try {
            const response = await uploadMedia(tourId, file, featured);
            await loadMedia(tourId);
            toast.success(featured ? 'Featured image uploaded.' : 'Gallery image uploaded.');
            return response.data;
        } catch (error) {
            toast.error(tourErrorMessage(error));
            throw error;
        } finally { setImageBusy(false); }
    }
    const uploadEditorImage = useCallback(async (file: File) => {
        if (!isEdit || tourId === null) throw new Error('Save your tour first to upload editor images.');
        const response = await uploadMedia(tourId, file, false);
        await loadMedia(tourId);
        return { url: response.data.url, path: response.data.path, temporary: false };
    }, [isEdit, tourId, loadMedia]);
    async function updateImage(item: MediaItem, changes: { is_featured?: boolean; sort_order?: number; alt_text?: string | null }) {
        if (!tourId) return;
        setImageBusy(true);
        try {
            await tourRequest(`${API}/${tourId}/media/${item.id}`, { method: 'PATCH', body: changes });
            await loadMedia(tourId);
            toast.success('Image updated.');
        } catch (error) { toast.error(tourErrorMessage(error)); }
        finally { setImageBusy(false); }
    }
    async function deleteImage(item: MediaItem) {
        if (!tourId) return;
        const accepted = await confirmAction({
            title: 'Delete tour image?',
            description: 'Permanently delete this uploaded image? Remove it from the description first if it is embedded there.',
            confirmLabel: 'Delete image', tone: 'danger',
        });
        if (!accepted) return;
        setImageBusy(true);
        try {
            await tourRequest<void>(`${API}/${tourId}/media/${item.id}`, { method: 'DELETE' });
            await loadMedia(tourId);
            toast.success('Tour image deleted.');
        } catch (error) { toast.error(tourErrorMessage(error)); }
        finally { setImageBusy(false); }
    }

    const selectedTags = draft.tag_names.map((tag) => tag.toLowerCase());
    const remainingSuggestions = suggestions.filter((suggestion) => !selectedTags.includes(suggestion.name.toLowerCase()));
    const canWrite = isEdit ? can.edit : can.create;
    const disabled = busy || loading || !canWrite;
    const optionsStatuses = can.publish ? statuses : statuses.filter((status) => !['scheduled', 'published'].includes(status));
    return <>
        <Head title={`${isEdit ? 'Edit' : 'Add'} Tour · Bablys CMS`} />
        <BablysAdminLayout>
            <main className="mx-auto w-full min-w-0 max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link className="text-sm font-semibold text-teal-800 hover:underline" href="/admin/tours">← All tours</Link>
                        <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-900">{isEdit ? 'Edit Tour' : 'Add New Tour'}</h1>
                        <p className="mt-1 text-sm text-slate-500">Full-page tour editor · 9-column content and 3-column settings</p>
                    </div>
                    <button type="submit" form="tour-editor-form" className={primary} disabled={disabled}>{busy ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Tour'}</button>
                </header>
                <form id="tour-editor-form" className="mt-6 grid min-w-0 grid-cols-1 items-start gap-5 lg:grid-cols-12" onSubmit={(event) => void save(event)}>
                    <div className="min-w-0 space-y-3 lg:col-span-9">
                        <Panel title="Tour Information">
                            <label className="block text-sm font-semibold text-slate-700">Tour title *
                                <input className={field} required maxLength={200} minLength={2} disabled={disabled} value={draft.title}
                                    onChange={(event) => {
                                        const title = event.target.value;
                                        setDraft((current) => ({ ...current, title, ...(!isEdit && !slugTouched ? { slug: toSlug(title) } : {}) }));
                                        setDirty(true);
                                    }} />
                                {validation(errors, 'title')}
                            </label>
                            <label className="block text-sm font-semibold text-slate-700">Permanent slug *
                                <input className={field} required maxLength={160} value={draft.slug} disabled={disabled || isEdit}
                                    onChange={(event) => { setSlugTouched(true); change('slug', event.target.value); }} placeholder="e.g. sundarbans-adventure" />
                                <span className="mt-1 block text-xs font-normal text-slate-500">Slug cannot be changed after tour creation.</span>
                                {validation(errors, 'slug')}
                            </label>
                            <label className="block text-sm font-semibold text-slate-700">Short summary
                                <textarea className={field} rows={3} maxLength={500} value={draft.summary} disabled={disabled}
                                    onChange={(event) => change('summary', event.target.value)} />
                                {validation(errors, 'summary')}
                            </label>
                        </Panel>
                        <Panel title="Tour Description · MicroWeb Tiptap Editor">
                            <p className="text-xs text-slate-500">Rich-text content is sanitized on the server. Save a new tour before inserting images into the description.</p>
                            <div className="min-w-0 overflow-x-auto rounded-lg border border-slate-200">
                                <TiptapEditor value={draft.description} minHeight={430} onChange={(html) => change('description', html)} onImageUpload={uploadEditorImage} />
                            </div>
                            {validation(errors, 'description')}
                        </Panel>
                        <TourDepartureEditor
                            tourId={isEdit ? tourId : null}
                            status={originalStatus}
                            canEdit={can.edit}
                            canPublish={can.publish}
                            tourBusy={busy || loading}
                        />
                        <TourPricingEditor
                            tourId={isEdit ? tourId : null}
                            status={originalStatus}
                            canEdit={can.edit}
                            canPublish={can.publish}
                            tourBusy={busy || loading}
                        />
                        <TourItineraryEditor
                            tourId={isEdit ? tourId : null}
                            persistedDurationDays={persistedDurationDays}
                            durationChangePending={isEdit && persistedDurationDays !== null && Number(draft.duration_days) !== persistedDurationDays}
                            status={originalStatus}
                            canEdit={can.edit}
                            canPublish={can.publish}
                            tourBusy={busy || loading}
                        />
                        <Panel title="Tour Duration">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="text-sm font-semibold text-slate-700">Days *
                                    <input className={field} type="number" min={1} max={365} required disabled={disabled}
                                        value={draft.duration_days} onChange={(event) => change('duration_days', event.target.value)} />
                                    {validation(errors, 'duration_days')}
                                </label>
                                <label className="text-sm font-semibold text-slate-700">Nights
                                    <input className={field} type="number" min={0} max={365} disabled={disabled}
                                        value={draft.duration_nights} onChange={(event) => change('duration_nights', event.target.value)} />
                                    {validation(errors, 'duration_nights')}
                                </label>
                            </div>
                        </Panel>
                    </div>
                    <aside className="min-w-0 space-y-5 lg:col-span-3" aria-label="Tour settings">
                        <Panel title="Publish">
                            <label className="block text-sm font-semibold text-slate-700">Status
                                <select className={field} value={draft.status} disabled={disabled} onChange={(event) => change('status', event.target.value as TourStatus)}>
                                    {optionsStatuses.map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}
                                </select>
                                {validation(errors, 'status')}
                            </label>
                            {draft.status === 'scheduled' && <label className="block text-sm font-semibold text-slate-700">Future schedule
                                <input type="datetime-local" className={field} disabled={disabled} value={draft.scheduled_for}
                                    onChange={(event) => change('scheduled_for', event.target.value)} />
                                <span className="mt-1 block text-xs text-amber-700">Scheduled tours stay private until scheduling automation is implemented.</span>
                                {validation(errors, 'scheduled_for')}
                            </label>}
                            <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                <input type="checkbox" className="size-4 accent-teal-800" checked={draft.is_featured} disabled={disabled}
                                    onChange={(event) => change('is_featured', event.target.checked)} /> Featured tour
                            </label>
                            <label className="block text-sm font-semibold text-slate-700">Sort order
                                <input type="number" min={0} max={1000000} className={field} value={draft.sort_order} disabled={disabled}
                                    onChange={(event) => change('sort_order', event.target.value)} />
                            </label>
                            {validation(errors, 'tour')}
                            <button className={`${primary} w-full`} type="submit" disabled={disabled}>{busy ? 'Saving…' : isEdit ? 'Update Tour' : 'Create Tour'}</button>
                        </Panel>
                        <Panel title="Categories">
                            <TaxonomySelector label="Categories" options={options.categories} values={draft.category_ids} disabled={disabled}
                                onChange={(ids) => change('category_ids', ids)} />
                            {validation(errors, 'category_ids')}
                        </Panel>
                        <Panel title="Destinations">
                            <TaxonomySelector label="Destinations" options={options.destinations} values={draft.destination_ids} disabled={disabled}
                                onChange={(ids) => change('destination_ids', ids)} />
                            {validation(errors, 'destination_ids')}
                        </Panel>
                        <Panel title="Tour Tags">
                            <div className="flex flex-wrap gap-2">
                                {draft.tag_names.map((tag) => <span key={tag.toLowerCase()} className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2 py-1 text-xs text-teal-900">
                                    {tag}<button type="button" aria-label={`Remove ${tag}`} disabled={disabled} className="ml-1 font-bold" onClick={() => change('tag_names', draft.tag_names.filter((item) => item !== tag))}>×</button>
                                </span>)}
                            </div>
                            <input className={field} value={tagInput} maxLength={80} disabled={disabled}
                                onChange={(event) => setTagInput(event.target.value)} onKeyDown={handleTagKey}
                                placeholder="Type and press Enter" aria-label="Search or add tour tag" />
                            {tagInput.trim() !== '' && <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                                {remainingSuggestions.map((suggestion) => <button type="button" key={suggestion.id}
                                    className="block w-full rounded p-1 text-left text-sm hover:bg-slate-50" onClick={() => addTag(suggestion.name)}>
                                    {suggestion.name} <span className="text-xs text-slate-500">Existing tag</span>
                                </button>)}
                                {!suggestions.some((suggestion) => suggestion.name.toLowerCase() === tagInput.trim().toLowerCase()) &&
                                    <button type="button" className="block w-full rounded p-1 text-left text-sm font-semibold text-teal-800 hover:bg-teal-50" onClick={() => addTag(tagInput)}>
                                        + Create “{tagInput.trim()}”
                                    </button>}
                            </div>}
                            <p className="text-xs text-slate-500">Existing tags are suggested as you type; new tags are created when you save. Maximum 20.</p>
                            {validation(errors, 'tag_names')}
                        </Panel>
                        <Panel title="Featured Image">
                            {media.find((item) => item.is_featured) ? <img className="w-full rounded-lg border border-slate-200 object-cover" src={media.find((item) => item.is_featured)?.url} alt="Featured tour" />
                                : <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-xs text-slate-500">No featured image</div>}
                            <input type="file" accept="image/jpeg,image/png,image/webp" disabled={!isEdit || imageBusy || disabled} className="block w-full text-xs"
                                onChange={(event) => { const file = event.target.files?.[0]; if (file) void addImage(file, true).catch(() => undefined); event.target.value = ''; }} />
                            {!isEdit && <p className="text-xs text-amber-700">Create the tour first to upload its featured image.</p>}
                        </Panel>
                        <Panel title="Tour Gallery">
                            <p className="text-xs text-slate-500">JPEG, PNG or WebP · maximum 5 MB and 24 images per tour. Images are re-encoded to strip metadata.</p>
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={!isEdit || imageBusy || disabled}
                                className="block w-full text-xs" onChange={(event) => {
                                    const files = Array.from(event.target.files ?? []);
                                    void (async () => { for (const file of files) { try { await addImage(file, false); } catch { break; } } })();
                                    event.target.value = '';
                                }} />
                            {media.length > 0 && <div className="grid grid-cols-2 gap-2">
                                {media.map((item) => <div key={item.id} className="min-w-0 rounded-lg border border-slate-200 p-1">
                                    <img src={item.url} alt={item.alt_text ?? 'Gallery image'} className="aspect-square w-full rounded object-cover" />
                                    <input className={`${field} text-xs`} maxLength={200} value={item.alt_text ?? ''} aria-label="Image alt text"
                                        onChange={(event) => setMedia((current) => current.map((image) => image.id === item.id ? { ...image, alt_text: event.target.value } : image))}
                                        onBlur={() => void updateImage(item, { alt_text: item.alt_text })} disabled={disabled || imageBusy} placeholder="Alt text" />
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {!item.is_featured && <button type="button" className="text-xs font-semibold text-teal-800" disabled={imageBusy || disabled} onClick={() => void updateImage(item, { is_featured: true })}>Set featured</button>}
                                        <button type="button" className="text-xs text-rose-700" disabled={imageBusy || disabled} onClick={() => void deleteImage(item)}>Delete</button>
                                    </div>
                                </div>)}
                            </div>}
                            {!isEdit && <p className="text-xs text-amber-700">Save the new tour before adding gallery images.</p>}
                        </Panel>
                    </aside>
                </form>
            </main>
        </BablysAdminLayout>
    </>;
}
