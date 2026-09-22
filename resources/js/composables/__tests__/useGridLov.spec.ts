import type { CellKeyDownEvent, ICellRendererParams } from 'ag-grid-community';
import { describe, expect, it, vi } from 'vitest';
import { useGridLov } from '@/composables/useGridLov';
import { setTranslations } from '@/lib/i18n';

type Row = { operation: string; operation_id: number | null };

function params(
    value: string,
    rowIndex: number,
    colId = 'operation',
): ICellRendererParams<Row> {
    return {
        value,
        data: { operation: value, operation_id: null },
        node: { rowIndex },
        api: {},
        column: { getColId: () => colId },
    } as unknown as ICellRendererParams<Row>;
}

function keyEvent(
    key: string,
    rowIndex: number,
    colId = 'operation',
): CellKeyDownEvent<Row> {
    return {
        event: new KeyboardEvent('keydown', { key, cancelable: true }),
        data: { operation: '', operation_id: null },
        node: { rowIndex },
        api: {},
        column: { getColId: () => colId },
        rowIndex,
    } as unknown as CellKeyDownEvent<Row>;
}

describe('useGridLov', () => {
    it('openFor sets the dialog state and select hands the row to apply once', () => {
        const lov = useGridLov<Row>();
        const apply = vi.fn();
        lov.openFor('lots', { in_stock: 1 }, apply, '1234');
        expect(lov.open.value).toBe(true);
        expect(lov.name.value).toBe('lots');
        expect(lov.filters.value).toEqual({ in_stock: 1 });
        expect(lov.initialQuery.value).toBe('1234');

        const row = { id: 7, code: 'X', label: 'X' };
        lov.select(row);
        expect(apply).toHaveBeenCalledTimes(1);
        expect(apply).toHaveBeenCalledWith(row);
        expect(lov.open.value).toBe(false);
        lov.select(row); // nothing pending: ignored
        expect(apply).toHaveBeenCalledTimes(1);
    });

    it('renderer draws a button that opens the list for its row, disabled when enabled() says so', () => {
        setTranslations('en', 'ltr', { forms: { select: 'Select' } });
        const lov = useGridLov<Row>();
        const apply = vi.fn();
        let on = true;
        const render = lov.renderer(
            'operations',
            () => ({ department_id: 3 }),
            apply,
            () => on,
        );

        const blank = render(params('', 0)) as HTMLButtonElement;
        expect(blank.tagName).toBe('BUTTON');
        expect(blank.textContent).toBe('Select');
        expect(blank.disabled).toBe(false);

        const filled = render(params('SINGING', 1)) as HTMLButtonElement;
        expect(filled.textContent).toBe('SINGING');
        filled.click();
        expect(lov.open.value).toBe(true);
        expect(lov.name.value).toBe('operations');
        expect(lov.filters.value).toEqual({ department_id: 3 });

        const picked = { id: 9, code: 'DRYING', label: 'DRYING' };
        lov.select(picked);
        expect(apply).toHaveBeenCalledWith(
            picked,
            expect.objectContaining({ rowIndex: 1 }),
        );

        on = false;
        const off = render(params('SINGING', 2)) as HTMLButtonElement;
        expect(off.disabled).toBe(true);
    });

    it('inline renderer keeps the text and opens only from its list button', () => {
        const lov = useGridLov<Row>();
        const render = lov.renderer(
            'lots',
            () => ({}),
            vi.fn(),
            () => true,
            { inline: true },
        );
        const el = render(params('123 · 456', 0, 'lot'));
        expect(el.tagName).toBe('SPAN');
        expect(el.querySelector('span')?.textContent).toBe('123 · 456');
        const button = el.querySelector('button')!;
        const outer = vi.fn();
        el.addEventListener('click', outer);
        button.click();
        expect(lov.open.value).toBe(true);
        expect(outer).not.toHaveBeenCalled(); // the click stops at the button: no single-click edit
    });

    it('F9 in a registered LOV cell opens its list; other keys and columns are left alone', () => {
        const lov = useGridLov<Row>();
        const apply = vi.fn();
        const render = lov.renderer('operations', () => ({}), apply);
        render(params('', 0)); // registers the column

        expect(lov.onCellKeyDown(keyEvent('Enter', 0))).toBe(false);
        expect(lov.onCellKeyDown(keyEvent('F9', 0, 'batch_no'))).toBe(false);
        expect(lov.open.value).toBe(false);

        const e = keyEvent('F9', 4);
        expect(lov.onCellKeyDown(e)).toBe(true);
        expect((e.event as KeyboardEvent).defaultPrevented).toBe(true);
        expect(lov.open.value).toBe(true);
        lov.select({ id: 1, code: 'A', label: 'A' });
        expect(apply).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1 }),
            expect.objectContaining({ rowIndex: 4 }),
        );
    });
});
