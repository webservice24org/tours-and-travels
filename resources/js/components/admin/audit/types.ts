export type AuditEvent = {
    id: string;
    actor_id: number | null;
    actor_name: string;
    action: string;
    subject_type: string;
    subject_id: string | null;
    occurred_at: string;
};

export type AuditDetail = AuditEvent & {
    metadata: Record<string, unknown> | unknown[];
    ip_address: string | null;
    user_agent: string | null;
};

export type AuditPageMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type AuditPageResponse = { data: AuditEvent[]; meta: AuditPageMeta };
export type AuditDetailResponse = { data: AuditDetail };
export type AuditOptionsResponse = { data: { actions: string[]; subject_types: string[] } };
