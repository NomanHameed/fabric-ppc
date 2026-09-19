<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { t } from '@/lib/i18n';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ choose: [choice: 'save' | 'discard' | 'cancel'] }>();
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('choose', 'cancel')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('forms.unsaved_title') }}</DialogTitle>
                <DialogDescription>{{
                    t('forms.unsaved_body')
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="emit('choose', 'cancel')">{{
                    t('forms.cancel')
                }}</Button>
                <Button variant="outline" @click="emit('choose', 'discard')">{{
                    t('forms.discard')
                }}</Button>
                <Button @click="emit('choose', 'save')">{{
                    t('forms.actions.save')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
