<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const form = useForm({ current_password: '', password: '', password_confirmation: '' });

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) { form.reset('password', 'password_confirmation'); passwordInput.value?.focus(); }
            if (form.errors.current_password) { form.reset('current_password'); currentPasswordInput.value?.focus(); }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-slate-900">Update password</h2>
            <p class="mt-1 text-sm text-slate-500">Ensure your account is using a long, random password to stay secure.</p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-5">
            <div>
                <InputLabel for="current_password" value="Current password" />
                <TextInput id="current_password" ref="currentPasswordInput" v-model="form.current_password" type="password" class="mt-1.5 block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" autocomplete="current-password" />
                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>

            <div>
                <InputLabel for="password" value="New password" />
                <TextInput id="password" ref="passwordInput" v-model="form.password" type="password" class="mt-1.5 block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" autocomplete="new-password" />
                <InputError :message="form.errors.password" class="mt-2" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm password" />
                <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" class="mt-1.5 block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100" autocomplete="new-password" />
                <InputError :message="form.errors.password_confirmation" class="mt-2" />
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
