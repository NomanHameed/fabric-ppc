<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import LovField from '@/components/forms/LovField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type { ImportAction, ImportDetail, ImportRow } from '@/types/oracle';

const props = defineProps<{
    import: ImportDetail;
    tab: ImportAction;
    rows: ImportRow[];
    canRun: boolean;
}>();

const tabs: ImportAction[] = [
    'error',
    'new',
    'changed',
    'missing',
    'unchanged',
];
const count = (a: ImportAction) => props.import[`${a}_count` as const];
const resolutions = reactive<
    Record<
        number,
        { partyId: number | null; display: string; type: 'brand' | 'local' }
    >
>({});
const busy = computed(() => props.import.status === 'committing');

const poll = usePoll(2000, { only: ['import'] }, { autoStart: false });
const polling = ref(false);
// Set once the commit request succeeds: keep polling until the job reports committed or failed,
// even if a poll still shows the import as committing (or, briefly, previewed).
const awaitingResult = ref(false);
const finished = computed(
    () =>
        props.import.status === 'committed' || props.import.status === 'failed',
);

function startPolling(): void {
    if (!polling.value) {
        polling.value = true;
        poll.start();
    }
}

function stopPolling(): void {
    polling.value = false;
    poll.stop();
}

if (busy.value) {
    startPolling();
}

function openTab(tab: ImportAction): void {
    router.get(
        `/oracle/imports/${props.import.id}`,
        { tab },
        { only: ['rows', 'tab'], preserveState: true, preserveScroll: true },
    );
}

function resolutionFor(code: number) {
    return (
        resolutions[code] ?? {
            partyId: null,
            display: '',
            type: 'brand' as const,
        }
    );
}

function resolve(code: number, mode: 'link' | 'create'): void {
    const r = resolutionFor(code);
    router.post(
        `/oracle/imports/${props.import.id}/parties/${code}`,
        mode === 'link'
            ? { mode, party_id: r.partyId }
            : { mode, type: r.type },
        {
            preserveScroll: true,
            onSuccess: () => statusLine.setMessage(t('forms.saved'), 'success'),
            onError: (errors) =>
                statusLine.setMessage(
                    errors.import ?? errors.party_id ?? errors.code ?? '',
                    'error',
                ),
        },
    );
}

function commit(): void {
    router.post(
        `/oracle/imports/${props.import.id}/commit`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                awaitingResult.value = true;
                startPolling();
            },
            onError: (errors) =>
                statusLine.setMessage(errors.import ?? '', 'error'),
        },
    );
}

function onNavigated(): void {
    if (busy.value || (awaitingResult.value && !finished.value)) {
        startPolling();

        return;
    }
    stopPolling();
    awaitingResult.value = false;
    if (props.import.status === 'committed') {
        statusLine.setMessage(
            t('oracle.committed', {
                new: props.import.new_count,
                changed: props.import.changed_count,
                missing: props.import.missing_count,
            }),
            'success',
        );
    } else if (props.import.status === 'failed' && props.import.error_message) {
        statusLine.setMessage(props.import.error_message, 'error');
    }
}
onBeforeUnmount(() => stopPolling());
const off = router.on('success', onNavigated);
onBeforeUnmount(() => off());

const diffText = (d: ImportRow['diff']) =>
    d
        ? Object.entries(d)
              .map(([k, [a, b]]) => `${t(`oracle.fields.${k}`)}: ${a} → ${b}`)
              .join('; ')
        : '';

