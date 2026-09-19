import { beforeEach, describe, expect, it } from 'vitest';
import { statusLine } from '@/lib/statusLine';

describe('statusLine', () => {
    beforeEach(() => statusLine.reset());

    it('tracks record position, message and dirty flag', () => {
        statusLine.setRecord(3, 18);
        statusLine.setMessage('Saved', 'success');
        statusLine.setDirty(true);
        expect(statusLine.state).toMatchObject({ recordIndex: 3, recordCount: 18, message: 'Saved', kind: 'success', dirty: true });
    });

    it('resets', () => {
        statusLine.setHint('hint');
        statusLine.reset();
        expect(statusLine.state.hint).toBe('');
    });
});
