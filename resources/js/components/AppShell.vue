<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { SidebarProvider } from '@/components/ui/sidebar';
import { t } from '@/lib/i18n';

const isOpen = usePage().props.sidebarOpen;
</script>

<template>
    <!--
      The skip link is the first focusable thing in the document, so a keyboard or
      screen-reader user can jump past the sidebar (which is long: six nav groups)
      straight to the page. It is off-screen until focused.
    -->
    <a
        href="#main-content"
        class="bg-primary text-primary-foreground focus-visible:ring-ring sr-only rounded-md px-4 py-2 text-sm font-medium focus-visible:not-sr-only focus-visible:fixed focus-visible:start-4 focus-visible:top-4 focus-visible:z-50 focus-visible:ring-2"
    >
        {{ t('app.a11y.skip_to_content') }}
    </a>
    <SidebarProvider :default-open="isOpen">
        <slot />
    </SidebarProvider>
</template>
