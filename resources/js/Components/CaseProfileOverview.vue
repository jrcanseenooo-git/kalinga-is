<script setup lang="ts">
import { classificationLabels, incidenceRows, type IncidenceRecord } from '@/data/cefmu';
import { computed, ref } from 'vue';
import { ClipboardList, FileText, HandHeart, House, Send, ShieldCheck, UserRound, Users } from '@lucide/vue';
import { NOTE_TYPES } from '@/utils/caseOptions';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { shortDate, titleCase, riskTone } from '@/utils/caseDisplay';
import { ageFromBirthdate, parseStored, philippineTimestamp } from '@/utils/philippineTime';
import { stepLabel, type TrackedReferral } from '@/utils/referralTracking';
import type { SupportRelationship } from '@/types/clientContext';
import type { MonitoringVisit, ProgressNote } from '@/types/monitoring';

// Read-only case summary in the layout of the earlier Kalinga case page: client
// profile, case information and one recent-activity timeline. It only arranges
// data the case page already received; nothing extra is requested.
const props = defineProps<{
  record: Record<string, any>;
  relationships: SupportRelationship[];
  notes: ProgressNote[];
  visits: MonitoringVisit[];
  referrals: TrackedReferral[];
  statusHistory: Array<{ id: number; event: string; changes?: string | Record<string, unknown> | null; created_at: string }>;
  services?: Array<{ id: number; service_type: string; specific_detail: string | null; amount: string | number | null; date_provided: string; provided_by: string | null }>;
}>();
const services = computed(() => props.services ?? []);
const peso = (value: string | number | null) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
const assistanceTotal = computed(() => services.value.reduce((sum, item) => sum + Number(item.amount || 0), 0));
const emit = defineEmits<{ open: [section: 'people' | 'monitoring' | 'collaboration'] }>();

const profileTab = ref<'personal' | 'address' | 'family'>('personal');
const value = (input: unknown) => (input === null || input === undefined || String(input).trim() === '' ? 'Not recorded' : String(input));
const age = computed(() => ageFromBirthdate(props.record.birthdate));
const fullName = computed(() => [props.record.first_name, props.record.middle_name, props.record.last_name, props.record.suffix].filter(Boolean).join(' '));
const address = computed(() => [props.record.house_no_street, props.record.barangay, props.record.municipality, props.record.province, props.record.region].filter(part => part && String(part).trim()).join(', '));
const classifications = computed(() => {
  const raw = props.record.cefmu_classifications;
  const list = Array.isArray(raw) ? raw : typeof raw === 'string' && raw.trim().startsWith('[') ? JSON.parse(raw) : raw ? [raw] : [];
  return classificationLabels(list).join(', ');
});

const personal = computed(() => [
  ['Sex', value(props.record.sex)], ['Age', age.value === null ? 'Not recorded' : `${age.value} years`],
  ['Date of birth', props.record.birthdate ? shortDate(props.record.birthdate) : 'Not recorded'], ['Civil status', value(props.record.civil_status && titleCase(props.record.civil_status))],
  ['Religion', value(props.record.religion)], ['IP category', value(props.record.ip_category)],
  ['Education', value(props.record.education_attainment && titleCase(props.record.education_attainment))], ['Contact number', value(props.record.phone)],
]);
const caseInfo = computed(() => [
  ['CEFMU classification', value(classifications.value)], ...incidenceRows(props.record as IncidenceRecord),
  ['Case category', value(props.record.category || props.record.case_type)], ['Circumstance', value(props.record.circumstance)],
  ['Mode of reporting', value(props.record.mode_of_admission && titleCase(props.record.mode_of_admission))], ['Referred by', value(props.record.referred_by)],
  ['Referral date', props.record.referral_date ? shortDate(props.record.referral_date) : 'Not recorded'], ['Intake completed', props.record.intake_completed_at ? shortDate(props.record.intake_completed_at) : 'Not recorded'],
  ['Opened', props.record.opened_at ? shortDate(props.record.opened_at) : 'Not recorded'], ['Case worker', value(props.record.worker_name)],
  ['Education at intake', value(props.record.education_status_at_intake && titleCase(props.record.education_status_at_intake))], ['Pregnancy or parenting', value(props.record.pregnancy_parenting_status && titleCase(props.record.pregnancy_parenting_status))],
]);

