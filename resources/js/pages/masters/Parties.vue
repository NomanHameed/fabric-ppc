<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import DetailGrid from '@/components/forms/DetailGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import { useQueryMode } from '@/composables/useQueryMode';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { useFlashSelect } from '@/lib/flashSelect';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

type Alias = { id: number; alias: string };
type AliasRow = { alias: string };
type PartyRow = {
    id: number;
    oracle_code: number | null;
    name: string;
    type: string;
    is_active: boolean;
    aliases: Alias[];
};

const props = defineProps<{
    parties: PartyRow[];
    canEdit: boolean;
    criteria: Record<string, string>;
}>();

const typeOptions = [
    { value: 'brand', label: t('masters.options.brand') },
    { value: 'local', label: t('masters.options.local') },
];

function aliasSignature(rows: AliasRow[]): string {
    return JSON.stringify(
        rows.map((r) => (r.alias ?? '').trim()).filter(Boolean),
    );
}

function currentParty(): PartyRow | null {
    return props.parties.find((p) => p.id === currentId.value) ?? null;
}

function headerFrom(p: PartyRow | null): Record<string, unknown> {
    return {
        oracle_code: p?.oracle_code ?? null,
        name: p?.name ?? '',
        type: p?.type ?? 'brand',
        is_active: p ? p.is_active : true,
    };
}

function aliasRowsFrom(p: PartyRow | null): AliasRow[] {
    return p ? p.aliases.map((a) => ({ alias: a.alias })) : [];
}

const currentId = ref<number | null>(props.parties[0]?.id ?? null);
const detailKey = ref(0);
const form = useForm<Record<string, any>>(headerFrom(currentParty()));
const aliasRows = ref<AliasRow[]>(aliasRowsFrom(currentParty()));
const aliasBaseline = ref(aliasSignature(aliasRows.value));
const query = useQueryMode<Record<string, string | null>>([
    'name',
    'oracle_code',
    'type',
]);

function isDirty(): boolean {
    return (
        form.isDirty || aliasSignature(aliasRows.value) !== aliasBaseline.value
    );
}

function load(id: number | null): void {
    currentId.value = id;
    const party = currentParty();
    form.defaults(headerFrom(party));
    form.reset();
    form.clearErrors();
    aliasRows.value = aliasRowsFrom(party);
    aliasBaseline.value = aliasSignature(aliasRows.value);
    detailKey.value += 1;
}

const index = computed(() =>
    props.parties.findIndex((p) => p.id === currentId.value),
);
watch(
    [index, () => props.parties.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);
watch(
    () => isDirty(),
    (d) => statusLine.setDirty(d),
    { immediate: true },
);
const flashSelect = useFlashSelect();
watch(
    [() => props.parties, flashSelect.pending],
    ([, pending], [, wasPending]) => {
        // A just-saved record (flashed selectedId) wins over the current one once it is in the rows.
        // When the rows changed on their own (pending unchanged) and still lack the id, the flash is stale.
        const wanted =
            flashSelect.take((id) => props.parties.some((p) => p.id === id), {
                dropIfMissing: pending === wasPending,
            }) ?? currentId.value;
        load(
            props.parties.find((p) => p.id === wanted)?.id ??
                props.parties[0]?.id ??
                null,
        );
    },
);

const inQuery = computed(() => query.mode.value === 'enterQuery');
const editable = computed(() => props.canEdit && !inQuery.value);
const criteria = query.criteria;

const aliasColumns = computed<ColumnDef<AliasRow>[]>(() => [
    {
        field: 'alias',
        headerName: t('masters.fields.alias'),
        flex: 1,
        editable: editable.value,
    },
]);

const columns = computed<ColumnDef<PartyRow>[]>(() => [
    { field: 'name', headerName: t('masters.fields.name'), flex: 3 },
    {
        field: 'oracle_code',
        headerName: t('masters.fields.oracle_code'),
        flex: 1,
    },
    {
        field: 'type',
        headerName: t('masters.fields.type'),
        flex: 1,
        valueFormatter: (p) => t(`masters.options.${p.value}`),
    },
    {
        field: 'aliases',
        headerName: t('masters.fields.aliases'),
        flex: 2,
        valueGetter: (p) =>
            (p.data?.aliases ?? []).map((a) => a.alias).join(', '),
    },
    {
        field: 'is_active',
        headerName: t('masters.fields.is_active'),
        flex: 1,
        valueFormatter: (p) => (p.value ? '✓' : ''),
    },
]);

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        form.transform((data) => ({
            ...data,
            aliases: aliasRows.value.map((r) => r.alias).filter(Boolean),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                statusLine.setMessage(t('forms.saved'), 'success');
                resolve(true);
            },
            onError: () => {
                statusLine.setMessage(
                    Object.values(form.errors)[0] ?? '',
                    'error',
                );
                resolve(false);
            },
        };
        if (currentId.value === null) {
            form.post('/masters/parties', opts);
        } else {
            form.put(`/masters/parties/${currentId.value}`, opts);
        }
    });
}

