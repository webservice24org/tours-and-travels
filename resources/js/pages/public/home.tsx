import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Category = {
    key: string;
    title: string;
    description: string;
};

type Props = {
    brand: {
        name: string;
        shortName: string;
        tagline: string;
    };
    categories: Category[];
};

export default function Home({ brand, categories }: Props) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    return (
        <>
            <Head title={`${brand.shortName} — Tours & Travel`}>
                <meta
                    name="description"
                    content="Explore domestic and international travel experiences with Bably's Travel & Tour."
                />
            </Head>
            <div className="min-h-screen overflow-x-hidden bg-[#f8fbfc] text-slate-900">
                <a
                    href="#main"
                    className="sr-only rounded bg-white px-3 py-2 text-slate-900 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50"
                >
                    Skip to content
                </a>
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4 lg:px-8">
                        <a
                            href="/"
                            aria-label={`${brand.shortName}, home`}
                            className="min-w-0 text-lg font-extrabold tracking-tight text-[#0f5965] sm:text-xl"
                        >
                            Bably's<span className="text-[#e99b45]"> Club</span>
                        </a>
                        <nav className="hidden items-center gap-7 text-sm font-semibold lg:flex" aria-label="Main navigation">
                            <a href="#tour-types" className="hover:text-[#0f7181]">Tour categories</a>
                            <a href="#about" className="hover:text-[#0f7181]">About us</a>
                            <a href="#project-status" className="hover:text-[#0f7181]">Launch progress</a>
                        </nav>
                        <button
                            type="button"
                            className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0f7181] lg:hidden"
                            aria-expanded={mobileMenuOpen}
                            aria-controls="mobile-navigation"
                            onClick={() => setMobileMenuOpen((open) => !open)}
                        >
                            {mobileMenuOpen ? 'Close menu' : 'Menu'}
                        </button>
                    </div>
                    {mobileMenuOpen && (
                        <nav id="mobile-navigation" aria-label="Mobile navigation" className="border-t border-slate-200 px-5 py-4 lg:hidden">
                            <div className="flex flex-col gap-3 text-sm font-semibold">
                                <a href="#tour-types" onClick={() => setMobileMenuOpen(false)}>Tour categories</a>
                                <a href="#about" onClick={() => setMobileMenuOpen(false)}>About us</a>
                                <a href="#project-status" onClick={() => setMobileMenuOpen(false)}>Launch progress</a>
                            </div>
                        </nav>
                    )}
                </header>

                <main id="main">
                    <section className="relative isolate overflow-hidden bg-[#103b49] px-5 py-20 text-white sm:py-28 lg:px-8">
                        <div aria-hidden="true" className="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_84%_18%,#207f90_0%,transparent_45%),radial-gradient(circle_at_8%_90%,#146473_0%,transparent_45%)]" />
                        <div className="mx-auto max-w-7xl">
                            <p className="mb-5 inline-flex rounded-full border border-white/25 bg-white/10 px-4 py-2 text-xs font-semibold tracking-[0.18em] uppercase">
                                Bablys Tours & Travels
                            </p>
                            <h1 className="max-w-3xl text-4xl leading-tight font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                                Explore new places. Create lasting memories.
                            </h1>
                            <p className="mt-6 max-w-2xl text-lg leading-8 text-slate-200">
                                {brand.tagline} A new digital travel experience is taking shape.
                            </p>
                            <a
                                href="#tour-types"
                                className="mt-9 inline-flex min-h-12 items-center justify-center rounded-xl bg-[#f3ab55] px-6 font-semibold text-[#102f38] transition hover:bg-[#ffbd70] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                            >
                                Discover our tour categories
                            </a>
                        </div>
                    </section>

                    <section id="tour-types" aria-labelledby="tour-types-title" className="mx-auto max-w-7xl px-5 py-16 lg:px-8 lg:py-20">
                        <div className="max-w-2xl">
                            <p className="text-sm font-bold tracking-widest text-[#0f7181] uppercase">Explore with Bably's</p>
                            <h2 id="tour-types-title" className="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Travel, your way</h2>
                            <p className="mt-4 text-slate-600">These are category previews only. Tours, departure dates, prices and booking options will be added after content verification.</p>
                        </div>
                        <div className="mt-9 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            {categories.map((category, index) => (
                                <article key={category.key} className="rounded-2xl border border-slate-200 bg-white p-7 shadow-sm">
                                    <span aria-hidden="true" className="inline-flex size-11 items-center justify-center rounded-xl bg-[#e5f5f6] font-extrabold text-[#0f7181]">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    <h3 className="mt-6 text-lg font-bold">{category.title}</h3>
                                    <p className="mt-3 leading-7 text-slate-600">{category.description}</p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section id="about" aria-labelledby="about-title" className="bg-white px-5 py-16 lg:px-8">
                        <div className="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-center lg:gap-16">
                            <div>
                                <p className="text-sm font-bold tracking-widest text-[#0f7181] uppercase">Our direction</p>
                                <h2 id="about-title" className="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">A modern home for every journey</h2>
                            </div>
                            <p className="leading-8 text-slate-600">
                                The Laravel + React rebuild will make travel packages, destinations, itineraries, inquiries, and departure availability manageable through one dedicated platform.
                            </p>
                        </div>
                    </section>

                    <section id="project-status" className="mx-auto max-w-7xl px-5 py-16 lg:px-8">
                        <div className="rounded-2xl border border-[#b8dbe1] bg-[#edf9fa] p-7 sm:p-9">
                            <h2 className="text-xl font-bold text-[#145562]">Initial development foundation</h2>
                            <p className="mt-3 leading-7 text-slate-700">This is a preview shell for local development. It does not accept real bookings or payment information. Existing public-site content remains on the current website until approved migration.</p>
                        </div>
                    </section>
                </main>

                <footer className="bg-[#0d2934] px-5 py-9 text-sm text-slate-300 lg:px-8">
                    <div className="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="font-bold text-white">{brand.name}</p>
                        <p>Local development preview · No live bookings</p>
                    </div>
                </footer>
            </div>
        </>
    );
}
