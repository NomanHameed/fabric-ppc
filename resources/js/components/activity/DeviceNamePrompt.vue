<script setup lang="ts">
import { Monitor, X } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

/**
 * Asks — once, quietly — for the name of the terminal the user is sitting at (decision A2: HTTP carries no MAC
 * address, so the human-readable half of a device's identity has to be declared).
 *
 * Deliberately not a modal: it is an inline strip that can be dismissed, and dismissal is remembered per
 * device id in localStorage, so nobody is nagged on every page. `/devices` is the permanent way back to it.
 */
const STORAGE_KEY = 'device-name-prompt-dismissed';

const deviceId = ref<string | null>(null);
const visible = ref(false);
const name = ref('');
const saving = ref(false);
const error = ref('');

function dismissedFor(id: string): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === id;
    } catch {
        // Private mode, or storage disabled: showing the strip is the safer failure.
        return false;
    }
}

function dismiss(): void {
    visible.value = false;
    try {
        if (deviceId.value) {
            window.localStorage.setItem(STORAGE_KEY, deviceId.value);
        }
    } catch {
        // Nothing to do: the strip is already hidden for this page view.
    }
}

onMounted(async () => {
    try {
        const res = await fetch('/devices/current', {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) {
            return;
        }
        const device = (await res.json()) as {
            device_id: string;
            named: boolean;
        };
        deviceId.value = device.device_id;
        visible.value = !device.named && !dismissedFor(device.device_id);
    } catch {
        // The prompt is a convenience; a failed probe simply means it does not appear.
    }
});

function save(): void {
    const value = name.value.trim();
    if (value === '' || saving.value) {
        return;
    }
    saving.value = true;
    error.value = '';
    router.put(
        '/devices/name',
        { computer_name: value },
        {
            preserveScroll: true,
            onSuccess: () => {
                visible.value = false;
            },
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        v-if="visible"
        class="bg-info-surface text-info-on-surface mx-3 mt-3 flex flex-col gap-3 rounded-md px-3 py-3 sm:mx-4 sm:mt-4"
        role="region"
        :aria-label="t('activity.device.prompt_title')"
        data-test="device-name-prompt"
    >
        <div class="flex items-start gap-2">
            <Monitor class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">
                    {{ t('activity.device.prompt_title') }}
                </p>
                <p class="mt-0.5 text-xs">
                    {{ t('activity.device.prompt_body') }}
                </p>
            </div>
            <button
                type="button"
                class="-me-1 -mt-1 rounded-md p-2 hover:opacity-70"
                :aria-label="t('activity.device.dismiss')"
                data-test="dismiss-device-prompt"
                @click="dismiss"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label class="sr-only" for="device-name-prompt-input">{{
                t('activity.device.name')
            }}</label>
            <Input
                id="device-name-prompt-input"
                v-model="name"
                class="bg-background h-11 sm:h-9 sm:max-w-xs"
                maxlength="60"
                autocomplete="off"
                :placeholder="t('activity.device.name')"
                @keydown.enter.prevent="save"
            />
            <Button
                class="min-h-11 sm:min-h-9"
                :disabled="name.trim() === '' || saving"
                data-test="save-device-name"
                @click="save"
            >
                {{ t('activity.device.save') }}
            </Button>
        </div>

        <!-- `-on-surface`, not `text-destructive`: the plain token is ~4:1 against this info surface in dark
             mode, and this line is the only thing that says why the name would not save. -->
        <p
            v-if="error"
            class="text-destructive-on-surface text-xs"
            role="alert"
        >
            {{ error }}
        </p>
    </section>
</template>
