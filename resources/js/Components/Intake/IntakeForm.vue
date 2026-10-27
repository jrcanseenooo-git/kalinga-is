<script setup lang="ts">
/**
 * The Kalinga Intake, 4 steps. Used by the online intake and by field mode;
 * the page around it decides how the answers are saved and sent.
 */
import Stepper from '@/Components/UI/Stepper.vue';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import ConsentPhoto from '@/Components/Intake/ConsentPhoto.vue';
import AddressFields from '@/Components/Intake/AddressFields.vue';
import FamilyMembers from '@/Components/Intake/FamilyMembers.vue';
import { INTAKE_STEPS, RISK_LEVELS, intakeFieldId, intakeStepForField, isLowerThan, suggestRisk, validateIntakeStep, type AssignedLocation, type IntakeData, type IntakeOptions } from '@/Casework/intake';
import { CEFMU_CLASSIFICATIONS, FORMAL_MARRIAGE_FORMS, INFORMAL_UNION_FORM, MARRIAGE_CLASSIFICATIONS, RESIDENCES, UNION_CLASSIFICATIONS, UNION_STATUSES } from '@/data/cefmu';
import { CIVIL_STATUSES, DISABILITY_TYPES, EDUCATION_LEVELS, IP_MEMBERSHIP, REFERRAL_SOURCES, RELATIONSHIPS, RELIGIONS } from '@/utils/caseOptions';
import { intakeGuidance } from '@/utils/intakeGuidance';
import { ageFromBirthdate } from '@/utils/philippineTime';
import { maskedName } from '@/utils/clientName';
import { CircleAlert, Info } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    data: IntakeData;
    options: IntakeOptions;
    serverErrors?: Record<string, string>;
    assignedLocation?: AssignedLocation;
    busy?: boolean;
    submitLabel: string;
}>();
const step = defineModel<number>('step', { default: 1 });
const emit = defineEmits<{ submit: []; stepChanged: [step: number]; continue: [] }>();

const stepErrors = ref<Record<string, string>>({});
const errors = computed(() => ({ ...(props.serverErrors ?? {}), ...stepErrors.value }));
const root = ref<HTMLElement | null>(null);
const summary = ref<HTMLElement | null>(null);
const todayIso = new Date().toISOString().slice(0, 10);
/** The answers; the page may swap in another object (for example a recovered draft). */
const d = computed(() => props.data);

const age = computed(() => {
    const value = d.value.birthdate ? ageFromBirthdate(d.value.birthdate) : null;
    return value !== null && value >= 0 ? value : null;
});
const presentAddress = computed(() => [d.value.house_no_street, d.value.barangay, d.value.municipality, d.value.province].filter(Boolean).join(', '));
const marriageChosen = computed(() => d.value.cefmu_classifications.some(code => MARRIAGE_CLASSIFICATIONS.includes(code)));
const unionChosen = computed(() => d.value.cefmu_classifications.some(code => UNION_CLASSIFICATIONS.includes(code)));
const inUnion = computed(() => d.value.union_status === 'yes_now' || d.value.union_status === 'yes_before');
const suggestion = computed(() => suggestRisk(d.value.risk_indicators, props.options));
const lowered = computed(() => Boolean(d.value.initial_risk_level) && isLowerThan(d.value.initial_risk_level, suggestion.value.level));
const guidance = computed(() => intakeGuidance(d.value));
const levelLabel = (value: string) => RISK_LEVELS.find(level => level.value === value)?.label ?? value;

// Drop marriage or union types that no longer match the classification.
watch(() => d.value.cefmu_classifications.join(','), () => {
    const kept = d.value.union_forms.filter(form => form === INFORMAL_UNION_FORM.value ? unionChosen.value : marriageChosen.value);
    if (kept.length !== d.value.union_forms.length) d.value.union_forms = kept;
});
// The level follows the risk factors until the case manager chooses one; a higher choice is kept.
const levelTouched = ref(Boolean(d.value.initial_risk_level));
watch(() => suggestion.value.level, level => {
    if (!levelTouched.value) d.value.initial_risk_level = level;
});
// Opening the safety step proposes the suggested level (nothing changes before that).
watch(step, value => {
    if (value === INTAKE_STEPS.length && !d.value.initial_risk_level) d.value.initial_risk_level = suggestion.value.level;
}, { immediate: true });
const chooseLevel = (value: string) => { levelTouched.value = true; d.value.initial_risk_level = value as IntakeData['initial_risk_level']; };

