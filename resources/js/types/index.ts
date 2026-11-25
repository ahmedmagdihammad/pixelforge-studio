import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    isAdmin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    credits: Credits | null;
    flash: { success: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface Credits {
    balance: string;
    held: string;
    available: string;
}

export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min: string;
    estimated_max: string;
    charge: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}
