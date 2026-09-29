<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import DeviceNamePrompt from '@/components/activity/DeviceNamePrompt.vue';
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
            <!--
              Mounted here, not on one screen: decision A2 makes the computer name half of a device's
              identity, and it was previously only asked for on the action log — a page almost nobody but an
              administrator opens, so almost no terminal ever got named. The strip still shows itself only for
              a device with no name on file, and a dismissal is still remembered per device id.
            -->
            <div class="flex min-w-0 flex-1 flex-col">
                <DeviceNamePrompt />
                <slot />
            </div>
            <StatusLine />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
