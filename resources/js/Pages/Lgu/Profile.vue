<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import FormField from '@/Components/UI/FormField.vue';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import Modal from '@/Components/Modal.vue';
import { CPU_ROLES, PARTNER_AGENCIES } from '@/utils/caseOptions';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Circle, ImagePlus, PenLine, Plus, Trash2, UsersRound } from '@lucide/vue';
import { computed, ref } from 'vue';

type Lgu = { id: number; name: string; province: string | null; region: string | null };
type Profile = { office_name: string | null; office_short_name: string | null; address: string | null; contact_number: string | null; email: string | null; head_name: string | null; head_position: string | null; head_designation: string | null; has_logo: boolean; updated_at: string | null };
type Member = { id: number; name: string; position: string | null; office: string | null; cpu_role: string; user_id: number | null; active: boolean; account_name: string | null };
type Account = { id: number; name: string; position: string | null; role: string; cpu_discipline: string | null };

const props = defineProps<{ lgus: Lgu[]; selectedLguId: number | null; profile: Profile | null; logoUrl: string | null; cpuMembers: Member[]; caseManagers: Account[]; linkableAccounts: Account[]; canManage: boolean }>();

const me = usePage().props.auth.user as { name: string; position?: string | null; role: string };
const preparer = computed(() => me.role === 'social_worker' ? { name: me.name, position: me.position } : { name: props.caseManagers[0]?.name, position: props.caseManagers[0]?.position });
const lgu = computed(() => props.lgus.find(item => item.id === props.selectedLguId) ?? null);
const switchLgu = (event: Event) => router.get(route('lgu-profile.show'), { lgu: (event.target as HTMLSelectElement).value }, { preserveScroll: true });

const profile = useForm({
  office_name: props.profile?.office_name ?? '', office_short_name: props.profile?.office_short_name ?? '',
  address: props.profile?.address ?? '', contact_number: props.profile?.contact_number ?? '', email: props.profile?.email ?? '',
  head_name: props.profile?.head_name ?? '', head_position: props.profile?.head_position ?? '', head_designation: props.profile?.head_designation ?? '',
});
const saveProfile = () => props.selectedLguId && profile.put(route('lgu-profile.update', props.selectedLguId), { preserveScroll: true });

const logoForm = useForm<{ logo: File | null }>({ logo: null });
const logoInput = ref<HTMLInputElement | null>(null);
function pickLogo(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file || !props.selectedLguId) return;
  logoForm.logo = file;
  logoForm.post(route('lgu-profile.logo.upload', props.selectedLguId), { preserveScroll: true, forceFormData: true, onFinish: () => { if (logoInput.value) logoInput.value.value = ''; } });
}
const removeLogo = () => props.selectedLguId && router.delete(route('lgu-profile.logo.remove', props.selectedLguId), { preserveScroll: true });

const memberOpen = ref(false);
const editing = ref<Member | null>(null);
const member = useForm({ name: '', position: '', office: '', cpu_role: '', user_id: null as number | null, active: true, sort_order: 0 });
function openMember(item: Member | null) {
  editing.value = item;
  member.clearErrors();
  member.defaults({ name: item?.name ?? '', position: item?.position ?? '', office: item?.office ?? '', cpu_role: item?.cpu_role ?? '', user_id: item?.user_id ?? null, active: item?.active ?? true, sort_order: 0 });
  member.reset();
  memberOpen.value = true;
}
/** Linking an account fills the name and position, so the worker does not type them twice. */
function linkAccount(event: Event) {
  const id = Number((event.target as HTMLSelectElement).value) || null;
  member.user_id = id;
  const account = props.linkableAccounts.find(item => item.id === id);
  if (account) { member.name ||= account.name; member.position ||= account.position ?? ''; }
}
function saveMember() {
  if (!props.selectedLguId) return;
  const options = { preserveScroll: true, onSuccess: () => { memberOpen.value = false; } };
  if (editing.value) member.put(route('lgu-profile.members.update', [props.selectedLguId, editing.value.id]), options);
  else member.post(route('lgu-profile.members.store', props.selectedLguId), options);
}
const removeMember = (item: Member) => props.selectedLguId && window.confirm(`Remove ${item.name} from the CPU list?`) && router.delete(route('lgu-profile.members.destroy', [props.selectedLguId, item.id]), { preserveScroll: true });

