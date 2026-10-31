<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useQueryMode } from '@/composables/useQueryMode';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import {
    index as sourceIndex,
    show as sourceShow,
} from '@/routes/oracle/source';
import type {
    SourceColumnRow,
    SourceConnection,
    SourceError,
    SourceFilters,
    SourceObject,
    SourceObjectPage,
    SourceObjectRow,
    SourceObjectType,
    SourceOwner,
    SourceSelection,
} from '@/types/oracle';

/**
 * The Oracle source schema browser: master/detail over the *data dictionary* of the connected Oracle
 * instance — the objects it holds on the left, the selected object's columns below.
 *
 * It exists because the eleven tables the importer expects are not in the instance the mill supplied, so
 * the real object names have to be discoverable from inside the application before anything can be mapped.
 *
 * Two things about this screen are deliberate and not decoration:
 *
 * - **It is the only screen that reads the client's production ERP**, so it says so, loudly and
 *   permanently — the banner, the badge and the status line all repeat it.
 * - **Save, New record and Delete record are disabled, always.** Not hidden: an operator used to these
 *   forms should see the three buttons that change records greyed out and know the screen cannot write,
 *   rather than wonder where they went.
 *
 * Oracle-Forms behaviour otherwise as everywhere else: F7 enters criteria, F8 runs them, Page Up / Page
 * Down walk the objects (crossing page boundaries), Ctrl+P prints, Ctrl+Q leaves.
 */
const props = defineProps<{
    connection: SourceConnection;
    filters: SourceFilters;
    defaultOwner: string | null;
    owners: SourceOwner[];
    types: SourceObjectType[];
    objects: SourceObjectPage;
    selected: SourceSelection | null;
    error: SourceError | null;
}>();

const selectClass =
    'bg-background h-11 w-full rounded-md border px-2 text-sm sm:h-9';

/**
 * Nothing on this screen can be edited, so nothing can ever be dirty — but record navigation still goes
 * through the guard, because that is how every other screen in the app navigates and the day a field does
 * become editable here, the guard is already in place.
 */
const guard = useUnsavedGuard(
    () => false,
    () => Promise.resolve(false),
);

const query = useQueryMode<{
    q: string | null;
    owner: string | null;
    type: string | null;
}>(['q', 'owner', 'type']);
const inQuery = computed(() => query.mode.value === 'enterQuery');

/** The applied criteria, or — while in query mode — what is being typed. */
const shown = computed(() => ({
    q: inQuery.value ? (query.criteria.q ?? '') : (props.filters.q ?? ''),
    owner: inQuery.value
        ? (query.criteria.owner ?? '')
        : (props.filters.owner ?? ''),
    type: inQuery.value
        ? (query.criteria.type ?? '')
        : (props.filters.type ?? ''),
}));

function setCriterion(field: 'q' | 'owner' | 'type', value: string): void {
    query.criteria[field] = value === '' ? null : value;
}

/** ag-Grid keys its nodes by `id`; owner + name is the identity of an Oracle object. */
const rows = computed<SourceObjectRow[]>(() =>
    props.objects.rows.map((o: SourceObject) => ({
        ...o,
        id: `${o.owner}.${o.name}`,
    })),
);

const selectedId = computed(() =>
    props.selected === null
        ? null
        : `${props.selected.owner}.${props.selected.name}`,
);

const selectedIndex = computed(() =>
    rows.value.findIndex((r) => r.id === selectedId.value),
);

const offset = computed(
    () => (props.objects.page - 1) * props.objects.per_page,
);

const showing = computed(() =>
    props.objects.total === 0
        ? t('oracle.source.none')
        : t('oracle.source.showing', {
              from: offset.value + 1,
              to: offset.value + rows.value.length,
              total: props.objects.total,
          }),
);

const pageLabel = computed(() =>
    t('oracle.source.page_of', {
        page: props.objects.page,
        pages: props.objects.pages,
    }),
);

const columnRows = computed<SourceColumnRow[]>(() =>
    (props.selected?.columns ?? []).map((c) => ({ ...c, id: c.position })),
);

/* ---- navigation ------------------------------------------------------------------------------------- */

type Criteria = { q: string; owner: string; type: string; page: number };

function currentCriteria(): Criteria {
    return {
        q: props.filters.q ?? '',
        // An owner that was explicitly cleared must stay cleared, so this is the applied value verbatim —
        // the server only falls back to the login's own schema when `owner` is absent altogether.
        owner: props.filters.owner ?? '',
        type: props.filters.type ?? '',
        page: props.objects.page,
    };
}

