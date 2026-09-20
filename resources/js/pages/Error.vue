<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

defineProps<{ status: number; reference: string }>();
</script>

<template>
    <Head :title="t('app.errors.title')" />
    <div
        class="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center"
    >
        <h1 class="text-2xl font-semibold">{{ t('app.errors.title') }}</h1>
        <p class="text-muted-foreground">{{ t('app.errors.unexpected') }}</p>
        <p class="font-mono text-sm" data-test="error-reference">
            {{ t('app.errors.reference', { ref: reference }) }}
        </p>
        <Button as="a" href="/dashboard">{{ t('app.errors.back') }}</Button>
    </div>
</template>
