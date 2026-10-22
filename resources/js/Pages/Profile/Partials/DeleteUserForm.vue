<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);
const form = useForm({ password: '' });

const confirmUserDeletion = () => { confirmingUserDeletion.value = true; nextTick(() => passwordInput.value?.focus()); };

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => { confirmingUserDeletion.value = false; form.clearErrors(); form.reset(); };
</script>

<template>
    <section class="space-y-5">
        <header>
            <h2 class="text-lg font-bold text-slate-900">Delete account</h2>
            <p class="mt-1 text-sm text-slate-500">Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.</p>
        </header>

        <button type="button" @click="confirmUserDeletion" class="rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700">Delete account</button>

        <Teleport to="body">
            <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                <div v-if="confirmingUserDeletion" class="fixed inset-0 z-50 grid place-items-end bg-slate-950/40 p-4 sm:place-items-center" @click.self="closeModal">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <h2 class="text-lg font-bold text-slate-900">Are you sure you want to delete your account?</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm.</p>

                        <div class="mt-5">
                            <InputLabel for="password" value="Password" class="sr-only" />
                            <TextInput
                                id="password" ref="passwordInput" v-model="form.password" type="password"
                                class="block w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                                placeholder="Password" @keyup.enter="deleteUser"
                            />
                            <InputError :message="form.errors.password" class="mt-2" />
                        </div>

                        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" @click="closeModal" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50">Cancel</button>
                            <button type="button" @click="deleteUser" :disabled="form.processing" class="min-h-11 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white transition hover:bg-rose-700 disabled:opacity-50">{{ form.processing ? 'Deleting…' : 'Delete account' }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </section>
</template>
