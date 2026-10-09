<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useQueryMode } from '@/composables/useQueryMode';
import type { FormsAction } from '@/forms/keymap';
import { formatQty } from '@/lib/dates';
import {
    FLAT_LOT_COLUMNS,
    FLAT_LOT_TOTAL_FIELDS,
    flatLotAlignClass,
    flatLotCell,
    flatLotHeader,
} from '@/lib/flatLotColumns';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type {
    FabricReportHeader,
    FabricReportMonth,
    FabricReportParams,
    FabricReportProcessFilter,
    FabricReportStage,
    FabricReportTotals,
} from '@/types/production';

/**
 * R1 — the Month Wise Fabric Report (spec §7.1, §4.3): the mill's headline report, on screen.
 *
 * **Oracle Reports conventions** (§4.3): a header block naming the company, the report, the parameters it
 * was run with, when it was run and by whom; a group header per stage, a subtotal per stage and a grand
 * total. Two totals levels, not three: resolution is stage-only, so a lot has a stage and nothing finer.
 * Dates are dd-mm-yyyy, every quantity is right-aligned tabular figures, and the whole layout uses logical
 * properties so Urdu mirrors cleanly.
 *
 * **Oracle Forms shell** (§4.2), as every other screen has: the toolbar with Print and Exit live and
 * Save / New / Delete off (a report writes nothing), F7 to enter the parameters and F8 to run them, and the
 * status line carrying the row count. There is no record form and no editable field on this screen, so there
 * is nothing for `useUnsavedGuard` to guard — the same as the read-only POs screen.
 *
 * **The body is the flat, Oracle-shaped lot payload**, under the mill's own field names, with its 21 visible
 * columns declared once in `@/lib/flatLotColumns` and shared with the Lots screen's flat grid. An HTML table
 * rather than AG Grid, because a report interleaves group headers and subtotal rows with its rows.
 *
 * **The totals are not the sum of the rows on screen.** They are summed over every match in the database,
 * while the rows are capped at `rowCap` across the whole report. When more lots match than the cap allows, a
 * stage can print a total with fewer rows (or none) beneath it — which would read as a bug — so the
 * truncation is named in full, and the status line says "n of m".
 *
 * Excel and PDF export are **not built yet** (spec §7.1 asks for both). Print works: the toolbar's Print
 * hands the page to the browser, and the print stylesheet below drops the chrome.
 */
const props = defineProps<{
    stages: FabricReportStage[];
    totals: FabricReportTotals;
    /** Flat rows actually listed, after the global cap. */
    rowsShown: number;
    /** Lots matching the parameters, whether or not the cap let them be listed. */
    rowsTotal: number;
    rowCap: number;
    months: FabricReportMonth[];
    params: FabricReportParams;
    criteria: Record<string, string>;
    header: FabricReportHeader;
}>();

/**
 * The parameters entered as criteria, over `ProductionAnalyticsService::LOT_CRITERIA`. Month and Process are
 * not here: they are discrete choices, so they are selects that re-run the report at once rather than boxes
 * to type into.
 */
const CRITERIA_FIELDS = [
    'party',
    'po_type_oracle',
    'design_oracle_id',
    'stage',
] as const;

/** The label each criterion box carries. `design_oracle_id` is the Oracle design code the flat payload shows. */
const CRITERIA_LABELS: Record<(typeof CRITERIA_FIELDS)[number], string> = {
    party: 'production.fields.party',
    po_type_oracle: 'production.fields.po_type_oracle',
    design_oracle_id: 'production.flat.design_oracle_id',
    stage: 'production.fields.stage',
};

/** The Process parameter's choices, in the spec's order; `ProductionAnalyticsService::PROCESSES`. */
const PROCESSES: FabricReportProcessFilter[] = ['pending', 'complete', 'all'];

const query = useQueryMode<Record<string, string | null>>([...CRITERIA_FIELDS]);
const criteria = query.criteria; // the boxes in query mode; `props.criteria` is what the server ran
const inQuery = computed(() => query.mode.value === 'enterQuery');

