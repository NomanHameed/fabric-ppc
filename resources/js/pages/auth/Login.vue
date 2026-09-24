<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { register } from '@/routes';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

// Layout props follow the locale (re-run whenever the dictionary changes).
watchEffect(() => {
    setLayoutProps({
        title: t('auth.pages.login.title'),
        description: t('auth.pages.login.description'),
    });
});
</script>

<template>
    <Head :title="t('auth.pages.login.head')" />

    <div
        v-if="status"
        class="text-success mb-4 text-center text-sm font-medium"
    >
        {{ status }}
    </div>

    <PasskeyVerify
        :label="t('auth.pages.login.passkey')"
        :loading-label="t('auth.pages.login.passkey_loading')"
        :separator="t('auth.pages.login.passkey_separator')"
    />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">{{ t('auth.pages.login.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    :placeholder="t('auth.pages.login.email_placeholder')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">{{
                        t('auth.pages.login.password')
                    }}</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        {{ t('auth.pages.login.forgot') }}
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    :placeholder="t('auth.pages.login.password')"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>{{ t('auth.pages.login.remember') }}</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                {{ t('auth.pages.login.submit') }}
            </Button>
        </div>
    </Form>

    <div class="text-muted-foreground mt-6 text-center text-sm">
        <span>{{ t('auth.pages.login.no_account') }}</span>
        <TextLink :href="register()" class="ms-1" :tabindex="6">{{
            t('auth.pages.login.register')
        }}</TextLink>
    </div>
</template>