/** Only the criteria that are set, so the URL stays readable and `owner=` keeps its "all owners" meaning. */
function queryFor(criteria: Criteria): Record<string, string | number> {
    const out: Record<string, string | number> = { owner: criteria.owner };
    if (criteria.q !== '') {
        out.q = criteria.q;
    }
    if (criteria.type !== '') {
        out.type = criteria.type;
    }
    if (criteria.page > 1) {
        out.page = criteria.page;
    }
    return out;
}

function go(criteria: Criteria, object?: SourceObject): void {
    const options = { query: queryFor(criteria) };
    guard.guard(() =>
        router.visit(
            object === undefined
                ? sourceIndex.url(options)
                : sourceShow.url(
                      { owner: object.owner, object: object.name },
                      options,
                  ),
            { preserveScroll: true },
        ),
    );
}

function executeQuery(): void {
    const taken = query.takeCriteria();
    go({
        q: taken.q ?? '',
        // `owner` typed as empty means every owner; the field is part of the criteria either way.
        owner: taken.owner ?? '',
        type: taken.type ?? '',
        page: 1,
    });
}

function clearQuery(): void {
    query.cancelQuery();
    go({
        q: '',
        owner: props.defaultOwner ?? '',
        type: '',
        page: 1,
    });
}

function select(object: SourceObject): void {
    go(currentCriteria(), object);
}

function clearSelection(): void {
    go(currentCriteria());
}

function toPage(page: number): void {
    if (page < 1 || page > props.objects.pages) {
        return;
    }
    go({ ...currentCriteria(), page });
}

/**
 * Previous / Next walk the objects, not the pages: at the top or bottom of a page they carry on into the
 * neighbouring one, which is what Page Up / Page Down do in the mill's own forms.
 */
function step(delta: -1 | 1): void {
    const i = selectedIndex.value;

    if (i >= 0 && i + delta >= 0 && i + delta < rows.value.length) {
        select(rows.value[i + delta]);
        return;
    }

    toPage(props.objects.page + delta);
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'enterQuery':
            query.enterQuery();
            statusLine.setMessage(t('oracle.source.query_mode_hint'));
            break;
        case 'executeQuery':
            if (inQuery.value) {
                executeQuery();
            } else {
                go({ ...currentCriteria(), page: 1 });
            }
            break;
        case 'previousRecord':
            step(-1);
            break;
        case 'nextRecord':
            step(1);
            break;
        case 'clear':
            if (inQuery.value) {
                query.cancelQuery();
            } else {
                clearQuery();
            }
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
        // save / newRecord / deleteRecord are disabled below: the Oracle source is read-only, forever.
    }
}

/** The three record-changing actions are off permanently; the rest follow what there is to act on. */
const disabled = computed<Partial<Record<FormsAction, boolean>>>(() => ({
    save: true,
    newRecord: true,
    deleteRecord: true,
    executeQuery: props.error !== null,
    previousRecord:
        props.error !== null ||
        (props.objects.page === 1 && selectedIndex.value <= 0),
    nextRecord:
        props.error !== null ||
        (props.objects.page >= props.objects.pages &&
            (selectedIndex.value < 0 ||
                selectedIndex.value >= rows.value.length - 1)),
}));

/* ---- status line ----------------------------------------------------------------------------------- */

watch(
    [() => props.objects, () => props.selected, () => props.error, inQuery],
    () => {
        const i = selectedIndex.value;
        statusLine.setRecord(
            i >= 0
                ? offset.value + i + 1
                : rows.value.length > 0
                  ? offset.value + 1
                  : 0,
            props.objects.total,
        );
        statusLine.setDirty(false);

        if (props.error !== null) {
            statusLine.setMessage(props.error.message, 'error');
        } else if (inQuery.value) {
            statusLine.setMessage(t('oracle.source.query_mode_hint'));
        } else {
            statusLine.setMessage(
                props.connection.mocked
                    ? t('oracle.source.standin_warning')
                    : t('oracle.source.live_warning'),
            );
        }

        statusLine.setHint(
            props.connection.mocked
                ? ''
                : `${t('oracle.source.read_only')} · ${props.connection.username}@${props.connection.service}`,
        );
    },
    { immediate: true, deep: true },
);

/* ---- grids ----------------------------------------------------------------------------------------- */

const formatCount = (v: unknown): string =>
    typeof v === 'number' ? v.toLocaleString() : t('oracle.source.unknown');

