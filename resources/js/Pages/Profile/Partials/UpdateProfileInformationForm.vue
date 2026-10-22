<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps<{ mustVerifyEmail?: Boolean; status?: String }>();

const user = usePage().props.auth.user;
const form = useForm({ name: user.name, position: (user as { position?: string | null }).position ?? '', email: user.email });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-slate-900">Profile information</h2>
            <p class="mt-1 text-sm text-slate-500">Update your account's profile information and email address.</p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-5">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput id="name" type="text" class="mt-1.5 block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" v-model="form.name" required autofocus autocomplete="name" />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="position" value="Position / designation" />
                <TextInput id="position" type="text" class="mt-1.5 block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" v-model="form.position" maxlength="160" placeholder="e.g. Social Welfare Officer II" />
                <p class="mt-1 text-xs text-slate-500">Printed under your name when you sign reports as preparer.</p>
                <InputError class="mt-2" :message="form.errors.position" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1.5 block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" v-model="form.email" required autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="text-sm text-slate-700">
                    Your email address is unverified.
                    <Link :href="route('verification.send')" method="post" as="button" class="text-sm font-semibold text-brand-600 underline transition hover:text-brand-800">Click here to re-send the verification email.</Link>
                </p>
                <div v-show="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-emerald-600">A new verification link has been sent to your email address.</div>
            </div>

            <div class="flex items-center gap-4">
                <button type="submit" :disabled="form.processing" class="rounded-xl bg-brand-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-50">Save</button>
                <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                    <p v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
