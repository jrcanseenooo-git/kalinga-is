<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import TwoFactorManageForm from './Partials/TwoFactorManageForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

const isSystemAdmin = computed(() => (usePage().props.auth as { user?: { role?: string } }).user?.role === 'system_admin');
defineProps<{
  mustVerifyEmail?: boolean;
  status?: string;
  twoFactorEnabled: boolean;
  twoFactorRequired: boolean;
  twoFactorMethod: 'email' | 'totp';
}>();
</script>

<template>
  <Head title="Profile" />
  <AuthenticatedLayout>
    <section class="w-full max-w-2xl">
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-sm font-bold text-brand-700">Account</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Profile settings</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Manage your account information and security preferences.</p>
      </header>

      <div class="mt-6 space-y-5">
        <article class="ui-panel p-5 sm:p-6">
          <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" class="max-w-xl" />
        </article>

        <article class="ui-panel p-5 sm:p-6">
          <UpdatePasswordForm class="max-w-xl" />
        </article>

        <article class="ui-panel p-5 sm:p-6">
          <TwoFactorManageForm :enabled="twoFactorEnabled" :required="twoFactorRequired" :method="twoFactorMethod" class="max-w-xl" />
        </article>

        <article v-if="isSystemAdmin" class="rounded-2xl border border-rose-100 bg-white p-5 shadow-sm sm:p-6">
          <DeleteUserForm class="max-w-xl" />
        </article>
        <article v-else class="ui-panel p-5 sm:p-6">
          <h2 class="text-lg font-bold text-slate-900">Account removal</h2>
          <p class="mt-1 max-w-xl text-sm text-slate-600">Accounts are managed by the system administrator. To close or transfer your account, ask your administrator. Case records you made stay with the case.</p>
        </article>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
