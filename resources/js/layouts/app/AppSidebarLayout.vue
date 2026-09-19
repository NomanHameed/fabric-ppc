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
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
            <StatusLine />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