const columns = computed<ColumnDef<ImportRow>[]>(() => [
    { field: 'row_no', headerName: t('oracle.fields.row_no'), width: 70 },
    { field: 'lot_no', headerName: t('oracle.fields.lot_no'), width: 100 },
    { field: 'po_no', headerName: t('oracle.fields.po_no'), width: 100 },
    { field: 'party', headerName: t('oracle.fields.party'), flex: 2 },
    { field: 'design', headerName: t('oracle.fields.design'), flex: 1 },
    { field: 'colour', headerName: t('oracle.fields.colour'), flex: 1 },
    { field: 'quality', headerName: t('oracle.fields.quality'), flex: 3 },
    {
        field: 'greige_qty',
        headerName: t('oracle.fields.greige_qty'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: (p) =>
            p.value == null ? '' : Number(p.value).toLocaleString('en-US'),
    },
    props.tab === 'changed'
        ? {
              colId: 'diff',
              headerName: t('oracle.fields.diff'),
              flex: 3,
              valueGetter: (p) => diffText(p.data?.diff ?? null),
          }
        : { field: 'message', headerName: t('oracle.fields.message'), flex: 2 },
]);
</script>

<template>
    <Head :title="`${t('oracle.title')} — ${props.import.label}`" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-lg font-semibold">{{ props.import.label }}</h1>
            <Badge variant="outline">{{
                t(`oracle.status.${props.import.status}`)
            }}</Badge>
            <span class="text-muted-foreground text-xs"
                >{{ props.import.uploader }} ·
                {{ props.import.created_at }}</span
            >
            <div
                v-if="canRun && props.import.status === 'previewed'"
                class="ms-auto flex gap-2"
            >
                <Button
                    variant="outline"
                    @click="
                        router.post(
                            `/oracle/imports/${props.import.id}/discard`,
                        )
                    "
                    >{{ t('oracle.discard') }}</Button
                >
                <Button
                    :disabled="props.import.error_count > 0"
                    @click="commit"
                    >{{ t('oracle.commit') }}</Button
                >
            </div>
        </div>

        <p v-if="props.import.error_message" class="text-destructive text-sm">
            {{ props.import.error_message }}
        </p>

        <FormBlock
            v-if="props.import.unresolved_parties.length && canRun"
            :title="t('oracle.unresolved')"
        >
            <p class="text-muted-foreground col-span-12 text-xs">
                {{ t('oracle.unresolved_hint') }}
            </p>
            <div
                v-for="p in props.import.unresolved_parties"
                :key="p.code"
                class="col-span-12 grid grid-cols-12 items-center gap-2 border-t pt-2"
            >
                <div class="col-span-12 text-sm md:col-span-4">
                    <span class="font-medium">{{ p.name }}</span>
                    <span
                        class="text-muted-foreground ms-2 text-xs tabular-nums"
                        >#{{ p.code }} · {{ p.rows }}</span
                    >
                </div>
                <div class="col-span-12 md:col-span-4">
                    <LovField
                        name="parties"
                        :model-value="resolutionFor(p.code).partyId"
                        :display="resolutionFor(p.code).display"
                        @update:model-value="
                            (v) =>
                                (resolutions[p.code] = {
                                    ...resolutionFor(p.code),
                                    partyId: v,
                                })
                        "
                        @update:display="
                            (v) =>
                                (resolutions[p.code] = {
                                    ...resolutionFor(p.code),
                                    display: v,
                                })
                        "
                    />
                </div>
                <div class="col-span-12 flex gap-2 md:col-span-4">
                    <Button
                        size="sm"
                        :disabled="!resolutionFor(p.code).partyId"
                        @click="resolve(p.code, 'link')"
                        >{{ t('oracle.link_to') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="resolve(p.code, 'create')"
                        >{{ t('oracle.create_party') }}</Button
                    >
                </div>
            </div>
        </FormBlock>

        <div role="tablist" class="flex flex-wrap gap-1 border-b">
            <button
                v-for="a in tabs"
                :key="a"
                role="tab"
                :aria-selected="tab === a"
                class="-mb-px border-b-2 px-3 py-1.5 text-sm tabular-nums"
                :class="
                    tab === a
                        ? 'border-primary font-medium'
                        : 'text-muted-foreground hover:text-foreground border-transparent'
                "
                @click="openTab(a)"
            >
                {{ t(`oracle.actions.${a}`) }}
                <span
                    class="bg-muted ms-1 rounded px-1.5 text-xs"
                    :class="
                        a === 'error' && count(a) > 0
                            ? 'bg-destructive/15 text-destructive'
                            : ''
                    "
                    >{{ count(a) }}</span
                >
            </button>
        </div>

        <DataGrid :rows="rows" :columns="columns" />
    </div>
</template>
