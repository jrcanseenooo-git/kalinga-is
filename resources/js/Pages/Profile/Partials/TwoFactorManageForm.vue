<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    enabled: boolean;
    required: boolean;
    method: 'email' | 'totp';
}>();

const showReset = ref(false);
const form = useForm({ password: '' });

const submitReset = () => {
    form.post(route('two-factor.reset'), {
        onFinish: () => form.reset('password'),
        onError: () => {},
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-slate-900">Two-factor authentication</h2>
            <p class="mt-1 text-sm text-slate-500">
                <template v-if="required">Your role requires two-step verification on each login.</template>
                <template v-else>Add an extra layer of security to your account.</template>
            </p>
        </header>

        <div class="mt-4 space-y-4">
            <!-- Current method indicator -->
            <div class="flex items-center gap-3">
                <span v-if="method === 'totp'" class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1 text-sm font-medium text-green-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                    Authenticator app active
                </span>
                <span v-else-if="required" class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                    Email verification active
                </span>
            </div>

            <!-- TOTP management (if active) -->
            <div v-if="method === 'totp'">
                <p class="text-sm text-slate-500">
                    Your sign-in uses an authenticator app. You can reset it to set up a new device, or switch back to email codes.
                </p>

                <button v-if="!showReset" type="button" @click="showReset = true" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">
                    Reset authenticator app
                </button>

                <form v-if="showReset" @submit.prevent="submitReset" class="mt-3 space-y-3">
                    <p class="text-sm font-medium text-amber-800">Confirm your password to reset. You will switch back to email verification codes.</p>
                    <div>
                        <InputLabel for="reset_password" value="Current password" />
                        <TextInput id="reset_password" v-model="form.password" type="password" class="mt-1 block w-full" required autofocus />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:opacity-50">
                            {{ form.processing ? 'Resetting…' : 'Confirm reset' }}
                        </button>
                        <button type="button" @click="showReset = false; form.reset()" class="text-sm text-slate-500 hover:text-slate-700">Cancel</button>
                    </div>
                </form>
            </div>

            <!-- Email method - offer TOTP upgrade -->
            <div v-else>
                <p class="text-sm text-slate-500">
                    <template v-if="required">A verification code is sent to your email each time you sign in.</template>
                    <template v-else>Enable two-factor authentication for stronger account security.</template>
                </p>

                <Link :href="route('two-factor.setup')" class="mt-3 inline-flex rounded-lg border border-brand-300 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-800 transition hover:bg-brand-100">
                    <template v-if="required">Upgrade to authenticator app</template>
                    <template v-else>Set up authenticator app</template>
                </Link>
                <p v-if="required" class="mt-2 text-xs text-slate-500">Optional - an authenticator app is faster and works offline.</p>
            </div>
        </div>
    </section>
</template>
