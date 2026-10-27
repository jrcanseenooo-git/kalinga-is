<script setup lang="ts">
import { classificationLabels, incidenceRows, type IncidenceRecord } from '@/data/cefmu';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { CLIENT_CATEGORIES, RELATIONSHIPS } from '@/utils/caseOptions';
import ConsentPhoto from '@/Components/Intake/ConsentPhoto.vue';
import { FileCheck2, FileWarning } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import LocationSelects from '@/Components/LocationSelects.vue';
import { registerUnsavedCaseWork } from '@/Security/browserHistory';
import { calendarDateLabel, philippineTimestamp } from '@/utils/philippineTime';
import type { ClientContext, ConsentForm, SupportRelationship } from '@/types/clientContext';
import type { PageProps } from '@/types';

const props = defineProps<{ record: ClientContext; relationships: SupportRelationship[]; consentForm: ConsentForm; canArchive: boolean }>();
const emit = defineEmits<{ archive: [] }>();
const page = usePage<PageProps>();
const allowed = (permission: string) => page.props.auth.permissions.includes(permission);
const view = ref<'details' | 'support'>('details');
const contextOpen = ref(false);
const message = ref('');
const failures = ref<string[]>([]);
const errorSummary = ref<HTMLElement | null>(null);
const edit = useForm({
    first_name: props.record.first_name, last_name: props.record.last_name, alias: props.record.alias ?? '',
    birthdate: props.record.birthdate?.slice(0, 10) ?? '', sex: props.record.sex ?? '', phone: props.record.phone ?? '',
    region: props.record.region ?? '', province: props.record.province ?? '', municipality: props.record.municipality ?? '',
    barangay: props.record.barangay ?? '', category: props.record.category ?? '', priority: props.record.priority,
});
const support = useForm({ name: '', relationship_type: '', phone: '', is_guardian: false });
const consentPhoto = useForm({ consent_photo: '', consent_form_type: '' });
const addingForm = ref(false);
const FORM_TYPES: Record<string, string> = { consent: 'Consent form (parent or guardian)', assent: 'Assent form (child)', consent_and_assent: 'Consent and assent forms' };
const busy = computed(() => edit.processing || support.processing || consentPhoto.processing);
const dirty = computed(() => edit.isDirty || support.isDirty || Boolean(consentPhoto.consent_photo));
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
    ['CEFMU classifications', classificationLabels(list(props.record.cefmu_classifications)).join(', ')],
    ...incidenceRows(props.record as IncidenceRecord),
    ['Education at intake', props.record.education_status_at_intake], ['Pregnancy / parenting context', props.record.pregnancy_parenting_status],
    ['Mode of reporting', props.record.mode_of_admission], ['Referred by', props.record.referred_by],
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
function saveConsentForm() {
    if (!begin()) return;
    consentPhoto.post(route('cases.consent-form.store', props.record.id), { preserveScroll: true, onError: failed, onSuccess: () => { consentPhoto.reset(); addingForm.value = false; message.value = 'Signed form attached to the case.'; } });
}
</script>

