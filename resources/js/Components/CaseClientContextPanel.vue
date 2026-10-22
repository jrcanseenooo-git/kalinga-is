<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { CLIENT_CATEGORIES, CONSENT_BASES, RELATIONSHIPS } from '@/utils/caseOptions';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import LocationSelects from '@/Components/LocationSelects.vue';
import { registerUnsavedCaseWork } from '@/Security/browserHistory';
import { calendarDateLabel, manilaDateTime, philippineTimestamp } from '@/utils/philippineTime';
import type { ClientContext, ConsentEntry, SupportRelationship } from '@/types/clientContext';
import type { PageProps } from '@/types';

const props = defineProps<{ record: ClientContext; relationships: SupportRelationship[]; consents: ConsentEntry[]; canArchive: boolean }>();
const emit = defineEmits<{ archive: [] }>();
const page = usePage<PageProps>();
const allowed = (permission: string) => page.props.auth.permissions.includes(permission);
const view = ref<'details' | 'support' | 'consent'>('details');
const contextOpen = ref(false);
const message = ref('');
const failures = ref<string[]>([]);
const errorSummary = ref<HTMLElement | null>(null);
const edit = useForm({
    first_name: props.record.first_name, last_name: props.record.last_name,
    birthdate: props.record.birthdate?.slice(0, 10) ?? '', sex: props.record.sex ?? '', phone: props.record.phone ?? '',
    region: props.record.region ?? '', province: props.record.province ?? '', municipality: props.record.municipality ?? '',
    barangay: props.record.barangay ?? '', category: props.record.category ?? '', priority: props.record.priority,
});
const support = useForm({ name: '', relationship_type: '', phone: '', is_guardian: false });
const consent = useForm({ lawful_basis: '', purposes_text: '', sharing_allowed: false, granted_at: manilaDateTime().slice(0, 10) });
const withdrawal = useForm({});
const busy = computed(() => edit.processing || support.processing || consent.processing || withdrawal.processing);
const dirty = computed(() => edit.isDirty || support.isDirty || consent.isDirty);
const unregister = registerUnsavedCaseWork(() => dirty.value || busy.value);
onBeforeUnmount(unregister);

function list(value: string | string[] | null | undefined): string[] {
    if (Array.isArray(value)) return value.filter(item => typeof item === 'string');
    if (!value) return [];
    try { const parsed: unknown = JSON.parse(value); return Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : [value]; }
    catch { return [value]; }
}
const human = (value: string | null | undefined) => value ? value.replaceAll('_', ' ') : 'Not recorded';
const context = computed(() => [
    ['CEFMU classifications', list(props.record.cefmu_classifications).map(human).join(', ')],
    ['Union type', props.record.union_type], ['Partner age band', props.record.partner_age_band],
    ['Education at intake', props.record.education_status_at_intake], ['Pregnancy / parenting context', props.record.pregnancy_parenting_status],
    ['Admission method', props.record.mode_of_admission], ['Referred by', props.record.referred_by],
    ['Referral date', props.record.referral_date ? calendarDateLabel(props.record.referral_date) : null],
    ['Privacy notice method', human(props.record.privacy_notice_method)],
    ['Intake completed', props.record.intake_completed_at ? philippineTimestamp(props.record.intake_completed_at) : null],
]);
async function failed(errors: Record<string, string>) {
    failures.value = Object.values(errors).filter(Boolean);
    await nextTick(); errorSummary.value?.focus();
}
function begin() { if (busy.value) return false; failures.value = []; message.value = ''; return true; }
function saveDetails() {
    if (!begin()) return;
    edit.patch(route('cases.update', props.record.id), { preserveScroll: true, onError: failed, onSuccess: () => { edit.defaults(); message.value = 'Client and case details saved.'; } });
}
function saveSupport() {
    if (!begin()) return;
    support.post(route('cases.relationships.store', props.record.id), { preserveScroll: true, onError: failed, onSuccess: () => { support.reset(); message.value = 'Support relationship recorded.'; } });
}
function saveConsent() {
    if (!begin()) return;
    consent.transform(data => ({ lawful_basis: data.lawful_basis, purposes: data.purposes_text.split('\n').map(value => value.trim()).filter(Boolean), sharing_allowed: data.sharing_allowed, granted_at: data.granted_at }))
        .post(route('cases.consents.store', props.record.id), { preserveScroll: true, onError: failed, onSuccess: () => { consent.reset(); message.value = 'Consent record saved.'; } });
}
function withdraw(id: number) {
    if (busy.value || !window.confirm('Record withdrawal of this consent? The original entry and audit history will be preserved.')) return;
    if (!begin()) return;
    withdrawal.patch(route('cases.consents.withdraw', [props.record.id, id]), { preserveScroll: true, onError: failed, onSuccess: () => { message.value = 'Consent withdrawal recorded.'; } });
}
</script>

