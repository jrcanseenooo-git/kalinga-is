<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import QRCode from 'qrcode';

const props = defineProps<{ secret: string; otpUrl: string }>();
const form = useForm({ code: '' });
const submit = () => form.post(route('two-factor.confirm'));

const qrSvg = ref('');
onMounted(async () => {
    qrSvg.value = await QRCode.toString(props.otpUrl, { type: 'svg', margin: 2, width: 200 });
});
</script>

<template>
    <GuestLayout fit-viewport><Head title="Set up authenticator app" />
        <div><h2 class="text-2xl font-extrabold tracking-tight text-slate-900">Set up authenticator app</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Add an authenticator app for stronger account security. This replaces email verification codes when you sign in.</p>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Setup instructions</p>
            <ol class="mt-3 space-y-2 text-sm text-slate-600">
                <li>1. Install an authenticator app (Google Authenticator, Authy, or Microsoft Authenticator)</li>
                <li>2. Scan the QR code below, or enter the key manually</li>
                <li>3. Enter the 6-digit code shown in your app</li>
            </ol>
        </div>

        <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scan with your authenticator app</p>
            <div class="mt-3 flex justify-center">
                <div v-if="qrSvg" v-html="qrSvg" class="rounded-lg bg-white p-2" />
                <div v-else class="flex h-[200px] w-[200px] items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-600">Generating QR code…</div>
            </div>
        </div>

        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Or enter this key manually</p>
            <p class="mt-2 select-all break-all rounded-lg bg-slate-100 px-3 py-2 font-mono text-sm tracking-wider text-slate-800">{{ secret }}</p>
        </div>

        <form @submit.prevent="submit" data-skip-generic-confirm="true" class="mt-6 space-y-4">
            <div>
                <InputLabel for="code" value="Verification code" />
                <TextInput id="code" v-model="form.code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-center font-mono text-lg tracking-[.3em] shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" required autofocus autocomplete="one-time-code" placeholder="000000" />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>
            <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800 disabled:opacity-50">
                {{ form.processing ? 'Verifying…' : 'Verify and enable' }}
            </button>
        </form>

        <Link :href="route('profile.edit')" class="mt-4 block w-full text-center text-sm font-semibold text-slate-500 transition hover:text-slate-600">
            Cancel
        </Link>
    </GuestLayout>
</template>
