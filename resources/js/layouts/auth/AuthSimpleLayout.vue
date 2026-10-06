<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LanguageSwitch from '@/components/LanguageSwitch.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { t } from '@/lib/i18n';
import { home } from '@/routes';

/**
 * The sign-in shell: the first screen anyone sees, so it carries the brand.
 * One column at every width — the card is `max-w-sm` and the page padding steps
 * from 1rem on a phone to 2.5rem on a desktop, so nothing ever scrolls sideways.
 * Appearance and language sit in the corner because both have to be reachable
 * before a user is authenticated.
 */
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="bg-background relative isolate flex min-h-svh flex-col items-center justify-center gap-6 overflow-x-clip p-4 sm:p-6 md:p-10"
    >
        <!--
          Decorative wash. `-z-10` only stays above the parent's own `bg-background`
          because the parent is `isolate`: without a new stacking context the negative
          z-index drops the layer behind the parent's background and it never paints.
        -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 -z-10"
            style="
                background:
                    radial-gradient(
                        44rem 26rem at 50% -8%,
                        color-mix(in oklab, var(--primary) 14%, transparent),
                        transparent 70%
                    ),
                    radial-gradient(
                        32rem 24rem at 100% 100%,
                        color-mix(
                            in oklab,
                            var(--brand-muted) 12%,
                            transparent
                        ),
                        transparent 70%
                    );
            "
        />

        <div class="absolute end-3 top-3 flex items-center gap-1 sm:end-4">
            <ThemeToggle />
            <LanguageSwitch />
        </div>

        <div class="flex w-full max-w-sm flex-col gap-8">
            <Link
                :href="home()"
                :aria-label="t('app.brand.home')"
                class="flex flex-col items-center gap-3"
            >
                <AppLogoIcon class="size-14 shrink-0 sm:size-16" labelled />
                <span class="flex flex-col items-center gap-1">
                    <span
                        class="text-brand-strong text-lg font-semibold tracking-[0.18em]"
                        >{{ t('app.brand.wordmark') }}</span
                    >
                    <span
                        class="text-muted-foreground text-[0.625rem] font-medium tracking-[0.32em]"
                        >{{ t('app.brand.wordmark_sub') }}</span
                    >
                </span>
            </Link>

            <div
                class="bg-card rounded-xl border p-5 shadow-sm sm:p-6 dark:shadow-none"
            >
                <div v-if="title || description" class="mb-6 space-y-1.5">
                    <h1
                        v-if="title"
                        class="text-center text-lg font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-muted-foreground text-center text-sm text-balance"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