const guard = useUnsavedGuard(isDirty, save);
const confirm = useConfirm();

async function deleteRecord(): Promise<void> {
    if (
        currentId.value !== null &&
        (await confirm.ask(t('forms.confirm_delete'), { destructive: true }))
    ) {
        router.delete(`/masters/parties/${currentId.value}`, {
            preserveScroll: true,
            onSuccess: () =>
                statusLine.setMessage(t('forms.deleted'), 'success'),
            onError: (errors) => {
                form.setError(errors);
                statusLine.setMessage(Object.values(errors)[0] ?? '', 'error');
            },
        });
    }
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'newRecord':
            guard.guard(() => load(null));
            break;
        case 'deleteRecord':
            void deleteRecord();
            break;
        case 'enterQuery':
            query.enterQuery();
            break;
        case 'executeQuery': {
            const q =
                query.mode.value === 'enterQuery' ? query.takeCriteria() : {};
            router.get(
                '/masters/parties',
                { q },
                { only: ['parties', 'criteria'], preserveState: true },
            );
            break;
        }
        case 'previousRecord':
            if (index.value > 0)
                guard.guard(() => load(props.parties[index.value - 1].id));
            break;
        case 'nextRecord':
            if (index.value < props.parties.length - 1)
                guard.guard(() => load(props.parties[index.value + 1].id));
            break;
        case 'clear':
            if (query.mode.value === 'enterQuery') {
                query.cancelQuery();
            } else {
                // Oracle Forms' Clear prompts Save/Discard/Cancel before dropping edits.
                guard.guard(() => load(currentId.value));
            }
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}
</script>

<template>
    <Head :title="t('masters.titles.parties')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <h1 class="text-lg font-semibold">{{ t('masters.titles.parties') }}</h1>
        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{
                save: !canEdit || inQuery,
                newRecord: !canEdit,
                deleteRecord: !canEdit || currentId === null,
            }"
            @action="onAction"
        />
        <FormBlock>
            <FormField
                :label="t('masters.fields.oracle_code')"
                for="f-oracle_code"
                :span="3"
            >
                <Input
                    v-if="inQuery"
                    id="f-oracle_code"
                    v-model="criteria.oracle_code as string"
                    class="border-warning/70"
                />
                <Input
                    v-else
                    id="f-oracle_code"
                    v-model="form.oracle_code as string"
                    type="number"
                    :disabled="!editable"
                />
            </FormField>
            <FormField
                :label="`${t('masters.fields.name')} *`"
                for="f-name"
                :span="6"
                :error="form.errors.name"
            >
                <Input
                    v-if="inQuery"
                    id="f-name"
                    v-model="criteria.name as string"
                    class="border-warning/70"
                />
                <Input
                    v-else
                    id="f-name"
                    v-model="form.name as string"
                    :disabled="!editable"
                />
            </FormField>
            <FormField
                :label="t('masters.fields.type')"
                for="f-type"
                :span="3"
                :error="form.errors.type"
            >
                <Input
                    v-if="inQuery"
                    id="f-type"
                    v-model="criteria.type as string"
                    class="border-warning/70"
                />
                <select
                    v-else
                    id="f-type"
                    v-model="form.type"
                    :disabled="!editable"
                    class="bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option
                        v-for="o in typeOptions"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
            </FormField>
            <FormField
                :label="t('masters.fields.is_active')"
                for="f-is_active"
                :span="2"
            >
                <Input v-if="inQuery" id="f-is_active" disabled />
                <Checkbox
                    v-else
                    id="f-is_active"
                    :model-value="Boolean(form.is_active)"
                    :disabled="!editable"
                    @update:model-value="(v) => (form.is_active = v === true)"
                />
            </FormField>
        </FormBlock>
        <p v-if="form.errors['record']" class="text-destructive text-sm">
            {{ form.errors['record'] }}
        </p>

        <div class="flex flex-col gap-1">
            <h2
                class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
            >
                {{ t('masters.fields.aliases') }}
            </h2>
            <DetailGrid
                :key="detailKey"
                v-model:rows="aliasRows"
                :columns="aliasColumns"
                :new-row="() => ({ alias: '' })"
            />
        </div>

        <DataGrid
            :rows="parties"
            :columns="columns"
            :selected-id="currentId"
            @row-selected="(r) => guard.guard(() => load(r.id))"
        />
        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
        <ConfirmDialog
            :open="confirm.open.value"
            :message="confirm.message.value"
            v-bind="confirm.options.value"
            @confirm="confirm.choose(true)"
            @cancel="confirm.choose(false)"
        />
    </div>
</template>
