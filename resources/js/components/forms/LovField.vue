<script setup lang="ts">
import { List } from '@lucide/vue';
import { ref } from 'vue';
import LovDialog from '@/components/forms/LovDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { fetchLov } from '@/lib/lov';
import type { LovRow } from '@/lib/lov';

const props = defineProps<{ name: string; filters?: Record<string, string | number | null>; placeholder?: string; disabled?: boolean; id?: string }>();
const model = defineModel<number | null>({ default: null });
const display = defineModel<string>('display', { default: '' });
const emit = defineEmits<{ selected: [row: LovRow] }>();

const open = ref(false);
const initialQuery = ref('');

function select(row: LovRow): void {
    model.value = row.id;
    display.value = row.code === row.label ? row.code : `${row.code} — ${row.label}`;
    emit('selected', row);
}

function onInput(value: string | number): void {
    display.value = String(value);
    model.value = null;
}

async function onBlur(): Promise<void> {
    if (model.value !== null || display.value.trim() === '') {
        return;
    }
    const res = await fetchLov(props.name, { exact: display.value.trim(), filters: props.filters });
    if (res.data.length === 1) {
        select(res.data[0]);
    } else {
        initialQuery.value = display.value.trim();
        open.value = true;
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'F9') {
        e.preventDefault();
        e.stopPropagation();
        initialQuery.value = model.value === null ? display.value : '';
        open.value = true;
    }
}
</script>

<template>
    <div class="flex items-center gap-1">
        <Input
            :id="id"
            :model-value="display"
            :placeholder="placeholder"
            :disabled="disabled"
            autocomplete="off"
            @update:model-value="onInput"
            @blur="onBlur"
            @keydown="onKeydown"
        />
        <Button type="button" variant="outline" size="icon" class="size-9 shrink-0" :disabled="disabled" :aria-label="t('forms.actions.lov')" @click="open = true">
            <List class="size-4" />
        </Button>
        <LovDialog v-model:open="open" :name="name" :filters="filters" :initial-query="initialQuery" @select="select" />
    </div>
</template>