/** The six quantity columns are the last six, so the totals' label spans everything before them. */
const TOTAL_LABEL_SPAN = FLAT_LOT_COLUMNS.length - FLAT_LOT_TOTAL_FIELDS.length;

/**
 * More matched than the cap let us list. Not a cosmetic detail: it is the only thing that explains a stage
 * whose subtotal has no rows beneath it.
 */
const truncated = computed(() => props.rowsTotal > props.rowsShown);

const truncationNotice = computed(() =>
    t('production.fabric_report.truncated', {
        cap: props.rowCap,
        n: props.rowsShown,
        m: props.rowsTotal,
    }),
);

const showing = computed(() =>
    t('production.fabric_report.showing', {
        n: props.rowsShown,
        m: props.rowsTotal,
    }),
);

/** Only groups that hold lots are printed, as a report does — an empty stage is not a row of zeros. */
const visibleStages = computed(() => props.stages.filter((s) => s.lots > 0));

/** The parameters the report was run with, for the header block (spec §4.3). */
const monthLabel = computed(
    () =>
        props.months.find((m) => m.value === props.params.month)?.label ??
        t('production.fabric_report.all'),
);

const parametersUsed = computed<{ label: string; value: string }[]>(() => {
    const used = [
        {
            label: t('production.fabric_report.month'),
            value: monthLabel.value,
        },
        {
            label: t('production.fields.process'),
            value: t(
                `production.fabric_report.process.${props.params.process}`,
            ),
        },
    ];
    for (const field of CRITERIA_FIELDS) {
        const value = props.criteria[field];
        if (typeof value === 'string' && value.trim() !== '') {
            used.push({
                label: t(CRITERIA_LABELS[field]),
                value: value.trim(),
            });
        }
    }
    return used;
});

// The status line is this screen's record counter: rows listed of lots matched.
watch(
    [() => props.rowsShown, () => props.rowsTotal],
    ([shown, total]) => {
        statusLine.setRecord(shown, total);
        statusLine.setHint(truncated.value ? showing.value : '');
    },
    { immediate: true },
);

/**
 * Run the report. One GET with the parameters in the URL, so a report is a link someone can keep: the
 * screen's whole state is its query string.
 */
function run(
    params: Partial<FabricReportParams> = {},
    q: Partial<Record<string, string | null>> = props.criteria,
): void {
    const month =
        params.month === undefined ? props.params.month : params.month;
    const process =
        params.process === undefined ? props.params.process : params.process;
    router.get(
        '/production/reports/fabric',
        {
            q,
            ...(month === null || month === '' ? {} : { month }),
            process,
        },
        {
            only: [
                'stages',
                'totals',
                'rowsShown',
                'rowsTotal',
                'params',
                'criteria',
                'header',
            ],
            preserveState: true,
            preserveScroll: true,
            // a criterion the server rejected must tell the user, not silently drop query mode
            onError: (errors) => {
                statusLine.setMessage(Object.values(errors)[0] ?? '', 'error');
                query.enterQuery();
            },
        },
    );
}

