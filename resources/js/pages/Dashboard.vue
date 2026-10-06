<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';
import ChartPlaceholder from '@/components/charts/ChartPlaceholder.vue';
import SummaryBars from '@/components/charts/SummaryBars.vue';
import type { ChartContext } from '@/components/charts/options';
import {
    departmentProductionOption,
    flowOption,
    formatMetres,
    utilisationOption,
    utilisationTone,
    wipStageOption,
} from '@/components/charts/options';
import { useChartTheme } from '@/components/charts/useChartTheme';
import { useRangeFilters } from '@/components/charts/useRangeFilters';
import type { RangePreset } from '@/components/charts/useRangeFilters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { can } from '@/lib/can';
import { getJson, isAbortError } from '@/lib/api';
import { formatDmy } from '@/lib/dates';
import { t, useI18n } from '@/lib/i18n';
import type { DashboardAnalytics } from '@/types/analytics';

/**
 * ECharts is 595 kB (201 kB gzipped) and only one of the two renderings on this page needs it: a phone gets
 * `SummaryBars`, and a role without `inquiry.view` gets no chart at all. Loading it lazily keeps it out of the
 * dashboard's own chunk, so that cost is paid by the people who actually see a canvas. `options.ts` imports
 * `EChartsOption` as a type only, which is what lets the split work at all.
 */
const BaseChart = defineAsyncComponent({
    loader: () => import('@/components/charts/BaseChart.vue'),
    loadingComponent: ChartPlaceholder,
});

/**
 * The mill's dashboard: what was made, by whom, where the metres are now, and how committed the machines are.
 *
 * The standing counts and the last import arrive as Inertia props (Foundation's DashboardController owns them).
 * Everything the filters touch is fetched from `GET /production/analytics`, so a range change re-runs the
 * queries on the server instead of narrowing a dataset the browser already holds.
 */
type DashboardStats = {
    open_lots: number;
    open_contracts: number;
    open_greige_qty: number;
    parties: number;
    missing_from_oracle: number;
};

type LastImport = {
    id: number;
    label: string;
    committed_at: string;
    uploader: string | null;
    new_count: number;
    changed_count: number;
    missing_count: number;
};

const props = defineProps<{
    stats: DashboardStats;
    lastImport: LastImport | null;
}>();

const { dir, locale } = useI18n();
const { palette } = useChartTheme();
const range = useRangeFilters({ preset: 'month' });

// A canvas that has to carry a date axis, a legend and several stacked series needs room; below this width
// the same figures are shown as totals instead of as an illegible smear.
const wide = useMediaQuery('(min-width: 640px)');

const data = ref<DashboardAnalytics | null>(null);
const loading = ref(false);
const failed = ref(false);
/** True when the server ran a shorter range than the one asked for (it caps at a year). */
const clamped = ref(false);

/**
 * Request sequencing.
 *
 * Every filter control re-issues the whole read, and the server takes longer over a quarter than over a week,
 * so responses do not come back in the order they were asked for. Without a generation counter a slow "last 90
 * days" landing after a fast "this week" would paint the quarter's figures under the week's filters — silently
 * wrong, and indistinguishable from a bug in the query. `seq` is the only thing a response is allowed to be
 * judged by; the previous request is also aborted so it stops costing anything at all.
 */
let seq = 0;
let inFlight: AbortController | null = null;

async function load(): Promise<void> {
    const mine = ++seq;
    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;
    loading.value = true;
    failed.value = false;
    try {
        const res = await getJson<DashboardAnalytics>(
            `/production/analytics?${range.query()}`,
            controller.signal,
        );
        if (mine !== seq) {
            return;
        }
        data.value = res;
        // The inputs must show the range the figures are actually for, not the one that was asked for.
        clamped.value = range.adopt(res.range.from, res.range.to);
    } catch (error) {
        if (mine !== seq || isAbortError(error)) {
            return;
        }
        failed.value = true;
    } finally {
        if (mine === seq) {
            loading.value = false;
        }
    }
}

onMounted(() => void load());
// Department and process names come back translated, so a language change has to re-ask the server for them.
watch(locale, () => void load());

function apply(): void {
    range.apply();
    void load();
}

