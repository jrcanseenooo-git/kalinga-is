<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';

const form = useForm({ password: '' });
const showPassword = ref(false);
const submit = () => form.post(route('password.confirm'), { onFinish: () => form.reset() });
</script>

<template>
    <GuestLayout><Head title="Confirm password" />
        <div>
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Confirm password</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">This is a secure area. Please confirm your password before continuing.</p>
        </div>

        <form @submit.prevent="submit" class="mt-5 space-y-4 sm:mt-7 sm:space-y-5">
            <div>
                <InputLabel for="password" value="Password" />
                <div class="relative mt-2">
                    <TextInput
                        id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required autofocus autocomplete="current-password"
                        class="block w-full rounded-xl border-slate-200 bg-white px-4 py-3 pr-12 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                    />
                    <button type="button" class="absolute inset-y-0 right-0 grid w-12 place-items-center rounded-r-xl text-slate-500 transition hover:text-brand-700" @click="showPassword = !showPassword">
                        <EyeOff v-if="showPassword" class="h-5 w-5" /><Eye v-else class="h-5 w-5" />
                    </button>
                </div>
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <button
                type="submit" :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800 disabled:opacity-50"
            >{{ form.processing ? 'Confirming…' : 'Confirm' }}</button>
        </form>
    </GuestLayout>
</template>
