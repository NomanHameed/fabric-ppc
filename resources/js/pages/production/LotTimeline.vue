<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import LovField from '@/components/forms/LovField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { getJson } from '@/lib/api';
import { can } from '@/lib/can';
import { formatQty } from '@/lib/dates';
import { t, useI18n } from '@/lib/i18n';
import { groupByDay, movement } from '@/lib/timeline';
import type { TimelineResponse } from '@/types/analytics';

/**
 * A lot's story: loaded → issued → moved to dyeing → approved by X → dispatched.
 *
 * Deliberately not a grid. The same rows in a table read as an audit extract; grouped by day, with the actor
 * and the place on each line, they read as what happened to the lot — which is the question someone standing
 * at the machine is actually asking.
 *
 * The events come from `GET /production/lots/{lot}/timeline`, the read-model over `lot_movements`, `audits`,
 * `colour_overrides` and `user_actions`. This screen writes nothing.
 */
type LotHeader = {
    id: number;
    lot_no_oracle: string;
    po_no: number;
    contract_id: number;
    party: string;
    quality: string | null;
    design: string | null;
    color_oracle_name: string | null;
    clr_status_oracle: string | null;
    po_type_oracle: string;
    state: string;
    status_oracle: string | null;
    greige_received_oracle: number;
    wip: number;
    finished: number;
    dispatch: number;
};

const props = defineProps<{ lot: LotHeader | null; types: string[] }>();

const { locale } = useI18n();

const lotId = ref<number | null>(props.lot?.id ?? null);
const lotDisplay = ref(props.lot?.lot_no_oracle ?? '');
const events = ref<TimelineResponse['events']>([]);
const loading = ref(false);
const failed = ref(false);
/** Empty means "everything"; the endpoint takes the same convention. */
const selectedTypes = ref<string[]>([]);

/**
 * How many events are asked for.
 *
 * The endpoint returns *every* event when no limit is given, and a lot that has been reprocessed a few times
 * carries hundreds — each one an `<li>` with a rail, a badge and three lines of text. Rendering all of them
 * costs the reader nothing they wanted: a timeline is read from the top. So the screen asks for a bounded
 * page, says plainly when it has one, and offers the rest on demand up to the endpoint's own ceiling of 500.
 */
const PAGE_LIMIT = 200;
const FULL_LIMIT = 500;
const limit = ref(PAGE_LIMIT);

async function load(): Promise<void> {
    if (props.lot === null) {
        events.value = [];
        return;
    }
    loading.value = true;
    failed.value = false;
    const params = new URLSearchParams();
    for (const type of selectedTypes.value) {
        params.append('types[]', type);
    }
    params.set('limit', String(limit.value));
    try {
        const res = await getJson<TimelineResponse>(
            `/production/lots/${props.lot.id}/timeline?${params.toString()}`,
        );
        events.value = res.events;
    } catch {
        failed.value = true;
        events.value = [];
    } finally {
        loading.value = false;
    }
}

/**
 * A full page back means the lot probably has more; it might have exactly this many, which is why the notice
 * says "there may be more" rather than claiming a number it cannot know.
 */
const capped = computed(() => events.value.length >= limit.value);

function loadAll(): void {
    limit.value = FULL_LIMIT;
    void load();
}

onMounted(() => void load());
watch(
    () => props.lot?.id,
    () => {
        // A different lot is a different question: it starts from the first page again.
        limit.value = PAGE_LIMIT;
        void load();
    },
);
// Every event sentence is written server-side in the current locale, so a language change has to re-ask.
watch(locale, () => void load());

function openLot(id: number | null): void {
    if (id === null) {
        router.get('/production/timeline', {}, { preserveState: false });
        return;
    }
    router.get('/production/timeline', { lot: id }, { preserveState: false });
}

function toggleType(type: string): void {
    selectedTypes.value = selectedTypes.value.includes(type)
        ? selectedTypes.value.filter((s) => s !== type)
        : [...selectedTypes.value, type];
    limit.value = PAGE_LIMIT;
    void load();
}

function showAll(): void {
    selectedTypes.value = [];
    limit.value = PAGE_LIMIT;
    void load();
}

const days = computed(() => groupByDay(events.value));

const DOT: Record<string, string> = {
    neutral: 'bg-muted-foreground',
    brand: 'bg-primary',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    info: 'bg-info',
};

const quantities = computed(() =>
    props.lot === null
        ? []
        : [
              { key: 'greige', value: props.lot.greige_received_oracle },
              { key: 'wip', value: props.lot.wip },
              { key: 'finished', value: props.lot.finished },
              { key: 'dispatched', value: props.lot.dispatch },
          ],
);

const place = (event: TimelineResponse['events'][number]): string | null =>
    movement(event, (r) => t('timeline.screen.from_to', r));
</script>

