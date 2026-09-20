<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import type {
    CellKeyDownEvent,
    ICellRendererParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import DetailGrid from '@/components/forms/DetailGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import LovDialog from '@/components/forms/LovDialog.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useQueryMode } from '@/composables/useQueryMode';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { useFlashSelect } from '@/lib/flashSelect';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';
import { statusLine } from '@/lib/statusLine';

type PoType = { value: string; label: string };
type Step = {
    seq: number;
    operation_id: number;
    operation: string;
    department: string;
};
type StepRow = {
    operation_id: number | null;
    operation: string;
    department: string;
};
type TemplateRow = {
    id: number;
    name: string;
    po_type: string | null;
    quality_pattern: string | null;
    priority: number;
    is_active: boolean;
    steps: Step[];
};

const props = defineProps<{
    templates: TemplateRow[];
    poTypes: PoType[];
    canEdit: boolean;
    criteria: Record<string, string>;
}>();

function currentTemplate(): TemplateRow | null {
    return props.templates.find((tpl) => tpl.id === currentId.value) ?? null;
}

function headerFrom(tpl: TemplateRow | null): Record<string, unknown> {
    return {
        name: tpl?.name ?? '',
        po_type: tpl?.po_type ?? null,
        quality_pattern: tpl?.quality_pattern ?? null,
        priority: tpl?.priority ?? 0,
        is_active: tpl ? tpl.is_active : true,
    };
}

function stepRowsFrom(tpl: TemplateRow | null): StepRow[] {
    return tpl
        ? tpl.steps.map((s) => ({
              operation_id: s.operation_id,
              operation: s.operation,
              department: s.department,
          }))
        : [];
}

function stepSignature(rows: StepRow[]): string {
    return JSON.stringify(
        rows.filter((r) => r.operation_id).map((r) => r.operation_id),
    );
}

const currentId = ref<number | null>(props.templates[0]?.id ?? null);
const detailKey = ref(0);
const form = useForm<Record<string, any>>(headerFrom(currentTemplate()));
const stepRows = ref<StepRow[]>(stepRowsFrom(currentTemplate()));
const stepBaseline = ref(stepSignature(stepRows.value));
const query = useQueryMode<Record<string, string | null>>([
    'name',
    'po_type',
    'quality_pattern',
]);

const lovOpen = ref(false);
const lovTargetRow = ref<number | null>(null);

function isDirty(): boolean {
    return form.isDirty || stepSignature(stepRows.value) !== stepBaseline.value;
}

function load(id: number | null): void {
    currentId.value = id;
    const tpl = currentTemplate();
    form.defaults(headerFrom(tpl));
    form.reset();
    form.clearErrors();
    stepRows.value = stepRowsFrom(tpl);
    stepBaseline.value = stepSignature(stepRows.value);
    detailKey.value += 1;
}

const index = computed(() =>
    props.templates.findIndex((tpl) => tpl.id === currentId.value),
);
watch(
    [index, () => props.templates.length],
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
    [() => props.templates, flashSelect.pending],
    ([, pending], [, wasPending]) => {
        // A just-saved record (flashed selectedId) wins over the current one once it is in the rows.
        // When the rows changed on their own (pending unchanged) and still lack the id, the flash is stale.
        const wanted =
            flashSelect.take(
                (id) => props.templates.some((tpl) => tpl.id === id),
                { dropIfMissing: pending === wasPending },
            ) ?? currentId.value;
        load(
            props.templates.find((tpl) => tpl.id === wanted)?.id ??
                props.templates[0]?.id ??
                null,
        );
    },
);

const inQuery = computed(() => query.mode.value === 'enterQuery');
const editable = computed(() => props.canEdit && !inQuery.value);
const criteria = query.criteria;
const poTypeOptions = computed(() => [
    { value: '', label: t('forms.select') },
    ...props.poTypes,
]);

function operationCellRenderer(
    params: ICellRendererParams<StepRow>,
): HTMLElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.className =
        'w-full truncate rounded border border-transparent px-2 py-0.5 text-start text-sm hover:border-input hover:bg-muted disabled:pointer-events-none disabled:opacity-50';
    button.textContent = params.value
        ? String(params.value)
        : t('forms.select');
    button.disabled = !editable.value;
    button.addEventListener('click', () => {
        lovTargetRow.value = params.node?.rowIndex ?? null;
        lovOpen.value = true;
    });
    return button;
}

const columns = computed<ColumnDef<StepRow>[]>(() => [
    {
        colId: 'seq',
        headerName: t('masters.fields.seq'),
        width: 90,
        editable: false,
        valueGetter: (p: ValueGetterParams<StepRow>) =>
            ((p.node?.rowIndex ?? 0) + 1) * 10,
    },
    {
        field: 'operation',
        headerName: t('masters.fields.operation'),
        flex: 2,
        editable: false,
        cellRenderer: operationCellRenderer,
    },
    {
        field: 'department',
        headerName: t('masters.fields.department'),
        flex: 1,
        editable: false,
    },
]);

function onOperationSelected(row: LovRow): void {
    if (lovTargetRow.value === null) {
        return;
    }
    const rows = [...stepRows.value];
    const idx = lovTargetRow.value;
    if (rows[idx]) {
        rows[idx] = {
            ...rows[idx],
            operation_id: Number(row.id),
            operation: String(row.label),
            department: String(row.department ?? ''),
        };
        stepRows.value = rows;
    }
    lovTargetRow.value = null;
}

