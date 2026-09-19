<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useQueryMode } from '@/composables/useQueryMode';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type { MasterField, MasterMeta, MasterRow } from '@/types/masters';

const props = defineProps<{ resource: MasterMeta; rows: MasterRow[]; criteria: Record<string, string> }>();

const blank = (): Record<string, unknown> => Object.fromEntries(props.resource.fields.map((f) => [
    f.name, f.type === 'bool' ? true : f.type === 'multiselect' ? [] : null,
]));

const currentId = ref<number | null>(props.rows[0]?.id ?? null);
const form = useForm<Record<string, any>>(currentRow() ?? blank());
const query = useQueryMode<Record<string, string | null>>(props.resource.fields.filter((f) => f.queryable).map((f) => f.name));

function currentRow(): Record<string, unknown> | null {
    const row = props.rows.find((r) => r.id === currentId.value);
    return row ? Object.fromEntries(props.resource.fields.map((f) => [f.name, row[f.name] ?? null])) : null;
}

function load(id: number | null): void {
    currentId.value = id;
    form.defaults(currentRow() ?? blank());
    form.reset();
    form.clearErrors();
}

const index = computed(() => props.rows.findIndex((r) => r.id === currentId.value));
watch([index, () => props.rows.length], ([i, n]) => statusLine.setRecord(i + 1, n), { immediate: true });
watch(() => form.isDirty, (d) => statusLine.setDirty(d), { immediate: true });
watch(() => props.rows, () => load(props.rows.find((r) => r.id === currentId.value)?.id ?? props.rows[0]?.id ?? null));

const columns = computed<ColumnDef<MasterRow>[]>(() => props.resource.fields.filter((f) => f.grid).map((f) => ({
    field: f.type === 'multiselect' ? `${f.name}_label` : f.name,
    headerName: f.label,
    flex: f.span,
    valueFormatter: f.type === 'select'
        ? (p) => f.options.find((o) => o.value === p.value)?.label ?? String(p.value ?? '')
        : f.type === 'bool' ? (p) => (p.value ? '✓' : '') : undefined,
})));

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => { statusLine.setMessage(t('forms.saved'), 'success'); resolve(true); },
            onError: () => { statusLine.setMessage(Object.values(form.errors)[0] ?? '', 'error'); resolve(false); },
        };
        if (currentId.value === null) {
            form.post(`/masters/${props.resource.key}`, opts);
        } else {
            form.put(`/masters/${props.resource.key}/${currentId.value}`, opts);
        }
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save': void save(); break;
        case 'newRecord': load(null); break;
        case 'deleteRecord':
            if (currentId.value !== null && window.confirm(t('forms.confirm_delete'))) {
                router.delete(`/masters/${props.resource.key}/${currentId.value}`, {
                    preserveScroll: true,
                    onSuccess: () => statusLine.setMessage(t('forms.deleted'), 'success'),
                    onError: (e) => statusLine.setMessage(Object.values(e)[0] ?? '', 'error'),
                });
            }
            break;
        case 'enterQuery': query.enterQuery(); break;
        case 'executeQuery': {
            const q = query.mode.value === 'enterQuery' ? query.takeCriteria() : {};
            router.get(`/masters/${props.resource.key}`, { q }, { preserveState: true, preserveScroll: true, only: ['rows', 'criteria'] });
            break;
        }
        case 'previousRecord': if (index.value > 0) load(props.rows[index.value - 1].id); break;
        case 'nextRecord': if (index.value < props.rows.length - 1) load(props.rows[index.value + 1].id); break;
        case 'clear': query.mode.value === 'enterQuery' ? query.cancelQuery() : load(currentId.value); break;
        case 'print': window.print(); break;
        case 'exit': router.visit('/dashboard'); break;
    }
}

const inQuery = computed(() => query.mode.value === 'enterQuery');
const editable = (f: MasterField) => props.resource.canEdit && !f.readonly && !inQuery.value;
const criteria = reactive(query.criteria);
</script>

<template>
    <Head :title="resource.title" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ resource.title }}</h1>
        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{ save: !resource.canEdit || inQuery, newRecord: !resource.canEdit, deleteRecord: !resource.canEdit || currentId === null }"
            @action="onAction"
        />
        <FormBlock>
            <FormField
                v-for="f in resource.fields"
                :key="f.name"
                :label="f.label + (f.required ? ' *' : '')"
                :for="`f-${f.name}`"
                :span="f.span"
                :error="form.errors[f.name]"
            >
                <template v-if="inQuery">
                    <Input v-if="f.queryable" :id="`f-${f.name}`" v-model="criteria[f.name] as string" class="border-amber-500/60" />
                    <Input v-else :id="`f-${f.name}`" disabled />
                </template>
                <template v-else-if="f.type === 'bool'">
                    <Checkbox :id="`f-${f.name}`" :model-value="Boolean(form[f.name])" :disabled="!editable(f)" @update:model-value="(v) => (form[f.name] = v === true)" />
                </template>
                <template v-else-if="f.type === 'select'">
                    <select :id="`f-${f.name}`" v-model="form[f.name]" :disabled="!editable(f)" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option v-for="o in f.options" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
                    </select>
                </template>
                <template v-else-if="f.type === 'multiselect'">
                    <select :id="`f-${f.name}`" v-model="form[f.name]" multiple :disabled="!editable(f)" class="min-h-20 rounded-md border bg-background px-2 text-sm">
                        <option v-for="o in f.options" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
                    </select>
                </template>
                <template v-else>
                    <Input
                        :id="`f-${f.name}`"
                        v-model="form[f.name] as string"
                        :type="f.type === 'number' ? 'number' : f.type === 'time' ? 'time' : 'text'"
                        :disabled="!editable(f)"
                    />
                </template>
            </FormField>
        </FormBlock>
        <p v-if="form.errors['record']" class="text-sm text-destructive">{{ form.errors['record'] }}</p>
        <DataGrid :rows="rows" :columns="columns" :selected-id="currentId" @row-selected="(r) => load(r.id)" />
        <ConfirmSaveDialog :open="guard.dialogOpen.value" @choose="guard.choose" />
    </div>
</template>
