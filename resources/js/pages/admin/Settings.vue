<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Input } from '@/components/ui/input';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

type Settings = { company_name: string; idle_lot_alert_days: number };

const props = defineProps<{ settings: Settings }>();

const form = useForm<Record<string, any>>({ company_name: props.settings.company_name, idle_lot_alert_days: props.settings.idle_lot_alert_days });

watch(() => form.isDirty, (d) => statusLine.setDirty(d), { immediate: true });

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        form.put('/admin/settings', {
            preserveScroll: true,
            onSuccess: () => { statusLine.setMessage(t('forms.saved'), 'success'); resolve(true); },
            onError: () => { statusLine.setMessage(Object.values(form.errors)[0] ?? '', 'error'); resolve(false); },
        });
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

const disabled: Partial<Record<FormsAction, boolean>> = {
    newRecord: true, deleteRecord: true, enterQuery: true, executeQuery: true,
    previousRecord: true, nextRecord: true, clear: true, print: true,
};

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save': void save(); break;
        case 'exit': router.visit('/dashboard'); break;
    }
}
</script>

<template>
    <Head :title="t('admin.settings')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('admin.settings') }}</h1>
        <FormsToolbar :disabled="disabled" @action="onAction" />
        <FormBlock>
            <FormField :label="`${t('admin.company_name')} *`" for="f-company_name" :span="6" :error="form.errors.company_name">
                <Input id="f-company_name" v-model="form.company_name as string" />
            </FormField>
            <FormField :label="`${t('admin.idle_lot_alert_days')} *`" for="f-idle_lot_alert_days" :span="3" :error="form.errors.idle_lot_alert_days">
                <Input id="f-idle_lot_alert_days" v-model="form.idle_lot_alert_days as string" type="number" min="1" max="60" />
            </FormField>
        </FormBlock>
        <ConfirmSaveDialog :open="guard.dialogOpen.value" @choose="guard.choose" />
    </div>
</template>
