export interface StaffUser {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    cms_staff_enabled: boolean;
    role_keys: string[];
    created_at: string | null;
}

export interface RoleOption {
    key: string;
    name: string;
    description: string;
}

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface StaffListResponse {
    data: StaffUser[];
    meta: PageMeta;
}

export type StaffStatusFilter = 'all' | 'active' | 'pending' | 'disabled' | 'customer';
export type StaffApiResponse = { data: StaffUser; message?: string };
export type Notice = { kind: 'success' | 'error'; message: string };
