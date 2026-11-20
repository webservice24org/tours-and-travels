export type DestinationStatus = 'draft' | 'in_review' | 'published' | 'archived';
export type StatusFilter = 'all' | DestinationStatus;
export type CoordinatesFilter = 'all' | 'yes' | 'no';

export interface Destination {
    id: number;
    name: string;
    slug: string;
    country_code: string;
    region_name: string | null;
    city_name: string | null;
    summary: string | null;
    description: string | null;
    latitude: string | null;
    longitude: string | null;
    status: DestinationStatus;
    sort_order: number;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface DestinationListResponse { data: Destination[]; meta: PaginationMeta }
export interface DestinationResponse { data: Destination }
export type FieldErrors = Record<string, string[]>;
