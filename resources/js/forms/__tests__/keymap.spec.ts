import { describe, expect, it } from 'vitest';
import { keyLabel, matchAction } from '@/forms/keymap';

const key = (k: string, mods: Partial<KeyboardEventInit> = {}) => new KeyboardEvent('keydown', { key: k, ...mods });

describe('keymap', () => {
    it('maps Oracle Forms function keys', () => {
        expect(matchAction(key('F7'))).toBe('enterQuery');
        expect(matchAction(key('F8'))).toBe('executeQuery');
        expect(matchAction(key('F9'))).toBe('lov');
        expect(matchAction(key('F10'))).toBe('save');
    });

    it('maps modifier combos', () => {
        expect(matchAction(key('s', { ctrlKey: true }))).toBe('save');
        expect(matchAction(key('S', { ctrlKey: true }))).toBe('save');
        expect(matchAction(key('q', { ctrlKey: true }))).toBe('exit');
        expect(matchAction(key('ArrowDown', { ctrlKey: true }))).toBe('newRecord');
        expect(matchAction(key('Delete', { ctrlKey: true }))).toBe('deleteRecord');
        expect(matchAction(key('PageDown'))).toBe('nextRecord');
    });

    it('ignores unmapped keys', () => {
        expect(matchAction(key('a'))).toBeNull();
        expect(matchAction(key('s'))).toBeNull();
        expect(matchAction(key('F6'))).toBeNull();
    });

    it('formats labels', () => {
        expect(keyLabel('save')).toBe('F10 / Ctrl+S');
    });
});
