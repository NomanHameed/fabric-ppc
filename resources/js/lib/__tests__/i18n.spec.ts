import { describe, expect, it } from 'vitest';
import { setTranslations, t, useI18n } from '@/lib/i18n';

describe('i18n', () => {
    it('resolves nested keys and replacements', () => {
        setTranslations('en', 'ltr', {
            forms: { record: 'Record :n of :m' },
            app: { name: 'Fabric PPC' },
        });
        expect(t('app.name')).toBe('Fabric PPC');
        expect(t('forms.record', { n: 3, m: 18 })).toBe('Record 3 of 18');
    });

    it('returns the key when missing', () => {
        setTranslations('en', 'ltr', {});
        expect(t('nope.missing')).toBe('nope.missing');
    });

    it('exposes locale and direction', () => {
        setTranslations('ur', 'rtl', {});
        const { locale, dir } = useI18n();
        expect(locale.value).toBe('ur');
        expect(dir.value).toBe('rtl');
        expect(document.documentElement.dir).toBe('rtl');
    });
});