async function focusSummary() {
    await nextTick();
    summary.value?.focus();
}
/** Opens the step that holds the field and puts the cursor in it. */
async function visitError(field: string) {
    const target = intakeStepForField(field);
    if (target !== step.value) { step.value = target; emit('stepChanged', target); }
    await nextTick();
    const control = document.getElementById(intakeFieldId(field));
    if (control) control.focus(); else summary.value?.focus();
}
async function goTo(target: number) {
    step.value = target;
    emit('stepChanged', target);
    await nextTick();
    root.value?.querySelector<HTMLElement>('fieldset > legend')?.focus();
}
async function next() {
    emit('continue');
    stepErrors.value = validateIntakeStep(step.value, d.value, props.options);
    if (Object.keys(stepErrors.value).length) { await focusSummary(); return; }
    if (step.value < INTAKE_STEPS.length) await goTo(step.value + 1);
    else emit('submit');
}
async function back() { stepErrors.value = {}; await goTo(step.value - 1); }
watch(() => props.serverErrors, value => { if (value && Object.keys(value).length) { stepErrors.value = {}; void focusSummary(); } });
defineExpose({ goTo, focusSummary });
</script>

<template>
    <form ref="root" class="intake-form" novalidate data-skip-generic-confirm="true" @submit.prevent="next">
        <section v-if="Object.keys(errors).length" ref="summary" role="alert" aria-label="Review intake errors" tabindex="-1" class="mb-4 rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 focus:outline focus:outline-2 focus:outline-rose-700">
            <h3 class="font-bold">Check these before continuing</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5"><li v-for="(message, field) in errors" :key="field"><button type="button" class="min-h-11 text-left underline" @click="visitError(String(field))">{{ message }}</button></li></ul>
        </section>
        <Stepper :steps="[...INTAKE_STEPS]" :current="step" :busy="busy" @select="goTo" />

        <!-- 1. Privacy notice -->
        <fieldset v-if="step === 1" :disabled="busy" :aria-busy="busy" class="space-y-4">
            <legend tabindex="-1" class="step-legend"><b>1</b> Privacy notice and consent</legend>
            <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm leading-6 text-brand-900">
                <p class="font-bold">Privacy notice</p>
                <p class="mt-1">The Department of Social Welfare and Development collects and processes personal and, when needed, sensitive personal information for CEFMU case intake, case management, referrals, monitoring and reporting, as allowed by law and the Kalinga program. Only authorized staff can see it, and it is kept only as long as records rules allow.</p>
                <p class="mt-2 text-xs text-brand-800">Explain this in clear, age-appropriate words. This acknowledgement is not the child's consent.</p>
            </div>
            <label class="flex min-h-11 items-start gap-2 rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold">
                <input id="intake-privacy_notice_acknowledged" v-model="d.privacy_notice_acknowledged" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-700" :aria-invalid="Boolean(errors.privacy_notice_acknowledged)">
                <span>I gave the privacy notice in clear language, or I will give it at the next practical opportunity because urgent safety action is needed.</span>
            </label>
            <label class="block text-sm font-medium" for="intake-privacy_notice_method">How was the privacy notice given? <span class="text-rose-700">*</span>
                <select id="intake-privacy_notice_method" v-model="d.privacy_notice_method" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.privacy_notice_method)">
                    <option value="">Select</option>
                    <option value="explained_verbally">Explained verbally</option>
                    <option value="written_or_digital_notice">Written or digital notice shown or given</option>
                    <option value="trusted_adult_or_guardian">Through a trusted adult or guardian</option>
                    <option value="deferred_emergency_next_practicable">Emergency: will be given at the next practical opportunity</option>
                </select>
            </label>
            <ConsentPhoto v-model:photo="d.consent_photo" v-model:type="d.consent_form_type" :error="errors.consent_photo" :type-error="errors.consent_form_type" />
        </fieldset>

        <!-- 2. Child and safe contact -->
        <fieldset v-if="step === 2" :disabled="busy" :aria-busy="busy" class="space-y-5">
            <legend tabindex="-1" class="step-legend"><b>2</b> Child and safe contact</legend>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-sm font-medium" for="intake-first_name">First name <span class="text-rose-700">*</span><input id="intake-first_name" v-model="d.first_name" maxlength="80" autocomplete="off" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.first_name)"></label>
                <label class="text-sm font-medium" for="intake-middle_name">Middle name<input id="intake-middle_name" v-model="d.middle_name" maxlength="80" autocomplete="off" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"></label>
                <label class="text-sm font-medium" for="intake-last_name">Last name <span class="text-rose-700">*</span><input id="intake-last_name" v-model="d.last_name" maxlength="80" autocomplete="off" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.last_name)"></label>
                <label class="text-sm font-medium" for="intake-suffix">Suffix
                    <select id="intake-suffix" v-model="d.suffix" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"><option value="">None</option><option>Jr.</option><option>Sr.</option><option>II</option><option>III</option><option>IV</option><option>V</option></select>
                </label>
                <label class="text-sm font-medium sm:col-span-2" for="intake-alias">Alias or nickname
                    <input id="intake-alias" v-model="d.alias" maxlength="80" autocomplete="off" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" placeholder="Optional" aria-describedby="intake-alias-hint">
                </label>
                <p id="intake-alias-hint" class="-mt-2 text-xs text-slate-600 sm:col-span-2 sm:-mt-1 lg:mt-0 lg:self-end lg:pb-3">Forms show the masked name ({{ maskedName(d) === 'Not recorded' ? 'for example J**S***D**UZ' : maskedName(d) }}) unless you choose the alias or the full name.</p>
                <label class="text-sm font-medium" for="intake-birthdate">Date of birth <span class="text-rose-700">*</span><input id="intake-birthdate" v-model="d.birthdate" type="date" :max="todayIso" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.birthdate)"><span v-if="age !== null" class="mt-1 block text-xs font-normal text-slate-600">{{ age }} years old</span></label>
                <label class="text-sm font-medium" for="intake-sex">Sex <span class="text-rose-700">*</span>
                    <select id="intake-sex" v-model="d.sex" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.sex)"><option value="">Select</option><option>Female</option><option>Male</option></select>
                </label>
                <label class="text-sm font-medium">Civil status<OptionSelect id="intake-civil_status" v-model="d.civil_status" :options="CIVIL_STATUSES" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                <label class="text-sm font-medium">Religion<OptionSelect id="intake-religion" v-model="d.religion" :options="RELIGIONS" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                <label class="text-sm font-medium">Indigenous Peoples group<OptionSelect id="intake-ip_category" v-model="d.ip_category" :options="IP_MEMBERSHIP" other-label="IP group (specify)" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                <label class="text-sm font-medium">Highest education reached<OptionSelect id="intake-education_attainment" v-model="d.education_attainment" :options="EDUCATION_LEVELS" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                <label class="text-sm font-medium sm:col-span-2" for="intake-disability_evidence">Disability or support need
                    <select id="intake-disability_evidence" v-model="d.disability_evidence" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                        <option value="">Not recorded</option><option v-for="item in DISABILITY_TYPES" :key="item.value" :value="item.value">{{ item.label }}</option>
                    </select>
                </label>
            </div>

            <section class="space-y-3 rounded-xl border border-slate-200 p-4" aria-labelledby="present-title">
                <h3 id="present-title" class="text-sm font-bold text-slate-900">Present address</h3>
                <p v-if="assignedLocation" class="text-xs text-slate-600">The case is recorded in your assigned LGU. Choose the barangay where the child lives now.</p>
                <AddressFields v-model:street="d.house_no_street" v-model:region="d.region" v-model:province="d.province" v-model:municipality="d.municipality" v-model:barangay="d.barangay"
                    id-prefix="intake-" :lock-area="Boolean(assignedLocation)" />
                <label class="flex min-h-11 items-center gap-2 rounded-lg bg-slate-50 px-3 text-sm font-semibold"><input id="intake-permanent_same_as_present" v-model="d.permanent_same_as_present" type="checkbox" class="rounded border-slate-300 text-brand-700">Permanent (primary) address is the same as the present address</label>
                <div v-if="!d.permanent_same_as_present" class="space-y-3 border-t border-slate-100 pt-3">
                    <h4 class="text-sm font-bold text-slate-800">Permanent (primary) address</h4>
                    <AddressFields v-model:street="d.permanent_house_no_street" v-model:region="d.permanent_region" v-model:province="d.permanent_province" v-model:municipality="d.permanent_municipality"
                        v-model:barangay="d.permanent_barangay" id-prefix="intake-permanent_" />
                </div>
            </section>

            <section class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-3" aria-labelledby="contact-title">
                <h3 id="contact-title" class="text-sm font-bold text-slate-900 sm:col-span-3">Safe contact</h3>
                <label class="text-sm font-medium" for="intake-phone">Mobile number<input id="intake-phone" v-model="d.phone" inputmode="tel" maxlength="13" placeholder="09XXXXXXXXX" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.phone)"></label>
                <label class="text-sm font-medium" for="intake-safe_contact_method">Safe way to contact
                    <select id="intake-safe_contact_method" v-model="d.safe_contact_method" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                        <option>Not recorded</option><option>Direct call</option><option>SMS</option><option>Trusted adult / caregiver</option><option>In-person through authorized worker</option><option>Do not contact without safety check</option>
                    </select>
                </label>
                <label class="text-sm font-medium" for="intake-safe_contact_notes">Notes<input id="intake-safe_contact_notes" v-model="d.safe_contact_notes" maxlength="1000" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" placeholder="Safe time, cautions"></label>
            </section>

            <section class="space-y-3 rounded-xl border border-slate-200 p-4" aria-labelledby="caregiver-title">
                <h3 id="caregiver-title" class="text-sm font-bold text-slate-900">Primary caregiver / custody</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm font-medium" for="intake-primary_caregiver_name">Name<input id="intake-primary_caregiver_name" v-model="d.primary_caregiver_name" maxlength="160" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"></label>
                    <label class="text-sm font-medium">Relationship to the child<OptionSelect id="intake-primary_caregiver_relationship" v-model="d.primary_caregiver_relationship" :options="RELATIONSHIPS" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                    <label class="text-sm font-medium" for="intake-primary_caregiver_contact">Contact number<input id="intake-primary_caregiver_contact" v-model="d.primary_caregiver_contact" maxlength="80" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"></label>
                </div>
                <label class="flex min-h-11 items-start gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                    <input id="intake-caregiver_same_address" v-model="d.caregiver_same_address" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-700">
                    <span><span class="font-semibold">Same address as the child</span><span v-if="d.caregiver_same_address" class="block text-xs text-slate-600">{{ presentAddress || "The child's present address" }}</span></span>
                </label>
                <AddressFields v-if="!d.caregiver_same_address" v-model:street="d.caregiver_house_no_street" v-model:region="d.caregiver_region" v-model:province="d.caregiver_province"
                    v-model:municipality="d.caregiver_municipality" v-model:barangay="d.caregiver_barangay" id-prefix="intake-caregiver_" />
            </section>

            <FamilyMembers v-model="d.family_members" :child-address="presentAddress" />
        </fieldset>

        <!-- 3. Case details: CEFMU incidence -->
        <fieldset v-if="step === 3" :disabled="busy" :aria-busy="busy" class="space-y-5">
            <legend tabindex="-1" class="step-legend"><b>3</b> Case details</legend>
            <section id="intake-cefmu_classifications" tabindex="-1" aria-labelledby="class-title">
                <h3 id="class-title" class="text-sm font-bold text-slate-900">CEFMU classification <span class="text-rose-700">*</span></h3>
                <p class="text-xs text-slate-600">Select all that apply.</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label v-for="item in CEFMU_CLASSIFICATIONS" :key="item.value" class="choice-card">
                        <input v-model="d.cefmu_classifications" type="checkbox" :value="item.value" class="mt-0.5 rounded border-slate-300 text-brand-700">
                        <span><b class="block text-sm">{{ item.label }}</b><small class="block text-xs leading-5 text-slate-600">{{ item.hint }}</small></span>
                    </label>
                </div>
            </section>

            <section v-if="marriageChosen || unionChosen" id="intake-union_forms" tabindex="-1" class="rounded-xl border border-slate-200 p-4" aria-labelledby="married-title">
                <h3 id="married-title" class="text-sm font-bold text-slate-900">Married</h3>
                <div class="mt-2 grid gap-4 lg:grid-cols-2">
                    <div v-if="marriageChosen">
                        <p class="text-sm font-semibold text-slate-800">Formal child marriage</p>
                        <div class="mt-1 space-y-1.5">
                            <label v-for="form in FORMAL_MARRIAGE_FORMS" :key="form.value" class="choice-row">
                                <input v-model="d.union_forms" type="checkbox" :value="form.value" class="mt-0.5 rounded border-slate-300 text-brand-700">
                                <span><span class="block text-sm font-medium">{{ form.label }}</span><small class="block text-xs text-slate-600">{{ form.hint }}</small></span>
                            </label>
                        </div>
                    </div>
                    <div v-if="unionChosen">
                        <p class="text-sm font-semibold text-slate-800">Informal union</p>
                        <label class="choice-row mt-1">
                            <input v-model="d.union_forms" type="checkbox" :value="INFORMAL_UNION_FORM.value" class="mt-0.5 rounded border-slate-300 text-brand-700">
                            <span><span class="block text-sm font-medium">{{ INFORMAL_UNION_FORM.label }}</span><small class="block text-xs text-slate-600">{{ INFORMAL_UNION_FORM.hint }}</small></span>
                        </label>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 rounded-xl border border-slate-200 p-4 lg:grid-cols-2" aria-labelledby="incidence-title">
                <h3 id="incidence-title" class="text-sm font-bold text-slate-900 lg:col-span-2">CEFMU incidence</h3>
                <div id="intake-union_status" tabindex="-1" role="radiogroup" aria-labelledby="status-label">
                    <p id="status-label" class="text-sm font-semibold text-slate-800">Married or cohabiting with a partner?</p>
                    <div class="mt-1 space-y-1.5">
                        <label v-for="item in UNION_STATUSES" :key="item.value" class="choice-row">
                            <input v-model="d.union_status" type="radio" :value="item.value" name="union_status" class="mt-0.5 border-slate-300 text-brand-700">
                            <span><span class="block text-sm font-medium">{{ item.label }}</span><small class="block text-xs text-slate-600">{{ item.hint }}</small></span>
                        </label>
                    </div>
                </div>
                <div class="space-y-3">
                    <template v-if="inUnion">
                        <label class="block text-sm font-medium" for="intake-partner_current_age">Partner's age (current)
                            <span class="mt-1 flex items-center gap-2"><input id="intake-partner_current_age" v-model.number="d.partner_current_age" type="number" min="10" max="99" inputmode="numeric" class="block min-h-11 w-28 rounded-xl border-slate-300" :aria-invalid="Boolean(errors.partner_current_age)"><span class="text-sm text-slate-600">years old</span></span>
                        </label>
                        <fieldset>
                            <legend class="text-sm font-medium">Length of marriage or cohabitation</legend>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <input id="intake-union_duration_years" v-model.number="d.union_duration_years" type="number" min="0" max="50" inputmode="numeric" aria-label="Years" class="block min-h-11 w-24 rounded-xl border-slate-300"><span class="text-sm text-slate-600">years</span>
                                <input v-model.number="d.union_duration_extra_months" type="number" min="0" max="11" inputmode="numeric" aria-label="Months" class="block min-h-11 w-24 rounded-xl border-slate-300"><span class="text-sm text-slate-600">months</span>
                            </div>
                        </fieldset>
                    </template>
                    <div role="radiogroup" aria-labelledby="residence-label">
                        <p id="residence-label" class="text-sm font-semibold text-slate-800">Current residence</p>
                        <div class="mt-1 space-y-1.5">
                            <label v-for="item in RESIDENCES" :key="item.value" class="choice-row">
                                <input v-model="d.current_residence" type="radio" :value="item.value" name="current_residence" class="mt-0.5 border-slate-300 text-brand-700"><span class="text-sm">{{ item.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-3" aria-labelledby="other-title">
                <h3 id="other-title" class="text-sm font-bold text-slate-900 sm:col-span-2 lg:col-span-3">Schooling, health and reporting</h3>
                <label class="text-sm font-medium" for="intake-education_status_at_intake">Schooling now
                    <select id="intake-education_status_at_intake" v-model="d.education_status_at_intake" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                        <option value="not_recorded">Not recorded</option><option value="in_school">In school</option><option value="interrupted">Interrupted schooling</option><option value="out_of_school">Out of school</option><option value="completed">Completed</option>
                    </select>
                </label>
                <label class="text-sm font-medium" for="intake-pregnancy_parenting_status">Pregnancy or parenting
                    <select id="intake-pregnancy_parenting_status" v-model="d.pregnancy_parenting_status" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                        <option value="not_recorded">Not recorded / not applicable</option><option value="pregnant">Pregnant</option><option value="parenting">Parenting</option><option value="postpartum">Postpartum</option><option value="not_pregnant_not_parenting">Not pregnant / not parenting</option>
                    </select>
                </label>
                <label class="text-sm font-medium" for="intake-mode_of_admission">Mode of reporting
                    <select id="intake-mode_of_admission" v-model="d.mode_of_admission" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                        <option value="">Select</option><option>Walk-in</option><option>Referral</option><option>Outreach / field identification</option><option>Agency endorsement</option>
                    </select>
                </label>
                <label class="text-sm font-medium">Referred by<OptionSelect id="intake-referred_by" v-model="d.referred_by" :options="REFERRAL_SOURCES" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                <label class="text-sm font-medium" for="intake-referral_date">Referral date<input id="intake-referral_date" v-model="d.referral_date" type="date" :max="todayIso" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"></label>
                <label class="text-sm font-medium" for="intake-priority">Priority
                    <select id="intake-priority" v-model="d.priority" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
                </label>
            </section>
            <section v-if="options.customFields?.length" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2" aria-labelledby="custom-title">
                <h3 id="custom-title" class="text-sm font-bold text-slate-900 sm:col-span-2">Additional information</h3>
                <div v-for="field in options.customFields" :key="field.key" :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                    <label :for="`intake-x-${field.key}`" class="text-sm font-medium">{{ field.label }}<span v-if="field.required" aria-hidden="true"> *</span></label>
                    <select v-if="field.type === 'select'" :id="`intake-x-${field.key}`" v-model="d.custom_fields[field.key]" :required="field.required" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"><option value="">Choose</option><option v-for="choice in field.options" :key="choice" :value="choice">{{ choice }}</option></select>
                    <select v-else-if="field.type === 'yes_no'" :id="`intake-x-${field.key}`" v-model="d.custom_fields[field.key]" :required="field.required" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"><option value="">Choose</option><option value="yes">Yes</option><option value="no">No</option></select>
                    <textarea v-else-if="field.type === 'textarea'" :id="`intake-x-${field.key}`" v-model="d.custom_fields[field.key]" :required="field.required" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300"></textarea>
                    <input v-else :id="`intake-x-${field.key}`" v-model="d.custom_fields[field.key]" :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'" :required="field.required" maxlength="255" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                    <p v-if="field.help_text" class="mt-1 text-xs text-slate-600">{{ field.help_text }}</p>
                    <p v-if="errors[`custom_fields.${field.key}`]" class="mt-1 text-xs text-rose-700" role="alert">{{ errors[`custom_fields.${field.key}`] }}</p>
                </div>
            </section>
        </fieldset>

        <!-- 4. Safety triage -->
        <fieldset v-if="step === 4" :disabled="busy" :aria-busy="busy" class="space-y-5">
            <legend tabindex="-1" class="step-legend"><b>4</b> Safety triage</legend>
            <p class="text-sm text-slate-600">Tick what you observed or were told. Do not record a detailed disclosure here; the full assessment goes into the case forms.</p>
            <section id="intake-risk_indicators" tabindex="-1" aria-labelledby="risk-title">
                <h3 id="risk-title" class="text-sm font-bold text-slate-900">CEFMU risks observed</h3>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label v-for="factor in options.riskFactors" :key="factor.code" class="choice-card" :class="factor.code === 'immediate_danger' ? 'danger' : ''">
                        <input v-model="d.risk_indicators" type="checkbox" :value="factor.code" class="mt-0.5 rounded border-slate-300 text-brand-700">
                        <span><b class="block text-sm">{{ factor.label }}</b><small v-if="factor.definition" class="block text-xs leading-5 text-slate-600">{{ factor.definition }}</small></span>
                    </label>
                </div>
            </section>

            <section class="rounded-xl border p-4" :class="['high', 'critical'].includes(suggestion.level) ? 'border-rose-200 bg-rose-50' : 'border-brand-200 bg-brand-50'" aria-live="polite">
                <p class="flex items-start gap-2 text-sm"><Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>Suggested level: <b>{{ levelLabel(suggestion.level) }}</b> ({{ suggestion.points }} {{ suggestion.points === 1 ? 'point' : 'points' }}). Moderate from {{ options.riskRule.moderate }}, high from {{ options.riskRule.high }}, critical from {{ options.riskRule.critical }} points.</span>
                </p>
            </section>

            <div id="intake-initial_risk_level" tabindex="-1" role="radiogroup" aria-labelledby="level-title">
                <h3 id="level-title" class="text-sm font-bold text-slate-900">Initial risk level <span class="text-rose-700">*</span></h3>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label v-for="level in RISK_LEVELS" :key="level.value" class="choice-card" :class="`level-${level.value}`">
                        <input type="radio" name="initial_risk_level" :value="level.value" :checked="d.initial_risk_level === level.value" class="mt-0.5 border-slate-300 text-brand-700" @change="chooseLevel(level.value)">
                        <span><b class="block text-sm">{{ level.label }}<span v-if="level.value === suggestion.level" class="ml-1 rounded bg-white/80 px-1.5 text-[10px] font-bold uppercase text-brand-700">Suggested</span></b><small class="block text-xs text-slate-600">{{ level.hint }}</small></span>
                    </label>
                </div>
            </div>
            <label v-if="lowered" class="block text-sm font-medium" for="intake-risk_level_reason">Why is the level lower than suggested? <span class="text-rose-700">*</span>
                <textarea id="intake-risk_level_reason" v-model="d.risk_level_reason" rows="3" maxlength="1000" class="mt-1 block w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors.risk_level_reason)" placeholder="For example: the child is now staying with a relative and the partner has no contact."></textarea>
            </label>
            <p v-if="['high', 'critical'].includes(d.initial_risk_level)" class="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"><CircleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Act through the authorized protection and emergency pathway now. After the case folder opens, record the actions and the safety plan in the case forms.</p>

            <aside v-if="guidance.length" class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 text-sm" aria-labelledby="guidance-title">
                <h3 id="guidance-title" class="font-bold text-violet-900">Kalinga suggests</h3>
                <ul class="mt-2 space-y-1.5"><li v-for="hint in guidance" :key="hint.title" class="rounded-md bg-white px-3 py-2" :class="hint.tone === 'urgent' ? 'border-l-4 border-rose-500' : hint.tone === 'action' ? 'border-l-4 border-amber-500' : 'border-l-4 border-violet-300'"><b>{{ hint.title }}.</b> {{ hint.text }}</li></ul>
                <p class="mt-2 text-xs text-slate-600">You decide what applies.</p>
            </aside>
        </fieldset>

        <footer class="intake-actions">
            <button v-if="step > 1" type="button" class="ui-button min-h-12 px-4" :disabled="busy" @click="back">Back</button>
            <span v-else />
            <slot name="status" />
            <button type="submit" class="ui-button-primary min-h-12 px-5" :disabled="busy">{{ step < INTAKE_STEPS.length ? 'Continue' : submitLabel }}</button>
        </footer>
    </form>
</template>

<style scoped>
.step-legend{display:flex;align-items:center;gap:.5rem;font-size:1rem;font-weight:800;color:var(--color-slate-900);margin-bottom:.25rem}
.step-legend:focus{outline:none}
.step-legend b{display:inline-grid;place-items:center;width:1.6rem;height:1.6rem;border-radius:999px;background:var(--color-brand-50);color:var(--color-brand-700);font-size:.8rem}
.choice-card{display:flex;align-items:flex-start;gap:.6rem;min-height:3.5rem;border:1px solid var(--color-slate-200);border-radius:.75rem;background:#fff;padding:.7rem .8rem;cursor:pointer}
.choice-card:has(input:checked){border-color:var(--color-brand-600);background:var(--color-brand-50)}
.choice-card.danger:has(input:checked),.choice-card.level-critical:has(input:checked){border-color:#e11d48;background:#fff1f2}
.choice-card.level-high:has(input:checked){border-color:#f59e0b;background:#fffbeb}
.choice-row{display:flex;align-items:flex-start;gap:.55rem;min-height:2.75rem;border-radius:.6rem;padding:.4rem .5rem;cursor:pointer}
.choice-row:hover{background:var(--color-slate-50)}
.intake-actions{position:sticky;bottom:calc(4.75rem + env(safe-area-inset-bottom));z-index:5;margin-top:1.25rem;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.75rem;border-top:1px solid var(--color-slate-200);background:rgb(255 255 255 / .97);padding:.75rem 0}
@media (min-width:1024px){.intake-actions{bottom:0}}
</style>
