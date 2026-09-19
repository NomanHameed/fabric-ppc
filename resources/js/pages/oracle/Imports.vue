<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import { Button } from '@/components/ui/button';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import type { ImportSummary } from '@/types/oracle';

const props = defineProps<{ imports: ImportSummary[]; canRun: boolean }>();
const form = useForm<{ file: File | null }>({ file: null });

function upload(): void {
    form.post('/oracle/imports', { forceFormData: true });
}

const columns = computed<ColumnDef<ImportSummary>[]>(() => [
    { field: 'created_at', headerName: t('oracle.fields.date'), width: 140 },
    { field: 'label', headerName: t('oracle.fields.file'), flex: 2 },
    { field: 'uploader', headerName: t('oracle.fields.user'), flex: 1 },
    {
        field: 'status',
        headerName: t('oracle.fields.status_col'),
        valueFormatter: (p) => t(`oracle.status.${p.value}`),
        width: 130,
    },
    {
        field: 'new_count',
        headerName: t('oracle.actions.new'),
        width: 90,
        type: 'numericColumn',
    },
    {
        field: 'changed_count',
        headerName: t('oracle.actions.changed'),
        width: 100,
        type: 'numericColumn',
    },
    {
        field: 'missing_count',
        headerName: t('oracle.actions.missing'),
        width: 120,
        type: 'numericColumn',
    },
    {
        field: 'error_count',
        headerName: t('oracle.actions.error'),
        width: 90,
        type: 'numericColumn',
    },
]);
</script>

<template>
    <Head :title="t('oracle.title')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('oracle.title') }}</h1>
        <FormBlock v-if="canRun" :title="t('oracle.upload')">
            <FormField
                :label="t('oracle.upload_hint')"
                for="oracle-file"
                :span="8"
                :error="form.errors.file"
            >
                <input
                    id="oracle-file"
                    type="file"
                    accept=".xlsx"
                    class="file:bg-muted text-sm file:me-3 file:rounded-md file:border file:px-3 file:py-1.5"
                    @change="
                        (e) =>
                            (form.file =
                                (e.target as HTMLInputElement).files?.[0] ??
                                null)
                    "
                />
            </FormField>
            <div class="col-span-12 flex items-end md:col-span-4">
                <Button
                    :disabled="!form.file || form.processing"
                    @click="upload"
                >
                    {{ form.processing ? '…' : t('oracle.preview') }}
                </Button>
            </div>
        </FormBlock>
        <h2 class="text-muted-foreground text-sm font-semibold">
            {{ t('oracle.history') }}
        </h2>
        <DataGrid
            :rows="props.imports"
            :columns="columns"
            @row-selected="(r) => router.visit(`/oracle/imports/${r.id}`)"
        />
    </div>
</template>
