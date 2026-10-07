<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { getJson, isAbortError } from '@/lib/api';
import { t } from '@/lib/i18n';
import type { Stage, StageFallback, StagesResponse } from '@/types/stages';

/**
 * The reusable reporting-stage select (spec §17). Its options come from `GET /api/stages` — never a
 * hard-coded list, so a stage added on the Stages master appears here without a deploy.
 *
 * Only active stages are listed, because the list exists to be chosen from. `fallback` is the value the
 * record already carries when that stage has since been deactivated: it is added as an option so the screen
 * can still name it instead of silently showing an empty box.
 */
const props = defineProps<{
    modelValue: number | null;
    disabled?: boolean;
    id?: string;
    /** The record's current stage, shown even if it is no longer active. */
    fallback?: StageFallback;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
    loaded: [stages: Stage[]];
}>();

const stages = ref<Stage[]>([]);
const loading = ref(false);
const failed = ref(false);
let controller: AbortController | null = null;

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    failed.value = false;
    try {
        const res = await getJson<StagesResponse>(
            '/api/stages',
            controller.signal,
        );
        stages.value = res.data;
        emit('loaded', res.data);
    } catch (error) {
        // A load this component abandoned must not paint an error over a healthy screen.
        if (!isAbortError(error)) {
            failed.value = true;
        }
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());
onBeforeUnmount(() => controller?.abort());

/** The current stage, kept as an option while it is missing from the active list. */
const extra = ref<StageFallback>(null);
watch(
    [() => props.fallback, () => props.modelValue, stages],
    ([fallback, value, list]) => {
        extra.value =
            fallback !== null &&
            fallback !== undefined &&
            fallback.id === value &&
            !list.some((s) => s.id === value)
                ? fallback
                : null;
    },
    { immediate: true },
);

function onChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    emit('update:modelValue', value === '' ? null : Number(value));
}

defineExpose({ reload: load });
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <select
            :id="id"
            :value="modelValue === null ? '' : String(modelValue)"
            :disabled="disabled || loading"
            class="bg-background h-9 w-full rounded-md border px-2 text-sm"
            @change="onChange"
        >
            <option value="">
                {{
                    loading
                        ? t('production.lots.stage_loading')
                        : t('production.lots.stage_pick')
                }}
            </option>
            <option v-if="extra" :value="String(extra.id)">
                {{ extra.name }}
            </option>
            <option v-for="s in stages" :key="s.id" :value="String(s.id)">
                {{ s.name }}
            </option>
        </select>
        <button
            v-if="failed"
            type="button"
            class="text-destructive text-start text-xs underline"
            @click="void load()"
        >
            {{ t('production.lots.stage_failed') }}
        </button>
    </div>
</template>