function executeQuery(): void {
    run({}, inQuery.value ? query.takeCriteria() : props.criteria);
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'enterQuery':
            query.enterQuery();
            break;
        case 'executeQuery':
            executeQuery();
            break;
        case 'clear':
            query.cancelQuery();
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

function openLot(id: number): void {
    router.visit(`/inquiry/lots/${id}`);
}

/** A total cell's value: metres, fixed 2 dp, right-aligned by the column's own class. */
const totalOf = (
    totals: FabricReportTotals,
    key: (typeof FLAT_LOT_TOTAL_FIELDS)[number],
): string => formatQty(totals[key]);
</script>

<template>
    <Head :title="t('production.fabric_report.title')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <!--
          The Oracle Reports header block (spec §4.3): company, report title, the parameters it was run
          with, when it was run and by whom. Printed with the report, which is why it is a real block on the
          page and not a toolbar caption.
        -->
        <header
            data-testid="report-header"
            class="bg-card rounded-md border px-3 py-2"
        >
            <p class="text-sm font-semibold" data-testid="report-company">
                {{ header.company }}
            </p>
            <h1 class="text-lg font-semibold">
                {{ t('production.fabric_report.title') }}
            </h1>
            <dl
                class="text-muted-foreground mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs"
                data-testid="report-params"
            >
                <div
                    v-for="p in parametersUsed"
                    :key="p.label"
                    class="flex gap-1"
                >
                    <dt>{{ p.label }}:</dt>
                    <dd class="text-foreground font-medium">{{ p.value }}</dd>
                </div>
            </dl>
            <p
                class="text-muted-foreground mt-1 flex flex-wrap gap-x-4 text-xs"
            >
                <span class="tabular-nums"
                    >{{ t('production.fabric_report.run_at') }}:
                    {{ header.run_at }}</span
                >
                <span
                    >{{ t('production.fabric_report.user') }}:
                    {{ header.user }}</span
                >
                <span class="tabular-nums" data-testid="report-showing">{{
                    showing
                }}</span>
            </p>
        </header>

        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{
                save: true,
                newRecord: true,
                deleteRecord: true,
                previousRecord: true,
                nextRecord: true,
                clear: !inQuery,
            }"
            @action="onAction"
        />

        <!-- Month and Process: discrete choices, so they run the report as soon as they change. -->
        <div
            class="bg-card flex flex-wrap items-end gap-3 rounded-md border p-3"
        >
            <div class="flex min-w-40 flex-col gap-1">
                <Label for="fr-month" class="text-muted-foreground text-xs">{{
                    t('production.fabric_report.month')
                }}</Label>
                <select
                    id="fr-month"
                    data-testid="param-month"
                    class="bg-background h-9 rounded-md border px-2 text-sm"
                    :value="params.month ?? ''"
                    @change="
                        run({
                            month:
                                ($event.target as HTMLSelectElement).value ||
                                null,
                        })
                    "
                >
                    <option value="">
                        {{ t('production.fabric_report.all') }}
                    </option>
                    <option v-for="m in months" :key="m.value" :value="m.value">
                        {{ m.label }}
                    </option>
                </select>
            </div>
            <div class="flex min-w-40 flex-col gap-1">
                <Label for="fr-process" class="text-muted-foreground text-xs">{{
                    t('production.fields.process')
                }}</Label>
                <select
                    id="fr-process"
                    data-testid="param-process"
                    class="bg-background h-9 rounded-md border px-2 text-sm"
                    :value="params.process"
                    @change="
                        run({
                            process: ($event.target as HTMLSelectElement)
                                .value as FabricReportProcessFilter,
                        })
                    "
                >
                    <option v-for="p in PROCESSES" :key="p" :value="p">
                        {{ t(`production.fabric_report.process.${p}`) }}
                    </option>
                </select>
            </div>
            <p class="text-muted-foreground ms-auto max-w-xl text-xs">
                {{ t('production.fabric_report.query_hint') }}
            </p>
        </div>

        <!-- The rest of the parameters, as Forms criteria: F7 opens them, F8 runs them. -->
        <FormBlock v-if="inQuery" :title="t('production.fabric_report.params')">
            <FormField
                v-for="f in CRITERIA_FIELDS"
                :key="f"
                :label="t(CRITERIA_LABELS[f])"
                :for="`q-${f}`"
                :span="3"
            >
                <Input
                    :id="`q-${f}`"
                    v-model="criteria[f] as string"
                    class="border-warning/70"
                    autocomplete="off"
                />
            </FormField>
        </FormBlock>

        <!--
          The truncation, named in full. Without this, a stage subtotal with no rows beneath it reads as a
          bug rather than as a capped report.
        -->
        <p
            v-if="truncated"
            data-testid="truncation-notice"
            class="bg-warning-surface text-warning-on-surface rounded-md border px-3 py-2 text-xs"
        >
            {{ truncationNotice }}
        </p>

        <div
            v-if="visibleStages.length > 0"
            class="bg-card overflow-x-auto rounded-md border"
        >
            <table
                class="w-full border-collapse text-xs tabular-nums"
                data-testid="report-table"
            >
                <thead class="bg-muted/60">
                    <tr>
                        <th
                            v-for="c in FLAT_LOT_COLUMNS"
                            :key="c.field"
                            scope="col"
                            class="border-b px-2 py-1 font-medium whitespace-nowrap"
                            :class="flatLotAlignClass(c.kind)"
                        >
                            {{ flatLotHeader(c.field) }}
                        </th>
                    </tr>
                </thead>
                <tbody
                    v-for="stage in visibleStages"
                    :key="stage.code"
                    class="border-b"
                >
                    <!-- group header: the stage -->
                    <tr class="bg-muted/40">
                        <th
                            scope="colgroup"
                            :colspan="FLAT_LOT_COLUMNS.length"
                            class="border-y px-2 py-1 text-start font-semibold"
                            :data-testid="`stage-head-${stage.code}`"
                        >
                            {{ t('production.fields.stage') }}:
                            {{ stage.name }}
                            <span class="text-muted-foreground font-normal">
                                ({{ t('production.fabric_report.lots') }}
                                {{ stage.lots }})
                            </span>
                        </th>
                    </tr>

                    <tr
                        v-for="row in stage.rows"
                        :key="row.lot_id"
                        :data-testid="`lot-row-${row.lot_id}`"
                        class="hover:bg-muted/40 cursor-default"
                        @dblclick="openLot(row.lot_id)"
                    >
                        <td
                            v-for="c in FLAT_LOT_COLUMNS"
                            :key="c.field"
                            class="px-2 py-0.5 whitespace-nowrap"
                            :class="[
                                flatLotAlignClass(c.kind),
                                c.cellClass ?? '',
                            ]"
                        >
                            {{ flatLotCell(row, c) }}
                        </td>
                    </tr>

                    <!-- the cap hid this stage's rows; its subtotal still stands -->
                    <tr v-if="stage.rows.length === 0">
                        <td
                            :colspan="FLAT_LOT_COLUMNS.length"
                            class="text-muted-foreground px-2 py-1 text-start italic"
                            :data-testid="`stage-empty-${stage.code}`"
                        >
                            {{ t('production.fabric_report.empty_stage') }}
                        </td>
                    </tr>

                    <!-- subtotal row: this stage -->
                    <tr
                        class="bg-muted/30 border-t-2 font-semibold"
                        :data-testid="`stage-total-${stage.code}`"
                    >
                        <td
                            :colspan="TOTAL_LABEL_SPAN"
                            class="px-2 py-1 text-end"
                        >
                            {{
                                t('production.fabric_report.stage_total', {
                                    stage: stage.name,
                                })
                            }}
                        </td>
                        <td
                            v-for="f in FLAT_LOT_TOTAL_FIELDS"
                            :key="f"
                            class="px-2 py-1 text-end tabular-nums"
                            :data-testid="`stage-total-${stage.code}-${f}`"
                        >
                            {{ totalOf(stage, f) }}
                        </td>
                    </tr>
                </tbody>

                <!-- grand total -->
                <tfoot>
                    <tr
                        class="bg-muted/60 border-t-2 font-bold"
                        data-testid="grand-total"
                    >
                        <td
                            :colspan="TOTAL_LABEL_SPAN"
                            class="px-2 py-1 text-end"
                        >
                            {{ t('production.fabric_report.grand_total') }}
                            <span class="font-normal">
                                ({{ t('production.fabric_report.lots') }}
                                {{ totals.lots }})
                            </span>
                        </td>
                        <td
                            v-for="f in FLAT_LOT_TOTAL_FIELDS"
                            :key="f"
                            class="px-2 py-1 text-end tabular-nums"
                            :data-testid="`grand-total-${f}`"
                        >
                            {{ totalOf(totals, f) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-else class="text-muted-foreground text-sm" data-testid="no-rows">
            {{ t('production.fabric_report.none') }}
        </p>

        <p
            v-if="visibleStages.length > 0"
            class="text-muted-foreground text-xs"
        >
            {{ t('production.fabric_report.open_lot') }}
        </p>
    </div>
</template>

<style scoped>
/*
 * Print is the one export this screen has (Excel and PDF are not built yet), so it has to produce something
 * a planner can hand over: the report block and the table, without the app's controls.
 */
@media print {
    [role='toolbar'],
    select {
        display: none;
    }
    table {
        font-size: 9pt;
    }
}
</style>
