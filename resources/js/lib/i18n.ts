import { router } from '@inertiajs/vue3';
import type { App } from 'vue';
import { computed, reactive } from 'vue';

type Dict = { [key: string]: string | Dict };
type Dir = 'ltr' | 'rtl';

const state = reactive<{
    locale: string;
    dir: Dir;
    dict: Dict;
    version: string;
}>({ locale: 'en', dir: 'ltr', dict: {}, version: '' });

export function setTranslations(
    locale: string,
    dir: Dir,
    dict: Dict,
    version?: string,
): void {
    state.locale = locale;
    state.dir = dir;
    state.dict = dict;
    if (version !== undefined) {
        state.version = version;
    }
    if (typeof document !== 'undefined') {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }
}

export function t(
    key: string,
    replace: Record<string, string | number> = {},
): string {
    const value = key
        .split('.')
        .reduce<string | Dict | undefined>(
            (acc, part) =>
                acc !== undefined && typeof acc === 'object'
                    ? acc[part]
                    : undefined,
            state.dict,
        );
    let text = typeof value === 'string' ? value : key;
    for (const [name, v] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, String(v));
    }
    return text;
}

export function useI18n() {
    return {
        t,
        locale: computed(() => state.locale),
        dir: computed(() => state.dir),
    };
}

/**
 * Fetches a locale's dictionary and installs it, unless `apply()` says the result is no longer
 * wanted by the time it arrives (a later switch superseded this one).
 */
export async function loadLocale(
    locale: string,
    dir: Dir,
    version: string,
    apply: () => boolean = () => true,
): Promise<void> {
    const res = await fetch(`/i18n/${locale}.json?v=${version}`, {
        headers: { Accept: 'application/json' },
    });
    if (!res.ok) {
        throw new Error(`Failed to load translations for ${locale}`);
    }
    const dict = (await res.json()) as Dict;
    if (apply()) {
        setTranslations(locale, dir, dict, version);
    }
}

type LocaleProps = { locale?: string; dir?: Dir; i18nVersion?: string };
let inFlight: { key: string; promise: Promise<void> } | null = null;
/** The most recently requested `locale|version`; a response for anything else is discarded. */
let latestKey: string | null = null;

/** Load the page's locale if it differs from the loaded dictionary (locale or translation version). */
export function syncLocale(props: LocaleProps): Promise<void> {
    const { locale, i18nVersion: version } = props;
    if (
        !locale ||
        !version ||
        (locale === state.locale && version === state.version)
    ) {
        return Promise.resolve();
    }
    const key = `${locale}|${version}`;
    if (inFlight?.key === key) {
        return inFlight.promise;
    }
    latestKey = key;
    const promise = loadLocale(
        locale,
        props.dir ?? 'ltr',
        version,
        () => latestKey === key,
    )
        .catch(() => undefined) // keep the current dictionary; the next navigation retries
        .finally(() => {
            if (inFlight?.key === key) {
                inFlight = null;
            }
        });
    inFlight = { key, promise };

    return promise;
}

/**
 * Keep the dictionary in step with the server's locale on every Inertia navigation, so a locale set
 * outside switchLocale (login restoring the user's language, another tab, a server-side change) applies.
 */
export function initializeLocaleSync(): () => void {
    return router.on('navigate', (event) => {
        void syncLocale(event.detail.page.props as unknown as LocaleProps);
    });
}

export function switchLocale(locale: string): Promise<void> {
    return new Promise((resolve) => {
        router.post(
            '/locale',
            { locale },
            {
                preserveScroll: true,
                onSuccess: async (page) => {
                    // The navigate listener may already be loading this locale; share that request.
                    await syncLocale(page.props as unknown as LocaleProps);
                    resolve();
                },
                onFinish: () => resolve(),
            },
        );
    });
}

export const i18n = {
    install(app: App): void {
        const boot = (
            window as unknown as {
                __I18N__?: {
                    locale: string;
                    dir: Dir;
                    dict: Dict;
                    version?: string;
                };
            }
        ).__I18N__;
        if (boot) {
            setTranslations(boot.locale, boot.dir, boot.dict, boot.version);
        }
        app.config.globalProperties.$t = t;
    },
};

declare module 'vue' {
    interface ComponentCustomProperties {
        $t: typeof t;
    }
}
