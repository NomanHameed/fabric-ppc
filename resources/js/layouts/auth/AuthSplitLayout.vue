<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { t } from '@/lib/i18n';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid min-h-svh grid-cols-1 items-stretch overflow-x-clip lg:grid-cols-2"
    >
        <!-- The brand panel is decoration: it is dropped below `lg` rather than squeezed. -->
        <div
            class="bg-sidebar text-sidebar-foreground border-sidebar-border relative hidden h-full flex-col justify-between border-e p-10 lg:flex"
        >
            <Link
                :href="home()"
                :aria-label="t('app.brand.home')"
                class="relative z-20 flex items-center gap-3"
            >
                <AppLogoIcon class="size-10 shrink-0" />
                <span class="flex flex-col">
                    <span
                        class="text-brand-strong text-base font-semibold tracking-[0.18em]"
                        >{{ t('app.brand.wordmark') }}</span
                    >
                    <span
                        class="text-muted-foreground text-[0.625rem] font-medium tracking-[0.32em]"
                        >{{ t('app.brand.wordmark_sub') }}</span
                    >
                </span>
            </Link>
        </div>

        <div class="flex items-center justify-center p-4 sm:p-6 lg:p-8">
            <div class="flex w-full max-w-sm flex-col justify-center gap-6">
                <div class="flex flex-col gap-2 text-center">
                    <h1
                        v-if="title"
                        class="text-lg font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-muted-foreground text-sm text-balance"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
