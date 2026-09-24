<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import NavGroups from '@/components/NavGroups.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { t, useI18n } from '@/lib/i18n';
import { dashboard } from '@/routes';

/**
 * One sidebar, three behaviours, all from the primitive:
 * - desktop (>= 768px): docked and persistent, its open/closed state kept in a cookie;
 * - tablet / narrow desktop: `collapsible="icon"` collapses it to a 3rem icon rail,
 *   and `SidebarRail` gives the edge a click/drag target to toggle it;
 * - phone (<= 768px): the primitive swaps itself for an off-canvas Sheet driven by
 *   `SidebarTrigger` in the header.
 *
 * `side` follows the document direction so Urdu docks it on the right.
 */
const { dir } = useI18n();
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        :side="dir === 'rtl' ? 'right' : 'left'"
    >
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link
                            :href="dashboard()"
                            :aria-label="t('app.brand.home')"
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <!--
              `SidebarContent` renders a plain div, so an `aria-label` on it is dropped
              by assistive tech (a label needs a role to attach to). The real landmark
              lives here.
            -->
            <nav :aria-label="t('app.a11y.primary_nav')">
                <NavGroups />
            </nav>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>

        <SidebarRail />
    </Sidebar>
    <slot />
</template>
