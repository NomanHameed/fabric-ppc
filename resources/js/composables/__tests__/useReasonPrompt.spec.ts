import { describe, expect, it } from 'vitest';
import { useReasonPrompt } from '@/composables/useReasonPrompt';

describe('useReasonPrompt', () => {
    it('resolves with the confirmed value, null on cancel, and supersedes an open question', async () => {
        const prompt = useReasonPrompt();
        const first = prompt.ask({ title: 'Override', label: 'Why' });
        expect(prompt.open.value).toBe(true);
        expect(prompt.options.value).toEqual({
            title: 'Override',
            label: 'Why',
        });
        prompt.confirm('Oracle short');
        await expect(first).resolves.toBe('Oracle short');
        expect(prompt.open.value).toBe(false);

        const second = prompt.ask({ title: 'Total', type: 'number' });
        prompt.cancel();
        await expect(second).resolves.toBeNull();

        const third = prompt.ask({ title: 'A' });
        const fourth = prompt.ask({ title: 'B' });
        await expect(third).resolves.toBeNull();
        prompt.confirm('x');
        await expect(fourth).resolves.toBe('x');
    });
});
