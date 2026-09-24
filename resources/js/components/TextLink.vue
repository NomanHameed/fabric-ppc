<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

type Props = {
    href: LinkComponentBaseProps['href'];
    tabindex?: number;
    method?: Method;
    as?: string;
};

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="text-primary decoration-primary/40 hover:decoration-primary rounded-sm underline underline-offset-4 transition-colors duration-200 ease-out focus-visible:outline-2 focus-visible:outline-offset-2"
    >
        <slot />
    </Link>
</template>
