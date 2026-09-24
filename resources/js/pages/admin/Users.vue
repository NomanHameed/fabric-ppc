<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApprovalBadge from '@/components/activity/ApprovalBadge.vue';
import { formatDateTime } from '@/components/activity/format';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import { useReasonPrompt } from '@/composables/useReasonPrompt';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { useFlashSelect } from '@/lib/flashSelect';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

type DeptOption = { value: number; label: string };
type UserRow = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    locale: string;
    roles: string[];
    department_ids: number[];
    approval_status: string;
    approved_at: string | null;
    approved_by: string | null;
    rejected_reason: string | null;
    email_verified: boolean;
    registered_at: string | null;
};

const props = defineProps<{
    users: UserRow[];
    roles: string[];
    departments: DeptOption[];
}>();

const page = usePage();
const locales = computed(
    () => page.props.locales as Record<string, { name: string; dir: string }>,
);
const permissions = computed(
    () =>
        (page.props.auth as { permissions?: string[] })?.permissions ??
        ([] as string[]),
);
const canApprove = computed(() =>
    permissions.value.includes('admin.users.approve'),
);

function currentUser(): UserRow | null {
    return props.users.find((u) => u.id === currentId.value) ?? null;
}

function headerFrom(u: UserRow | null): Record<string, unknown> {
    return {
        name: u?.name ?? '',
        email: u?.email ?? '',
        password: '',
        is_active: u ? u.is_active : true,
        locale: u?.locale ?? 'en',
        roles: u ? [...u.roles] : [],
        department_ids: u ? [...u.department_ids] : [],
    };
}

const currentId = ref<number | null>(props.users[0]?.id ?? null);
const form = useForm<Record<string, any>>(headerFrom(currentUser()));

function load(id: number | null): void {
    currentId.value = id;
    form.defaults(headerFrom(currentUser()));
    form.reset();
    form.clearErrors();
}

