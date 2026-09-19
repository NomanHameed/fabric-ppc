import { describe, expect, it } from 'vitest';
import { useQueryMode } from '@/composables/useQueryMode';

describe('useQueryMode', () => {
    it('enters query mode with cleared criteria and returns non-blank criteria', () => {
        const q = useQueryMode<{ code: string | null; name: string | null }>([
            'code',
            'name',
        ]);
        q.criteria.code = 'old';
        q.enterQuery();
        expect(q.mode.value).toBe('enterQuery');
        expect(q.criteria.code).toBeNull();
        q.criteria.code = '2610%';
        q.criteria.name = '  ';
        expect(q.takeCriteria()).toEqual({ code: '2610%' });
        expect(q.mode.value).toBe('normal');
    });

    it('cancels back to normal', () => {
        const q = useQueryMode<{ code: string | null }>(['code']);
        q.enterQuery();
        q.cancelQuery();
        expect(q.mode.value).toBe('normal');
    });
});
