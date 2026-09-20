<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { t } from '@/lib/i18n';
import { edit } from '@/routes/appearance';

// Breadcrumbs follow the locale (re-run whenever the dictionary changes).
watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [{ title: t('settings.appearance.title'), href: edit() }],
    });
});
</script>

<template>
    <Head :title="t('settings.appearance.title')" />

    <h1 class="sr-only">{{ t('settings.appearance.title') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.appearance.title')"
            :description="t('settings.appearance.description')"
        />
        <AppearanceTabs />
    </div>
</template>
