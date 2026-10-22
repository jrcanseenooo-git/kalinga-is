<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { computed, ref } from 'vue';

defineProps<{ canResetPassword?: boolean; status?: string; googleEnabled?: boolean; registrationEnabled?: boolean }>();
const pageErrors = computed(() => (usePage().props.errors as Record<string, string>) ?? {});
const form = useForm({ email: '', password: '', remember: false });
const showPassword = ref(false);
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <GuestLayout fit-viewport><Head title="Sign in" />
        <div><h2 class="text-3xl font-extrabold tracking-tight text-slate-900">Login account</h2><p class="mt-2 text-sm leading-6 text-slate-500">Welcome back, sign in to continue.</p></div>
        <div v-if="status" class="mt-6 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-700">{{ status }}</div>
        <div v-if="pageErrors.email && !form.errors.email" class="mt-6 rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm font-medium text-rose-700">{{ pageErrors.email }}</div>
        <div class="mt-5 sm:mt-7">
            <a v-if="googleEnabled" :href="route('auth.google')" class="flex w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"><img src="/icons/google-mark.svg" alt="" class="h-5 w-5" />Continue with Google</a>
            <button v-else type="button" disabled class="flex w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600 shadow-sm cursor-not-allowed"><img src="/icons/google-mark.svg" alt="" class="h-5 w-5 opacity-50" />Continue with Google</button>
            <p v-if="!googleEnabled" class="mt-2 text-center text-xs text-slate-600">Available after Google OAuth is configured</p>
        </div>
        <div class="my-4 flex items-center gap-3 sm:my-6" aria-hidden="true"><span class="h-px flex-1 bg-slate-100"/><span class="text-[11px] font-semibold uppercase tracking-[.14em] text-slate-600">or</span><span class="h-px flex-1 bg-slate-100"/></div>
        <form @submit.prevent="submit" class="space-y-4 sm:space-y-5"><div><InputLabel for="email" value="Email" /><TextInput id="email" v-model="form.email" type="email" class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100" required autofocus autocomplete="username" /><InputError class="mt-2" :message="form.errors.email" /></div><div><InputLabel for="password" value="Password" /><div class="relative mt-2"><TextInput id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" class="block w-full rounded-xl border-slate-200 bg-white px-4 py-3 pr-12 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100" required autocomplete="current-password" /><button type="button" class="absolute inset-y-0 right-0 grid w-12 place-items-center rounded-r-xl text-slate-500 transition hover:text-brand-700" :aria-label="showPassword ? 'Hide password' : 'Show password'" :title="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword"><EyeOff v-if="showPassword" class="h-5 w-5" aria-hidden="true"/><Eye v-else class="h-5 w-5" aria-hidden="true"/></button></div><InputError class="mt-2" :message="form.errors.password" /></div><div class="flex items-center justify-between gap-3"><label class="flex cursor-pointer items-center"><Checkbox name="remember" v-model:checked="form.remember" /><span class="ms-2 text-sm text-slate-500">Remember me</span></label><Link v-if="canResetPassword" :href="route('password.request')" class="whitespace-nowrap text-sm font-semibold text-brand-600 transition hover:text-brand-800">Forgot your password?</Link></div><button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800 disabled:opacity-50">{{ form.processing ? 'Signing in…' : 'Login Account' }}</button></form>
        <p v-if="registrationEnabled" class="mt-5 text-center text-sm text-slate-500 sm:mt-8">Don't have an account? <Link :href="route('register')" class="font-semibold text-brand-600 transition hover:text-brand-800">Register here</Link></p>
        <p class="mt-3 text-center text-xs text-slate-600" :class="{ 'sm:mt-8': !registrationEnabled }">Access is restricted to authorized personnel only.</p>
    </GuestLayout>
</template>
