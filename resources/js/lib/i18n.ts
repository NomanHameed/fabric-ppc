import { router } from '@inertiajs/vue3';
import type { App } from 'vue';
import { computed, reactive } from 'vue';

type Dict = { [key: string]: string | Dict };
type Dir = 'ltr' | 'rtl';

const state = reactive<{ locale: string; dir: Dir; dict: Dict }>({ locale: 'en', dir: 'ltr', dict: {} });

export function setTranslations(locale: string, dir: Dir, dict: Dict): void {
    state.locale = locale;
    state.dir = dir;
    state.dict = dict;
    if (typeof document !== 'undefined') {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }
}

export function t(key: string, replace: Record<string, string | number> = {}): string {
    const value = key.split('.').reduce<string | Dict | undefined>(
        (acc, part) => (acc !== undefined && typeof acc === 'object' ? acc[part] : undefined),
        state.dict,
    );
    let text = typeof value === 'string' ? value : key;
    for (const [name, v] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, String(v));
    }
    return text;
}

export function useI18n() {
    return { t, locale: computed(() => state.locale), dir: computed(() => state.dir) };
}

export async function loadLocale(locale: string, dir: Dir, version: string): Promise<void> {
    const res = await fetch(`/i18n/${locale}.json?v=${version}`, { headers: { Accept: 'application/json' } });
    setTranslations(locale, dir, (await res.json()) as Dict);
}

export function switchLocale(locale: string): Promise<void> {
    return new Promise((resolve) => {
        router.post('/locale', { locale }, {
            preserveScroll: true,
            onSuccess: async (page) => {
                const props = page.props as unknown as { locale: string; dir: Dir; i18nVersion: string };
                await loadLocale(props.locale, props.dir, props.i18nVersion);
                resolve();
            },
            onFinish: () => resolve(),
        });
    });
}

export const i18n = {
    install(app: App): void {
        const boot = (window as unknown as { __I18N__?: { locale: string; dir: Dir; dict: Dict } }).__I18N__;
        if (boot) {
            setTranslations(boot.locale, boot.dir, boot.dict);
        }
        app.config.globalProperties.$t = t;
    },
};

declare module 'vue' {
    interface ComponentCustomProperties {
        $t: typeof t;
    }
}
