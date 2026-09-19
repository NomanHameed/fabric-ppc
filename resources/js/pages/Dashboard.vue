<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { can } from '@/lib/can';
import { t } from '@/lib/i18n';

type DashboardStats = {
    open_lots: number;
    open_contracts: number;
    open_greige_qty: number;
    parties: number;
    missing_from_oracle: number;
};

type LastImport = {
    id: number;
    label: string;
    committed_at: string;
    uploader: string | null;
    new_count: number;
    changed_count: number;
    missing_count: number;
};

const props = defineProps<{
    stats: DashboardStats;
    lastImport: LastImport | null;
}>();

const tiles = computed(() => [
    {
        key: 'open_lots',
        label: t('dashboard.open_lots'),
        value: props.stats.open_lots.toLocaleString('en-US'),
    },
    {
        key: 'open_contracts',
        label: t('dashboard.open_contracts'),
        value: props.stats.open_contracts.toLocaleString('en-US'),
    },
    {
        key: 'open_greige',
        label: t('dashboard.open_greige'),
        value: t('dashboard.metres', {
            qty: props.stats.open_greige_qty.toLocaleString('en-US'),
        }),
    },
    {
        key: 'parties',
        label: t('dashboard.parties'),
        value: props.stats.parties.toLocaleString('en-US'),
    },
    {
        key: 'missing_from_oracle',
        label: t('dashboard.missing_from_oracle'),
        value: props.stats.missing_from_oracle.toLocaleString('en-US'),
    },
]);
</script>

<template>
    <Head :title="t('app.nav.dashboard')" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <Card v-for="tile in tiles" :key="tile.key">
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground text-sm font-medium"
                        >{{ tile.label }}</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <template
                        v-if="
                            tile.key === 'missing_from_oracle' &&
                            stats.missing_from_oracle > 0
                        "
                    >
                        <Link href="/oracle/imports" class="inline-block">
                            <Badge
                                variant="outline"
                                class="border-amber-500 bg-amber-100 text-base font-semibold text-amber-900 tabular-nums dark:bg-amber-950 dark:text-amber-300"
                            >
                                {{ tile.value }}
                            </Badge>
                        </Link>
                    </template>
                    <p v-else class="text-2xl font-semibold tabular-nums">
                        {{ tile.value }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('dashboard.last_import') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="lastImport" class="flex flex-col gap-2">
                    <Link
                        :href="`/oracle/imports/${lastImport.id}`"
                        class="font-medium hover:underline"
                    >
                        {{ lastImport.label }}
                    </Link>
                    <p class="text-muted-foreground text-sm">
                        {{ lastImport.committed_at }} —
                        {{ lastImport.uploader }}
                    </p>
                    <div class="flex gap-4 text-sm tabular-nums">
                        <span
                            >{{ t('oracle.actions.new') }}:
                            {{
                                lastImport.new_count.toLocaleString('en-US')
                            }}</span
                        >
                        <span
                            >{{ t('oracle.actions.changed') }}:
                            {{
                                lastImport.changed_count.toLocaleString('en-US')
                            }}</span
                        >
                        <span
                            >{{ t('oracle.actions.missing') }}:
                            {{
                                lastImport.missing_count.toLocaleString('en-US')
                            }}</span
                        >
                    </div>
                </div>
                <div v-else class="flex flex-col items-start gap-3">
                    <p class="text-muted-foreground text-sm">
                        {{ t('dashboard.no_import') }}
                    </p>
                    <Button v-if="can('oracle.import.view')" as-child size="sm">
                        <Link href="/oracle/imports">{{
                            t('dashboard.go_to_import')
                        }}</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
