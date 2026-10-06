<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

type RoleData = { name: string; permissions: string[] };

const props = defineProps<{ roles: RoleData[]; permissions: string[] }>();

const GROUP_ORDER = [
    'admin',
    'masters',
    'oracle',
    'production',
    'colour',
    'dispatch',
    'inquiry',
    'reports',
    'audit',
];

const groups = computed(() => {
    const byPrefix = new Map<string, string[]>();
    for (const perm of props.permissions) {
        const prefix = perm.split('.')[0];
        if (!byPrefix.has(prefix)) {
            byPrefix.set(prefix, []);
        }
        byPrefix.get(prefix)?.push(perm);
    }
    const ordered = [
        ...GROUP_ORDER,
        ...[...byPrefix.keys()].filter((k) => !GROUP_ORDER.includes(k)),
    ];
    return ordered
        .filter((k) => byPrefix.has(k))
        .map((k) => ({ key: k, permissions: byPrefix.get(k) ?? [] }));
});

function checkedSet(role: RoleData): Record<string, boolean> {
    return Object.fromEntries(
        props.permissions.map((p) => [
            p,
            role.name === 'admin' || role.permissions.includes(p),
        ]),
    );
}

const state = reactive<Record<string, Record<string, boolean>>>(
    Object.fromEntries(props.roles.map((r) => [r.name, checkedSet(r)])),
);

function signature(role: string): string {
    return JSON.stringify(props.permissions.filter((p) => state[role][p]));
}

const baseline = reactive<Record<string, string>>(
    Object.fromEntries(props.roles.map((r) => [r.name, signature(r.name)])),
);

function isRoleDirty(role: string): boolean {
    return signature(role) !== baseline[role];
}

function isDirty(): boolean {
    return props.roles.some((r) => r.name !== 'admin' && isRoleDirty(r.name));
}

function toggle(role: string, permission: string, checked: boolean): void {
    if (role === 'admin') {
        return;
    }
    state[role][permission] = checked;
}

function saveRole(role: string): Promise<boolean> {
    return new Promise((resolve) => {
        const permissions = props.permissions.filter((p) => state[role][p]);
        router.put(
            `/admin/roles/${role}`,
            { permissions },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    baseline[role] = signature(role);
                    statusLine.setMessage(t('forms.saved'), 'success');
                    resolve(true);
                },
                onError: (errors) => {
                    statusLine.setMessage(
                        Object.values(errors)[0] ?? '',
                        'error',
                    );
                    resolve(false);
                },
            },
        );
    });
}

async function save(): Promise<boolean> {
    let ok = true;
    for (const r of props.roles) {
        if (r.name !== 'admin' && isRoleDirty(r.name)) {
            ok = (await saveRole(r.name)) && ok;
        }
    }
    return ok;
}

const guard = useUnsavedGuard(isDirty, save);
watch(isDirty, (d) => statusLine.setDirty(d), { immediate: true });

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            if (!isDirty()) {
                statusLine.setMessage(t('forms.nothing_to_save'));
                break;
            }
            void save();
            break;
        case 'clear':
            // Oracle Forms' Clear: drop the edits back to what was last saved, after the Save/Discard/Cancel prompt.
            guard.guard(() => {
                for (const r of props.roles) {
                    Object.assign(state[r.name], checkedSet(r));
                }
            });
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
    <Head :title="t('admin.roles')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <h1 class="text-lg font-semibold">{{ t('admin.roles') }}</h1>
        <FormsToolbar
            :disabled="{
                newRecord: true,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
                previousRecord: true,
                nextRecord: true,
            }"
            @action="onAction"
        />
        <div class="bg-card overflow-x-auto rounded-md border">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-muted/40 border-b">
                        <th class="p-2 text-start font-medium">
                            {{ t('admin.permission') }}
                        </th>
                        <th
                            v-for="role in roles"
                            :key="role.name"
                            class="p-2 text-center font-medium"
                        >
                            {{ t(`admin.role_names.${role.name}`) }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="group in groups" :key="group.key">
                        <tr class="bg-muted/20 border-b">
                            <td
                                :colspan="roles.length + 1"
                                class="text-muted-foreground px-2 py-1 text-xs font-semibold tracking-wide uppercase"
                            >
                                {{ t(`admin.permission_groups.${group.key}`) }}
                            </td>
                        </tr>
                        <tr
                            v-for="perm in group.permissions"
                            :key="perm"
                            class="border-b last:border-b-0"
                        >
                            <td class="p-2">{{ perm }}</td>
                            <td
                                v-for="role in roles"
                                :key="role.name"
                                class="p-2 text-center"
                            >
                                <Checkbox
                                    :model-value="state[role.name][perm]"
                                    :disabled="role.name === 'admin'"
                                    @update:model-value="
                                        (v) =>
                                            toggle(role.name, perm, v === true)
                                    "
                                />
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
    </div>
</template>