function onStepKeyDown(e: CellKeyDownEvent<StepRow>): void {
    const ke = e.event as KeyboardEvent | undefined;
    if (!ke || !ke.ctrlKey || e.rowIndex === null || e.rowIndex === undefined) {
        return;
    }
    let target: number | null = null;
    if (ke.key === 'ArrowUp') {
        target = e.rowIndex - 1;
    } else if (ke.key === 'ArrowDown') {
        target = e.rowIndex + 1;
    }
    if (
        target === null ||
        target < 0 ||
        target >= e.api.getDisplayedRowCount()
    ) {
        return;
    }
    ke.preventDefault();
    ke.stopPropagation();
    const rows: StepRow[] = [];
    e.api.forEachNode((n) => n.data && rows.push(n.data));
    [rows[e.rowIndex], rows[target]] = [rows[target], rows[e.rowIndex]];
    stepRows.value = rows;
}

const templateColumns = computed<ColumnDef<TemplateRow>[]>(() => [
    { field: 'name', headerName: t('masters.fields.name'), flex: 2 },
    { field: 'po_type', headerName: t('masters.fields.po_type'), flex: 1 },
    {
        field: 'quality_pattern',
        headerName: t('masters.fields.quality_pattern'),
        flex: 2,
    },
    { field: 'priority', headerName: t('masters.fields.priority'), flex: 1 },
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
            po_type: data.po_type || null,
            steps: stepRows.value
                .filter((r) => r.operation_id)
                .map((r) => ({ operation_id: r.operation_id })),
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
            form.post('/masters/route-templates', opts);
        } else {
            form.put(`/masters/route-templates/${currentId.value}`, opts);
        }
    });
}

const guard = useUnsavedGuard(isDirty, save);

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'newRecord':
            guard.guard(() => load(null));
            break;
        case 'deleteRecord':
            if (
                currentId.value !== null &&
                window.confirm(t('forms.confirm_delete'))
            ) {
                router.delete(`/masters/route-templates/${currentId.value}`, {
                    preserveScroll: true,
                    onSuccess: () =>
                        statusLine.setMessage(t('forms.deleted'), 'success'),
                    onError: (errors) => {
                        form.setError(errors);
                        statusLine.setMessage(
                            Object.values(errors)[0] ?? '',
                            'error',
                        );
                    },
                });
            }
            break;
        case 'enterQuery':
            query.enterQuery();
            break;
        case 'executeQuery': {
            const q =
                query.mode.value === 'enterQuery' ? query.takeCriteria() : {};
            router.get(
                '/masters/route-templates',
                { q },
                { only: ['templates', 'criteria'], preserveState: true },
            );
            break;
        }
        case 'previousRecord':
            if (index.value > 0)
                guard.guard(() => load(props.templates[index.value - 1].id));
            break;
        case 'nextRecord':
            if (index.value < props.templates.length - 1)
                guard.guard(() => load(props.templates[index.value + 1].id));
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
    <Head :title="t('masters.titles.route_templates')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">
            {{ t('masters.titles.route_templates') }}
        </h1>
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
                :label="`${t('masters.fields.name')} *`"
                for="f-name"
                :span="4"
                :error="form.errors.name"
            >
                <Input
                    v-if="inQuery"
                    id="f-name"
                    v-model="criteria.name as string"
                    class="border-amber-500/60"
                />
                <Input
                    v-else
                    id="f-name"
                    v-model="form.name as string"
                    :disabled="!editable"
                />
            </FormField>
            <FormField
                :label="t('masters.fields.po_type')"
                for="f-po_type"
                :span="3"
                :error="form.errors.po_type"
            >
                <Input
                    v-if="inQuery"
                    id="f-po_type"
                    v-model="criteria.po_type as string"
                    class="border-amber-500/60"
                />
                <select
                    v-else
                    id="f-po_type"
                    v-model="form.po_type"
                    :disabled="!editable"
                    class="bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option
                        v-for="o in poTypeOptions"
                        :key="o.value"
                        :value="o.value || null"
                    >
                        {{ o.label }}
                    </option>
                </select>
            </FormField>
            <FormField
                :label="t('masters.fields.quality_pattern')"
                for="f-quality_pattern"
                :span="3"
                :hint="t('masters.quality_pattern_hint')"
                :error="form.errors.quality_pattern"
            >
                <Input
                    v-if="inQuery"
                    id="f-quality_pattern"
                    v-model="criteria.quality_pattern as string"
                    class="border-amber-500/60"
                />
                <Input
                    v-else
                    id="f-quality_pattern"
                    v-model="form.quality_pattern as string"
                    :disabled="!editable"
                />
            </FormField>
            <FormField
                :label="t('masters.fields.priority')"
                for="f-priority"
                :span="2"
                :error="form.errors.priority"
            >
                <Input v-if="inQuery" id="f-priority" disabled />
                <Input
                    v-else
                    id="f-priority"
                    v-model="form.priority as string"
                    type="number"
                    :disabled="!editable"
                />
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
        <p v-if="form.errors['steps']" class="text-destructive text-sm">
            {{ form.errors['steps'] }}
        </p>

        <div class="flex flex-col gap-1">
            <h2
                class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
            >
                {{ t('masters.fields.steps') }}
            </h2>
            <DetailGrid
                :key="detailKey"
                v-model:rows="stepRows"
                :columns="columns"
                :new-row="
                    () => ({
                        operation_id: null,
                        operation: '',
                        department: '',
                    })
                "
                @cell-key-down="onStepKeyDown"
            />
            <p class="text-muted-foreground text-xs">
                {{ t('forms.reorder_hint') }}
            </p>
        </div>

        <DataGrid
            :rows="templates"
            :columns="templateColumns"
            :selected-id="currentId"
            @row-selected="(r) => guard.guard(() => load(r.id))"
        />
        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
        <LovDialog
            v-model:open="lovOpen"
            name="operations"
            @select="onOperationSelected"
        />
    </div>
</template>
