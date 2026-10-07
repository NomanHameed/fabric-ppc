<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { getJson, isAbortError } from '@/lib/api';
import { t } from '@/lib/i18n';
import type {
    StageFallback,
    StageProcess,
    StageProcessesResponse,
} from '@/types/stages';

/**
 * The reusable stage-process select (spec §17). Its options are the processes of the chosen stage, read from
 * `GET /api/stages/{stage}/processes` — so the list follows the stage above it and is never hard-coded.
 *
 * With no stage chosen there is nothing to choose from, and the select says so rather than offering every
 * process in the mill. When the stage changes, a value that does not belong to the new stage is cleared
 * upwards (emitting null), so the pair the form submits is always consistent.
 */
const props = defineProps<{
    modelValue: number | null;
    stageId: number | null;
    disabled?: boolean;
    id?: string;
    /** The record's current process, shown even if it is no longer active. */
    fallback?: StageFallback;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
    loaded: [processes: StageProcess[]];
}>();

const processes = ref<StageProcess[]>([]);
const loading = ref(false);
const failed = ref(false);
let controller: AbortController | null = null;

async function load(stageId: number | null): Promise<void> {
    controller?.abort();
    processes.value = [];
    failed.value = false;
    if (stageId === null) {
        return;
    }
    controller = new AbortController();
    loading.value = true;
    try {
        const res = await getJson<StageProcessesResponse>(
            `/api/stages/${stageId}/processes`,
            controller.signal,
        );
        processes.value = res.data;
        emit('loaded', res.data);
    } catch (error) {
        if (!isAbortError(error)) {
            failed.value = true;
        }
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.stageId,
    (id) => void load(id),
    { immediate: true },
);
onBeforeUnmount(() => controller?.abort());

/** The current process, kept as an option while it is missing from the active list of its own stage. */
const extra = computed<StageFallback>(() =>
    props.fallback !== null &&
    props.fallback !== undefined &&
    props.fallback.id === props.modelValue &&
    !processes.value.some((p) => p.id === props.modelValue)
        ? props.fallback
        : null,
);

// A process of the previous stage must never stay selected: the pair would not be a pair any more.
watch([processes, () => props.modelValue], ([list, value]) => {
    if (
        value !== null &&
        !loading.value &&
        props.stageId !== null &&
        !list.some((p) => p.id === value) &&
        extra.value === null
    ) {
        emit('update:modelValue', null);
    }
});

function onChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    emit('update:modelValue', value === '' ? null : Number(value));
}

const placeholder = computed(() =>
    props.stageId === null
        ? t('production.lots.stage_pick_stage_first')
        : loading.value
          ? t('production.lots.stage_loading')
          : t('production.lots.stage_process_pick'),
);

defineExpose({ reload: () => load(props.stageId) });
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <select
            :id="id"
            :value="modelValue === null ? '' : String(modelValue)"
            :disabled="disabled || loading || stageId === null"
            class="bg-background h-9 w-full rounded-md border px-2 text-sm"
            @change="onChange"
        >
            <option value="">{{ placeholder }}</option>
            <option v-if="extra" :value="String(extra.id)">
                {{ extra.name }}
            </option>
            <option v-for="p in processes" :key="p.id" :value="String(p.id)">
                {{ p.name }}
            </option>
        </select>
        <button
            v-if="failed"
            type="button"
            class="text-destructive text-start text-xs underline"
            @click="void load(stageId)"
        >
            {{ t('production.lots.stage_failed') }}
        </button>
    </div>
</template>
