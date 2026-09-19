import {
    AllCommunityModule,
    ModuleRegistry,
    themeQuartz,
} from 'ag-grid-community';
import type { ColDef } from 'ag-grid-community';

let registered = false;

export function registerAgGrid(): void {
    if (!registered) {
        ModuleRegistry.registerModules([AllCommunityModule]);
        registered = true;
    }
}

/** Compact, app-token-driven theme; colours come from CSS variables so light/dark both work. */
export const gridTheme = themeQuartz.withParams({
    fontFamily: 'inherit',
    fontSize: 13,
    rowHeight: 30,
    headerHeight: 32,
    spacing: 5,
    backgroundColor: 'var(--card)',
    foregroundColor: 'var(--card-foreground)',
    headerBackgroundColor: 'var(--muted)',
    borderColor: 'var(--border)',
    accentColor: 'var(--primary)',
    oddRowBackgroundColor: 'color-mix(in oklab, var(--muted) 35%, transparent)',
});

export type ColumnDef<T = Record<string, unknown>> = ColDef<T>;
