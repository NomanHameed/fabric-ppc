<script setup lang="ts">
import {
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogRoot,
    AlertDialogTitle,
} from 'reka-ui';
import { Button } from '@/components/ui/button';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { t } from '@/lib/i18n';

/**
 * The Forms-style Yes/No question (delete this record? apply this template?) that replaces `window.confirm`:
 * a reka-ui AlertDialog (modal, focus-trapped, Escape cancels), translated. Pair it with `useConfirm()`:
 *   <ConfirmDialog :open="confirm.open.value" :message="confirm.message.value" @confirm="confirm.choose(true)"
 *                  @cancel="confirm.choose(false)" />
 */
const props = defineProps<{
    open: boolean;
    message: string;
    title?: string;
    confirmLabel?: string;
    destructive?: boolean;
}>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();

// Shadow the form's shortcuts (F10 Save, Ctrl+↓ New, F8 …) only while the question is open.
useFormsKeys({}, () => props.open);
</script>

<template>
    <AlertDialogRoot :open="open" @update:open="(v) => !v && emit('cancel')">
        <AlertDialogPortal>
            <AlertDialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/80"
            />
            <AlertDialogContent
                data-confirm-dialog
                class="bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-lg duration-200 sm:max-w-md"
            >
                <div class="flex flex-col gap-2 text-center sm:text-start">
                    <AlertDialogTitle
                        class="text-lg leading-none font-semibold"
                        >{{
                            title ?? t('forms.confirm_title')
                        }}</AlertDialogTitle
                    >
                    <AlertDialogDescription
                        class="text-muted-foreground text-sm"
                        >{{ message }}</AlertDialogDescription
                    >
                </div>
                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                >
                    <!-- plain buttons: AlertDialogAction/Cancel would also close the root and double the cancel -->
                    <Button variant="ghost" @click="emit('cancel')">{{
                        t('forms.cancel')
                    }}</Button>
                    <Button
                        :variant="destructive ? 'destructive' : 'default'"
                        @click="emit('confirm')"
                        >{{ confirmLabel ?? t('forms.confirm') }}</Button
                    >
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>