<template>
    <div class="space-y-5">
        <header class="rounded-lg border border-brand-200 bg-kalinga-lavender p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="text-lg font-semibold text-brand-900">Client, household & support</h2><p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">Client details, the consent given at intake, recorded intake context and household support.</p></div>
                <button v-if="canArchive" type="button" class="ui-button text-rose-800" :disabled="busy || dirty" @click="emit('archive')">Archive case</button>
            </div>
            <p class="mt-3 text-xs leading-5 text-slate-700">Online entry only. Save before closing. A recorded relationship or guardian flag does not establish safe contact, custody or authority to share case information.</p>
        </header>
        <div v-if="failures.length" ref="errorSummary" role="alert" tabindex="-1" class="rounded-md border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900"><p class="font-semibold">Review the fields below. Your entries remain on this page.</p><ul class="mt-2 list-inside list-disc"><li v-for="(failure,index) in failures" :key="index">{{ failure }}</li></ul></div>
        <p v-if="message" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{ message }}</p>
        <p v-if="dirty" class="rounded-md border border-amber-300 bg-kalinga-warm p-3 text-sm text-amber-950">Unfinished entries are retained on this page. Save online before closing; cancel navigation to keep working.</p>
        <nav aria-label="Client context activities" class="grid grid-cols-2 gap-1 rounded-lg border border-brand-200 bg-brand-100 p-1">
            <button v-for="item in [{key:'details',label:'Client details'},{key:'support',label:'Household & support'}] as const" :key="item.key" type="button" class="min-h-11 rounded-md px-3 py-2 text-sm font-semibold" :class="view===item.key?'bg-brand-800 text-white':'text-brand-900'" :aria-current="view===item.key?'page':undefined" @click="view=item.key">{{ item.label }}</button>
        </nav>
        <section v-if="view==='details'" class="grid items-start gap-5 xl:grid-cols-2" aria-label="Client details and intake context">
            <form v-if="allowed('cases.update')" aria-labelledby="client-edit-title" autocomplete="off" class="ui-panel p-4" @submit.prevent="saveDetails">
                <h3 id="client-edit-title" class="ui-section-title">Update client details</h3><p class="ui-muted mt-1">Changes apply to the existing client record. Recorded intake context remains available alongside this form.</p>
                <fieldset :disabled="busy" class="mt-4 space-y-4"><legend class="sr-only">Client and case fields</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="client-first" label="Given name" :error="edit.errors.first_name" required><input id="client-first" v-model="edit.first_name" required maxlength="80" class="ui-field" :aria-invalid="Boolean(edit.errors.first_name)||undefined"></FormField>
                        <FormField id="client-last" label="Family name" :error="edit.errors.last_name" required><input id="client-last" v-model="edit.last_name" required maxlength="80" class="ui-field"></FormField>
                        <FormField id="client-alias" label="Alias or nickname" :error="edit.errors.alias" hint="Shown on forms instead of the full name when chosen."><input id="client-alias" v-model="edit.alias" maxlength="80" class="ui-field"></FormField>
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
                <section class="rounded-lg border p-4" :class="consentForm.document_id ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-kalinga-warm'" aria-labelledby="client-consent-title">
                    <div class="flex items-start gap-3">
                        <component :is="consentForm.document_id ? FileCheck2 : FileWarning" class="mt-0.5 h-6 w-6 shrink-0" :class="consentForm.document_id ? 'text-emerald-700' : 'text-amber-800'" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <h3 id="client-consent-title" class="ui-section-title">Consent given at intake</h3>
                            <dl class="mt-2 space-y-2 text-sm">
                                <div><dt class="font-semibold">Privacy notice</dt><dd class="mt-0.5">{{ record.privacy_notice_method ? `Given ${human(record.privacy_notice_method)}` : 'Not recorded' }}<span v-if="record.privacy_notice_provided_at">, {{ philippineTimestamp(record.privacy_notice_provided_at) }}</span></dd></div>
                                <div><dt class="font-semibold">Signed consent / assent form</dt>
                                    <dd v-if="consentForm.document_id" class="mt-0.5">{{ FORM_TYPES[consentForm.type ?? 'consent'] }}<span v-if="consentForm.added_at">, added {{ philippineTimestamp(consentForm.added_at) }}</span>.
                                        <a :href="route('cases.documents.download', [record.id, consentForm.document_id])" class="ui-link" target="_blank" rel="noopener">View the signed form</a></dd>
                                    <dd v-else class="mt-0.5">No photo of the signed form yet. Add it so consent can be used as the basis for referrals.</dd></div>
                            </dl>
                            <button v-if="allowed('cases.update') && !addingForm" type="button" class="ui-button mt-3 min-h-11" @click="addingForm = true">{{ consentForm.document_id ? 'Replace the photo' : 'Add the signed form' }}</button>
                            <form v-if="addingForm" class="mt-3 space-y-3" @submit.prevent="saveConsentForm">
                                <ConsentPhoto v-model:photo="consentPhoto.consent_photo" v-model:type="consentPhoto.consent_form_type" :error="consentPhoto.errors.consent_photo" :type-error="consentPhoto.errors.consent_form_type" :optional="false" id-prefix="case" />
                                <div class="flex flex-wrap gap-2"><button type="submit" class="ui-button-primary min-h-11" :disabled="busy || !consentPhoto.consent_photo">Save the photo</button><button type="button" class="ui-button min-h-11" :disabled="busy" @click="consentPhoto.reset(); addingForm = false">Cancel</button></div>
                            </form>
                        </div>
                    </div>
                </section>
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
    </div>
</template>
