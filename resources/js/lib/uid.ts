let counter = 0;

/**
 * A client-side row id (`_uid`) for detail-grid rows: AG Grid keys its row nodes by it (`getRowId`), so a row keeps
 * its node — focus, edit state, row classes — across every `rowData` change, and a page finds its model row again
 * after an `await` by uid rather than by index or object identity (ag-grid-vue3 copies every row object it is given).
 */
export function newUid(): string {
    counter += 1;
    return `r${Date.now().toString(36)}-${counter.toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}
