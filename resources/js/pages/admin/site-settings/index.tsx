import { Head, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';

import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type Branding = Record<string, string> & { site_name: string; tagline: string; legal_name: string };
type Contact = Record<string, string> & { email: string; phone: string; whatsapp: string; office_address: string; business_hours: string };
type Social = Record<string, string> & { facebook: string; instagram: string; youtube: string; linkedin: string };
type Legal = Record<string, string> & { copyright_notice: string; privacy_url: string; terms_url: string };
type Settings = { branding: Branding; contact: Contact; social: Social; legal: Legal };
type Props = {
    draft: Settings;
    published: Settings;
    draft_version: number;
    published_version: number;
    draft_updated_at: string | null;
    can_publish: false;
};
type GroupName = keyof Settings;
type InputSpec = { group: GroupName; key: string; label: string; hint?: string; type?: 'email' | 'url' | 'tel'; multiline?: boolean };

const fields: InputSpec[] = [
    { group: 'branding', key: 'site_name', label: 'Website name', hint: 'Displayed in the future global website header' },
    { group: 'branding', key: 'tagline', label: 'Tagline' },
    { group: 'branding', key: 'legal_name', label: 'Registered business name' },
    { group: 'contact', key: 'email', label: 'Public contact email', type: 'email' },
    { group: 'contact', key: 'phone', label: 'Public phone number', type: 'tel' },
    { group: 'contact', key: 'whatsapp', label: 'WhatsApp phone number', type: 'tel' },
    { group: 'contact', key: 'office_address', label: 'Office address', multiline: true },
    { group: 'contact', key: 'business_hours', label: 'Business hours' },
    { group: 'social', key: 'facebook', label: 'Facebook URL', type: 'url' },
    { group: 'social', key: 'instagram', label: 'Instagram URL', type: 'url' },
    { group: 'social', key: 'youtube', label: 'YouTube URL', type: 'url' },
    { group: 'social', key: 'linkedin', label: 'LinkedIn URL', type: 'url' },
    { group: 'legal', key: 'copyright_notice', label: 'Copyright / legal footer text' },
    { group: 'legal', key: 'privacy_url', label: 'Privacy policy HTTPS URL', type: 'url' },
    { group: 'legal', key: 'terms_url', label: 'Terms & conditions HTTPS URL', type: 'url' },
];
const groups: { group: GroupName; title: string; description: string }[] = [
    { group: 'branding', title: 'Brand identity', description: 'Name, tagline and business identity' },
    { group: 'contact', title: 'Contact & offices', description: 'Publicly approved contact information' },
    { group: 'social', title: 'Social links', description: 'Only HTTPS links are accepted' },
    { group: 'legal', title: 'Legal information', description: 'Footer text and approved legal-page links' },
];
const inputClass = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700';

function updated(settings: Settings, group: GroupName, key: string, value: string): Settings {
    // The key is chosen from the fixed local field registry, never untrusted input.
    if (group === 'branding') return { ...settings, branding: { ...settings.branding, [key]: value } };
    if (group === 'contact') return { ...settings, contact: { ...settings.contact, [key]: value } };
    if (group === 'social') return { ...settings, social: { ...settings.social, [key]: value } };
    return { ...settings, legal: { ...settings.legal, [key]: value } };
}
function fieldValue(settings: Settings, field: InputSpec): string {
    if (field.group === 'branding') return settings.branding[field.key as keyof Branding];
    if (field.group === 'contact') return settings.contact[field.key as keyof Contact];
    if (field.group === 'social') return settings.social[field.key as keyof Social];
    return settings.legal[field.key as keyof Legal];
}

export default function SiteSettingsEditor({ draft, published, draft_version, published_version, draft_updated_at }: Props) {
    const [values, setValues] = useState<Settings>(draft);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    useEffect(() => { setValues(draft); setErrors({}); }, [draft]);

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSaved(false);
        router.put('/admin/site-settings', {
            expected_version: draft_version,
            branding: values.branding,
            contact: values.contact,
            social: values.social,
            legal: values.legal,
        }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (next) => setErrors(next),
            onSuccess: () => { setErrors({}); setSaved(true); },
        });
    }

    return <BablysAdminLayout>
        <Head title="Site Settings · Bablys Admin" />
        <main className="mx-auto w-full max-w-6xl min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div><p className="text-xs font-bold uppercase tracking-wide text-teal-700">Global CMS · P4A.1</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900 sm:text-3xl">Website settings</h1>
                    <p className="mt-2 max-w-2xl text-sm text-slate-600">Edit site-wide branding and contact details. All changes are saved as private drafts until publishing is enabled in P4A.3.</p>
                </div>
                <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">Draft editor · Not published</span>
            </div>
            <section aria-label="Revision information" className="grid gap-3 sm:grid-cols-3">
                {[
                    { label: 'Draft revision', value: String(draft_version) },
                    { label: 'Published revision', value: String(published_version) },
                    { label: 'Last draft save', value: draft_updated_at || 'Not yet saved' },
                ].map((item) => <div key={item.label} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4">
                    <p className="text-xs text-slate-500">{item.label}</p><p className="mt-1 break-words text-sm font-semibold text-slate-900">{item.value}</p>
                </div>)}
            </section>
            <form onSubmit={save} className="space-y-5" noValidate>
                {groups.map(({ group, title, description }) => <section key={group} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby={`p4a-${group}`}>
                    <div className="border-b border-slate-100 bg-slate-50 px-5 py-4"><h2 id={`p4a-${group}`} className="font-bold text-slate-900">{title}</h2><p className="text-xs text-slate-500">{description}</p></div>
                    <div className="grid gap-5 p-5 md:grid-cols-2">
                        {fields.filter((field) => field.group === group).map((field) => {
                            const name = `${field.group}.${field.key}`;
                            const id = `p4a-field-${field.group}-${field.key}`;
                            const message = errors[name];
                            return <div key={name} className={`min-w-0 ${field.multiline ? 'md:col-span-2' : ''}`}>
                                <label htmlFor={id} className="block text-sm font-semibold text-slate-800">{field.label}</label>
                                {field.multiline ? <textarea id={id} name={name} rows={3} maxLength={500} value={fieldValue(values, field)} aria-invalid={Boolean(message)} aria-describedby={message ? `${id}-error` : undefined}
                                    onChange={(event) => setValues((prev) => updated(prev, field.group, field.key, event.target.value))} className={inputClass} />
                                    : <input id={id} name={name} type={field.type ?? 'text'} maxLength={field.type === 'url' ? 300 : undefined} value={fieldValue(values, field)} aria-invalid={Boolean(message)} aria-describedby={message ? `${id}-error` : undefined}
                                        onChange={(event) => setValues((prev) => updated(prev, field.group, field.key, event.target.value))} className={inputClass} />}
                                {field.hint && <p className="mt-1 text-xs text-slate-500">{field.hint}</p>}
                                {message && <p id={`${id}-error`} className="mt-1 text-xs font-semibold text-red-700" role="alert">{message}</p>}
                            </div>;
                        })}
                    </div>
                </section>)}
                {errors.expected_version && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{errors.expected_version} Reload before saving again.</p>}
                {saved && <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">Draft saved successfully. Public settings remain unchanged.</p>}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
                    <span className="text-xs text-slate-500">Publication controls and rollback arrive in P4A.3. No changes are live yet.</span>
                    <button type="submit" disabled={saving} className="min-h-11 rounded-lg bg-teal-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-teal-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-60">{saving ? 'Saving…' : 'Save private draft'}</button>
                </div>
            </form>
            <section aria-label="Currently published brand" className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                <h2 className="font-semibold text-slate-900">Currently published website name</h2>
                <p className="mt-1 text-slate-800">{published.branding.site_name}</p>
            </section>
        </main>
    </BablysAdminLayout>;
}
