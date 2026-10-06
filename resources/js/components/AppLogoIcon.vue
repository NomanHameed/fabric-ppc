<script setup lang="ts">
import { computed, type HTMLAttributes } from 'vue';
import { t } from '@/lib/i18n';

/**
 * The Albeen Textiles mark, redrawn as vector geometry from `public/images/albeen-logo.jpeg`
 * (which is a JPEG on an opaque white ground and therefore unusable on a dark sidebar).
 * The motif is a four-fold pinwheel — two rounded bars and one disc per quadrant, each
 * quadrant a 90 degrees rotation of the previous one — set on the diagonal by the outer
 * rotate(-45). Bars are bitten by a disc of radius 68 so the woven gap is carved out of the
 * shape itself rather than painted with a white stroke; that is what keeps the mark
 * transparent, so it sits correctly on any surface in either mode.
 *
 * Colours come from tokens, not from the image: `--brand` / `--brand-muted` are the logo's
 * green and grey in light mode and lightened equivalents in dark mode, so the mark keeps at
 * least 3:1 against every surface it is placed on. `tone="mono"` paints it in `currentColor`
 * for single-colour contexts (print, a solid brand bar, a favicon mask).
 */
defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        className?: HTMLAttributes['class'];
        tone?: 'brand' | 'mono';
        /** Decorative by default; set a title when the mark is the only thing identifying the app. */
        labelled?: boolean;
    }>(),
    { tone: 'brand', labelled: false },
);

const a = computed(() =>
    props.tone === 'mono' ? 'currentColor' : 'var(--brand)',
);
const b = computed(() =>
    props.tone === 'mono' ? 'currentColor' : 'var(--brand-muted)',
);
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 400 400"
        :class="className"
        :role="labelled ? 'img' : undefined"
        :aria-hidden="labelled ? undefined : 'true'"
        :aria-label="labelled ? t('app.brand.logo_alt') : undefined"
        v-bind="$attrs"
    >
        <g transform="rotate(-45 200 200)">
            <path :fill="a" d="M101 17H206.54a68 68 0 0 0 0 66H101Z" />
            <path :fill="b" d="M209.5 101H109.46a68 68 0 0 1 0 66H209.5Z" />
            <circle :fill="a" cx="266" cy="50" r="49.5" />
            <path
                :fill="b"
                d="M101 17H206.54a68 68 0 0 0 0 66H101Z"
                transform="rotate(90 200 200)"
            />
            <path
                :fill="a"
                d="M209.5 101H109.46a68 68 0 0 1 0 66H209.5Z"
                transform="rotate(90 200 200)"
            />
            <circle
                :fill="b"
                cx="266"
                cy="50"
                r="49.5"
                transform="rotate(90 200 200)"
            />
            <path
                :fill="a"
                d="M101 17H206.54a68 68 0 0 0 0 66H101Z"
                transform="rotate(180 200 200)"
            />
            <path
                :fill="b"
                d="M209.5 101H109.46a68 68 0 0 1 0 66H209.5Z"
                transform="rotate(180 200 200)"
            />
            <circle
                :fill="a"
                cx="266"
                cy="50"
                r="49.5"
                transform="rotate(180 200 200)"
            />
            <path
                :fill="b"
                d="M101 17H206.54a68 68 0 0 0 0 66H101Z"
                transform="rotate(270 200 200)"
            />
            <path
                :fill="a"
                d="M209.5 101H109.46a68 68 0 0 1 0 66H209.5Z"
                transform="rotate(270 200 200)"
            />
            <circle
                :fill="b"
                cx="266"
                cy="50"
                r="49.5"
                transform="rotate(270 200 200)"
            />
        </g>
    </svg>
</template>
