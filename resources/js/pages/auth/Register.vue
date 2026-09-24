<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';

/**
 * Self-registration (decision A4). Creating the account gets the person nothing but the pending screen — the
 * notice under the form says so before they submit, so nobody signs up expecting to be let straight in.
 */
defineProps<{
    passwordRules: string;
}>();

// Layout props follow the locale (re-run whenever the dictionary changes).
watchEffect(() => {
    setLayoutProps({
        title: t('auth.pages.register.title'),
        description: t('auth.pages.register.description'),
    });
});
</script>

<template>
    <Head :title="t('auth.pages.register.title')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">{{ t('auth.pages.register.name') }}</Label>
                <Input
                    id="name"
                    type="text"
                    name="name"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('auth.pages.register.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    :placeholder="t('auth.pages.register.email_placeholder')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{
                    t('auth.pages.register.password')
                }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    :placeholder="t('auth.pages.register.password')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    t('auth.pages.register.confirm')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    :placeholder="t('auth.pages.register.confirm')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                :tabindex="5"
                :disabled="processing"
                data-test="register-button"
            >
                <Spinner v-if="processing" />
                {{ t('auth.pages.register.submit') }}
            </Button>
        </div>
    </Form>

    <p
        class="bg-info-surface text-info-on-surface mt-6 rounded-md px-3 py-2 text-center text-xs"
        role="note"
    >
        {{ t('auth.pages.register.approval_notice') }}
    </p>

    <div class="text-muted-foreground mt-4 text-center text-sm">
        <span>{{ t('auth.pages.register.have_account') }}</span>
        <TextLink :href="login()" class="ms-1" :tabindex="6">{{
            t('auth.pages.register.login')
        }}</TextLink>
    </div>
</template>
