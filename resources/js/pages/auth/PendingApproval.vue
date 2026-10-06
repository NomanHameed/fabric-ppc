<script setup lang="ts">
import { CircleX, Hourglass, MailWarning } from '@lucide/vue';
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

/**
 * The dead end an unapproved account lands in, and the only screen EnsureUserIsApproved lets it reach.
 *
 * It shows one of three states and nothing else: waiting on the email link, waiting on a moderator, or
 * refused with the reason. There is deliberately no link anywhere but logout (plus "resend the link" while the
 * address is unconfirmed) — every other route would bounce straight back here.
 */
const props = defineProps<{
    status: string;
    rejected: boolean;
    rejectedReason: string | null;
    emailVerified: boolean;
    email: string;
}>();

/** Rejection outranks an unconfirmed address: confirming it would change nothing. */
const state = computed<'rejected' | 'verify' | 'pending'>(() =>
    props.rejected ? 'rejected' : props.emailVerified ? 'pending' : 'verify',
);

const heading = computed(() => t(`approval.${state.value}.title`));
const body = computed(() => t(`approval.${state.value}.description`));

watchEffect(() => {
    setLayoutProps({ title: heading.value, description: body.value });
});
</script>

<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-6">
        <div
            class="flex items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium"
            :class="
                state === 'rejected'
                    ? 'bg-destructive-surface text-destructive-on-surface'
                    : state === 'verify'
                      ? 'bg-warning-surface text-warning-on-surface'
                      : 'bg-info-surface text-info-on-surface'
            "
            role="status"
        >
            <CircleX v-if="state === 'rejected'" class="size-4 shrink-0" />
            <MailWarning
                v-else-if="state === 'verify'"
                class="size-4 shrink-0"
            />
            <Hourglass v-else class="size-4 shrink-0" />
            <span>{{ heading }}</span>
        </div>

        <dl class="grid gap-1 text-sm">
            <dt class="text-muted-foreground text-xs">
                {{ t('approval.fields.email') }}
            </dt>
            <dd class="font-medium break-all">{{ email }}</dd>

            <template v-if="state === 'rejected' && rejectedReason">
                <dt class="text-muted-foreground mt-3 text-xs">
                    {{ t('approval.rejected.reason') }}
                </dt>
                <dd class="text-destructive font-medium">
                    {{ rejectedReason }}
                </dd>
            </template>
        </dl>

        <p
            v-if="state === 'rejected'"
            class="text-muted-foreground text-sm text-balance"
        >
            {{ t('approval.rejected.contact') }}
        </p>

        <!-- Only offered while the address is unconfirmed; the pending and rejected states have nothing to resend. -->
        <Form
            v-if="state === 'verify'"
            v-bind="send.form()"
            class="flex flex-col gap-2"
            v-slot="{ processing }"
        >
            <p class="text-muted-foreground text-sm text-balance">
                {{ t('approval.pending.verify_first') }}
            </p>
            <Button
                type="submit"
                variant="secondary"
                class="w-full"
                :disabled="processing"
                data-test="resend-verification-button"
            >
                <Spinner v-if="processing" />
                {{ t('auth.pages.verify.resend') }}
            </Button>
        </Form>

        <Form v-bind="logout.form()" v-slot="{ processing }">
            <Button
                type="submit"
                variant="ghost"
                class="w-full"
                :disabled="processing"
                data-test="pending-logout-button"
            >
                {{ t('approval.pending.logout') }}
            </Button>
        </Form>
    </div>
</template>
