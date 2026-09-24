<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { formatDateTime } from '@/components/activity/format';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

/**
 * The permanent home of the computer name the dismissible prompt asks for. Whichever terminal the request came
 * from is the one being named — the device is resolved from the signed cookie server-side and is never chosen
 * by the page, so nobody can rename someone else's machine from here.
 */
const props = defineProps<{
    device: {
        device_id: string;
        computer_name: string | null;
        last_seen_at: string;
    };
}>();

const form = useForm({ computer_name: props.device.computer_name ?? '' });

function save(): void {
    form.put('/devices/name', { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('activity.device.title')" />

    <div class="flex min-w-0 flex-col gap-4 p-3 sm:p-4">
        <header class="min-w-0">
            <h1 class="text-lg font-semibold">
                {{ t('activity.device.title') }}
            </h1>
            <p class="text-muted-foreground mt-0.5 max-w-prose text-sm">
                {{ t('activity.device.description') }}
            </p>
        </header>

        <FormBlock>
            <FormField
                :label="t('activity.device.name')"
                for="computer-name"
                :hint="t('activity.device.hint')"
                :span="6"
                :error="form.errors.computer_name"
            >
                <Input
                    id="computer-name"
                    v-model="form.computer_name"
                    class="h-11 sm:h-9"
                    maxlength="60"
                    autocomplete="off"
                    @keydown.enter.prevent="save"
                />
            </FormField>

            <FormField
                :label="t('activity.device.id')"
                for="device-id"
                :span="3"
            >
                <p
                    id="device-id"
                    class="text-muted-foreground truncate py-2 font-mono text-xs"
                >
                    {{ device.device_id }}
                </p>
            </FormField>

            <FormField
                :label="t('activity.device.last_seen')"
                for="device-last-seen"
                :span="3"
            >
                <p
                    id="device-last-seen"
                    class="text-muted-foreground py-2 text-sm tabular-nums"
                >
                    {{ formatDateTime(device.last_seen_at) }}
                </p>
            </FormField>
        </FormBlock>

        <div>
            <Button
                class="min-h-11 sm:min-h-9"
                :disabled="form.computer_name.trim() === '' || form.processing"
                data-test="save-device"
                @click="save"
            >
                {{ t('activity.device.save') }}
            </Button>
        </div>
    </div>
</template>
