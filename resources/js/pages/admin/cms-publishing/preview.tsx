import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type MenuItem = { id: string; label: string; url: string; parent_id: string | null; kind: string; new_tab: boolean };
type MenuValue = { name: string; items: MenuItem[] };
type SettingsValue = Record<string, Record<string, string>>;
type HistoryEvent = { id: number; action: string; serial: number; source_draft_version: number; actor_id: number | null; created_at: string };
type Snapshot = {
    kind: 'settings' | 'navigation'; name: string; key: string | null; subject_id: number | null;
    draft: SettingsValue | MenuValue; published: SettingsValue | MenuValue | null;
    draft_version: number; published_version: number; publication_serial: number; history: HistoryEvent[];
};
type Props = { snapshot: Snapshot };

function SettingsPreview({ data }: { data: SettingsValue }) {
    return <div className="space-y-4">{Object.entries(data).map(([group, items]) => <section key={group} className="rounded-lg border border-slate-200 p-3">
        <h3 className="font-bold capitalize text-slate-800">{group}</h3>
        <dl className="mt-2 space-y-2">{Object.entries(items).map(([key, value]) => <div key={key} className="grid gap-1 border-t border-slate-100 pt-2 sm:grid-cols-2">
            <dt className="text-xs font-medium text-slate-600">{key.replaceAll('_', ' ')}</dt><dd className="break-words text-sm text-slate-900">{value || '(empty)'}</dd>
        </div>)}</dl>
    </section>)}</div>;
}
function MenuPreview({ data }: { data: MenuValue }) {
    return <div><p className="font-semibold text-slate-900">{data.name}</p>
        <ol className="mt-2 space-y-2">{data.items.map((item) => <li key={item.id} className="rounded-lg border border-slate-200 p-3 text-sm">
            <span className="block font-semibold">{item.label}</span>
            <span className="break-all text-slate-700">{item.url}</span>
            <span className="block text-xs text-slate-500">{item.parent_id ? `Child of: ${data.items.find(p => p.id === item.parent_id)?.label ?? 'unknown'}` : 'Top level'} · {item.kind} · {item.new_tab ? 'New tab' : 'Same tab'}</span>
        </li>)}</ol>
    </div>;
}
function Content({ kind, value }: { kind: Snapshot['kind']; value: Snapshot['draft'] | null }) {
    if (value === null) return <p className="text-sm text-slate-500">Nothing published yet.</p>;
    return kind === 'settings' ? <SettingsPreview data={value as SettingsValue} /> : <MenuPreview data={value as MenuValue} />;
}
export default function CmsPublishingPreview({ snapshot }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const base = snapshot.kind === 'settings' ? '/admin/cms-publishing/site-settings' : `/admin/cms-publishing/navigation-menus/${snapshot.subject_id}`;
    const ready = snapshot.draft_version > 0;
    const expected = { expected_draft_version: snapshot.draft_version, expected_publication_serial: snapshot.publication_serial };
    function publish() {
        if (!window.confirm(`Publish ${snapshot.name}? Visitors will receive this snapshot from the published-only API.`)) return;
        setError(null);
        router.post(`${base}/publish`, expected, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: e => setError(Object.values(e).join(' ')) });
    }
    function restore(event: HistoryEvent) {
        if (!window.confirm(`Restore published snapshot #${event.serial} for ${snapshot.name}? Private drafts will remain unchanged.`)) return;
        setError(null);
        router.post(`${base}/rollback`, { ...expected, publication_event_id: event.id }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: e => setError(Object.values(e).join(' ')) });
    }
    return <BablysAdminLayout>
        <Head title={`Preview ${snapshot.name} · Bablys Admin`} />
        <main className="mx-auto w-full max-w-6xl min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header>
                <Link href="/admin/cms-publishing" className="inline-flex min-h-11 items-center text-sm font-semibold text-teal-800 underline">Publishing center</Link>
                <h1 className="mt-2 break-words text-2xl font-bold text-slate-900 sm:text-3xl">Preview: {snapshot.name}</h1>
                <p className="mt-2 text-sm text-slate-600">Draft v{snapshot.draft_version} · Published from draft v{snapshot.published_version} · Publication #{snapshot.publication_serial}</p>
                <p className="mt-1 text-xs text-amber-800">Content comparison preview only. The final public header/footer rendering is integrated in P4A.4; unpublished values are never sent to visitors.</p>
            </header>
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
                <p className="text-sm text-slate-700">Publishing changes public API content. It does not overwrite the draft.</p>
                <button disabled={busy || !ready} onClick={publish} className="min-h-11 rounded-lg bg-teal-800 px-5 text-sm font-bold text-white disabled:opacity-50">{busy ? 'Processing…' : 'Publish current draft'}</button>
            </div>
            {!ready && <p className="text-sm text-amber-800" role="status">Save a draft in the editor before publishing.</p>}
            {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error} Reload this preview before retrying.</p>}
            <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                <section aria-label="Private draft preview" className="min-w-0 rounded-xl border border-amber-200 bg-white p-4">
                    <h2 className="mb-3 text-lg font-bold text-slate-900">Private draft</h2><Content kind={snapshot.kind} value={snapshot.draft} />
                </section>
                <section aria-label="Published preview" className="min-w-0 rounded-xl border border-emerald-200 bg-white p-4">
                    <h2 className="mb-3 text-lg font-bold text-slate-900">Currently published</h2><Content kind={snapshot.kind} value={snapshot.published} />
                </section>
            </div>
            <section className="rounded-xl border border-slate-200 bg-white p-5" aria-labelledby="publication-history-heading">
                <h2 id="publication-history-heading" className="text-lg font-bold">Publication history</h2>
                <p className="my-2 text-xs text-slate-600">Each event is an immutable published snapshot. Restoring a previous publication never changes the draft editor or its revision number.</p>
                {snapshot.history.length === 0 && <p className="text-sm text-slate-500">No publication history.</p>}
                <ul className="divide-y divide-slate-100">{snapshot.history.map(event => <li key={event.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div><p className="text-sm font-semibold">#{event.serial} · {event.action} · from draft v{event.source_draft_version}</p><p className="text-xs text-slate-600">{event.created_at} · Actor #{event.actor_id ?? 'unknown'}</p></div>
                    <button type="button" disabled={busy || event.serial === snapshot.publication_serial} onClick={() => restore(event)} className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-800 disabled:opacity-50">Restore this publication</button>
                </li>)}</ul>
            </section>
        </main>
    </BablysAdminLayout>;
}
