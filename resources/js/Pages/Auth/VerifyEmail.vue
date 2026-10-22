<script setup lang="ts">
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ status?: string }>();
const form = useForm({});
const submit = () => form.post(route('verification.send'));
const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout><Head title="Verify email" />
        <div>
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Verify your email</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Before getting started, please verify your email address by clicking the link we just sent you. If you didn't receive it, we'll gladly send another.</p>
        </div>

        <div v-if="verificationLinkSent" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700">
            A new verification link has been sent to your email address.
        </div>

        <form @submit.prevent="submit" class="mt-5 space-y-4 sm:mt-7">
            <button
                type="submit" :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800 disabled:opacity-50"
            >{{ form.processing ? 'Sending…' : 'Resend verification email' }}</button>

            <Link
                :href="route('logout')" method="post" as="button"
                class="flex w-full items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
            >Sign out</Link>
        </form>
    </GuestLayout>
</template>
