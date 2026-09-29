<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, watchEffect } from 'vue';
import { formatDateTime } from '@/components/activity/format';
import DataGrid from '@/components/forms/DataGrid.vue';
import { Button } from '@/components/ui/button';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

/**
 * The user action log (request 2 and 3): who did what, to which record, from which address and terminal.
 *
 * Paging and filtering are the server's job — the log only grows, so the page never holds more than the
 * fifty rows it was given. Every control re-requests the same route with query parameters, which keeps the
 * view shareable as a URL and survives a refresh.
 */
type ActionRow = {
    id: number;
    action: string;
    label: string;
    description: string;
    user: string | null;
    subject_type: string | null;
    subject_id: number | null;
    ip_address: string | null;
    device_id: string | null;
    computer_name: string | null;
    occurred_at: string;
};

type Option = { value: string | number; label: string };

type Filters = {
    user_id: number | null;
    action: string | null;
    device_id: string | null;
    from: string | null;
    to: string | null;
};

const props = defineProps<{
    data: ActionRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: Filters;
    options: { users: Option[]; actions: Option[]; devices: Option[] };
}>();

/** Bound to the controls; `''` is "no filter" so a native select can express it. */
const form = reactive({
    user_id:
        props.filters.user_id === null ? '' : String(props.filters.user_id),
    action: props.filters.action ?? '',
    device_id: props.filters.device_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

/**
 * The status line's "Record n of m".
 *
 * `watchEffect`, not `watch(() => props.meta.total)`, for two reasons. The index is the row this page starts
 * at (`meta.from`), not a hard-coded 1 — on page three of the log the footer said "Record 1 of 412" while the
 * grid showed rows 101 to 150. And the layout resets the status line on every Inertia `navigate`, which fires
 * before a watcher on `total` alone would: paging to another page of the same result set leaves `total`
 * unchanged, the watcher never runs, and the footer stays blank. `watchEffect` re-runs on every prop this
 * reads, so the reset is always followed by a write.
 */
watchEffect(() =>
    statusLine.setRecord(
        // Reading `data` as well as `meta` is deliberate: the rows are a fresh array on every response, so a
        // reload that changes neither the offset nor the total still re-runs this after the layout's reset.
        props.data.length === 0 ? 0 : (props.meta.from ?? 0),
        props.meta.total,
    ),
);

/** Only the filters that are actually set; an empty one is left out so the URL stays readable. */
function query(page = 1): Record<string, string | number> {
    const q: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(form)) {
        if (value !== '') {
            q[key] = value;
        }
    }
    if (page > 1) {
        q.page = page;
    }
    return q;
}

function apply(page = 1): void {
    router.get('/activity/actions', query(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function reset(): void {
    form.user_id = '';
    form.action = '';
    form.device_id = '';
    form.from = '';
    form.to = '';
    apply();
}

const showing = computed(() =>
    props.meta.total === 0
        ? t('activity.empty')
        : t('activity.paging.showing', {
              from: props.meta.from ?? 0,
              to: props.meta.to ?? 0,
              total: props.meta.total,
          }),
);

const selectClass =
    'bg-background h-11 w-full rounded-md border px-2 text-sm sm:h-9';

const columns = computed<ColumnDef<ActionRow>[]>(() => [
    {
        field: 'occurred_at',
        headerName: t('activity.columns.when'),
        width: 150,
        valueFormatter: (p) => formatDateTime(p.value as string),
    },
    { field: 'user', headerName: t('activity.columns.user'), width: 160 },
    {
        field: 'action',
        headerName: t('activity.columns.action'),
        width: 160,
        valueFormatter: (p) => t(`activity.action_names.${String(p.value)}`),
    },
    {
        field: 'label',
        headerName: t('activity.columns.what'),
        flex: 3,
        minWidth: 240,
    },
    {
        field: 'subject_type',
        headerName: t('activity.columns.subject'),
        width: 150,
        // The morph class is a FQCN; only its last segment means anything to a reader.
        valueGetter: (p) =>
            p.data?.subject_type
                ? `${p.data.subject_type.split('\\').pop()} #${p.data.subject_id}`
                : t('activity.subject.none'),
    },
    {
        field: 'computer_name',
        headerName: t('activity.columns.computer'),
        width: 150,
        valueGetter: (p) =>
            p.data?.computer_name ??
            (p.data?.device_id
                ? t('activity.device.unnamed', {
                      id: p.data.device_id.slice(-6),
                  })
                : ''),
    },
    {
        field: 'ip_address',
        headerName: t('activity.columns.ip'),
        width: 140,
        cellClass: 'font-mono',
    },
]);
</script>

<template>
    <Head :title="t('activity.title')" />

    <div class="flex min-w-0 flex-col gap-4 p-3 sm:p-4">
        <header class="min-w-0">
            <h1 class="text-lg font-semibold">{{ t('activity.title') }}</h1>
            <p class="text-muted-foreground mt-0.5 max-w-prose text-sm">
                {{ t('activity.description') }}
            </p>
        </header>

        <section
            class="bg-card rounded-md border p-3"
            :aria-label="t('activity.filters.title')"
        >
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end"
            >
                <div class="flex flex-col gap-1">
                    <label
                        class="text-muted-foreground text-xs"
                        for="filter-user"
                        >{{ t('activity.filters.user') }}</label
                    >
                    <select
                        id="filter-user"
                        v-model="form.user_id"
                        :class="selectClass"
                        @change="apply()"
                    >
                        <option value="">
                            {{ t('activity.filters.all_users') }}
                        </option>
                        <option
                            v-for="o in options.users"
                            :key="o.value"
                            :value="String(o.value)"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label
                        class="text-muted-foreground text-xs"
                        for="filter-action"
                        >{{ t('activity.filters.action') }}</label
                    >
                    <select
                        id="filter-action"
                        v-model="form.action"
                        :class="selectClass"
                        @change="apply()"
                    >
                        <option value="">
                            {{ t('activity.filters.all_actions') }}
                        </option>
                        <option
                            v-for="o in options.actions"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label
                        class="text-muted-foreground text-xs"
                        for="filter-device"
                        >{{ t('activity.filters.device') }}</label
                    >
                    <select
                        id="filter-device"
                        v-model="form.device_id"
                        :class="selectClass"
                        @change="apply()"
                    >
                        <option value="">
                            {{ t('activity.filters.all_devices') }}
                        </option>
                        <option
                            v-for="o in options.devices"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label
                        class="text-muted-foreground text-xs"
                        for="filter-from"
                        >{{ t('activity.filters.from') }}</label
                    >
                    <input
                        id="filter-from"
                        v-model="form.from"
                        type="date"
                        :class="selectClass"
                        @change="apply()"
                    />
                </div>

                <div class="flex flex-col gap-1">
                    <label
                        class="text-muted-foreground text-xs"
                        for="filter-to"
                    >
                        {{ t('activity.filters.to') }}</label
                    >
                    <input
                        id="filter-to"
                        v-model="form.to"
                        type="date"
                        :class="selectClass"
                        @change="apply()"
                    />
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    data-test="apply-filters"
                    @click="apply()"
                >
                    {{ t('activity.filters.apply') }}
                </Button>
                <Button
                    variant="ghost"
                    class="min-h-11 sm:min-h-9"
                    data-test="reset-filters"
                    @click="reset"
                >
                    {{ t('activity.filters.reset') }}
                </Button>
            </div>
        </section>

        <DataGrid :rows="data" :columns="columns" />

        <nav
            class="flex flex-wrap items-center justify-between gap-2"
            :aria-label="
                t('activity.paging.page', {
                    page: meta.current_page,
                    pages: meta.last_page,
                })
            "
        >
            <p class="text-muted-foreground text-sm tabular-nums">
                {{ showing }}
            </p>
            <div class="flex items-center gap-2">
                <span class="text-muted-foreground text-sm tabular-nums">{{
                    t('activity.paging.page', {
                        page: meta.current_page,
                        pages: meta.last_page,
                    })
                }}</span>
                <Button
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="meta.current_page <= 1"
                    data-test="previous-page"
                    @click="apply(meta.current_page - 1)"
                >
                    <ChevronLeft class="size-4 rtl:rotate-180" />
                    {{ t('activity.paging.previous') }}
                </Button>
                <Button
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="meta.current_page >= meta.last_page"
                    data-test="next-page"
                    @click="apply(meta.current_page + 1)"
                >
                    {{ t('activity.paging.next') }}
                    <ChevronRight class="size-4 rtl:rotate-180" />
                </Button>
            </div>
        </nav>
    </div>
</template>