const objectColumns = computed<ColumnDef<SourceObjectRow>[]>(() => [
    {
        field: 'owner',
        headerName: t('oracle.source.fields.owner'),
        width: 130,
        cellClass: 'font-mono',
    },
    {
        field: 'name',
        headerName: t('oracle.source.fields.object'),
        flex: 2,
        minWidth: 180,
        cellClass: 'font-mono',
    },
    {
        field: 'type',
        headerName: t('oracle.source.fields.type'),
        width: 100,
        valueFormatter: (p) => t(`oracle.source.types.${String(p.value)}`),
    },
    {
        field: 'columns',
        headerName: t('oracle.source.fields.columns'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: (p) => formatCount(p.value),
    },
    {
        field: 'rows',
        headerName: t('oracle.source.fields.rows'),
        width: 130,
        type: 'numericColumn',
        valueFormatter: (p) => formatCount(p.value),
    },
    {
        field: 'analysed_at',
        headerName: t('oracle.source.fields.analysed_at'),
        width: 140,
        valueFormatter: (p) =>
            typeof p.value === 'string' && p.value !== ''
                ? p.value
                : t('oracle.source.unknown'),
    },
]);

const columnColumns = computed<ColumnDef<SourceColumnRow>[]>(() => [
    {
        field: 'position',
        headerName: t('oracle.source.column_fields.position'),
        width: 70,
        type: 'numericColumn',
    },
    {
        field: 'name',
        headerName: t('oracle.source.column_fields.name'),
        flex: 2,
        minWidth: 160,
        cellClass: 'font-mono',
    },
    {
        field: 'type',
        headerName: t('oracle.source.column_fields.type'),
        flex: 1,
        minWidth: 150,
        cellClass: 'font-mono',
    },
    {
        field: 'nullable',
        headerName: t('oracle.source.column_fields.nullable'),
        width: 110,
        valueFormatter: (p) =>
            p.value === true ? t('oracle.source.yes') : t('oracle.source.no'),
    },
    {
        field: 'default',
        headerName: t('oracle.source.column_fields.default'),
        flex: 1,
        minWidth: 120,
        cellClass: 'font-mono',
        valueFormatter: (p) =>
            typeof p.value === 'string' && p.value !== ''
                ? p.value
                : t('oracle.source.unknown'),
    },
]);

const connectionFields = computed(() => [
    { key: 'driver', value: props.connection.driver },
    { key: 'user', value: props.connection.username },
    {
        key: 'host',
        value:
            props.connection.host === ''
                ? t('oracle.source.connection.none')
                : `${props.connection.host}:${props.connection.port}`,
    },
    {
        key: 'service',
        value:
            props.connection.service === ''
                ? t('oracle.source.connection.none')
                : props.connection.service,
    },
    {
        key: 'schema_prefix',
        value:
            props.connection.schema_prefix === ''
                ? t('oracle.source.connection.none')
                : props.connection.schema_prefix,
    },
    { key: 'server_version', value: props.connection.server_version },
]);

const ownersTotal = computed(() =>
    t('oracle.source.owners_total', {
        owners: props.owners.length,
        objects: props.owners.reduce((n, o) => n + o.objects, 0),
    }),
);
</script>

<template>
    <Head :title="t('oracle.source.title')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <header class="flex flex-wrap items-center gap-2">
            <h1 class="text-lg font-semibold">
                {{ t('oracle.source.title') }}
            </h1>
            <Badge
                variant="outline"
                class="border-destructive/60 bg-destructive-surface text-destructive-on-surface"
                data-testid="read-only-badge"
            >
                {{ t('oracle.source.read_only') }}
            </Badge>
            <span
                v-if="owners.length > 0"
                class="text-muted-foreground ms-auto text-xs tabular-nums"
            >
                {{ ownersTotal }}
            </span>
        </header>

        <!--
          Unmistakable, on every render, for the live instance and the stand-in alike: whoever opens this
          screen must never have to wonder whether they can change something here.
        -->
        <Alert
            :variant="connection.mocked ? 'default' : 'destructive'"
            data-testid="read-only-alert"
        >
            <ShieldAlert class="size-4" />
            <AlertTitle>{{ t('oracle.source.read_only') }}</AlertTitle>
            <AlertDescription>
                <p>
                    {{
                        connection.mocked
                            ? t('oracle.source.standin_warning')
                            : t('oracle.source.live_warning')
                    }}
                </p>
                <p class="text-muted-foreground">
                    {{ t('oracle.source.intro') }}
                </p>
            </AlertDescription>
        </Alert>

        <FormsToolbar
            :disabled="disabled"
            :mode="inQuery ? 'enterQuery' : 'normal'"
            @action="onAction"
        />

        <AlertError
            v-if="error"
            :title="error.message"
            :errors="
                [...error.hints, error.detail ?? '', error.code ?? ''].filter(
                    (line) => line !== '',
                )
            "
        />

        <FormBlock :title="t('oracle.source.connection.title')">
            <FormField
                v-for="field in connectionFields"
                :key="field.key"
                :label="t(`oracle.source.connection.${field.key}`)"
                :span="2"
            >
                <p class="truncate font-mono text-sm" :title="field.value">
                    {{ field.value }}
                </p>
            </FormField>
        </FormBlock>

        <FormBlock :title="t('oracle.source.filters.title')">
            <FormField
                :label="t('oracle.source.filters.search')"
                for="source-q"
                :span="4"
            >
                <Input
                    id="source-q"
                    :model-value="shown.q"
                    :disabled="!inQuery"
                    class="font-mono"
                    @update:model-value="
                        (v) => setCriterion('q', String(v ?? ''))
                    "
                    @keydown.enter.prevent="inQuery && executeQuery()"
                />
            </FormField>
            <FormField
                :label="t('oracle.source.filters.owner')"
                for="source-owner"
                :span="4"
            >
                <select
                    id="source-owner"
                    :value="shown.owner"
                    :disabled="!inQuery"
                    :class="selectClass"
                    @change="
                        (e) =>
                            setCriterion(
                                'owner',
                                (e.target as HTMLSelectElement).value,
                            )
                    "
                >
                    <option value="">
                        {{ t('oracle.source.filters.all_owners') }}
                    </option>
                    <option v-for="o in owners" :key="o.owner" :value="o.owner">
                        {{
                            t('oracle.source.owner_objects', {
                                owner: o.owner,
                                n: o.objects,
                            })
                        }}
                    </option>
                </select>
            </FormField>
            <FormField
                :label="t('oracle.source.filters.type')"
                for="source-type"
                :span="2"
            >
                <select
                    id="source-type"
                    :value="shown.type"
                    :disabled="!inQuery"
                    :class="selectClass"
                    @change="
                        (e) =>
                            setCriterion(
                                'type',
                                (e.target as HTMLSelectElement).value,
                            )
                    "
                >
                    <option value="">
                        {{ t('oracle.source.filters.all_types') }}
                    </option>
                    <option v-for="type in types" :key="type" :value="type">
                        {{ t(`oracle.source.types.${type}`) }}
                    </option>
                </select>
            </FormField>
            <div class="col-span-12 flex items-end gap-2 md:col-span-2">
                <Button
                    v-if="inQuery"
                    type="button"
                    size="sm"
                    data-testid="run-query"
                    @click="executeQuery()"
                >
                    {{ t('forms.actions.executeQuery') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="enter-query"
                    @click="onAction('enterQuery')"
                >
                    {{ t('forms.actions.enterQuery') }}
                </Button>
            </div>
            <p class="text-muted-foreground col-span-12 text-xs">
                {{
                    inQuery
                        ? t('oracle.source.query_mode_hint')
                        : t('oracle.source.pick')
                }}
            </p>
        </FormBlock>

        <section v-if="!error" class="flex min-w-0 flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-muted-foreground text-sm font-semibold">
                    {{ t('oracle.source.objects') }}
                </h2>
                <span class="text-muted-foreground text-xs tabular-nums">{{
                    showing
                }}</span>
                <div class="ms-auto flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="prev-page"
                        :disabled="objects.page <= 1"
                        @click="toPage(objects.page - 1)"
                    >
                        {{ t('forms.actions.previousRecord') }}
                    </Button>
                    <span class="text-muted-foreground text-xs tabular-nums">{{
                        pageLabel
                    }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="next-page"
                        :disabled="objects.page >= objects.pages"
                        @click="toPage(objects.page + 1)"
                    >
                        {{ t('forms.actions.nextRecord') }}
                    </Button>
                </div>
            </div>

            <DataGrid
                v-if="rows.length > 0"
                :rows="rows"
                :columns="objectColumns"
                :selected-id="selectedId"
                @row-selected="select"
                @row-activated="select"
            />
            <p v-else class="text-muted-foreground text-sm">
                {{ t('oracle.source.none') }}
            </p>

            <p class="text-muted-foreground text-xs">
                {{ t('oracle.source.rows_note') }}
            </p>
        </section>

        <section
            v-if="selected"
            class="flex min-w-0 flex-col gap-2"
            data-testid="columns-block"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-muted-foreground text-sm font-semibold">
                    {{
                        t('oracle.source.columns_title', {
                            object: `${selected.owner}.${selected.name}`,
                        })
                    }}
                </h2>
                <Badge variant="outline">{{
                    t(`oracle.source.types.${selected.type}`)
                }}</Badge>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="ms-auto"
                    data-testid="clear-selection"
                    @click="clearSelection()"
                >
                    {{ t('oracle.source.clear_selection') }}
                </Button>
            </div>
            <DataGrid
                v-if="columnRows.length > 0"
                :rows="columnRows"
                :columns="columnColumns"
            />
            <p v-else class="text-muted-foreground text-sm">
                {{ t('oracle.source.no_columns') }}
            </p>
        </section>

        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
    </div>
</template>
