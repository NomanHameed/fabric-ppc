<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

type DeptOption = { value: number; label: string };
type UserRow = {
    id: number; name: string; email: string; is_active: boolean; locale: string;
    roles: string[]; department_ids: number[];
};

const props = defineProps<{ users: UserRow[]; roles: string[]; departments: DeptOption[] }>();

const page = usePage();
const locales = computed(() => page.props.locales as Record<string, { name: string; dir: string }>);

function currentUser(): UserRow | null {
    return props.users.find((u) => u.id === currentId.value) ?? null;
}

function headerFrom(u: UserRow | null): Record<string, unknown> {
    return {
        name: u?.name ?? '', email: u?.email ?? '', password: '',
        is_active: u ? u.is_active : true, locale: u?.locale ?? 'en',
        roles: u ? [...u.roles] : [], department_ids: u ? [...u.department_ids] : [],
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

const index = computed(() => props.users.findIndex((u) => u.id === currentId.value));
watch([index, () => props.users.length], ([i, n]) => statusLine.setRecord(i + 1, n), { immediate: true });
watch(() => form.isDirty, (d) => statusLine.setDirty(d), { immediate: true });
watch(() => props.users, () => load(props.users.find((u) => u.id === currentId.value)?.id ?? props.users[0]?.id ?? null));

const showDepartments = computed(() => (form.roles as string[]).includes('dept_incharge'));

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
        valueGetter: (p) => (p.data?.roles ?? []).map((r) => t(`admin.role_names.${r}`)).join(', '),
    },
    { field: 'is_active', headerName: t('admin.active'), flex: 1, valueFormatter: (p) => (p.value ? '✓' : '') },
]);

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => { statusLine.setMessage(t('forms.saved'), 'success'); resolve(true); },
            onError: () => { statusLine.setMessage(Object.values(form.errors)[0] ?? '', 'error'); resolve(false); },
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
        case 'save': void save(); break;
        case 'newRecord': guard.guard(() => load(null)); break;
        case 'previousRecord': if (index.value > 0) guard.guard(() => load(props.users[index.value - 1].id)); break;
        case 'nextRecord': if (index.value < props.users.length - 1) guard.guard(() => load(props.users[index.value + 1].id)); break;
        case 'clear': load(currentId.value); break;
        case 'print': window.print(); break;
        case 'exit': router.visit('/dashboard'); break;
    }
}
</script>

<template>
    <Head :title="t('admin.users')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('admin.users') }}</h1>
        <FormsToolbar
            :disabled="{ save: false, newRecord: false, deleteRecord: true, enterQuery: true, executeQuery: true }"
            @action="onAction"
        />
        <FormBlock>
            <FormField :label="`${t('admin.name')} *`" for="f-name" :span="4" :error="form.errors.name">
                <Input id="f-name" v-model="form.name as string" />
            </FormField>
            <FormField :label="`${t('admin.email')} *`" for="f-email" :span="4" :error="form.errors.email">
                <Input id="f-email" v-model="form.email as string" type="email" />
            </FormField>
            <FormField :label="t('admin.locale')" for="f-locale" :span="2" :error="form.errors.locale">
                <select id="f-locale" v-model="form.locale" class="h-9 rounded-md border bg-background px-2 text-sm">
                    <option v-for="(meta, code) in locales" :key="code" :value="code">{{ meta.name }}</option>
                </select>
            </FormField>
            <FormField :label="t('admin.active')" for="f-active" :span="2" :error="form.errors.is_active">
                <Checkbox
                    id="f-active"
                    :model-value="Boolean(form.is_active)"
                    @update:model-value="(v) => (form.is_active = v === true)"
                />
            </FormField>
            <FormField :label="t('admin.password')" for="f-password" :hint="t('admin.password_hint')" :span="4" :error="form.errors.password">
                <Input id="f-password" v-model="form.password as string" type="password" autocomplete="new-password" />
            </FormField>

            <FormField :label="t('admin.role_list')" for="f-roles" :span="6" :error="form.errors.roles">
                <div id="f-roles" class="flex flex-wrap gap-x-4 gap-y-1">
                    <label v-for="role in roles" :key="role" class="flex items-center gap-1.5 text-sm">
                        <Checkbox
                            :model-value="(form.roles as string[]).includes(role)"
                            @update:model-value="(v) => toggleRole(role, v === true)"
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
                    class="min-h-20 rounded-md border bg-background px-2 text-sm"
                >
                    <option v-for="o in departments" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </FormField>
        </FormBlock>
        <p v-if="form.errors['record']" class="text-sm text-destructive">{{ form.errors['record'] }}</p>

        <DataGrid :rows="users" :columns="columns" :selected-id="currentId" @row-selected="(r) => guard.guard(() => load(r.id))" />
        <ConfirmSaveDialog :open="guard.dialogOpen.value" @choose="guard.choose" />
    </div>
</template>
