export interface CmsRole {
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_system: boolean;
    user_count: number;
    permission_keys: string[];
    can_edit: boolean;
}

export interface PermissionDefinition {
    key: string;
    module: string;
    label: string;
    description: string;
    assignable: boolean;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface RoleListResponse {
    data: CmsRole[];
    meta: PaginationMeta;
}
export interface RoleResponse { data: CmsRole }
export interface CatalogResponse { data: PermissionDefinition[] }
export type RoleTypeFilter = 'all' | 'system' | 'custom';
