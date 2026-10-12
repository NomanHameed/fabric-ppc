<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import StageSelect from '@/components/stages/StageSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type { UnmappedStatusRow } from '@/types/production';
import type { Stage } from '@/types/stages';

/**
 * "Unmapped Production Statuses" (spec §10): every status the mill's lots carry that no **stage** claims,
 * with the number of lots on it — and the one action that fixes it.
 *
 * Picking a row opens the mapping block: one Stage select. Confirming adds the status to that stage's
 * aliases and re-resolves the lots carrying it, so the row disappears and its lots land on the chosen stage.
 * Nothing is posted until the operator confirms, and the toast says how many lots moved — a
 * re-classification of historical production is not something to do silently.
 *
 * The statuses the chosen stage already claims are shown, read-only, beside the select: the decision is
 * "does this status belong with those?", and it cannot be made without seeing them.
 *
 * Lots whose stage was pinned by hand are not counted here and are left alone by the mapping.
 */
const props = defineProps<{
    statuses: UnmappedStatusRow[];
    statusesTotal: number;
    lotsAffected: number;
    lotsWithoutStage: number;
}>();

const selected = ref<UnmappedStatusRow | null>(null);
const form = useForm<{ status: string }>({ status: '' });
const stageId = ref<number | null>(null);

/**
 * The active stages, as StageSelect loaded them for its own options — so the picked stage's current
 * aliases can be shown without a second request for the same list.
 */
const stages = ref<Stage[]>([]);
const selectedStage = computed<Stage | null>(
    () => stages.value.find((s) => s.id === stageId.value) ?? null,
);

const showing = computed(() =>
    props.statuses.length < props.statusesTotal
        ? t('production.unmapped.showing', {
              n: props.statuses.length,
              m: props.statusesTotal,
          })
        : '',
);

function pick(row: UnmappedStatusRow): void {
    selected.value = row;
    stageId.value = null;
    form.status = row.label;
    form.clearErrors();
}

function cancel(): void {
    selected.value = null;
    stageId.value = null;
    form.clearErrors();
}

function map(): void {
    if (stageId.value === null) {
        return;
    }
    form.post(`/production/unmapped-statuses/${stageId.value}/aliases`, {
        preserveScroll: true,
        onSuccess: () => cancel(),
        onError: (errors) =>
            statusLine.setMessage(Object.values(errors)[0] ?? '', 'error'),
    });
}

const columns = computed<ColumnDef<UnmappedStatusRow>[]>(() => [
    {
        field: 'label',
        headerName: t('production.unmapped.label'),
        flex: 2,
        cellClass: 'font-mono',
    },
    {
        field: 'normalised',
        headerName: t('production.unmapped.normalised'),
        flex: 2,
        cellClass: 'font-mono',
    },
    {
        field: 'lots',
        headerName: t('production.unmapped.lots'),
        width: 120,
        type: 'numericColumn',
    },
]);
</script>

<template>
    <Head :title="t('production.unmapped.title')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-lg font-semibold">
                {{ t('production.unmapped.title') }}
            </h1>
            <span
                v-if="showing"
                class="text-muted-foreground ms-auto text-xs tabular-nums"
                >{{ showing }}</span
            >
        </div>

        <FormBlock>
            <p class="text-muted-foreground col-span-12 text-xs">
                {{ t('production.unmapped.intro') }}
            </p>
            <div class="col-span-12 flex flex-wrap items-center gap-2">
                <Badge variant="outline" class="tabular-nums">{{
                    t('production.unmapped.summary', {
                        statuses: statusesTotal,
                        lots: lotsAffected,
                    })
                }}</Badge>
                <Badge
                    variant="outline"
                    class="text-muted-foreground tabular-nums"
                    >{{
                        t('production.unmapped.without_stage', {
                            n: lotsWithoutStage,
                        })
                    }}</Badge
                >
                <Link
                    class="text-primary ms-auto text-sm underline"
                    href="/masters/stages"
                    >{{ t('production.unmapped.open_master') }}</Link
                >
            </div>
            <p class="text-muted-foreground col-span-12 text-xs">
                {{ t('production.unmapped.overridden_note') }}
            </p>
        </FormBlock>

        <!-- the mapping action for the picked row -->
        <FormBlock
            v-if="selected"
            :title="
                t('production.unmapped.map_title', { status: selected.label })
            "
        >
            <FormField
                :label="t('production.unmapped.label')"
                :span="3"
                :error="form.errors.status"
            >
                <Input
                    :model-value="selected.label"
                    readonly
                    class="font-mono"
                />
            </FormField>
            <FormField
                :label="t('production.fields.stage')"
                for="map-stage"
                :span="3"
            >
                <StageSelect
                    id="map-stage"
                    v-model="stageId"
                    @loaded="stages = $event"
                />
            </FormField>
            <FormField :label="''" :span="3">
                <div class="flex min-h-9 items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="stageId === null || form.processing"
                        @click="map()"
                        >{{ t('production.unmapped.map_confirm') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.processing"
                        @click="cancel()"
                        >{{ t('production.unmapped.map_cancel') }}</Button
                    >
                </div>
            </FormField>
            <p class="text-muted-foreground col-span-12 text-xs">
                {{
                    t('production.unmapped.map_hint', {
                        alias: selected.normalised ?? '',
                    })
                }}
            </p>
            <!-- what the chosen stage already claims: read-only, so the mapping is not decided blind -->
            <p
                v-if="selectedStage"
                class="text-muted-foreground col-span-12 text-xs"
            >
                <template v-if="selectedStage.status_aliases.length > 0">
                    {{ t('production.unmapped.stage_claims') }}
                    <span class="font-mono">{{
                        selectedStage.status_aliases.join(', ')
                    }}</span>
                </template>
                <template v-else>{{
                    t('production.unmapped.stage_claims_none')
                }}</template>
            </p>
        </FormBlock>

        <template v-if="statuses.length > 0">
            <DataGrid
                :rows="statuses"
                :columns="columns"
                :selected-id="selected?.id ?? null"
                @row-selected="pick"
                @row-activated="
                    (r: UnmappedStatusRow) =>
                        router.visit(`/inquiry/lots/${r.lot_id}`)
                "
            />
            <p class="text-muted-foreground text-xs">
                {{
                    selected
                        ? t('production.unmapped.open_lot')
                        : t('production.unmapped.map_pick')
                }}
            </p>
        </template>
        <template v-else>
            <p class="text-muted-foreground text-sm">
                {{ t('production.unmapped.none') }}
            </p>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="router.visit('/masters/stages')"
                    >{{ t('production.unmapped.open_master') }}</Button
                >
            </div>
        </template>
    </div>
</template>
