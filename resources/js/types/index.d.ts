export interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    email_verified_at?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    unreadNotifications: number;
    pushPublicKey?: string | null;
    notificationPreview: Array<{ id: number; type: string; title: string; body: string; action_url?: string; read_at?: string | null; created_at: string }>;
    auth: {
        user: User;
        permissions: string[];
    };
};
