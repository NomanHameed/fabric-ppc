<script setup lang="ts">
import {
    BadgeCheck,
    MailWarning,
    Monitor,
    UserCheck,
    UserX,
} from '@lucide/vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDateTime } from '@/components/activity/format';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@/composables/useConfirm';
import { useReasonPrompt } from '@/composables/useReasonPrompt';
import { t } from '@/lib/i18n';

/**
 * Signup moderation (decision A4). Every card is one account that currently receives nothing, so the decision
 * is the whole screen: approve lets them in immediately, reject keeps them out and records why.
 *
 * Rejecting always goes through ReasonDialog because the endpoint refuses without a reason — the dialog is the
 * only way to submit one, not a nicety on top of a form that would otherwise work.
 *
 * Cards rather than DataGrid: each row carries two actions, and an ag-Grid row is far below the 44px touch
 * target this branch requires on a phone.
 */
type PendingUser = {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    registered_at: string | null;
    ip_address: string | null;
    computer_name: string | null;
};

const props = defineProps<{ pending: PendingUser[] }>();

const confirm = useConfirm();
const prompt = useReasonPrompt();
/** The account a request is in flight for, so only its own buttons go quiet. */
const busy = ref<number | null>(null);
const error = ref('');

const count = computed(() => props.pending.length);

// A manager holds `admin.users.approve` but not `admin.users.manage`; offering them the user list would only
// hand them a 403.
const page = usePage();
const canManageUsers = computed(() =>
    (
        (page.props.auth as { permissions?: string[] })?.permissions ?? []
    ).includes('admin.users.manage'),
);

function send(user: PendingUser, path: string, data: Record<string, string>) {
    busy.value = user.id;
    error.value = '';
    router.post(`/admin/users/${user.id}/${path}`, data, {
        preserveScroll: true,
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? '';
        },
        onFinish: () => {
            busy.value = null;
        },
    });
}

async function approve(user: PendingUser): Promise<void> {
    const ok = await confirm.ask(
        t('approval.confirm_approve', { name: user.name }),
        { title: t('approval.actions.approve') },
    );
    if (ok) {
        send(user, 'approve', {});
    }
}

async function reject(user: PendingUser): Promise<void> {
    const reason = await prompt.ask({
        title: t('approval.reject_dialog.title', { name: user.name }),
        message: t('approval.reject_dialog.message'),
        label: t('approval.reject_dialog.label'),
        placeholder: t('approval.reject_dialog.placeholder'),
    });
    if (reason !== null) {
        send(user, 'reject', { rejected_reason: reason });
    }
}
</script>

<template>
    <Head :title="t('approval.queue.title')" />

    <div class="flex min-w-0 flex-col gap-4 p-3 sm:p-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold">
                    {{ t('approval.queue.title') }}
                </h1>
                <p class="text-muted-foreground mt-0.5 max-w-prose text-sm">
                    {{ t('approval.queue.description') }}
                </p>
            </div>
            <span
                v-if="count > 0"
                class="bg-warning-surface text-warning-on-surface rounded-full px-2.5 py-1 text-xs font-medium"
                >{{ t('approval.queue.waiting', { count }) }}</span
            >
        </header>

        <p
            v-if="error"
            class="bg-destructive-surface text-destructive-on-surface rounded-md px-3 py-2 text-sm"
            role="alert"
        >
            {{ error }}
        </p>

        <p
            v-if="count === 0"
            class="bg-card text-muted-foreground rounded-md border px-3 py-8 text-center text-sm"
        >
            {{ t('approval.queue.empty') }}
        </p>

        <ul v-else class="grid gap-3 lg:grid-cols-2">
            <li
                v-for="user in pending"
                :key="user.id"
                class="bg-card flex flex-col gap-3 rounded-md border p-3"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <span class="truncate font-medium">{{ user.name }}</span>
                    <span class="text-muted-foreground truncate text-sm">{{
                        user.email
                    }}</span>
                </div>

                <dl
                    class="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
                >
                    <div class="flex items-center gap-1.5">
                        <dt>{{ t('approval.fields.registered_at') }}</dt>
                        <dd class="text-foreground tabular-nums">
                            {{ formatDateTime(user.registered_at) }}
                        </dd>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <dt class="sr-only">
                            {{ t('approval.fields.email_verified') }}
                        </dt>
                        <dd
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                            :class="
                                user.email_verified
                                    ? 'bg-success-surface text-success-on-surface'
                                    : 'bg-warning-surface text-warning-on-surface'
                            "
                        >
                            <BadgeCheck
                                v-if="user.email_verified"
                                class="size-3"
                            />
                            <MailWarning v-else class="size-3" />
                            {{
                                user.email_verified
                                    ? t('approval.verified')
                                    : t('approval.unverified')
                            }}
                        </dd>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <dt class="sr-only">
                            {{ t('approval.fields.device') }}
                        </dt>
                        <dd class="text-foreground flex items-center gap-1">
                            <Monitor class="size-3 shrink-0" />
                            <span class="truncate">{{
                                user.computer_name ?? t('approval.unknown')
                            }}</span>
                        </dd>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <dt>{{ t('approval.fields.ip') }}</dt>
                        <dd class="text-foreground font-mono tabular-nums">
                            {{ user.ip_address ?? t('approval.unknown') }}
                        </dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2">
                    <Button
                        class="min-h-11 flex-1"
                        :disabled="busy === user.id"
                        :data-test="`approve-${user.id}`"
                        @click="approve(user)"
                    >
                        <UserCheck class="size-4" />
                        {{ t('approval.actions.approve') }}
                    </Button>
                    <Button
                        variant="outline"
                        class="text-destructive min-h-11 flex-1"
                        :disabled="busy === user.id"
                        :data-test="`reject-${user.id}`"
                        @click="reject(user)"
                    >
                        <UserX class="size-4" />
                        {{ t('approval.actions.reject') }}
                    </Button>
                </div>
            </li>
        </ul>

        <p v-if="canManageUsers" class="text-sm">
            <Link
                href="/admin/users"
                class="text-primary underline-offset-4 hover:underline"
                >{{ t('approval.queue.users_link') }}</Link
            >
        </p>

        <ConfirmDialog
            :open="confirm.open.value"
            :message="confirm.message.value"
            v-bind="confirm.options.value"
            @confirm="confirm.choose(true)"
            @cancel="confirm.choose(false)"
        />
        <ReasonDialog
            :open="prompt.open.value"
            v-bind="prompt.options.value"
            @confirm="prompt.confirm"
            @cancel="prompt.cancel"
        />
    </div>
</template>
