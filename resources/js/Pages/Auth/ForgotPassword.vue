<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ status?: string }>();

const form = useForm({ email: '' });
const submit = () => form.post(route('password.email'));
</script>

<template>
    <GuestLayout><Head title="Forgot password" />
        <div>
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Forgot password</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Enter your email and we'll send a password reset link.</p>
        </div>

        <div v-if="status" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700">{{ status }}</div>

        <form @submit.prevent="submit" class="mt-5 space-y-4 sm:mt-7 sm:space-y-5">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email" v-model="form.email" type="email" required autofocus autocomplete="username"
                    class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <button
                type="submit" :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800 disabled:opacity-50"
            >{{ form.processing ? 'Sending…' : 'Send reset link' }}</button>

            <p class="text-center text-sm text-slate-500">
                <Link :href="route('login')" class="font-semibold text-brand-600 transition hover:text-brand-800">Back to sign in</Link>
            </p>
        </form>
    </GuestLayout>
</template>
