<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { t } from '@/lib/i18n';

/**
 * Asks for one required value — a reason for an override, hold or cancel, or a figure (`type: 'number'`) —
 * and confirms it with Enter or the OK button. Closing the dialog any other way is a cancel. With `options`
 * the value is picked from a list (a lookup code such as a hold reason) instead of typed.
 */
const props = defineProps<{
    open: boolean;
    title: string;
    message?: string;
    label?: string;
    initial?: string;
    type?: 'text' | 'number';
    placeholder?: string;
    options?: { value: string; label: string }[];
}>();
const emit = defineEmits<{ confirm: [value: string]; cancel: [] }>();

const value = ref(props.initial ?? '');
const input = ref<InstanceType<typeof Input> | null>(null);
const select = ref<HTMLSelectElement | null>(null);

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            value.value = props.initial ?? '';
            await nextTick();
            if (props.options) {
                select.value?.focus();
            } else {
                (input.value?.$el as HTMLInputElement | undefined)?.focus();
            }
        }
    },
);

// Shadow the form's shortcuts (F10 Save, Ctrl+↓ New, …) only while the dialog is open.
useFormsKeys({}, () => props.open);

function confirm(): void {
    const v = String(value.value ?? '').trim();
    if (v !== '') {
        emit('confirm', v);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('cancel')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="message">{{
                    message
                }}</DialogDescription>
                <DialogDescription v-else class="sr-only">{{
                    title
                }}</DialogDescription>
            </DialogHeader>
            <label class="text-muted-foreground text-xs" for="reason-input">{{
                label ?? t('forms.reason')
            }}</label>
            <select
                v-if="options"
                id="reason-input"
                ref="select"
                v-model="value"
                data-reason-input
                class="bg-background h-9 w-full rounded-md border px-2 text-sm"
                @keydown.enter.prevent="confirm"
            >
                <option value="">{{ placeholder ?? t('forms.select') }}</option>
                <option v-for="o in options" :key="o.value" :value="o.value">
                    {{ o.label }}
                </option>
            </select>
            <Input
                v-else
                id="reason-input"
                ref="input"
                v-model="value"
                data-reason-input
                :type="type ?? 'text'"
                :step="type === 'number' ? '0.01' : undefined"
                :min="type === 'number' ? '0' : undefined"
                :placeholder="placeholder"
                autocomplete="off"
                @keydown.enter.prevent="confirm"
            />
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="emit('cancel')">{{
                    t('forms.cancel')
                }}</Button>
                <Button
                    :disabled="String(value ?? '').trim() === ''"
                    @click="confirm"
                    >{{ t('forms.confirm') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