<template>
    <div class="space-y-5">
        <header class="rounded-lg border border-brand-200 bg-kalinga-lavender p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="text-lg font-semibold text-brand-900">Client, household & support</h2><p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">Client details, recorded intake context, support and consent history.</p></div>
                <button v-if="canArchive" type="button" class="ui-button text-rose-800" :disabled="busy || dirty" @click="emit('archive')">Archive case</button>
            </div>
            <p class="mt-3 text-xs leading-5 text-slate-700">Online entry only. Save before closing. A recorded relationship or guardian flag does not establish safe contact, custody or authority to share case information.</p>
        </header>
        <div v-if="failures.length" ref="errorSummary" role="alert" tabindex="-1" class="rounded-md border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900"><p class="font-semibold">Review the fields below. Your entries remain on this page.</p><ul class="mt-2 list-inside list-disc"><li v-for="(failure,index) in failures" :key="index">{{ failure }}</li></ul></div>
        <p v-if="message" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{ message }}</p>
        <p v-if="dirty" class="rounded-md border border-amber-300 bg-kalinga-warm p-3 text-sm text-amber-950">Unfinished entries are retained on this page. Save online before closing; cancel navigation to keep working.</p>
        <label class="block sm:hidden"><span class="sr-only">Client context activity</span><select v-model="view" class="ui-field border-brand-300 bg-brand-50 font-semibold text-brand-900"><option value="details">Client details</option><option value="support">Household & support</option><option value="consent">Consent register</option></select></label>
        <nav aria-label="Client context activities" class="hidden gap-1 rounded-lg border border-brand-200 bg-brand-100 p-1 sm:grid sm:grid-cols-3">
            <button v-for="item in [{key:'details',label:'Client details'},{key:'support',label:'Household & support'},{key:'consent',label:'Consent register'}] as const" :key="item.key" type="button" class="min-h-11 rounded-md px-3 py-2 text-sm font-semibold" :class="view===item.key?'bg-brand-800 text-white':'text-brand-900'" :aria-current="view===item.key?'page':undefined" @click="view=item.key">{{ item.label }}</button>
        </nav>
        <section v-if="view==='details'" class="grid items-start gap-5 xl:grid-cols-2" aria-label="Client details and intake context">
            <form v-if="allowed('cases.update')" aria-labelledby="client-edit-title" autocomplete="off" class="ui-panel p-4" @submit.prevent="saveDetails">
                <h3 id="client-edit-title" class="ui-section-title">Update client details</h3><p class="ui-muted mt-1">Changes apply to the existing client record. Recorded intake context remains available alongside this form.</p>
                <fieldset :disabled="busy" class="mt-4 space-y-4"><legend class="sr-only">Client and case fields</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="client-first" label="Given name" :error="edit.errors.first_name" required><input id="client-first" v-model="edit.first_name" required maxlength="80" class="ui-field" :aria-invalid="Boolean(edit.errors.first_name)||undefined"></FormField>
                        <FormField id="client-last" label="Family name" :error="edit.errors.last_name" required><input id="client-last" v-model="edit.last_name" required maxlength="80" class="ui-field"></FormField>
                        <FormField id="client-birth" label="Birthdate" :error="edit.errors.birthdate"><input id="client-birth" v-model="edit.birthdate" type="date" class="ui-field"></FormField>
                        <FormField id="client-sex" label="Sex" :error="edit.errors.sex"><select id="client-sex" v-model="edit.sex" class="ui-field"><option value="">Not recorded</option><option>Female</option><option>Male</option></select></FormField>
                        <FormField id="client-phone" label="Contact number (optional)" :error="edit.errors.phone" hint="Review the recorded safe contact guidance before making contact."><input id="client-phone" v-model="edit.phone" inputmode="tel" maxlength="13" class="ui-field"></FormField>
                        <FormField id="client-category" label="Recorded case category" :error="edit.errors.category"><OptionSelect id="client-category" v-model="edit.category" :options="CLIENT_CATEGORIES" :allow-other="false" /></FormField>
                        <FormField id="client-priority" label="Case priority" :error="edit.errors.priority" required><select id="client-priority" v-model="edit.priority" class="ui-field"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></FormField>
                    </div>
                    <p class="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-700">Recorded locality: {{ [record.barangay,record.municipality,record.province,record.region].filter(Boolean).join(', ') || 'Not recorded' }}. Updating contact locality does not change the case assignment.</p>
                    <LocationSelects v-model:region="edit.region" v-model:province="edit.province" v-model:municipality="edit.municipality" v-model:barangay="edit.barangay" />
                    <button type="submit" class="ui-button-primary">Save details</button>
                </fieldset>
            </form>
            <div class="space-y-4">
                <section class="rounded-lg border border-sky-200 bg-sky-50 p-4" aria-labelledby="client-safe-title"><h3 id="client-safe-title" class="ui-section-title">Recorded contact guidance</h3><dl class="mt-3 space-y-3 text-sm"><div><dt class="font-semibold">Safe contact method</dt><dd class="mt-1 break-words">{{ record.safe_contact_method || 'Not recorded' }}</dd></div><div><dt class="font-semibold">Safe contact notes</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ record.safe_contact_notes || 'Not recorded' }}</dd></div><div><dt class="font-semibold">Primary caregiver</dt><dd class="mt-1 break-words">{{ record.primary_caregiver_name || 'Not recorded' }}</dd><dd v-if="record.primary_caregiver_relationship" class="mt-1 break-words">{{ record.primary_caregiver_relationship }}</dd><dd v-if="record.primary_caregiver_contact" class="mt-1 break-words">{{ record.primary_caregiver_contact }}</dd></div></dl></section>
                <section class="ui-panel overflow-hidden"><button type="button" class="flex min-h-11 w-full items-center justify-between gap-3 bg-brand-50 p-4 text-left font-semibold text-brand-900" :aria-expanded="contextOpen" aria-controls="recorded-intake-context" @click="contextOpen=!contextOpen">Recorded intake context <span aria-hidden="true">{{contextOpen?'-':'+'}}</span></button>
                    <dl v-if="contextOpen" id="recorded-intake-context" class="space-y-3 p-4 text-sm"><div v-for="[label,value] in context" :key="label || ''"><dt class="font-semibold text-slate-900">{{label}}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-slate-700">{{value || 'Not recorded'}}</dd></div></dl>
                </section>
            </div>
        </section>
        <section v-else-if="view==='support'" aria-label="Household and support records" class="grid items-start gap-5 xl:grid-cols-2">
            <form v-if="allowed('relationships.manage')" aria-labelledby="support-entry-title" autocomplete="off" class="ui-panel p-4" @submit.prevent="saveSupport"><h3 id="support-entry-title" class="ui-section-title">Add support relationship</h3><p class="ui-muted mt-1">Record an identified relationship. This does not link or merge client identities or confirm household membership.</p>
                <fieldset :disabled="busy" class="mt-4 space-y-4"><legend class="sr-only">Support relationship fields</legend>
                    <FormField id="support-name" label="Person's name" :error="support.errors.name" required><input id="support-name" v-model="support.name" maxlength="255" required class="ui-field"></FormField>
                    <FormField id="support-type" label="Relationship to client" :error="support.errors.relationship_type" required><OptionSelect id="support-type" v-model="support.relationship_type" :options="RELATIONSHIPS" required /></FormField>
                    <FormField id="support-phone" label="Support contact (optional)" :error="support.errors.phone"><input id="support-phone" v-model="support.phone" maxlength="80" class="ui-field"></FormField>
                    <label class="flex min-h-11 items-center gap-3 text-sm"><input v-model="support.is_guardian" type="checkbox" class="h-5 w-5 rounded border-slate-400">Record guardian flag</label>
                    <button type="submit" class="ui-button-primary">Add relationship</button>
                </fieldset>
            </form>
            <div class="ui-panel overflow-hidden"><header class="ui-panel-header bg-brand-50"><h3 class="ui-section-title">Recorded household and support relationships</h3><span class="text-xs text-slate-600">{{relationships.length}}</span></header>
                <div class="divide-y divide-slate-200"><article v-for="item in relationships" :key="item.id" class="space-y-2 p-4 text-sm"><div class="flex flex-wrap items-center justify-between gap-2"><h4 class="break-words font-semibold">{{item.name}}</h4><span v-if="item.is_guardian" class="rounded border border-brand-200 bg-brand-50 px-2 py-1 text-xs text-brand-900">Guardian flag recorded</span></div><p class="break-words">{{item.relationship_type}}</p><p v-if="item.phone" class="break-words">Contact: {{item.phone}}</p><dl class="grid gap-2 sm:grid-cols-2"><div v-for="[label,value] in [['Sex',item.sex],['Birthdate',item.birthdate?calendarDateLabel(item.birthdate):null],['Education',item.education],['Occupation',item.occupation]]" v-show="value" :key="label || ''"><dt class="text-xs text-slate-600">{{label}}</dt><dd class="break-words">{{value}}</dd></div></dl></article></div><p v-if="!relationships.length" class="ui-muted p-4">No household or support relationships recorded.</p>
            </div>
        </section>
        <section v-else aria-label="Consent records" class="grid items-start gap-5 xl:grid-cols-2">
            <form v-if="allowed('consents.record')" aria-labelledby="consent-entry-title" autocomplete="off" class="ui-panel p-4" @submit.prevent="saveConsent"><h3 id="consent-entry-title" class="ui-section-title">Record consent</h3><p class="ui-muted mt-1">Document the actual basis and purposes. A register entry does not authorize unrestricted disclosure.</p>
                <fieldset :disabled="busy" class="mt-4 space-y-4"><legend class="sr-only">Consent fields</legend>
                    <FormField id="consent-basis" label="Lawful basis" :error="consent.errors.lawful_basis" required><OptionSelect id="consent-basis" v-model="consent.lawful_basis" :options="CONSENT_BASES" required /></FormField>
                    <FormField id="consent-date" label="Granted date" :error="consent.errors.granted_at" required><input id="consent-date" v-model="consent.granted_at" type="date" required class="ui-field"></FormField>
                    <FormField id="consent-purposes" label="Documented purposes" :error="consent.errors.purposes_text" hint="Enter one purpose per line." required><textarea id="consent-purposes" v-model="consent.purposes_text" required rows="4" class="ui-field"/></FormField>
                    <label class="flex min-h-11 items-center gap-3 text-sm"><input v-model="consent.sharing_allowed" type="checkbox" class="h-5 w-5 rounded border-slate-400">External sharing permission recorded</label>
                    <button type="submit" class="ui-button-primary">Record consent</button>
                </fieldset>
            </form>
            <div class="ui-panel overflow-hidden"><header class="ui-panel-header bg-brand-50"><h3 class="ui-section-title">Consent history</h3><span class="text-xs text-slate-600">{{consents.length}}</span></header><div class="divide-y divide-slate-200"><article v-for="item in consents" :key="item.id" class="space-y-3 p-4 text-sm"><div class="flex flex-wrap items-start justify-between gap-3"><h4 class="break-words font-semibold">{{item.lawful_basis}}</h4><span class="rounded border px-2 py-1 text-xs" :class="item.withdrawn_at?'border-amber-300 bg-kalinga-warm text-amber-950':'border-brand-200 bg-brand-50 text-brand-900'">{{item.withdrawn_at?'Withdrawn':'Withdrawal not recorded'}}</span></div><ul class="list-inside list-disc space-y-1"><li v-for="(purpose,index) in list(item.purposes)" :key="index" class="break-words">{{purpose}}</li></ul><p>{{item.sharing_allowed?'External sharing permission recorded':'External sharing permission not recorded'}}</p><p class="text-xs leading-5 text-slate-600">Granted {{calendarDateLabel(item.granted_at)}}<span v-if="item.withdrawn_at"><br>Withdrawn {{philippineTimestamp(item.withdrawn_at)}}</span></p><button v-if="!item.withdrawn_at&&allowed('consents.record')" type="button" :disabled="busy" class="ui-button text-rose-800" @click="withdraw(item.id)">Record withdrawal</button></article></div><p v-if="!consents.length" class="ui-muted p-4">No consent entries recorded.</p></div>
        </section>
    </div>
</template>
