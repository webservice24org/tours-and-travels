export type TourStatus = 'draft' | 'in_review' | 'scheduled' | 'published' | 'archived';
export type TourStatusFilter = TourStatus | 'all';
export type FeaturedFilter = 'all' | 'yes' | 'no';

export interface TourRow {
    id: number;
    title: string;
    slug: string;
    summary: string | null;
    status: TourStatus;
    duration_days: number;
    duration_nights: number | null;
    is_featured: boolean;
    sort_order: number;
    scheduled_for: string | null;
    published_at: string | null;
    category_count: number;
    destination_count: number;
}

export interface TourDetail extends TourRow {
    description: string | null;
    category_ids: number[];
    destination_ids: number[];
}

export type FieldErrors = Record<string, string[]>;

export interface TourListResponse {
    data: TourRow[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}
export type TourDetailResponse = { data: TourDetail };

export interface TaxonomyOption {
    id: number;
    name: string;
    slug: string;
    status: TourStatus;
    country_code?: string;
}
export interface TourTaxonomyOptionsResponse {
    categories: TaxonomyOption[];
    destinations: TaxonomyOption[];
}

export interface TourCapabilities {
    create: boolean;
    edit: boolean;
    publish: boolean;
}

export interface TourDraft {
    title: string;
    slug: string;
    summary: string;
    description: string;
    status: TourStatus;
    duration_days: string;
    duration_nights: string;
    is_featured: boolean;
    sort_order: string;
    scheduled_for: string;
    category_ids: number[];
    destination_ids: number[];
}
