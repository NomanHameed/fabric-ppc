<script setup lang="ts">
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

const emit = defineEmits<{
    success: [];
}>();

const getDefaultPasskeyName = () => {
    const ua = navigator.userAgent;

    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    const os = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    if (browser && os) {
        return t('settings.passkeys.default_name', { browser, os });
    }
    return browser ?? os ?? '';
};

const name = ref(getDefaultPasskeyName());
const showForm = ref(false);

const { register, isLoading, error, isSupported } = usePasskeyRegister({
    onSuccess: () => {
        name.value = '';
        showForm.value = false;
        emit('success');
    },
});

const handleSubmit = async (event: Event) => {
    event.preventDefault();

    if (!name.value.trim()) {
        return;
    }

    await register(name.value);
};

const handleCancel = () => {
    showForm.value = false;
    name.value = '';
};
</script>

<template>
    <div v-if="!isSupported" class="text-muted-foreground text-sm">
        {{ t('settings.passkeys.unsupported') }}
    </div>

    <Button v-else-if="!showForm" variant="outline" @click="showForm = true">
        {{ t('settings.passkeys.add') }}
    </Button>

    <form
        v-else
        @submit="handleSubmit"
        class="border-border bg-muted/50 space-y-4 rounded-lg border p-4"
    >
        <div class="grid gap-2">
            <Label for="passkey-name">{{ t('settings.passkeys.name') }}</Label>
            <Input
                id="passkey-name"
                type="text"
                v-model="name"
                :placeholder="t('settings.passkeys.name_placeholder')"
                class="border-foreground/20 mt-1 block w-full"
                autofocus
            />
            <p class="text-muted-foreground text-xs">
                {{ t('settings.passkeys.name_hint') }}
            </p>
        </div>

        <InputError v-if="error" :message="error" />

        <div class="flex gap-2">
            <Button type="submit" :disabled="isLoading || !name.trim()">
                {{
                    isLoading
                        ? t('settings.passkeys.registering')
                        : t('settings.passkeys.register')
                }}
            </Button>
            <Button type="button" variant="ghost" @click="handleCancel">
                {{ t('settings.passkeys.cancel') }}
            </Button>
        </div>
    </form>
</template>
