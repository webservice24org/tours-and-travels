export type WritableCategoryStatus = 'draft' | 'in_review' | 'published' | 'archived';
export type CategoryStatusFilter = 'all' | WritableCategoryStatus;

export interface TourCategory {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    summary: string | null;
    description: string | null;
    status: WritableCategoryStatus;
    sort_order: number;
    children_count: number;
}

export interface CategoryOption {
    id: number;
    name: string;
    parent_id: number | null;
    status: WritableCategoryStatus;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface CategoryListResponse {
    data: TourCategory[];
    meta: PaginationMeta;
}
export interface CategoryResponse { data: TourCategory }
export interface CategoryOptionsResponse { data: CategoryOption[] }
export type FieldErrors = Record<string, string[]>;