<template>
    <Head :title="t('timeline.title')" />

    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-lg font-semibold">{{ t('timeline.title') }}</h1>
            <span v-if="lot" class="text-muted-foreground text-sm tabular-nums">
                {{ lot.lot_no_oracle }}
            </span>
            <div class="ms-auto flex flex-wrap items-center gap-3">
                <Link
                    v-if="lot"
                    class="text-primary text-sm underline"
                    :href="`/inquiry/lots/${lot.id}`"
                    >{{ t('timeline.screen.open_lot') }}</Link
                >
                <Link
                    v-if="lot && can('production.route.manage')"
                    class="text-primary text-sm underline"
                    :href="`/production/routes?lot=${lot.id}`"
                    >{{ t('timeline.screen.open_route') }}</Link
                >
            </div>
        </div>

        <FormBlock :title="t('timeline.screen.lot_block')">
            <FormField
                :label="t('production.fields.lot_no_oracle')"
                for="f-lot"
                :span="4"
                :hint="t('timeline.screen.lot_hint')"
            >
                <LovField
                    id="f-lot"
                    v-model="lotId"
                    v-model:display="lotDisplay"
                    name="lots"
                    @selected="(row) => openLot(row.id)"
                />
            </FormField>
            <template v-if="lot">
                <FormField :label="t('production.fields.po_no')" :span="2">
                    <Input
                        :model-value="String(lot.po_no)"
                        readonly
                        class="tabular-nums"
                    />
                </FormField>
                <FormField :label="t('production.fields.party')" :span="3">
                    <Input :model-value="lot.party" readonly />
                </FormField>
                <FormField :label="t('production.fields.state')" :span="3">
                    <Input
                        :model-value="t(`production.states.${lot.state}`)"
                        readonly
                    />
                </FormField>
                <FormField :label="t('production.fields.quality')" :span="5">
                    <Input :model-value="lot.quality ?? ''" readonly />
                </FormField>
                <FormField
                    :label="t('production.fields.color_oracle_name')"
                    :span="3"
                >
                    <Input
                        :model-value="lot.color_oracle_name ?? ''"
                        readonly
                    />
                </FormField>
                <FormField
                    :label="t('production.fields.status_oracle')"
                    :span="4"
                >
                    <Input :model-value="lot.status_oracle ?? ''" readonly />
                </FormField>
                <FormField
                    v-for="q in quantities"
                    :key="q.key"
                    :label="t(`production.fields.${q.key}`)"
                    :span="3"
                >
                    <Input
                        :model-value="formatQty(q.value)"
                        readonly
                        class="text-end tabular-nums"
                    />
                </FormField>
            </template>
        </FormBlock>

        <p v-if="!lot" class="text-muted-foreground text-sm">
            {{ t('timeline.screen.pick_lot') }}
        </p>

        <Card v-else class="min-w-0">
            <CardHeader class="flex flex-col gap-3 pb-2">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <CardTitle class="text-sm">{{
                        t('timeline.screen.events')
                    }}</CardTitle>
                    <span class="text-muted-foreground text-xs tabular-nums">{{
                        t('timeline.screen.count', { n: events.length })
                    }}</span>
                    <template v-if="capped">
                        <span
                            class="text-muted-foreground text-xs"
                            data-test="timeline-capped"
                            >{{
                                t('timeline.screen.capped', { n: limit })
                            }}</span
                        >
                        <button
                            v-if="limit < FULL_LIMIT"
                            type="button"
                            class="text-primary text-xs underline underline-offset-4"
                            data-test="timeline-load-all"
                            @click="loadAll"
                        >
                            {{ t('timeline.screen.load_all') }}
                        </button>
                    </template>
                </div>
                <div class="-mx-1 flex flex-wrap gap-1.5 px-1">
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            selectedTypes.length === 0 ? 'default' : 'outline'
                        "
                        @click="showAll"
                    >
                        {{ t('timeline.screen.all_types') }}
                    </Button>
                    <Button
                        v-for="type in types"
                        :key="type"
                        type="button"
                        size="sm"
                        :variant="
                            selectedTypes.includes(type) ? 'default' : 'outline'
                        "
                        :aria-pressed="selectedTypes.includes(type)"
                        @click="toggleType(type)"
                    >
                        {{ t(`timeline.types.${type}`) }}
                    </Button>
                </div>
            </CardHeader>
            <CardContent class="min-w-0">
                <div
                    v-if="failed"
                    class="border-destructive/50 bg-destructive-surface text-destructive-on-surface flex flex-wrap items-center gap-3 rounded-md border px-3 py-2 text-sm"
                >
                    <span>{{ t('timeline.screen.failed') }}</span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="load"
                    >
                        {{ t('timeline.screen.retry') }}
                    </Button>
                </div>
                <p
                    v-else-if="loading"
                    class="text-muted-foreground py-6 text-center text-sm"
                >
                    {{ t('timeline.screen.loading') }}
                </p>
                <p
                    v-else-if="days.length === 0"
                    class="text-muted-foreground py-6 text-center text-sm"
                >
                    {{ t('timeline.empty') }}
                </p>
                <div v-else class="flex flex-col gap-5">
                    <section v-for="day in days" :key="day.key" class="min-w-0">
                        <h2
                            class="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase tabular-nums"
                        >
                            {{ day.label }}
                        </h2>
                        <ol class="flex min-w-0 flex-col">
                            <li
                                v-for="(event, i) in day.events"
                                :key="event.id"
                                class="flex min-w-0 gap-3"
                            >
                                <!-- the rail: a flex column, so it needs no left/right and mirrors for free -->
                                <div
                                    class="flex shrink-0 flex-col items-center"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="mt-1.5 size-2.5 rounded-full"
                                        :class="DOT[event.tone]"
                                    ></span>
                                    <span
                                        v-if="i < day.events.length - 1"
                                        class="bg-border w-px flex-1"
                                    ></span>
                                </div>
                                <div class="min-w-0 flex-1 pb-4">
                                    <p class="text-sm">{{ event.title }}</p>
                                    <div
                                        class="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs"
                                    >
                                        <span class="tabular-nums">{{
                                            event.time
                                        }}</span>
                                        <span>{{
                                            event.actor ??
                                            t('timeline.screen.system_actor')
                                        }}</span>
                                        <Badge
                                            v-if="place(event)"
                                            variant="outline"
                                            class="text-muted-foreground font-normal"
                                            >{{ place(event) }}</Badge
                                        >
                                    </div>
                                    <p
                                        v-if="event.note"
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ event.note }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