function reset(): void {
    range.reset();
    void load();
}

function preset(name: RangePreset): void {
    range.setPreset(name);
    apply();
}

const charts = computed<ChartContext>(() => ({
    palette: palette.value,
    dir: dir.value,
    labels: {
        metres: t('dashboard.axis.metres'),
        date: t('dashboard.axis.date'),
        allocated: t('production.capacity.legend.allocated'),
        capacity: t('production.capacity.legend.capacity'),
        notSet: t('production.capacity.not_set'),
        other: t('dashboard.charts.other'),
    },
    formatDate: (iso: string) => formatDmy(new Date(`${iso}T00:00:00`)),
}));

const metres = (value: number): string =>
    t('dashboard.metres', { qty: formatMetres(value) });

const rangeLabel = computed(() => {
    const range = data.value?.range;

    return range === undefined
        ? ''
        : t('dashboard.filters.range', {
              from: formatDmy(new Date(`${range.from}T00:00:00`)),
              to: formatDmy(new Date(`${range.to}T00:00:00`)),
          });
});

const kpis = computed(() => {
    const k = data.value?.kpi;
    return (['produced', 'issued', 'dispatched', 'loss', 'wip'] as const).map(
        (key) => ({
            key,
            label: t(`dashboard.kpi.${key}`),
            value: k === undefined ? '—' : metres(k[key]),
        }),
    );
});

// Processes are listed under the department they belong to, so picking one never contradicts the other box.
const processes = computed(() => {
    const all = data.value?.filters.operations ?? [];
    const dept = range.filters.department_id;
    return dept === ''
        ? all
        : all.filter((o) => String(o.department_id) === dept);
});

const departmentTotals = computed(() =>
    (data.value?.by_department ?? [])
        .filter((s) => s.total !== 0)
        .sort((a, b) => b.total - a.total),
);
const grandTotal = computed(() =>
    departmentTotals.value.reduce((sum, s) => sum + s.total, 0),
);

const departmentBars = computed(() =>
    departmentTotals.value.map((s) => ({
        key: s.key,
        name: s.name,
        value: metres(s.total),
        percent:
            grandTotal.value === 0 ? 0 : (s.total / grandTotal.value) * 100,
        tone: 'brand' as const,
    })),
);

const wipBars = computed(() => {
    const stages = data.value?.wip_by_stage ?? [];
    const max = Math.max(0, ...stages.map((s) => s.qty));
    return stages.map((s) => ({
        key: s.key,
        name: s.name,
        value: metres(s.qty),
        percent: max === 0 ? 0 : (s.qty / max) * 100,
        tone: 'brand' as const,
    }));
});

const machineBars = computed(() =>
    (data.value?.utilisation?.machines ?? []).map((m) => ({
        key: m.code,
        name: `${m.code} — ${m.name}`,
        value:
            m.utilisation_pct === null
                ? t('production.capacity.not_set')
                : `${formatMetres(m.utilisation_pct)}%`,
        percent: m.utilisation_pct,
        tone: utilisationTone(m.utilisation_pct),
        note: m.utilisation_pct === null ? metres(m.allocated_qty) : undefined,
    })),
);

const hasProduction = computed(() => departmentTotals.value.length > 0);
const hasFlow = computed(() =>
    (data.value?.flow ?? []).some((s) => s.total !== 0),
);
const chartedMachines = computed(
    () =>
        (data.value?.utilisation?.machines ?? []).filter(
            (m) => m.capacity_qty !== null && m.capacity_qty > 0,
        ).length,
);
const uncharted = computed(
    () =>
        (data.value?.utilisation?.machines.length ?? 0) - chartedMachines.value,
);

const standing = computed(() => [
    {
        key: 'open_lots',
        label: t('dashboard.open_lots'),
        value: props.stats.open_lots.toLocaleString('en-US'),
    },
    {
        key: 'open_contracts',
        label: t('dashboard.open_contracts'),
        value: props.stats.open_contracts.toLocaleString('en-US'),
    },
    {
        key: 'open_greige',
        label: t('dashboard.open_greige'),
        value: metres(props.stats.open_greige_qty),
    },
    {
        key: 'parties',
        label: t('dashboard.parties'),
        value: props.stats.parties.toLocaleString('en-US'),
    },
]);

