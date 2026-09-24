<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import SummaryBars from '@/components/charts/SummaryBars.vue';
import type { ChartContext } from '@/components/charts/options';
import {
    formatMetres,
    utilisationOption,
    utilisationTone,
} from '@/components/charts/options';
import { useChartTheme } from '@/components/charts/useChartTheme';
import { useRangeFilters } from '@/components/charts/useRangeFilters';
import type { RangePreset } from '@/components/charts/useRangeFilters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getJson } from '@/lib/api';
import { can } from '@/lib/can';
import { formatDmy } from '@/lib/dates';
import { t, useI18n } from '@/lib/i18n';
import type {
    DepartmentOption,
    UtilisationResponse,
    UtilisationRow,
} from '@/types/analytics';

/**
 * Machine capacity against what has been committed to it, over a date range.
 *
 * Three things this screen must not do, because each would invent a number:
 *
 *  - A machine with no capacity on file reads "not set", never 0% and never 100%. It stays in the table and
 *    drops out of the chart, because a bar has to have a length and there is no honest one to give it.
 *  - Over 100% is shown as it is. A mill that has promised 130% of a stenter needs to see 130%, not a full bar.
 *  - Allocation is a *plan*. `machine_allocations` never touches the ledger, so nothing here is presented as
 *    metres actually run — that is the production sheet's and the dashboard's business.
 */
const props = defineProps<{ departments: DepartmentOption[] }>();

const { dir, locale } = useI18n();
const { palette } = useChartTheme();
const range = useRangeFilters({ preset: 'month' });
const wide = useMediaQuery('(min-width: 640px)');

const data = ref<UtilisationResponse | null>(null);
const loading = ref(false);
const failed = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        data.value = await getJson<UtilisationResponse>(
            `/production/utilisation?${range.query()}`,
        );
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());
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
    },
    formatDate: (iso: string) => formatDmy(new Date(`${iso}T00:00:00`)),
}));

const machines = computed<UtilisationRow[]>(() => data.value?.machines ?? []);
const departments = computed<UtilisationRow[]>(
    () => data.value?.departments ?? [],
);
const charted = computed(
    () =>
        machines.value.filter(
            (m) => m.capacity_qty !== null && m.capacity_qty > 0,
        ).length,
);

const rangeLabel = computed(() => {
    const first = machines.value[0] ?? departments.value[0];
    if (data.value === null) {
        return '';
    }
    return t('production.capacity.range', {
        from: formatDmy(new Date(`${data.value.range.from}T00:00:00`)),
        to: formatDmy(new Date(`${data.value.range.to}T00:00:00`)),
        days: first?.days ?? 0,
    });
});

const TONE_BADGE: Record<string, string> = {
    over: 'border-destructive/60 bg-destructive-surface text-destructive-on-surface',
    high: 'border-warning/60 bg-warning-surface text-warning-on-surface',
    good: 'border-success/60 bg-success-surface text-success-on-surface',
    low: 'border-info/60 bg-info-surface text-info-on-surface',
    unknown: 'text-muted-foreground',
};

const percent = (row: UtilisationRow): string =>
    row.utilisation_pct === null
        ? t('production.capacity.not_set')
        : `${formatMetres(row.utilisation_pct)}%`;

const qty = (value: number | null): string =>
    value === null ? t('production.capacity.not_set') : formatMetres(value);

const machineBars = computed(() =>
    machines.value.map((m) => ({
        key: m.code,
        name: `${m.code} — ${m.name}`,
        value: percent(m),
        percent: m.utilisation_pct,
        tone: utilisationTone(m.utilisation_pct),
        note:
            m.utilisation_pct === null
                ? t('production.capacity.summary_unknown', {
                      allocated: formatMetres(m.allocated_qty),
                  })
                : undefined,
    })),
);
</script>

