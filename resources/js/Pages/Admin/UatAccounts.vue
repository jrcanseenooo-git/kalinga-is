<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

type RosterRow = { uat_key: string; name: string; email: string; role: string; cpu_discipline: string | null; region: string; province: string; municipality: string };
const props = defineProps<{ roster: RosterRow[]; existingCount: number; defaultExpiry: string; exportAvailable: boolean }>();
const recovery = useForm({ confirmed: false });
const acknowledgement = useForm({ confirmed: true });
const roles: Record<string, string> = { social_worker: 'Case Manager', cpu_member: 'CPU Member', lgu_head: 'LGU Head / Supervisor', fo_user: 'Field Office User' };
const disciplines: Record<string, string> = { health_officer: 'Health Officer', barangay_official: 'Barangay Official / BCPC', lea: 'Law Enforcement' };
const expiry = ref(props.defaultExpiry);
const confirmed = ref(false);
const busy = ref(false);
const created = ref(false);
const error = ref('');
const create = async () => {
    if (busy.value || !confirmed.value || created.value) return;
    busy.value = true;
    error.value = '';
    try {
        const response = await axios.post<{ accounts: (RosterRow & { password: string; expires_at: string })[] }>(route('admin.uat.store'), { expires_at: expiry.value, confirmed: true });
        // Credentials are returned once, never put in Inertia props, browser storage or logs.
        const file = new Blob([JSON.stringify({ system: window.location.origin, accounts: response.data.accounts }, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'kalinga-uat-credentials.json';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
        response.data.accounts = [];
        created.value = true;
    } catch (failure) {
        const data = axios.isAxiosError(failure) ? failure.response?.data as { errors?: Record<string, string[]> } | undefined : undefined;
        error.value = data?.errors ? Object.values(data.errors).flat().join(' ') : 'The request could not be confirmed. Check Users before retrying; existing accounts will never be reset.';
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="UAT account setup" />
        <main class="mx-auto max-w-6xl space-y-6 px-4 py-6">
            <Link :href="route('admin.index')" class="text-sm font-semibold text-brand-700">Back to Users</Link>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">UAT account setup</h1>
                <p class="mt-2 text-slate-600">43 temporary accounts: 28 regional participants and 15 ITO test accounts.</p>
            </div>
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                Use fictional data only. These accounts display their sign-in code after password entry and do not verify email ownership.
                Supervisors share their LGU's normal visibility; separate ITO logins are not separate sandboxes.
                BCPC maps to Barangay Official. Accounts stop working at expiry or when UAT mode is switched off.
            </div>
            <p v-if="created" role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-900">43 accounts created. Save the downloaded credentials privately and distribute only each participant's own accounts. Reload this page if the download failed; an encrypted retry export is available for 30 minutes.</p>
            <p v-else-if="existingCount" role="status" class="rounded-xl bg-slate-100 p-4">{{ existingCount }} roster accounts exist. Creation is blocked to prevent credential resets. Check Users.</p>
            <form v-else @submit.prevent="create" data-skip-generic-confirm="true" class="space-y-4 rounded-xl border bg-white p-5">
                <label class="block text-sm font-semibold">Access ends (Philippine time)
                    <input v-model="expiry" type="datetime-local" required class="mt-2 block rounded-lg border-slate-300" />
                </label>
                <label class="flex items-start gap-3 text-sm"><input v-model="confirmed" type="checkbox" required class="mt-1" /><span>I reviewed the roster, BCPC mapping, shared LGU visibility and expiry. Create these accounts and download their credentials.</span></label>
                <p v-if="error" role="alert" class="text-sm text-rose-700">{{ error }}</p>
                <button :disabled="!confirmed || busy" class="rounded-lg bg-brand-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{{ busy ? 'Creating accounts...' : 'Create 43 accounts and download credentials' }}</button>
            </form>
            <section v-if="exportAvailable" class="space-y-3 rounded-xl border border-emerald-300 bg-emerald-50 p-5">
                <h2 class="font-semibold">Credentials ready for private download</h2>
                <p>The encrypted retry export expires after 30 minutes or at account expiry. Download and check the file before removing this server copy.</p>
                <a :href="route('admin.uat.download')" class="block font-semibold underline">Download saved UAT credentials</a>
                <button :disabled="acknowledgement.processing" @click="acknowledgement.post(route('admin.uat.acknowledge'))" class="rounded-lg border bg-white px-4 py-3">I saved the file - remove the server copy</button>
            </section>
            <form v-else-if="existingCount && !created" @submit.prevent="recovery.post(route('admin.uat.recover'))" data-skip-generic-confirm="true" class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="font-semibold">Recover a failed first credential download</h2>
                <p>This changes passwords for all 43 accounts. It runs only once, only for the original administrator, and only while every account is unused, unchanged and unexpired. Roles, locations and expiry stay the same. After completion, reload this page and download the saved credentials.</p>
                <label class="flex items-start gap-3"><input v-model="recovery.confirmed" type="checkbox" required /><span>No credentials were received or distributed. I want to replace the unused roster passwords.</span></label>
                <p v-for="message in recovery.errors" :key="message" role="alert" class="text-rose-700">{{ message }}</p>
                <button :disabled="!recovery.confirmed || recovery.processing" class="rounded-lg bg-brand-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{{ recovery.processing ? 'Preparing credentials...' : 'Replace unused UAT passwords' }}</button>
            </form>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50"><tr><th class="p-3">Account</th><th class="p-3">Role / discipline</th><th class="p-3">Location</th></tr></thead>
                    <tbody><tr v-for="row in roster" :key="row.uat_key" class="border-t"><td class="p-3"><p class="font-semibold">{{ row.name }}</p><p>{{ row.email }}</p></td><td class="p-3">{{ roles[row.role] }}<span v-if="row.cpu_discipline"> / {{ disciplines[row.cpu_discipline] }}</span></td><td class="p-3">{{ row.municipality }}, {{ row.province }}<p class="text-slate-500">{{ row.region }}</p></td></tr></tbody>
                </table>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