// The page renders for everyone; the charts are a production read, so a role without it keeps the counts only.
const canRead = computed(() => can('inquiry.view'));
const canCapacity = computed(() => can('production.sheet.view'));

const flowBars = computed(() =>
    (data.value?.flow ?? []).map((s) => ({
        key: s.key,
        name: s.name,
        value: metres(s.total),
        percent: null,
    })),
);
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div class="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:p-4">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-lg font-semibold">{{ t('dashboard.title') }}</h1>
            <span
                v-if="rangeLabel"
                class="text-muted-foreground text-xs tabular-nums"
            >
                {{ rangeLabel }}
            </span>
            <div class="ms-auto flex flex-wrap items-center gap-2">
                <Link
                    v-if="canRead"
                    href="/production/timeline"
                    class="text-primary text-sm underline"
                    >{{ t('dashboard.links.timeline') }}</Link
                >
                <Link
                    v-if="canCapacity"
                    href="/production/capacity"
                    class="text-primary text-sm underline"
                    >{{ t('dashboard.links.capacity') }}</Link
                >
            </div>
        </div>

        <template v-if="canRead">
            <!-- filters: every one of these is a query parameter the server runs, not a client-side slice -->
            <Card>
                <CardContent class="flex flex-wrap items-end gap-3 pt-4">
                    <div
                        class="flex min-w-[8.5rem] flex-1 flex-col gap-1 sm:flex-none"
                    >
                        <Label
                            for="f-from"
                            class="text-muted-foreground text-xs"
                            >{{ t('dashboard.filters.from') }}</Label
                        >
                        <Input
                            id="f-from"
                            v-model="range.filters.from"
                            type="date"
                            class="tabular-nums"
                        />
                    </div>
                    <div
                        class="flex min-w-[8.5rem] flex-1 flex-col gap-1 sm:flex-none"
                    >
                        <Label
                            for="f-to"
                            class="text-muted-foreground text-xs"
                            >{{ t('dashboard.filters.to') }}</Label
                        >
                        <Input
                            id="f-to"
                            v-model="range.filters.to"
                            type="date"
                            class="tabular-nums"
                        />
                    </div>
                    <div class="flex min-w-[10rem] flex-1 flex-col gap-1">
                        <Label
                            for="f-dept"
                            class="text-muted-foreground text-xs"
                            >{{ t('dashboard.filters.department') }}</Label
                        >
                        <select
                            id="f-dept"
                            v-model="range.filters.department_id"
                            class="bg-background h-9 rounded-md border px-2 text-sm"
                            @change="range.filters.operation_id = ''"
                        >
                            <option value="">
                                {{ t('dashboard.filters.all_departments') }}
                            </option>
                            <option
                                v-for="d in data?.filters.departments ?? []"
                                :key="d.id"
                                :value="String(d.id)"
                            >
                                {{ d.name }}
                            </option>
                        </select>
                    </div>
                    <div class="flex min-w-[10rem] flex-1 flex-col gap-1">
                        <Label
                            for="f-process"
                            class="text-muted-foreground text-xs"
                            >{{ t('dashboard.filters.process') }}</Label
                        >
                        <select
                            id="f-process"
                            v-model="range.filters.operation_id"
                            class="bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            <option value="">
                                {{ t('dashboard.filters.all_processes') }}
                            </option>
                            <option
                                v-for="o in processes"
                                :key="o.id"
                                :value="String(o.id)"
                            >
                                {{ o.name }}
                            </option>
                        </select>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            :disabled="loading"
                            data-test="apply"
                            @click="apply"
                        >
                            {{ t('dashboard.filters.apply') }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="loading"
                            data-test="reset"
                            @click="reset"
                        >
                            {{ t('dashboard.filters.reset') }}
                        </Button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-for="p in ['week', 'month', 'quarter'] as const"
                            :key="p"
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="loading"
                            :data-test="`preset-${p}`"
                            @click="preset(p)"
                        >
                            {{ t(`dashboard.filters.presets.${p}`) }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <p
                v-if="clamped"
                class="border-warning/50 bg-warning-surface text-warning-on-surface rounded-md border px-3 py-2 text-sm"
                data-test="range-clamped"
                role="status"
            >
                {{ t('production.capacity.clamped', { days: 366 }) }}
            </p>

            <div
                v-if="failed"
                class="border-destructive/50 bg-destructive-surface text-destructive-on-surface flex flex-wrap items-center gap-3 rounded-md border px-3 py-2 text-sm"
                data-test="charts-failed"
            >
                <span>{{ t('dashboard.charts.failed') }}</span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    data-test="charts-retry"
                    @click="load"
                >
                    {{ t('dashboard.charts.retry') }}
                </Button>
            </div>

            <!-- what the range says -->
            <div class="grid auto-rows-min gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Card v-for="k in kpis" :key="k.key">
                    <CardHeader class="pb-1">
                        <CardTitle
                            class="text-muted-foreground text-xs font-medium"
                            >{{ k.label }}</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-xl font-semibold tabular-nums sm:text-2xl"
                        >
                            {{ k.value }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="grid min-w-0 gap-4 xl:grid-cols-2">
                <Card class="min-w-0">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm">{{
                            t('dashboard.charts.by_department')
                        }}</CardTitle>
                        <p class="text-muted-foreground text-xs">
                            {{ t('dashboard.charts.by_department_hint') }}
                        </p>
                    </CardHeader>
                    <CardContent class="min-w-0">
                        <BaseChart
                            v-if="wide"
                            :option="
                                departmentProductionOption(
                                    data?.dates ?? [],
                                    data?.by_department ?? [],
                                    charts,
                                )
                            "
                            :label="t('dashboard.charts.by_department')"
                            :height="280"
                            :empty="!hasProduction"
                            :empty-text="
                                loading
                                    ? t('dashboard.charts.loading')
                                    : t('dashboard.charts.empty')
                            "
                        />
                        <!-- Also rendered, visually hidden, when the chart is drawn: a canvas has no
                             accessible content, so this list is what a screen reader reads. -->
                        <div :class="wide ? 'sr-only' : ''">
                            <SummaryBars
                                :rows="departmentBars"
                                :empty-text="
                                    loading
                                        ? t('dashboard.charts.loading')
                                        : t('dashboard.charts.empty')
                                "
                            />
                            <p
                                v-if="!wide"
                                class="text-muted-foreground mt-3 text-xs"
                            >
                                {{ t('dashboard.charts.summary_view') }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm">{{
                            t('dashboard.charts.flow')
                        }}</CardTitle>
                        <p class="text-muted-foreground text-xs">
                            {{ t('dashboard.charts.flow_hint') }}
                        </p>
                    </CardHeader>
                    <CardContent class="min-w-0">
                        <BaseChart
                            v-if="wide"
                            :option="
                                flowOption(
                                    data?.dates ?? [],
                                    data?.flow ?? [],
                                    charts,
                                )
                            "
                            :label="t('dashboard.charts.flow')"
                            :height="280"
                            :empty="!hasFlow"
                            :empty-text="
                                loading
                                    ? t('dashboard.charts.loading')
                                    : t('dashboard.charts.empty')
                            "
                        />
                        <div :class="wide ? 'sr-only' : ''">
                            <SummaryBars
                                :rows="flowBars"
                                :empty-text="
                                    loading
                                        ? t('dashboard.charts.loading')
                                        : t('dashboard.charts.empty')
                                "
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm">{{
                            t('dashboard.charts.wip')
                        }}</CardTitle>
                        <p class="text-muted-foreground text-xs">
                            {{ t('dashboard.charts.wip_hint') }}
                        </p>
                    </CardHeader>
                    <CardContent class="min-w-0">
                        <BaseChart
                            v-if="wide"
                            :option="
                                wipStageOption(data?.wip_by_stage ?? [], charts)
                            "
                            :label="t('dashboard.charts.wip')"
                            :height="
                                Math.max(
                                    200,
                                    (data?.wip_by_stage.length ?? 3) * 34 + 40,
                                )
                            "
                            :empty="(data?.wip_by_stage.length ?? 0) === 0"
                            :empty-text="
                                loading
                                    ? t('dashboard.charts.loading')
                                    : t('dashboard.charts.empty')
                            "
                        />
                        <div :class="wide ? 'sr-only' : ''">
                            <SummaryBars
                                :rows="wipBars"
                                :empty-text="
                                    loading
                                        ? t('dashboard.charts.loading')
                                        : t('dashboard.charts.empty')
                                "
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="data?.utilisation" class="min-w-0">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm">{{
                            t('dashboard.charts.utilisation')
                        }}</CardTitle>
                        <p class="text-muted-foreground text-xs">
                            {{ t('dashboard.charts.utilisation_hint') }}
                        </p>
                    </CardHeader>
                    <CardContent class="min-w-0">
                        <BaseChart
                            v-if="wide"
                            :option="
                                utilisationOption(
                                    data.utilisation.machines,
                                    charts,
                                )
                            "
                            :label="t('dashboard.charts.utilisation')"
                            :height="Math.max(200, chartedMachines * 30 + 60)"
                            :empty="chartedMachines === 0"
                            :empty-text="t('production.capacity.not_set_hint')"
                        />
                        <div :class="wide ? 'sr-only' : ''">
                            <SummaryBars
                                :rows="machineBars"
                                :empty-text="t('production.capacity.empty')"
                            />
                        </div>
                        <p
                            v-if="uncharted > 0"
                            class="text-muted-foreground mt-3 text-xs"
                        >
                            {{ t('dashboard.charts.no_capacity_charted') }}
                        </p>
                        <Link
                            href="/production/capacity"
                            class="text-primary mt-2 inline-block text-sm underline"
                            >{{ t('dashboard.links.capacity') }}</Link
                        >
                    </CardContent>
                </Card>
            </div>
        </template>

        <!-- standing counts and the Oracle import: not range-bound, so they sit apart from the charts -->
        <div class="grid auto-rows-min gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <Card v-for="tile in standing" :key="tile.key">
                <CardHeader class="pb-1">
                    <CardTitle
                        class="text-muted-foreground text-xs font-medium"
                        >{{ tile.label }}</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ tile.value }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-1">
                    <CardTitle
                        class="text-muted-foreground text-xs font-medium"
                        >{{ t('dashboard.missing_from_oracle') }}</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Link
                        v-if="stats.missing_from_oracle > 0"
                        href="/oracle/imports"
                        class="inline-block"
                    >
                        <Badge
                            variant="outline"
                            class="border-warning/60 bg-warning-surface text-warning-on-surface text-base font-semibold tabular-nums"
                        >
                            {{
                                stats.missing_from_oracle.toLocaleString(
                                    'en-US',
                                )
                            }}
                        </Badge>
                    </Link>
                    <p v-else class="text-xl font-semibold tabular-nums">
                        {{ stats.missing_from_oracle.toLocaleString('en-US') }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">{{
                    t('dashboard.last_import')
                }}</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="lastImport" class="flex flex-col gap-2">
                    <Link
                        :href="`/oracle/imports/${lastImport.id}`"
                        class="font-medium hover:underline"
                    >
                        {{ lastImport.label }}
                    </Link>
                    <p class="text-muted-foreground text-sm">
                        {{ lastImport.committed_at }} —
                        {{ lastImport.uploader }}
                    </p>
                    <div class="flex flex-wrap gap-4 text-sm tabular-nums">
                        <span
                            >{{ t('oracle.actions.new') }}:
                            {{
                                lastImport.new_count.toLocaleString('en-US')
                            }}</span
                        >
                        <span
                            >{{ t('oracle.actions.changed') }}:
                            {{
                                lastImport.changed_count.toLocaleString('en-US')
                            }}</span
                        >
                        <span
                            >{{ t('oracle.actions.missing') }}:
                            {{
                                lastImport.missing_count.toLocaleString('en-US')
                            }}</span
                        >
                    </div>
                </div>
                <div v-else class="flex flex-col items-start gap-3">
                    <p class="text-muted-foreground text-sm">
                        {{ t('dashboard.no_import') }}
                    </p>
                    <Button v-if="can('oracle.import.view')" as-child size="sm">
                        <Link href="/oracle/imports">{{
                            t('dashboard.go_to_import')
                        }}</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