const activeMembers = computed(() => props.cpuMembers.filter(item => item.active));
/** What generated reports need from this page. */
const readiness = computed(() => [
  { label: 'Office name', done: !!props.profile?.office_name },
  { label: 'Logo uploaded', done: !!props.profile?.has_logo },
  { label: 'Office head or supervisor (name and position)', done: !!(props.profile?.head_name && props.profile?.head_position) },
  { label: 'At least one active CPU member', done: activeMembers.value.length > 0 },
  { label: 'Case managers have a position on their profile', done: props.caseManagers.length > 0 && props.caseManagers.every(item => !!item.position) },
]);
const readyCount = computed(() => readiness.value.filter(item => item.done).length);
const roleGaps = computed(() => {
  const roles = new Set(activeMembers.value.map(item => item.cpu_role));
  return ['Health representative', 'Law enforcement representative', 'Social welfare representative'].filter(role => !roles.has(role));
});
</script>

<template>
  <Head title="LGU profile & signatories" />
  <AuthenticatedLayout>
    <div class="space-y-5">
      <PageHeader eyebrow="Settings" title="LGU profile and signatories" description="Office details, logo and the people who sign Kalinga reports. The office head signs the Social Case Study Report and Case Summary as reviewer.">
        <template #actions>
          <label v-if="lgus.length > 1" class="text-sm font-medium text-slate-700">
            <span class="sr-only">LGU</span>
            <select :value="selectedLguId ?? ''" class="ui-field min-w-56" aria-label="Choose LGU" @change="switchLgu"><option v-for="item in lgus" :key="item.id" :value="item.id">{{ item.name }}{{ item.province ? `, ${item.province}` : '' }}</option></select>
          </label>
        </template>
      </PageHeader>

      <section v-if="!lgu" class="ui-panel p-6 text-sm text-slate-600">You are not assigned to an LGU yet. Ask the system administrator to assign your account to a pilot LGU.</section>

      <template v-else>
        <section class="ui-panel flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5" aria-label="Report readiness">
          <div class="flex items-center gap-3">
            <div class="grid h-14 w-14 shrink-0 place-items-center rounded-full text-lg font-bold" :class="readyCount === readiness.length ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">{{ readyCount }}/{{ readiness.length }}</div>
            <div><h2 class="ui-section-title">Report readiness</h2><p class="text-sm text-slate-600">{{ readyCount === readiness.length ? 'Reports for this LGU will carry the logo and signatories.' : 'Complete these so generated reports are ready to sign.' }}</p></div>
          </div>
          <ul class="grid flex-1 gap-1.5 text-sm sm:grid-cols-2">
            <li v-for="item in readiness" :key="item.label" class="flex items-center gap-2" :class="item.done ? 'text-slate-700' : 'text-amber-900'"><CheckCircle2 v-if="item.done" class="h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true"/><Circle v-else class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true"/>{{ item.label }}<span class="sr-only">{{ item.done ? ' - done' : ' - to do' }}</span></li>
          </ul>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-[1.25fr_.75fr]">
          <form class="ui-panel" aria-labelledby="office-title" @submit.prevent="saveProfile">
            <header class="ui-panel-header"><div><h2 id="office-title" class="ui-section-title">Office and signatory</h2><p class="text-xs text-slate-600">{{ lgu.name }}{{ lgu.province ? `, ${lgu.province}` : '' }}</p></div></header>
            <fieldset :disabled="!canManage || profile.processing" class="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
              <legend class="sr-only">Office details</legend>
              <FormField id="lgu-office" label="Office name" :error="profile.errors.office_name" required class="sm:col-span-2"><input id="lgu-office" v-model="profile.office_name" required maxlength="180" class="ui-field" placeholder="e.g. City Social Welfare and Development Office"></FormField>
              <FormField id="lgu-short" label="Short name" :error="profile.errors.office_short_name" hint="Shown on report headers, e.g. CSWDO or MSWDO."><input id="lgu-short" v-model="profile.office_short_name" maxlength="40" class="ui-field"></FormField>
              <FormField id="lgu-contact" label="Office contact number" :error="profile.errors.contact_number"><input id="lgu-contact" v-model="profile.contact_number" maxlength="60" inputmode="tel" class="ui-field"></FormField>
              <FormField id="lgu-address" label="Office address" :error="profile.errors.address" class="sm:col-span-2"><input id="lgu-address" v-model="profile.address" maxlength="255" class="ui-field"></FormField>
              <FormField id="lgu-email" label="Office email" :error="profile.errors.email" class="sm:col-span-2"><input id="lgu-email" v-model="profile.email" type="email" maxlength="160" class="ui-field"></FormField>
              <div class="sm:col-span-2 border-t border-slate-100 pt-4"><h3 class="text-sm font-semibold text-slate-900">Office head / supervisor</h3><p class="text-xs text-slate-600">Signs as "Noted by" on reports prepared by the case manager.</p></div>
              <FormField id="lgu-head" label="Full name" :error="profile.errors.head_name" required><input id="lgu-head" v-model="profile.head_name" required maxlength="160" class="ui-field"></FormField>
              <FormField id="lgu-head-position" label="Position" :error="profile.errors.head_position" required hint="Plantilla position, e.g. Social Welfare Officer IV."><input id="lgu-head-position" v-model="profile.head_position" required maxlength="160" class="ui-field"></FormField>
              <FormField id="lgu-head-designation" label="Designation" :error="profile.errors.head_designation" hint="e.g. City Social Welfare and Development Officer." class="sm:col-span-2"><input id="lgu-head-designation" v-model="profile.head_designation" maxlength="160" class="ui-field"></FormField>
              <div v-if="canManage" class="sm:col-span-2"><button type="submit" class="ui-button-primary">{{ profile.processing ? 'Saving...' : 'Save office and signatory' }}</button></div>
            </fieldset>
          </form>

          <div class="space-y-5">
            <section class="ui-panel p-4 sm:p-5" aria-labelledby="logo-title">
              <h2 id="logo-title" class="ui-section-title">Logo</h2><p class="text-xs text-slate-600">PNG, JPG or WEBP up to 1 MB. Appears on report headers.</p>
              <div class="mt-4 flex items-center gap-4">
                <div class="grid h-24 w-24 shrink-0 place-items-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50"><img v-if="logoUrl" :src="logoUrl" alt="LGU logo" class="h-full w-full object-contain"><ImagePlus v-else class="h-7 w-7 text-slate-500" aria-hidden="true"/></div>
                <div v-if="canManage" class="flex flex-col gap-2">
                  <label class="ui-button cursor-pointer"><ImagePlus class="h-4 w-4" aria-hidden="true"/>{{ logoUrl ? 'Replace logo' : 'Upload logo' }}<input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="pickLogo"></label>
                  <button v-if="logoUrl" type="button" class="ui-link text-rose-700" @click="removeLogo">Remove logo</button>
                  <p v-if="logoForm.errors.logo" role="alert" class="text-xs text-rose-800">{{ logoForm.errors.logo }}</p>
                </div>
              </div>
            </section>
            <section class="ui-panel p-4 sm:p-5" aria-labelledby="signature-title">
              <h2 id="signature-title" class="ui-section-title">Signature preview</h2>
              <div class="mt-4 grid grid-cols-2 gap-4 text-center text-xs">
                <div><p class="text-left text-slate-500">Prepared by:</p><p class="mt-6 border-t border-slate-400 pt-1 font-bold uppercase text-slate-900">{{ preparer.name || 'Case manager' }}</p><p class="text-slate-600">{{ preparer.position || 'Position' }}</p></div>
                <div><p class="text-left text-slate-500">Noted by:</p><p class="mt-6 border-t border-slate-400 pt-1 font-bold uppercase text-slate-900">{{ profile.head_name || 'Office head' }}</p><p class="text-slate-600">{{ profile.head_position || 'Position' }}</p><p v-if="profile.head_designation" class="text-slate-600">{{ profile.head_designation }}</p></div>
              </div>
            </section>
            <section class="ui-panel p-4 sm:p-5" aria-labelledby="cm-title">
              <h2 id="cm-title" class="ui-section-title">Case managers in this LGU</h2>
              <ul v-if="caseManagers.length" class="mt-3 divide-y divide-slate-100 text-sm"><li v-for="item in caseManagers" :key="item.id" class="flex items-center justify-between gap-3 py-2"><span class="font-medium text-slate-900">{{ item.name }}</span><span :class="item.position ? 'text-slate-600' : 'text-amber-800'">{{ item.position || 'No position on profile' }}</span></li></ul>
              <p v-else class="mt-2 text-sm text-slate-600">No case manager is assigned to this LGU.</p>
              <p class="mt-2 text-xs text-slate-500">Each case manager sets their own position under Profile and security.</p>
            </section>
          </div>
        </div>

        <section class="ui-panel" aria-labelledby="cpu-title">
          <header class="ui-panel-header">
            <div><h2 id="cpu-title" class="ui-section-title flex items-center gap-2"><UsersRound class="h-4 w-4 text-brand-700" aria-hidden="true"/>Child Protection Unit composition</h2><p class="text-xs text-slate-600">Members, positions and their role in the CPU. Linked members can receive referrals in Kalinga.</p></div>
            <button v-if="canManage" type="button" class="ui-button-primary" @click="openMember(null)"><Plus class="h-4 w-4" aria-hidden="true"/>Add CPU member</button>
          </header>
          <p v-if="roleGaps.length && cpuMembers.length" class="mx-4 mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 sm:mx-5">Usual CPU roles not yet listed: {{ roleGaps.join(', ') }}.</p>
          <div class="overflow-x-auto">
            <table class="ui-table ui-table-stack w-full text-left text-sm">
              <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600"><tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Position / designation</th><th class="px-4 py-3">Office / agency</th><th class="px-4 py-3">CPU role</th><th class="px-4 py-3">Kalinga account</th><th class="px-4 py-3"><span class="sr-only">Actions</span></th></tr></thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="item in cpuMembers" :key="item.id" :class="item.active ? '' : 'opacity-60'">
                  <td data-label="Name" class="px-4 py-3 font-medium text-slate-900">{{ item.name }}<span v-if="!item.active" class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">Inactive</span></td>
                  <td data-label="Position" class="px-4 py-3 text-slate-700">{{ item.position || '-' }}</td>
                  <td data-label="Office" class="px-4 py-3 text-slate-700">{{ item.office || '-' }}</td>
                  <td data-label="CPU role" class="px-4 py-3"><span class="rounded-full bg-brand-50 px-2 py-1 text-xs font-semibold text-brand-800">{{ item.cpu_role }}</span></td>
                  <td data-label="Account" class="px-4 py-3 text-slate-700">{{ item.account_name || 'Not linked' }}</td>
                  <td class="px-4 py-3 text-right"><div v-if="canManage" class="flex justify-end gap-1"><button type="button" class="ui-button h-9 min-h-9 px-2" :aria-label="`Edit ${item.name}`" @click="openMember(item)"><PenLine class="h-4 w-4"/></button><button type="button" class="ui-button h-9 min-h-9 px-2 text-rose-700" :aria-label="`Remove ${item.name}`" @click="removeMember(item)"><Trash2 class="h-4 w-4"/></button></div></td>
                </tr>
              </tbody>
            </table>
            <p v-if="!cpuMembers.length" class="p-6 text-center text-sm text-slate-600">No CPU members listed yet.</p>
          </div>
        </section>
      </template>
    </div>

    <Modal :show="memberOpen" max-width="lg" :aria-label="editing ? 'Edit CPU member' : 'Add CPU member'" @close="memberOpen = false">
      <form class="space-y-4 p-5" data-skip-generic-confirm="true" @submit.prevent="saveMember">
        <h2 class="text-lg font-semibold text-slate-900">{{ editing ? 'Edit CPU member' : 'Add CPU member' }}</h2>
        <FormField v-if="linkableAccounts.length" id="member-account" label="Kalinga account (optional)" hint="Choose a CPU member account in this LGU to fill the name and position."><select id="member-account" :value="member.user_id ?? ''" class="ui-field" @change="linkAccount"><option value="">Not linked</option><option v-for="account in linkableAccounts" :key="account.id" :value="account.id">{{ account.name }}</option></select></FormField>
        <FormField id="member-name" label="Full name" :error="member.errors.name" required><input id="member-name" v-model="member.name" required maxlength="160" class="ui-field"></FormField>
        <FormField id="member-position" label="Position / designation" :error="member.errors.position" required><input id="member-position" v-model="member.position" required maxlength="160" class="ui-field" placeholder="e.g. Municipal Health Officer"></FormField>
        <FormField id="member-office" label="Office / agency" :error="member.errors.office"><OptionSelect id="member-office" v-model="member.office" :options="PARTNER_AGENCIES" /></FormField>
        <FormField id="member-role" label="Role in the CPU" :error="member.errors.cpu_role" required><OptionSelect id="member-role" v-model="member.cpu_role" :options="CPU_ROLES" required /></FormField>
        <label class="flex min-h-11 items-center gap-3 text-sm"><input v-model="member.active" type="checkbox" class="h-5 w-5 rounded border-slate-400">Active member</label>
        <div class="flex justify-end gap-2"><button type="button" class="ui-button" @click="memberOpen = false">Cancel</button><button type="submit" class="ui-button-primary" :disabled="member.processing">{{ editing ? 'Save changes' : 'Add member' }}</button></div>
      </form>
    </Modal>
  </AuthenticatedLayout>
</template>
