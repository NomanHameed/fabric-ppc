<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import StatusLine from '@/components/forms/StatusLine.vue';
import { Toaster } from '@/components/ui/sonner';
import { statusLine } from '@/lib/statusLine';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const off = router.on('navigate', () => statusLine.reset());
onBeforeUnmount(() => off());
</script>

<template>
    <AppShell>
        <AppSidebar />
        <AppContent>
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="flex min-w-0 flex-1 flex-col">
                <slot />
            </div>
            <StatusLine />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