const index = computed(() =>
    props.users.findIndex((u) => u.id === currentId.value),
);
watch(
    [index, () => props.users.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);
watch(
    () => form.isDirty,
    (d) => statusLine.setDirty(d),
    { immediate: true },
);
const flashSelect = useFlashSelect();
watch(
    [() => props.users, flashSelect.pending],
    ([, pending], [, wasPending]) => {
        // A just-saved record (flashed selectedId) wins over the current one once it is in the rows.
        // When the rows changed on their own (pending unchanged) and still lack the id, the flash is stale.
        const wanted =
            flashSelect.take((id) => props.users.some((u) => u.id === id), {
                dropIfMissing: pending === wasPending,
            }) ?? currentId.value;
        load(
            props.users.find((u) => u.id === wanted)?.id ??
                props.users[0]?.id ??
                null,
        );
    },
);

const showDepartments = computed(() =>
    (form.roles as string[]).includes('dept_incharge'),
);

function toggleRole(role: string, checked: boolean): void {
    const roles = new Set(form.roles as string[]);
    if (checked) {
        roles.add(role);
    } else {
        roles.delete(role);
    }
    form.roles = Array.from(roles);
    if (!roles.has('dept_incharge')) {
        form.department_ids = [];
    }
}

const columns = computed<ColumnDef<UserRow>[]>(() => [
    { field: 'name', headerName: t('admin.name'), flex: 2 },
    { field: 'email', headerName: t('admin.email'), flex: 2 },
    {
        field: 'roles',
        headerName: t('admin.role_list'),
        flex: 2,
        valueGetter: (p) =>
            (p.data?.roles ?? [])
                .map((r) => t(`admin.role_names.${r}`))
                .join(', '),
    },
    {
        field: 'approval_status',
        headerName: t('approval.fields.status'),
        width: 120,
        valueFormatter: (p) => t(`approval.statuses.${String(p.value)}`),
    },
    {
        field: 'is_active',
        headerName: t('admin.active'),
        flex: 1,
        valueFormatter: (p) => (p.value ? '✓' : ''),
    },
]);

/*
 * Moderation from the record the administrator is already looking at. It is a POST, not part of the header
 * form, so it goes through the unsaved guard itself — the guard's router hook cannot intercept it.
 */
const confirm = useConfirm();
const prompt = useReasonPrompt();
const selected = computed(() => currentUser());
const moderationError = ref('');

function moderate(path: string, data: Record<string, string>): void {
    const user = selected.value;
    if (user === null) {
        return;
    }
    moderationError.value = '';
    router.post(`/admin/users/${user.id}/${path}`, data, {
        preserveScroll: true,
        onError: (errors) => {
            moderationError.value = Object.values(errors)[0] ?? '';
        },
    });
}

function approveSelected(): void {
    const user = selected.value;
    if (user === null) {
        return;
    }
    guard.guard(async () => {
        const ok = await confirm.ask(
            t('approval.confirm_approve', { name: user.name }),
            { title: t('approval.actions.approve') },
        );
        if (ok) {
            moderate('approve', {});
        }
    });
}

function rejectSelected(): void {
    const user = selected.value;
    if (user === null) {
        return;
    }
    guard.guard(async () => {
        const reason = await prompt.ask({
            title: t('approval.reject_dialog.title', { name: user.name }),
            message: t('approval.reject_dialog.message'),
            label: t('approval.reject_dialog.label'),
            placeholder: t('approval.reject_dialog.placeholder'),
        });
        if (reason !== null) {
            moderate('reject', { rejected_reason: reason });
        }
    });
}

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                statusLine.setMessage(t('forms.saved'), 'success');
                resolve(true);
            },
            onError: () => {
                statusLine.setMessage(
                    Object.values(form.errors)[0] ?? '',
                    'error',
                );
                resolve(false);
            },
        };
        if (currentId.value === null) {
            form.post('/admin/users', opts);
        } else {
            form.put(`/admin/users/${currentId.value}`, opts);
        }
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'newRecord':
            guard.guard(() => load(null));
            break;
        case 'previousRecord':
            if (index.value > 0)
                guard.guard(() => load(props.users[index.value - 1].id));
            break;
        case 'nextRecord':
            if (index.value < props.users.length - 1)
                guard.guard(() => load(props.users[index.value + 1].id));
            break;
        case 'clear':
            // Oracle Forms' Clear prompts Save/Discard/Cancel before dropping edits.
            guard.guard(() => load(currentId.value));
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}
</script>

