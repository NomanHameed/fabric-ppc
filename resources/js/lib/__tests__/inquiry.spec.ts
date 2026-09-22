import { beforeEach, describe, expect, it } from 'vitest';
import { setTranslations } from '@/lib/i18n';
import { auditChanges, positionLabel, showingText } from '@/lib/inquiry';

beforeEach(() => {
    setTranslations('en', 'ltr', {
        production: {
            positions: {
                stock: 'Greige in stock',
                issued: 'Issued to floor',
                step: 'At route step',
                finished: 'Finished',
            },
        },
        inquiry: { lot: { showing: 'Showing :n of :m' } },
    });
});

describe('positionLabel', () => {
    const steps = [
        { id: 7, seq: 10, operation: 'SINGING' },
        { id: 9, seq: 20, operation: 'DRY' },
    ];

    it('translates the fixed positions and names a step by seq and operation', () => {
        expect(positionLabel('STOCK', steps)).toBe('Greige in stock');
        expect(positionLabel('ISSUED', steps)).toBe('Issued to floor');
        expect(positionLabel('FINISHED', steps)).toBe('Finished');
        expect(positionLabel('STEP:9', steps)).toBe('20. DRY');
    });

    it('falls back to the bare id for a removed step and a dash for no position', () => {
        expect(positionLabel('STEP:42', steps)).toBe('At route step #42');
        expect(positionLabel(null, steps)).toBe('—');
        expect(positionLabel('', steps)).toBe('—');
    });
});

describe('auditChanges', () => {
    it('lists old → new per field, new values on create and old on delete', () => {
        expect(
            auditChanges({
                old: { state: 'open', hold_reason: null },
                new: { state: 'hold', hold_reason: 'PARTY_HOLD' },
            }),
        ).toEqual(['state: open → hold', 'hold_reason: — → PARTY_HOLD']);
        expect(
            auditChanges({ old: {}, new: { seq: 10, is_added: true } }),
        ).toEqual(['seq: 10', 'is_added: true']);
        expect(auditChanges({ old: { seq: 10 }, new: {} })).toEqual([
            'seq: 10 → —',
        ]);
    });
});

describe('showingText', () => {
    it('says showing n of m only when rows were cut off', () => {
        expect(showingText(200, 350)).toBe('Showing 200 of 350');
        expect(showingText(5, 5)).toBe('');
    });
});
