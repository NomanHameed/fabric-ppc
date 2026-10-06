<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { t } from '@/lib/i18n';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 overflow-x-clip p-4 sm:p-6 md:p-10"
    >
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link
                :href="home()"
                :aria-label="t('app.brand.home')"
                class="flex items-center gap-2 self-center"
            >
                <AppLogo />
            </Link>

            <Card class="rounded-xl">
                <CardHeader class="px-5 pt-6 pb-0 text-center sm:px-10 sm:pt-8">
                    <CardTitle class="text-lg">{{ title }}</CardTitle>
                    <CardDescription class="text-balance">
                        {{ description }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="px-5 py-6 sm:px-10 sm:py-8">
                    <slot />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