<template>
    <Head :title="t('admin.users')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ t('admin.users') }}</h1>
            <Link
                v-if="canApprove"
                href="/admin/users/approvals"
                class="text-primary text-sm underline-offset-4 hover:underline"
                >{{ t('approval.queue.link') }}</Link
            >
        </div>
        <FormsToolbar
            :disabled="{
                save: false,
                newRecord: false,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
            }"
            @action="onAction"
        />
        <FormBlock>
            <FormField
                :label="`${t('admin.name')} *`"
                for="f-name"
                :span="4"
                :error="form.errors.name"
            >
                <Input id="f-name" v-model="form.name as string" />
            </FormField>
            <FormField
                :label="`${t('admin.email')} *`"
                for="f-email"
                :span="4"
                :error="form.errors.email"
            >
                <Input
                    id="f-email"
                    v-model="form.email as string"
                    type="email"
                />
            </FormField>
            <FormField
                :label="t('admin.locale')"
                for="f-locale"
                :span="2"
                :error="form.errors.locale"
            >
                <select
                    id="f-locale"
                    v-model="form.locale"
                    class="bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option
                        v-for="(meta, code) in locales"
                        :key="code"
                        :value="code"
                    >
                        {{ meta.name }}
                    </option>
                </select>
            </FormField>
            <FormField
                :label="t('admin.active')"
                for="f-active"
                :span="2"
                :error="form.errors.is_active"
            >
                <Checkbox
                    id="f-active"
                    :model-value="Boolean(form.is_active)"
                    @update:model-value="(v) => (form.is_active = v === true)"
                />
            </FormField>
            <FormField
                :label="t('admin.password')"
                for="f-password"
                :hint="t('admin.password_hint')"
                :span="4"
                :error="form.errors.password"
            >
                <Input
                    id="f-password"
                    v-model="form.password as string"
                    type="password"
                    autocomplete="new-password"
                />
            </FormField>

            <FormField
                :label="t('admin.role_list')"
                for="f-roles"
                :span="6"
                :error="form.errors.roles"
            >
                <div id="f-roles" class="flex flex-wrap gap-x-4 gap-y-1">
                    <label
                        v-for="role in roles"
                        :key="role"
                        class="flex items-center gap-1.5 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                (form.roles as string[]).includes(role)
                            "
                            @update:model-value="
                                (v) => toggleRole(role, v === true)
                            "
                        />
                        {{ t(`admin.role_names.${role}`) }}
                    </label>
                </div>
            </FormField>
            <FormField
                v-if="showDepartments"
                :label="t('admin.departments')"
                for="f-departments"
                :span="6"
                :error="form.errors.department_ids"
            >
                <select
                    id="f-departments"
                    v-model="form.department_ids"
                    multiple
                    class="bg-background min-h-20 rounded-md border px-2 text-sm"
                >
                    <option
                        v-for="o in departments"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
            </FormField>
        </FormBlock>
        <p v-if="form.errors['record']" class="text-destructive text-sm">
            {{ form.errors['record'] }}
        </p>

        <!--
          Approval is not part of the header form: the columns are written only by UserApprovalService, so
          this block reports them and offers the two decisions rather than editing fields.
        -->
        <FormBlock
            v-if="selected !== null"
            :title="t('approval.fields.status')"
        >
            <FormField
                :label="t('approval.fields.status')"
                for="f-approval-status"
                :span="3"
            >
                <p id="f-approval-status" class="py-1.5">
                    <ApprovalBadge :status="selected.approval_status" />
                </p>
            </FormField>
            <FormField
                :label="t('approval.fields.registered_at')"
                for="f-registered-at"
                :span="3"
            >
                <p
                    id="f-registered-at"
                    class="text-muted-foreground py-2 text-sm tabular-nums"
                >
                    {{ formatDateTime(selected.registered_at) }}
                </p>
            </FormField>
            <FormField
                :label="t('approval.fields.email_verified')"
                for="f-email-verified"
                :span="3"
            >
                <p id="f-email-verified" class="py-2 text-sm">
                    {{
                        selected.email_verified
                            ? t('approval.verified')
                            : t('approval.unverified')
                    }}
                </p>
            </FormField>
            <FormField
                :label="t('approval.fields.approved_by')"
                for="f-approved-by"
                :span="3"
            >
                <p
                    id="f-approved-by"
                    class="text-muted-foreground py-2 text-sm"
                >
                    {{ selected.approved_by ?? t('approval.unknown') }}
                    <span v-if="selected.approved_at" class="tabular-nums">
                        · {{ formatDateTime(selected.approved_at) }}</span
                    >
                </p>
            </FormField>
            <FormField
                v-if="selected.rejected_reason"
                :label="t('approval.fields.rejected_reason')"
                for="f-rejected-reason"
                :span="12"
            >
                <p id="f-rejected-reason" class="text-destructive py-2 text-sm">
                    {{ selected.rejected_reason }}
                </p>
            </FormField>
            <div
                v-if="canApprove"
                class="col-span-12 flex flex-wrap items-center gap-2"
            >
                <Button
                    v-if="selected.approval_status !== 'approved'"
                    class="min-h-11 sm:min-h-9"
                    data-test="approve-selected"
                    @click="approveSelected"
                >
                    {{ t('approval.actions.approve') }}
                </Button>
                <Button
                    v-if="selected.approval_status !== 'rejected'"
                    variant="outline"
                    class="text-destructive min-h-11 sm:min-h-9"
                    data-test="reject-selected"
                    @click="rejectSelected"
                >
                    {{ t('approval.actions.reject') }}
                </Button>
                <p
                    v-if="moderationError"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ moderationError }}
                </p>
            </div>
        </FormBlock>

        <DataGrid
            :rows="users"
            :columns="columns"
            :selected-id="currentId"
            @row-selected="(r) => guard.guard(() => load(r.id))"
        />
        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
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