<template>
    <Head :title="t('production.capacity.title')" />

    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-lg font-semibold">
                {{ t('production.capacity.title') }}
            </h1>
            <span
                v-if="rangeLabel"
                class="text-muted-foreground text-xs tabular-nums"
            >
                {{ rangeLabel }}
            </span>
            <Link
                v-if="can('masters.view')"
                href="/masters/machines"
                class="text-primary ms-auto text-sm underline"
                >{{ t('production.capacity.edit_machines') }}</Link
            >
        </div>

        <Card>
            <CardContent class="flex flex-wrap items-end gap-3 pt-4">
                <div
                    class="flex min-w-[8.5rem] flex-1 flex-col gap-1 sm:flex-none"
                >
                    <Label for="u-from" class="text-muted-foreground text-xs">{{
                        t('production.capacity.from')
                    }}</Label>
                    <Input
                        id="u-from"
                        v-model="range.filters.from"
                        type="date"
                        class="tabular-nums"
                    />
                </div>
                <div
                    class="flex min-w-[8.5rem] flex-1 flex-col gap-1 sm:flex-none"
                >
                    <Label for="u-to" class="text-muted-foreground text-xs">{{
                        t('production.capacity.to')
                    }}</Label>
                    <Input
                        id="u-to"
                        v-model="range.filters.to"
                        type="date"
                        class="tabular-nums"
                    />
                </div>
                <div class="flex min-w-[10rem] flex-1 flex-col gap-1">
                    <Label for="u-dept" class="text-muted-foreground text-xs">{{
                        t('production.capacity.department')
                    }}</Label>
                    <select
                        id="u-dept"
                        v-model="range.filters.department_id"
                        class="bg-background h-9 rounded-md border px-2 text-sm"
                    >
                        <option value="">
                            {{ t('production.capacity.all_departments') }}
                        </option>
                        <option
                            v-for="d in props.departments"
                            :key="d.id"
                            :value="String(d.id)"
                        >
                            {{ d.name }}
                        </option>
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="loading"
                        @click="apply"
                    >
                        {{ t('production.capacity.apply') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="loading"
                        @click="reset"
                    >
                        {{ t('production.capacity.reset') }}
                    </Button>
                    <Button
                        v-for="p in ['week', 'month', 'quarter'] as const"
                        :key="p"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="loading"
                        @click="preset(p)"
                    >
                        {{ t(`dashboard.filters.presets.${p}`) }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="failed"
            class="border-destructive/50 bg-destructive-surface text-destructive-on-surface flex flex-wrap items-center gap-3 rounded-md border px-3 py-2 text-sm"
        >
            <span>{{ t('production.capacity.failed') }}</span>
            <Button type="button" size="sm" variant="outline" @click="load">
                {{ t('production.capacity.retry') }}
            </Button>
        </div>

        <!-- departments first: it is the level a planner commits work at -->
        <Card class="min-w-0">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">{{
                    t('production.capacity.departments')
                }}</CardTitle>
            </CardHeader>
            <CardContent class="min-w-0">
                <SummaryBars
                    :rows="
                        departments.map((d) => ({
                            key: d.code,
                            name: d.name,
                            value: percent(d),
                            percent: d.utilisation_pct,
                            tone: utilisationTone(d.utilisation_pct),
                            note:
                                d.utilisation_pct === null
                                    ? t('production.capacity.summary_unknown', {
                                          allocated: formatMetres(
                                              d.allocated_qty,
                                          ),
                                      })
                                    : undefined,
                        }))
                    "
                    :empty-text="
                        loading
                            ? t('production.capacity.loading')
                            : t('production.capacity.empty')
                    "
                />
            </CardContent>
        </Card>

        <Card class="min-w-0">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">{{
                    t('production.capacity.chart')
                }}</CardTitle>
            </CardHeader>
            <CardContent class="min-w-0">
                <BaseChart
                    v-if="wide"
                    :option="utilisationOption(machines, charts)"
                    :label="t('production.capacity.chart')"
                    :height="Math.max(200, charted * 30 + 60)"
                    :empty="charted === 0"
                    :empty-text="
                        loading
                            ? t('production.capacity.loading')
                            : t('production.capacity.not_set_hint')
                    "
                />
                <!-- Not rendered beside the chart on a wide screen: the full machine table below already
                     carries every one of these figures as real text. -->
                <SummaryBars
                    v-if="!wide"
                    :rows="machineBars"
                    :empty-text="
                        loading
                            ? t('production.capacity.loading')
                            : t('production.capacity.empty')
                    "
                />
            </CardContent>
        </Card>

        <Card class="min-w-0">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">{{
                    t('production.capacity.machines')
                }}</CardTitle>
            </CardHeader>
            <CardContent class="min-w-0">
                <!-- the table scrolls in its own box; the page never scrolls sideways -->
                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-muted-foreground border-b text-xs">
                                <th class="px-2 py-2 text-start font-medium">
                                    {{ t('production.capacity.columns.code') }}
                                </th>
                                <th class="px-2 py-2 text-start font-medium">
                                    {{ t('production.capacity.columns.name') }}
                                </th>
                                <th class="px-2 py-2 text-end font-medium">
                                    {{
                                        t(
                                            'production.capacity.columns.allocated',
                                        )
                                    }}
                                </th>
                                <th class="px-2 py-2 text-end font-medium">
                                    {{
                                        t(
                                            'production.capacity.columns.capacity',
                                        )
                                    }}
                                </th>
                                <th class="px-2 py-2 text-end font-medium">
                                    {{ t('production.capacity.columns.free') }}
                                </th>
                                <th class="px-2 py-2 text-end font-medium">
                                    {{
                                        t(
                                            'production.capacity.columns.utilisation',
                                        )
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="m in machines"
                                :key="m.id"
                                class="border-b last:border-0"
                            >
                                <td class="px-2 py-2 font-medium">
                                    {{ m.code }}
                                </td>
                                <td class="px-2 py-2">{{ m.name }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">
                                    {{ formatMetres(m.allocated_qty) }}
                                </td>
                                <td class="px-2 py-2 text-end tabular-nums">
                                    {{ qty(m.capacity_qty) }}
                                </td>
                                <td class="px-2 py-2 text-end tabular-nums">
                                    {{ qty(m.free_qty) }}
                                </td>
                                <td class="px-2 py-2 text-end">
                                    <Badge
                                        variant="outline"
                                        class="font-normal tabular-nums"
                                        :class="
                                            TONE_BADGE[
                                                utilisationTone(
                                                    m.utilisation_pct,
                                                )
                                            ]
                                        "
                                        :title="
                                            m.utilisation_pct === null
                                                ? t(
                                                      'production.capacity.not_set_hint',
                                                  )
                                                : undefined
                                        "
                                    >
                                        {{ percent(m) }}
                                    </Badge>
                                </td>
                            </tr>
                            <tr v-if="machines.length === 0">
                                <td
                                    colspan="6"
                                    class="text-muted-foreground px-2 py-6 text-center"
                                >
                                    {{
                                        loading
                                            ? t('production.capacity.loading')
                                            : t('production.capacity.empty')
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="machines.length > charted"
                    class="text-muted-foreground mt-3 text-xs"
                >
                    {{ t('production.capacity.not_set_hint') }}
                </p>
            </CardContent>
        </Card>
    </div>
</template>