type Activity = { key: string; at: string; kind: 'note' | 'visit' | 'referral' | 'service' | 'status'; title: string; text?: string; meta?: string; extra?: Array<[string, string]> };
const activity = computed<Activity[]>(() => {
  const items: Activity[] = [];
  for (const note of props.notes) items.push({ key: `n${note.id}`, at: note.occurred_at || note.created_at, kind: 'note', title: note.record_type === 'addendum' ? 'Progress note addendum' : `Progress note - ${NOTE_TYPES.find(type => type.value === note.note_type)?.label ?? titleCase(note.note_type || 'other')}`, text: note.body, meta: note.author,
    extra: [note.action_taken ? ['Action taken', note.action_taken] : null, note.next_steps ? ['Next steps', note.next_steps] : null].filter(Boolean) as Array<[string, string]> });
  for (const visit of props.visits) items.push({ key: `v${visit.id}`, at: visit.visited_at, kind: 'visit', title: `Monitoring visit${visit.location ? ` - ${visit.location}` : ''}`, text: visit.observations,
    extra: visit.next_steps ? [['Next steps', visit.next_steps]] : [] });
  for (const referral of props.referrals) for (const event of referral.events ?? []) {
    if (!event.created_at) continue;
    items.push({ key: `r${referral.id}-${event.to_status}-${event.created_at}`, at: event.created_at, kind: 'referral', title: `Referral ${stepLabel(event.to_status).toLowerCase()} - ${(referral as TrackedReferral & { purpose?: string | null }).purpose || referral.service_category}`, text: event.outcome || event.reason || event.remarks || undefined, meta: [(referral as TrackedReferral & { referred_to?: string | null }).referred_to || referral.provider, event.actor].filter(Boolean).join(' - ') });
  }
  for (const service of services.value) items.push({ key: `sv${service.id}`, at: service.date_provided, kind: 'service', title: `Service provided - ${service.service_type}${service.amount ? ` (${peso(service.amount)})` : ''}`, text: service.specific_detail ?? undefined, meta: service.provided_by ?? undefined });
  for (const entry of props.statusHistory) {
    if (entry.event !== 'case.transitioned') continue;
    const changes = typeof entry.changes === 'string' ? JSON.parse(entry.changes || '{}') : entry.changes ?? {};
    items.push({ key: `s${entry.id}`, at: entry.created_at, kind: 'status', title: `Case status: ${titleCase(String(changes.from ?? ''))} to ${titleCase(String(changes.to ?? ''))}` });
  }
  return items.filter(item => item.at).sort((a, b) => (parseStored(b.at)?.getTime() ?? 0) - (parseStored(a.at)?.getTime() ?? 0));
});
const shown = ref(6);
const kindStyle: Record<Activity['kind'], { dot: string; icon: unknown; label: string }> = {
  note: { dot: 'bg-brand-600 ring-brand-100', icon: FileText, label: 'Note' },
  visit: { dot: 'bg-sky-600 ring-sky-100', icon: House, label: 'Visit' },
  referral: { dot: 'bg-amber-500 ring-amber-100', icon: Send, label: 'Referral' },
  service: { dot: 'bg-emerald-600 ring-emerald-100', icon: HandHeart, label: 'Service' },
  status: { dot: 'bg-slate-500 ring-slate-100', icon: ClipboardList, label: 'Status' },
};
const when = (at: string) => (at.length === 10 ? shortDate(at) : philippineTimestamp(at));
</script>

