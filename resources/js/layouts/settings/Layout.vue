<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems = computed<NavItem[]>(() => [
    {
        title: t('settings.layout.profile'),
        href: editProfile(),
    },
    {
        title: t('settings.layout.security'),
        href: editSecurity(),
    },
    {
        title: t('settings.layout.appearance'),
        href: editAppearance(),
    },
]);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-3 py-5 sm:px-4 sm:py-6">
        <Heading
            :title="t('settings.layout.title')"
            :description="t('settings.layout.description')"
        />

        <div class="flex flex-col gap-0 lg:flex-row lg:gap-12">
            <aside class="w-full max-w-xl shrink-0 lg:w-48">
                <nav
                    class="flex flex-col gap-1"
                    :aria-label="t('settings.layout.title')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="min-w-0 flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
