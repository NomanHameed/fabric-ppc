import { usePage } from '@inertiajs/vue3';

export function can(permission: string): boolean {
    const auth = usePage().props.auth as { permissions?: string[] } | undefined;
    return auth?.permissions?.includes(permission) ?? false;
}

export function useCan() {
    return { can };
}