<template>
  <div class="space-y-5">
    <!-- Client profile -->
    <section class="ui-panel overflow-hidden p-0" aria-labelledby="client-profile-title">
      <header class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4">
        <div class="flex min-w-0 items-center gap-2"><UserRound class="h-4 w-4 text-brand-700" aria-hidden="true" /><h2 id="client-profile-title" class="ui-section-title">Client profile</h2></div>
        <button type="button" class="ui-link min-h-9 text-xs" @click="emit('open', 'people')">Edit in Client &amp; Intake</button>
      </header>
      <p class="px-5 pt-1 text-sm font-semibold text-slate-800">{{ fullName }}</p>
      <nav class="ui-tabs mx-5 mt-2" aria-label="Client profile sections">
        <button v-for="tab in ([['personal','Personal'],['address','Address & contact'],['family','Family & support']] as const)" :key="tab[0]" type="button" class="ui-tab" :class="profileTab === tab[0] ? 'ui-tab-active' : ''" :aria-current="profileTab === tab[0] ? 'true' : undefined" @click="profileTab = tab[0]">{{ tab[1] }}</button>
      </nav>
      <div class="px-5 py-4">
        <dl v-if="profileTab === 'personal'" class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm md:grid-cols-4">
          <div v-for="[label, text] in personal" :key="label" class="min-w-0"><dt class="text-xs text-slate-600">{{ label }}</dt><dd class="mt-0.5 break-words font-medium" :class="text === 'Not recorded' ? 'text-slate-500' : 'text-slate-900'">{{ text }}</dd></div>
        </dl>
        <div v-else-if="profileTab === 'address'" class="grid gap-4 md:grid-cols-2">
          <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-semibold text-slate-600">Present address</p><p class="mt-1 text-sm text-slate-800">{{ address || 'Not recorded' }}</p></div>
          <div class="rounded-xl bg-slate-50 p-4"><p class="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><ShieldCheck class="h-3.5 w-3.5 text-emerald-700" aria-hidden="true" />Safe contact</p><p class="mt-1 text-sm text-slate-800">{{ record.safe_contact_method ? titleCase(record.safe_contact_method) : 'Not recorded' }}</p><p v-if="record.safe_contact_notes" class="mt-1 whitespace-pre-wrap text-xs text-slate-600">{{ record.safe_contact_notes }}</p></div>
          <div class="rounded-xl bg-slate-50 p-4 md:col-span-2"><p class="text-xs font-semibold text-slate-600">Primary caregiver</p><p class="mt-1 text-sm text-slate-800">{{ record.primary_caregiver_name || 'Not recorded' }}<span v-if="record.primary_caregiver_relationship" class="text-slate-600"> - {{ titleCase(record.primary_caregiver_relationship) }}</span></p></div>
        </div>
        <template v-else>
          <table v-if="relationships.length" class="ui-table ui-table-stack -mx-5 w-[calc(100%+2.5rem)]">
            <thead><tr><th scope="col">Name</th><th scope="col">Relationship</th><th scope="col">Sex</th><th scope="col">Education</th><th scope="col">Occupation</th><th scope="col">Guardian</th></tr></thead>
            <tbody><tr v-for="person in relationships" :key="person.id"><td data-label="Name" class="font-medium text-slate-800">{{ person.name }}</td><td data-label="Relationship">{{ titleCase(person.relationship_type) }}</td><td data-label="Sex">{{ person.sex || '-' }}</td><td data-label="Education">{{ person.education || '-' }}</td><td data-label="Occupation">{{ person.occupation || '-' }}</td><td data-label="Guardian">{{ person.is_guardian ? 'Recorded guardian' : '-' }}</td></tr></tbody>
          </table>
          <p v-else class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-600"><Users class="mx-auto mb-1 h-5 w-5 text-slate-500" aria-hidden="true" />No family or support persons recorded.</p>
        </template>
      </div>
    </section>

    <!-- Case information -->
    <section class="ui-panel p-5" aria-labelledby="case-info-title">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="case-info-title" class="ui-section-title">Case information</h2><StatusBadge v-if="record.current_risk_level" :label="`Current risk: ${titleCase(record.current_risk_level)}`" :tone="riskTone(record.current_risk_level)" /></div>
      <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-sm md:grid-cols-3">
        <div v-for="[label, text] in caseInfo" :key="label" class="min-w-0"><dt class="text-xs text-slate-600">{{ label }}</dt><dd class="mt-0.5 break-words font-medium" :class="text === 'Not recorded' ? 'text-slate-500' : 'text-slate-900'">{{ text }}</dd></div>
      </dl>
      <slot />
    </section>

    <!-- Services provided (highlighted) -->
    <section class="overflow-hidden rounded-xl border-2 border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-5" aria-labelledby="overview-services-title">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-600 text-white"><HandHeart class="h-5 w-5" aria-hidden="true" /></span><div><h2 id="overview-services-title" class="ui-section-title">Services provided</h2><p class="text-xs text-emerald-900">{{ services.length }} service{{ services.length === 1 ? '' : 's' }} recorded</p></div></div>
        <div class="text-right"><p class="text-xl font-bold text-emerald-900">{{ peso(assistanceTotal) }}</p><p class="text-xs text-emerald-900">total assistance</p></div>
      </div>
      <ul v-if="services.length" class="mt-4 grid gap-2 sm:grid-cols-2"><li v-for="item in services.slice(0, 4)" :key="item.id" class="flex items-center justify-between gap-3 rounded-lg bg-white px-3 py-2 text-sm shadow-sm"><span class="min-w-0"><span class="block truncate font-medium text-slate-900">{{ item.service_type }}<span v-if="item.specific_detail" class="font-normal text-slate-600"> - {{ item.specific_detail }}</span></span><span class="block text-xs text-slate-600">{{ shortDate(item.date_provided) }}{{ item.provided_by ? ` - ${item.provided_by}` : '' }}</span></span><span v-if="item.amount" class="shrink-0 text-xs font-semibold text-emerald-800">{{ peso(item.amount) }}</span></li></ul>
      <p v-else class="mt-3 text-sm text-slate-600">No services recorded yet.</p>
      <button type="button" class="ui-link mt-2 min-h-9 text-xs" @click="emit('open', 'collaboration')">{{ services.length > 4 ? `See all ${services.length} services` : 'Open Services & referrals' }}</button>
    </section>

    <!-- Recent activity -->
    <section class="ui-panel p-5" aria-labelledby="case-activity-title">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h2 id="case-activity-title" class="ui-section-title">Progress notes and case activity</h2><p class="ui-muted mt-0.5">Notes, visits, referrals, services and status changes, newest first.</p></div>
        <button type="button" class="ui-button ui-button-primary min-h-10 text-sm" @click="emit('open', 'monitoring')">Add note or visit</button>
      </div>
      <ol v-if="activity.length" class="case-activity mt-4 space-y-4">
        <li v-for="item in activity.slice(0, shown)" :key="item.key" class="relative pl-8">
          <span aria-hidden="true" class="absolute left-0 top-1 grid h-5 w-5 place-items-center rounded-full ring-4" :class="kindStyle[item.kind].dot"><component :is="kindStyle[item.kind].icon" class="h-3 w-3 text-white" /></span>
          <div class="flex flex-wrap items-baseline justify-between gap-x-3">
            <p class="text-sm font-semibold text-slate-900"><span class="sr-only">{{ kindStyle[item.kind].label }}: </span>{{ item.title }}</p>
            <time :datetime="item.at" class="text-xs text-slate-600">{{ when(item.at) }}</time>
          </div>
          <p v-if="item.meta" class="text-xs text-slate-600">{{ item.meta }}</p>
          <p v-if="item.text" class="mt-1 line-clamp-3 whitespace-pre-wrap break-words text-sm text-slate-700">{{ item.text }}</p>
          <dl v-if="item.extra?.length" class="mt-2 grid gap-2 text-xs sm:grid-cols-2"><div v-for="[label, text] in item.extra" :key="label" class="rounded-lg bg-slate-50 px-3 py-2"><dt class="font-semibold text-slate-600">{{ label }}</dt><dd class="mt-0.5 line-clamp-2 whitespace-pre-wrap text-slate-700">{{ text }}</dd></div></dl>
        </li>
      </ol>
      <p v-else class="mt-4 rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-600">No notes, visits or referral updates yet.</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button v-if="activity.length > shown" type="button" class="ui-button min-h-9 text-xs" @click="shown += 10">Show more activity</button>
        <button type="button" class="ui-link min-h-9 text-xs" @click="emit('open', 'monitoring')">Open Progress notes</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.case-activity { position: relative; }
.case-activity::before { content: ''; position: absolute; left: 9px; top: .5rem; bottom: .5rem; width: 2px; background: var(--color-line); }
</style>
