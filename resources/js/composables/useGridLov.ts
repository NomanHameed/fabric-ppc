import type {
    CellKeyDownEvent,
    GridApi,
    ICellRendererParams,
    IRowNode,
} from 'ag-grid-community';
import { ref } from 'vue';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';

export type GridLovFilters = Record<string, string | number | null>;

/** The cell a pick lands on: its row node (and data) and the grid api, whether opened by click or by F9. */
export type GridLovCell<T> = {
    node: IRowNode<T>;
    data: T | undefined;
    api: GridApi<T>;
    rowIndex: number;
};

type Apply<T> = (row: LovRow, cell: GridLovCell<T>) => void;
type Enabled<T> = (cell: GridLovCell<T>) => boolean;
type Spec<T> = {
    lovName: string;
    filters: () => GridLovFilters;
    apply: Apply<T>;
    enabled: Enabled<T>;
};

const BUTTON_CLASS =
    'w-full truncate rounded border border-transparent px-2 py-0.5 text-start text-sm hover:border-input hover:bg-muted disabled:pointer-events-none disabled:opacity-50';
const ICON_CLASS =
    'text-muted-foreground hover:text-foreground hover:bg-muted ms-1 inline-flex size-6 shrink-0 items-center justify-center rounded disabled:pointer-events-none disabled:opacity-50';
const LIST_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h.01M3 12h.01M3 19h.01M8 5h13M8 12h13M8 19h13"/></svg>';

/**
 * One LovDialog shared by every LOV cell of a grid (the RouteTemplates pattern, generalised): a cell renderer
 * draws a button that opens the dialog for that row, F9 in the cell does the same, and the picked row is handed
 * to the `apply` callback of the column the dialog was opened from.
 *
 * Render once per page:
 *   <LovDialog v-model:open="lov.open.value" :name="lov.name.value" :filters="lov.filters.value"
 *              :initial-query="lov.initialQuery.value" @select="lov.select" />
 * and hand the grid's `cell-key-down` to `lov.onCellKeyDown` for F9.
 */
export function useGridLov<T = Record<string, unknown>>() {
    const open = ref(false);
    const name = ref('');
    const filters = ref<GridLovFilters>({});
    const initialQuery = ref('');
    let target: ((row: LovRow) => void) | null = null;
    /** LOV columns by colId, registered when their cells first render. */
    const specs = new Map<string, Spec<T>>();

    function openFor(
        lovName: string,
        lovFilters: GridLovFilters,
        apply: (row: LovRow) => void,
        query = '',
    ): void {
        name.value = lovName;
        filters.value = lovFilters;
        initialQuery.value = query;
        target = apply;
        open.value = true;
    }

    function select(row: LovRow): void {
        const apply = target;
        target = null;
        open.value = false;
        apply?.(row);
    }

    function cellOf(
        p: Pick<ICellRendererParams<T>, 'node' | 'data' | 'api'>,
    ): GridLovCell<T> {
        return {
            node: p.node,
            data: p.data,
            api: p.api,
            rowIndex: p.node.rowIndex ?? -1,
        };
    }

    function openSpec(spec: Spec<T>, cell: GridLovCell<T>, query = ''): void {
        openFor(
            spec.lovName,
            spec.filters(),
            (row) => spec.apply(row, cell),
            query,
        );
    }

    /**
     * Cell renderer factory: `cellRenderer: lov.renderer('operations', () => ({ department_id }), (row, cell) => …)`.
     * The default draws the value as a full-width button (a non-editable column); `inline: true` draws the value
     * as text with a trailing list button, for a column the user may also type into (single-click edit is left to
     * the text, the button only opens the list).
     */
    function renderer(
        lovName: string,
        lovFilters: () => GridLovFilters,
        apply: Apply<T>,
        enabled: Enabled<T> = () => true,
        { inline = false }: { inline?: boolean } = {},
    ) {
        const spec: Spec<T> = { lovName, filters: lovFilters, apply, enabled };
        return (params: ICellRendererParams<T>): HTMLElement => {
            specs.set(params.column?.getColId() ?? lovName, spec);
            const cell = cellOf(params);
            const text = params.value ? String(params.value) : '';
            const on = enabled(cell);
            if (!inline) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = BUTTON_CLASS;
                button.textContent = text || t('forms.select');
                button.disabled = !on;
                button.addEventListener('click', () => openSpec(spec, cell));
                return button;
            }
            const wrap = document.createElement('span');
            wrap.className = 'flex w-full items-center';
            const label = document.createElement('span');
            label.className = 'flex-1 truncate';
            label.textContent = text;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = ICON_CLASS;
            button.innerHTML = LIST_ICON;
            button.setAttribute('aria-label', t('forms.actions.lov'));
            button.tabIndex = -1;
            button.disabled = !on;
            button.addEventListener('click', (e) => {
                e.stopPropagation(); // not a click on the cell: no single-click edit
                openSpec(spec, cell);
            });
            wrap.append(label, button);
            return wrap;
        };
    }

    /** F9 in a registered LOV cell opens its list; returns true when the key was taken. */
    function onCellKeyDown(e: CellKeyDownEvent<T>): boolean {
        const ke = e.event as KeyboardEvent | undefined;
        if (!ke || ke.key !== 'F9') {
            return false;
        }
        const spec = specs.get(e.column.getColId());
        if (!spec) {
            return false;
        }
        const cell = cellOf(e);
        if (!spec.enabled(cell)) {
            return false;
        }
        ke.preventDefault();
        ke.stopPropagation();
        openSpec(spec, cell);
        return true;
    }

    return {
        open,
        name,
        filters,
        initialQuery,
        openFor,
        select,
        renderer,
        onCellKeyDown,
    };
}
