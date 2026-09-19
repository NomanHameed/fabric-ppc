import { afterEach, describe, expect, it, vi } from 'vitest';
import { initializeLocaleSync, setTranslations, t, useI18n } from '@/lib/i18n';

function navigate(props: Record<string, unknown>): void {
    document.dispatchEvent(
        new CustomEvent('inertia:navigate', {
            detail: {
                page: {
                    component: 'Dashboard',
                    props,
                    url: '/',
                    version: null,
                },
            },
        }),
    );
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('locale sync on navigate', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('loads the page locale when it differs from the loaded one (e.g. after login)', async () => {
        setTranslations('en', 'ltr', { app: { name: 'Fabric PPC' } }, 'v-en');
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ app: { name: 'فیبرک' } }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const off = initializeLocaleSync();

        navigate({ locale: 'ur', dir: 'rtl', i18nVersion: 'v-ur' });
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/i18n/ur.json?v=v-ur');
        expect(useI18n().locale.value).toBe('ur');
        expect(document.documentElement.dir).toBe('rtl');
        expect(t('app.name')).toBe('فیبرک');

        navigate({ locale: 'ur', dir: 'rtl', i18nVersion: 'v-ur' });
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        off();
    });

    it('reloads the dictionary when only the translation version changes', async () => {
        setTranslations('en', 'ltr', {}, 'v1');
        const fetchMock = vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);
        const off = initializeLocaleSync();

        navigate({ locale: 'en', dir: 'ltr', i18nVersion: 'v2' });
        await flush();

        expect(fetchMock).toHaveBeenCalledWith(
            '/i18n/en.json?v=v2',
            expect.anything(),
        );
        off();
    });
});
