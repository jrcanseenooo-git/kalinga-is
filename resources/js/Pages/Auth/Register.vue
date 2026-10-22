<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import LocationSelects from '@/Components/LocationSelects.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<{
    registrableRoles: { code: string; label: string }[];
    cpuDisciplines: Record<string, string>;
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    requested_role: '',
    cpu_discipline: '',
    region: '',
    province: '',
    municipality: '',
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <div>
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Create account</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Register for access. Your account will be reviewed and approved by an administrator.</p>
        </div>

        <form @submit.prevent="submit" data-skip-generic-confirm="true" class="mt-5 space-y-4 sm:mt-7 sm:space-y-5">
            <div>
                <InputLabel for="name" value="Full name" />
                <TextInput
                    id="name" v-model="form.name" type="text" required autofocus autocomplete="name"
                    class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email" v-model="form.email" type="email" required autocomplete="username"
                    class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="requested_role" value="Role" />
                <select
                    id="requested_role" v-model="form.requested_role" required
                    class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                >
                    <option value="" disabled>Select your role</option>
                    <option v-for="role in props.registrableRoles" :key="role.code" :value="role.code">{{ role.label }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.requested_role" />
            </div>

            <!-- CPU discipline picker - shown only when CPU Member is selected -->
            <div v-if="form.requested_role === 'cpu_member'">
                <InputLabel for="cpu_discipline" value="Represents as" />
                <select
                    id="cpu_discipline" v-model="form.cpu_discipline" required
                    class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                >
                    <option value="" disabled>Select your discipline</option>
                    <option v-for="(label, code) in props.cpuDisciplines" :key="code" :value="code">{{ label }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.cpu_discipline" />
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-slate-700">Jurisdiction / location</legend>
                <div class="mt-2">
                    <LocationSelects
                        v-model:region="form.region"
                        v-model:province="form.province"
                        v-model:municipality="form.municipality"
                        hide-barangay
                    />
                </div>
                <InputError class="mt-2" :message="form.errors.region" />
                <InputError class="mt-1" :message="form.errors.municipality" />
            </fieldset>

            <div>
                <InputLabel for="password" value="Password" />
                <div class="relative mt-2">
                    <TextInput
                        id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required autocomplete="new-password"
                        class="block w-full rounded-xl border-slate-200 bg-white px-4 py-3 pr-12 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                    />
                    <button type="button" class="absolute inset-y-0 right-0 grid w-12 place-items-center rounded-r-xl text-slate-500 transition hover:text-brand-700" @click="showPassword = !showPassword">
                        <EyeOff v-if="showPassword" class="h-5 w-5" /><Eye v-else class="h-5 w-5" />
                    </button>
                </div>
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm password" />
                <TextInput
                    id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password"
                    class="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-sm shadow-slate-900/[.02] transition placeholder:text-slate-300 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <button
                type="submit" :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Submitting…' : 'Submit registration' }}
            </button>

            <p class="text-center text-sm text-slate-500">
                Already have an account?
                <Link :href="route('login')" class="font-semibold text-brand-600 transition hover:text-brand-800">Sign in</Link>
            </p>
        </form>
    </GuestLayout>
</template>
