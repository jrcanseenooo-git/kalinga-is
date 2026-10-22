<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { intakeGuidance } from '@/utils/intakeGuidance';
import { CIVIL_STATUSES, EDUCATION_LEVELS, IP_MEMBERSHIP, OCCUPATIONS, REFERRAL_SOURCES, RELATIONSHIPS, RELIGIONS } from '@/utils/caseOptions';
import { computed, nextTick, ref } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Stepper from '@/Components/UI/Stepper.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Modal from '@/Components/Modal.vue'
import { intakeStepForError, intakeFieldId } from '@/Casework/intakeErrors'
import LocationSelects from '@/Components/LocationSelects.vue'
import { ageFromBirthdate } from '@/utils/philippineTime'

const props = defineProps<{ assignedLocation?: { municipality: string; province: string; region: string } | null }>()
const page = usePage<PageProps>()
const canUseOffline = computed(() => page.props.auth.permissions.includes('offline.use'))

const step = ref(1)
const started = ref(false)
const confirming = ref(false)
const errorSummary = ref<HTMLElement | null>(null)
const focusErrorsAfterClose = ref(false)
const intakeElement = ref<HTMLElement | null>(null)
async function focusStep() {
    await nextTick()
    intakeElement.value?.querySelector<HTMLElement>('fieldset > legend')?.focus()
}
async function navigateStep(target: number) {
    step.value = target
    await focusStep()
}
async function startIntake() { started.value = true; await focusStep() }
async function focusSummary() { await nextTick(); errorSummary.value?.focus() }
async function visitError(field: string) {
    step.value = intakeStepForError(field)
    await nextTick()
    const control = document.getElementById(intakeFieldId(field))
    if (control) control.focus(); else errorSummary.value?.focus()
}
function confirmationClosed() {
    if (focusErrorsAfterClose.value) { focusErrorsAfterClose.value = false; void focusSummary() }
}

type FamilyMember = { name: string; relationship_type: string; sex: string; birthdate: string; education: string; occupation: string }
const addFamilyMember = () => form.family_members.push({ name: '', relationship_type: '', sex: '', birthdate: '', education: '', occupation: '' })
const form = useForm({
    first_name: '', middle_name: '', last_name: '', suffix: '', birthdate: '', sex: '', phone: '',
    disability_evidence: '', civil_status: '', religion: '', ip_category: '', education_attainment: '',
    safe_contact_method: 'Not recorded', safe_contact_notes: '',
    primary_caregiver_name: '', primary_caregiver_relationship: '', primary_caregiver_contact: '',
    region: props.assignedLocation?.region ?? '', province: props.assignedLocation?.province ?? '', municipality: props.assignedLocation?.municipality ?? '', barangay: '',
    category: 'CEFMU', cefmu_classifications: [] as string[], union_type: 'not_applicable', partner_age_band: 'not_applicable',
    education_status_at_intake: 'not_recorded', pregnancy_parenting_status: 'not_recorded', cefmu_context: '',
    mode_of_admission: '', referred_by: '', referral_date: '', priority: 'normal',
    initial_risk_level: '', risk_indicators: [] as string[], immediate_safety_concern: false, immediate_actions: '',
    problem_presented: '', initial_assessment: '', safety_plan: '', next_review_at: '',
    privacy_notice_acknowledged: false, privacy_notice_method: '',
    case_type: '', circumstance: '', plan_recommendations: '', intake_remarks: '', family_members: [] as FamilyMember[], narrative: '',
})

const guidance = computed(() => intakeGuidance(form));
const ages = computed(() => {
    if (!form.birthdate) return ''
    const age = ageFromBirthdate(form.birthdate)
    return age !== null && age >= 0 ? `${age} years old` : ''
})
const todayIso = new Date().toISOString().slice(0, 10)

const riskOptions = [
    'Planned or impending union', 'Pressure to enter a union after pregnancy',
    'Force, coercion, or controlling behaviour', 'Age or power imbalance',
    'Family or community pressure', 'Economic hardship or dependence',
    'School interruption or dropout', 'Health, SRH, or psychosocial support need',
]

function validateStep() {
    form.clearErrors()
    if (step.value === 1) {
        if (!form.privacy_notice_acknowledged) form.setError('privacy_notice_acknowledged', 'Confirm that the privacy notice was provided or made available at the next practical opportunity.')
        if (!form.privacy_notice_method) form.setError('privacy_notice_method', 'Record how the privacy notice was provided.')
    }
    if (step.value === 2) {
        if (!form.initial_risk_level) form.setError('initial_risk_level', 'Select the current safety level.')
        if (['high', 'critical'].includes(form.initial_risk_level) && !form.immediate_actions.trim()) {
            form.setError('immediate_actions', 'Record the immediate action taken for high or critical risk.')
        }
    }
    if (step.value === 3) {
        if (!form.first_name.trim()) form.setError('first_name', 'First name is required.')
        if (!form.last_name.trim()) form.setError('last_name', 'Last name is required.')
        if (!form.birthdate) form.setError('birthdate', 'Date of birth is required.')
        if (!form.sex) form.setError('sex', 'Select the child’s sex.')
        if (form.phone && !/^(?:\+63|0)9\d{9}$/.test(form.phone)) form.setError('phone', 'Use 09XXXXXXXXX or +639XXXXXXXXX.')
    }
    if (step.value === 4 && !form.cefmu_classifications.length) form.setError('cefmu_classifications', 'Select at least one CEFMU classification.')
    if (step.value === 5) {
        if (!form.problem_presented.trim()) form.setError('problem_presented', 'Record the presenting concern.')
    }
    return !Object.keys(form.errors).length
}

async function next() {
    if (!validateStep()) { await focusSummary(); return }
    if (step.value < 5) await navigateStep(step.value + 1)
}

function submit() {
    if (!validateStep()) { void focusSummary(); return }
    confirming.value = true
}

function save() {
    if (form.processing) return
    form.post(route('cases.store'), {
        preserveScroll: true,
        onError: errors => {
            step.value = intakeStepForError(Object.keys(errors)[0] ?? '')
            focusErrorsAfterClose.value = true
            confirming.value = false
        },
    })
}

function autoGrow(event: Event) {
    const textarea = event.target as HTMLTextAreaElement
    textarea.style.height = 'auto'
    textarea.style.height = `${textarea.scrollHeight}px`
}

function normalizePhone(event: Event) {
    const input = event.target as HTMLInputElement
    let value = input.value.replace(/[^\d+]/g, '')
    if (value.startsWith('+')) value = `+${value.slice(1).replace(/\+/g, '').slice(0, 12)}`
    else value = value.replace(/\+/g, '').slice(0, 11)
    input.value = value
    form.phone = value
}
</script>

<template>

    <Head title="New case intake" />
    <AuthenticatedLayout>
        <div ref="intakeElement" class="intake-page">
            <PageHeader title="CEFMU Safety &amp; Case Intake" eyebrow="Intake / registration" description="Open a protected CEFMU case using the existing privacy, safety and case-opening workflow." />

            <section v-if="!started" class="mt-5 rounded-md border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-700" aria-label="Choose an intake workspace">
                <p>Online intake is saved when the case folder is created. Choose your workspace before entering information.</p>
                <p v-if="canUseOffline" class="mt-2">For encrypted drafts and disconnected field work, use the authorized field workspace.</p>
                <a v-if="canUseOffline" :href="route('offline.workspace')" class="ui-link mt-2">Open encrypted field workspace</a>
            </section>

            <section v-if="!started" class="start-card">
                <div>
                    <h2>Read the privacy notice first, then begin with safety.</h2>
                    <p>This intake records sensitive child and caregiver information for CEFMU case management. It is
                        not a detailed interview or investigation.</p>
                </div>
                <button class="primary shrink-0 whitespace-nowrap" @click="startIntake">Start CEFMU intake</button>
            </section>

            <form v-else class="intake-card" @submit.prevent="submit">
                <header class="intake-header">
                    <div>
                        <h2>Protected case opening</h2>
                        <p>Required fields are marked <b>*</b>. Save detailed assessment and case planning in the case
                            folder.</p>
                    </div>
                    <span>KALINGA INTAKE SHEET</span>
                </header>

                <section v-if="Object.keys(form.errors).length" ref="errorSummary" role="alert" aria-label="Review intake errors" tabindex="-1" class="my-4 rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 focus:outline focus:outline-2 focus:outline-rose-700">
                    <h3 class="font-bold">Review intake errors</h3>
                    <ul class="mt-2 list-disc pl-5">
                        <li v-for="(message, field) in form.errors" :key="field"><button type="button" class="min-h-11 text-left underline" @click="visitError(field)">{{ message }}</button></li>
                    </ul>
                </section>
                <Stepper :steps="['Privacy notice','Safety triage','Child & safe contact','Case details','Immediate response']" :current="step" :busy="form.processing" @select="navigateStep" />

                <fieldset v-if="step === 1">
                    <legend tabindex="-1"><b>1</b> Privacy notice before collection</legend>
                    <p class="hint">Explain in clear, age-appropriate language what information will be recorded, why it
                        is needed, who may receive it, how long it is retained, and the child’s data-privacy rights. Do
                        not treat this acknowledgement as consent.</p>
                    <div class="privacy-note"><strong>CEFMU case-management notice</strong><span>This system records
                            only information necessary to protect and support children affected by, or at risk of,
                            child, early, and forced marriage and unions. Access is restricted to authorized personnel.
                            Information is shared only when necessary and authorized for protection, referral, or
                            legal/public-authority duties.</span></div>
                    <label class="privacy-check"><input id="intake-privacy_notice_acknowledged" v-model="form.privacy_notice_acknowledged" :aria-invalid="Boolean(form.errors.privacy_notice_acknowledged)" type="checkbox"> I
                        provided the privacy notice in clear language, or will provide it at the next practical
                        opportunity because immediate safety action is required.</label><small
                        v-if="form.errors.privacy_notice_acknowledged" class="error">{{
                            form.errors.privacy_notice_acknowledged }}</small>
                    <label>How was the privacy notice provided? *<select id="intake-privacy_notice_method" v-model="form.privacy_notice_method" :aria-invalid="Boolean(form.errors.privacy_notice_method)">
                            <option value="">Select method</option>
                            <option value="explained_verbally">Explained verbally</option>
                            <option value="written_or_digital_notice">Written or digital notice shown / given</option>
                            <option value="trusted_adult_or_guardian">Made available through trusted adult or guardian
                            </option>
                            <option value="deferred_emergency_next_practicable">Emergency: deferred until the next
                                practical opportunity</option>
                        </select><small v-if="form.errors.privacy_notice_method" class="error">{{
                            form.errors.privacy_notice_method }}</small></label>
                </fieldset>

                <fieldset v-if="step === 2">
                    <legend tabindex="-1"><b>2</b> Immediate safety triage</legend>
                    <p class="hint">Record current safety and the action taken now. Do not record a detailed disclosure
                        here.</p>
                    <div class="intake-grid two">
                        <label>Initial risk level *
                            <select id="intake-initial_risk_level" v-model="form.initial_risk_level" :aria-invalid="Boolean(form.errors.initial_risk_level)">
                                <option value="">Select level</option>
                                <option value="low">Low</option>
                                <option value="moderate">Moderate</option>
                                <option value="high">High</option>
                                <option value="critical">Critical / immediate danger</option>
                            </select>
                            <small v-if="form.errors.initial_risk_level" class="error">{{ form.errors.initial_risk_level
                                }}</small>
                        </label>
                        <div><label class="group-label">CEFMU risks or support factors observed</label>
                            <div id="intake-risk_indicators" tabindex="-1" class="checks"><label v-for="risk in riskOptions" :key="risk"><input
                                        v-model="form.risk_indicators" :aria-invalid="Boolean(form.errors.risk_indicators)" type="checkbox" :value="risk"> {{ risk }}</label>
                            </div>
                        </div>
                    </div>
                    <label class="alert-check"><input id="intake-immediate_safety_concern" v-model="form.immediate_safety_concern" :aria-invalid="Boolean(form.errors.immediate_safety_concern)" type="checkbox"> Immediate
                        safety concern identified</label>
                    <p v-if="['high', 'critical'].includes(form.initial_risk_level)" class="urgent">High or critical
                        risk: act through the authorized protection and emergency pathway. Record only factual actions
                        taken.</p>
                    <label>Immediate action taken <textarea id="intake-immediate_actions" v-model="form.immediate_actions" :aria-invalid="Boolean(form.errors.immediate_actions)"
                            placeholder="Example: contacted the authorized focal person; arranged safe transport; referred to the appropriate health or protection service."
                            @input="autoGrow"></textarea><small v-if="form.errors.immediate_actions" class="error">{{
                                form.errors.immediate_actions }}</small></label>
                </fieldset>

                <fieldset v-if="step === 3">
                    <legend tabindex="-1"><b>3</b> Child and safe contact</legend>
                    <p class="hint">Use the child’s preferred and safe contact route. Never use a channel that may
                        expose the child.</p>
                    <div class="intake-grid four"><label>First name *<input id="intake-first_name" v-model="form.first_name" :aria-invalid="Boolean(form.errors.first_name)"
                                placeholder="e.g., Maria"></label><label>Middle name<input id="intake-middle_name" v-model="form.middle_name" :aria-invalid="Boolean(form.errors.middle_name)"
                                placeholder="e.g., Santos"></label><label>Last name *<input id="intake-last_name" v-model="form.last_name" :aria-invalid="Boolean(form.errors.last_name)"
                                placeholder="e.g., Dela Cruz"></label><label>Suffix<select id="intake-suffix" v-model="form.suffix" :aria-invalid="Boolean(form.errors.suffix)">
                                <option value="">No suffix</option>
                                <option>Jr.</option>
                                <option>Sr.</option>
                                <option>II</option>
                                <option>III</option>
                                <option>IV</option>
                                <option>V</option>
                            </select></label></div>
                    <div class="intake-grid four"><label>Date of birth *<input id="intake-birthdate" v-model="form.birthdate" :aria-invalid="Boolean(form.errors.birthdate)" type="date"
                                :max="todayIso"></label><label>Age (auto-calculated)<input :value="ages" disabled
                                placeholder="Updates from date of birth"></label><label>Sex *<select id="intake-sex" v-model="form.sex" :aria-invalid="Boolean(form.errors.sex)">
                                <option value="">Select sex</option>
                                <option>Female</option>
                                <option>Male</option>
                            </select></label><label>Disability type / support need<select
                                id="intake-disability_evidence" v-model="form.disability_evidence" :aria-invalid="Boolean(form.errors.disability_evidence)">
                                <option value="">Not recorded</option>
                                <option value="no_support_need_declared">No disability support need declared</option>
                                <option value="deaf_hard_of_hearing">Deaf / hard of hearing</option>
                                <option value="intellectual_disability">Intellectual disability</option>
                                <option value="learning_disability">Learning disability</option>
                                <option value="mental_disability">Mental disability</option>
                                <option value="physical_disability">Physical disability</option>
                                <option value="psychosocial_disability">Psychosocial disability</option>
                                <option value="speech_language_impairment">Speech and language impairment</option>
                                <option value="visual_disability">Visual disability</option>
                                <option value="cancer">Cancer</option>
                                <option value="rare_disease">Rare disease</option>
                            </select></label></div>
                    <div class="intake-grid three"><label>Philippine mobile number<input id="intake-phone" v-model="form.phone" :aria-invalid="Boolean(form.errors.phone)" inputmode="tel"
                                maxlength="13" placeholder="09XXXXXXXXX" @input="normalizePhone"><small
                                v-if="form.errors.phone" class="error">{{ form.errors.phone }}</small></label><label>Safe contact method<select id="intake-safe_contact_method" v-model="form.safe_contact_method" :aria-invalid="Boolean(form.errors.safe_contact_method)">
                                <option>Not recorded</option>
                                <option>Direct call</option>
                                <option>SMS</option>
                                <option>Trusted adult / caregiver</option>
                                <option>In-person through authorized worker</option>
                                <option>Do not contact without safety check</option>
                            </select></label><label>Safe-contact notes<input id="intake-safe_contact_notes" v-model="form.safe_contact_notes" :aria-invalid="Boolean(form.errors.safe_contact_notes)"
                            placeholder="Safe time, restrictions, or other caution"></label></div>
                    <div class="intake-grid four"><label>Civil status<OptionSelect id="intake-civil_status" v-model="form.civil_status" :options="CIVIL_STATUSES" /></label><label>Religion<OptionSelect id="intake-religion" v-model="form.religion" :options="RELIGIONS" /></label><label>Indigenous Peoples group<OptionSelect id="intake-ip_category" v-model="form.ip_category" :options="IP_MEMBERSHIP" other-label="IP group (specify group)" /></label><label>Highest education reached<OptionSelect id="intake-education_attainment" v-model="form.education_attainment" :options="EDUCATION_LEVELS" /></label></div>
                    <div class="notice">Potential identity matches are screened after intake in the authorized duplicate and identity review workspace. A similarity signal never blocks or merges this intake automatically.</div>
                    <hr>
                    <h3>Caregiver and location</h3>
                    <div class="intake-grid three"><label>Primary caregiver name<input
                                id="intake-primary_caregiver_name" v-model="form.primary_caregiver_name" :aria-invalid="Boolean(form.errors.primary_caregiver_name)"></label><label>Relationship to child<OptionSelect id="intake-primary_caregiver_relationship" v-model="form.primary_caregiver_relationship" :options="RELATIONSHIPS" /></label><label>Caregiver contact<input
                                id="intake-primary_caregiver_contact" v-model="form.primary_caregiver_contact" :aria-invalid="Boolean(form.errors.primary_caregiver_contact)"></label></div>
                    <section class="family-block" aria-labelledby="family-title">
                        <div class="family-head"><h3 id="family-title">Family composition</h3><button type="button" class="family-add" @click="addFamilyMember">Add family member</button></div>
                        <p class="hint">Household members and relatives involved in the child's care. Used in the case summary and the social case study report.</p>
                        <div v-for="(member, index) in form.family_members" :key="index" class="family-row">
                            <div class="intake-grid three"><label>Name<input v-model="member.name" maxlength="160" :aria-label="`Family member ${index + 1} name`"></label><label>Relationship to child<OptionSelect v-model="member.relationship_type" :options="RELATIONSHIPS" /></label><label>Sex<select v-model="member.sex"><option value="">Not recorded</option><option>Female</option><option>Male</option></select></label></div>
                            <div class="intake-grid three"><label>Date of birth<input v-model="member.birthdate" type="date" :max="todayIso"></label><label>Education<OptionSelect v-model="member.education" :options="EDUCATION_LEVELS" /></label><label>Occupation<OptionSelect v-model="member.occupation" :options="OCCUPATIONS" /></label></div>
                            <button type="button" class="family-remove" @click="form.family_members.splice(index, 1)">Remove</button>
                        </div>
                        <p v-if="!form.family_members.length" class="hint">No family members added yet.</p>
                    </section>
                    <div v-if="assignedLocation" class="assigned-location">
                        <p class="text-sm font-semibold">Jurisdiction: {{ assignedLocation.municipality }}, {{ assignedLocation.province }}</p>
                        <p class="text-xs" style="color: var(--color-slate-500); margin-top: 2px">Cases are recorded in your assigned LGU. Region, province, and municipality are set from your assignment.</p>
                    </div>
                    <LocationSelects class="location-selects" v-model:region="form.region" v-model:province="form.province"
                        v-model:municipality="form.municipality" v-model:barangay="form.barangay" />
                    <small
                        v-if="form.errors.first_name || form.errors.last_name || form.errors.birthdate || form.errors.sex"
                        class="error">{{ form.errors.first_name || form.errors.last_name || form.errors.birthdate ||
                        form.errors.sex }}</small>
                </fieldset>

                <fieldset v-if="step === 4">
                    <legend tabindex="-1"><b>4</b> Case details</legend>
                    <aside v-if="guidance.length" class="guidance" aria-labelledby="guidance-title-4"><h3 id="guidance-title-4">Kalinga suggests</h3><ul><li v-for="hint in guidance" :key="hint.title" :class="`hint-${hint.tone}`"><b>{{ hint.title }}.</b> {{ hint.text }}</li></ul><p>Suggestions follow the CEFMU case management pathway; you decide what applies.</p></aside>

                    <p class="hint">Classify the case for CEFMU case-management reporting. You may select one or both
                        classifications where applicable.</p>
                    <div id="intake-cefmu_classifications" tabindex="-1" class="classification"><span>CEFMU classification *</span>
                        <div><label class="classification-card"><input v-model="form.cefmu_classifications" :aria-invalid="Boolean(form.errors.cefmu_classifications)"
                                    type="checkbox" value="early_union"><span><b>Early union</b><small>Union or
                                        cohabitation involving a child.</small></span></label><label
                                class="classification-card"><input v-model="form.cefmu_classifications" :aria-invalid="Boolean(form.errors.cefmu_classifications)" type="checkbox"
                                    value="forced_marriage"><span><b>Forced marriage</b><small>Marriage or union without
                                        free and full consent.</small></span></label></div><small
                            v-if="form.errors.cefmu_classifications" class="error">{{ form.errors.cefmu_classifications
                            }}</small>
                    </div>
                    <div class="intake-grid three"><label>Union form<select id="intake-union_type" v-model="form.union_type" :aria-invalid="Boolean(form.errors.union_type)">
                                <option value="not_applicable">No union / not applicable</option>
                                <option value="planned_or_impending">Planned or impending union</option>
                                <option value="informal_cohabitation">Informal union / cohabitation</option>
                                <option value="civil_marriage">Civil marriage</option>
                                <option value="religious_marriage">Religious marriage</option>
                                <option value="customary_traditional_marriage">Customary / traditional marriage</option>
                            </select></label><label>Partner age / power context<select id="intake-partner_age_band" v-model="form.partner_age_band" :aria-invalid="Boolean(form.errors.partner_age_band)">
                                <option value="not_applicable">No partner / not applicable</option>
                                <option value="also_under_18">Also under 18</option>
                                <option value="18_or_older">18 or older</option>
                                <option value="age_or_power_imbalance_reported">Age or power imbalance reported</option>
                                <option value="unknown">Unknown</option>
                            </select></label><label>Education situation<select
                                id="intake-education_status_at_intake" v-model="form.education_status_at_intake" :aria-invalid="Boolean(form.errors.education_status_at_intake)">
                                <option value="not_recorded">Not recorded</option>
                                <option value="in_school">In school</option>
                                <option value="interrupted">Interrupted schooling</option>
                                <option value="out_of_school">Out of school</option>
                                <option value="completed">Completed</option>
                            </select></label></div>
                    <div class="intake-grid three"><label>Pregnancy / parenting context<select
                                id="intake-pregnancy_parenting_status" v-model="form.pregnancy_parenting_status" :aria-invalid="Boolean(form.errors.pregnancy_parenting_status)">
                                <option value="not_recorded">Not recorded / not applicable</option>
                                <option value="pregnant">Pregnant</option>
                                <option value="parenting">Parenting</option>
                                <option value="postpartum">Postpartum</option>
                                <option value="not_pregnant_not_parenting">Not pregnant / not parenting</option>
                            </select></label><label>Mode of admission<select id="intake-mode_of_admission" v-model="form.mode_of_admission" :aria-invalid="Boolean(form.errors.mode_of_admission)">
                                <option value="">Select</option>
                                <option>Walk-in</option>
                                <option>Referral</option>
                                <option>Outreach / field identification</option>
                                <option>Agency endorsement</option>
                            </select></label><label>Priority<select id="intake-priority" v-model="form.priority" :aria-invalid="Boolean(form.errors.priority)">
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select></label></div>
                    <div class="intake-grid two"><label>Referred by<OptionSelect id="intake-referred_by" v-model="form.referred_by" :options="REFERRAL_SOURCES" /></label><label>Referral date<input
                                id="intake-referral_date" v-model="form.referral_date" :aria-invalid="Boolean(form.errors.referral_date)" type="date"></label></div>
                </fieldset>

                <fieldset v-if="step === 5">
                    <legend tabindex="-1"><b>5</b> Brief initial response and next step</legend>
                    <aside v-if="guidance.length" class="guidance" aria-labelledby="guidance-title"><h3 id="guidance-title">Kalinga suggests</h3><ul><li v-for="hint in guidance" :key="hint.title" :class="`hint-${hint.tone}`"><b>{{ hint.title }}.</b> {{ hint.text }}</li></ul><p>Suggestions follow the CEFMU case management pathway; you decide what applies.</p></aside>

                    <p class="hint">Record concise facts, the child/family’s expressed need, and what happens next.
                        Complete the full assessment and case plan in the protected case folder.</p>
                    <label>What is the presenting concern? *<textarea id="intake-problem_presented" v-model="form.problem_presented" :aria-invalid="Boolean(form.errors.problem_presented)"
                            placeholder="Brief factual summary: what was observed or reported, when, and where."
                            @input="autoGrow"></textarea><small v-if="form.errors.problem_presented" class="error">{{
                                form.errors.problem_presented }}</small></label>
                    <div class="intake-grid two"><label>Initial professional impression<textarea
                                id="intake-initial_assessment" v-model="form.initial_assessment" :aria-invalid="Boolean(form.errors.initial_assessment)"
                                placeholder="Immediate risks, strengths, and needs identified at intake."
                                @input="autoGrow"></textarea></label><label>Safety / follow-up plan<textarea
                                id="intake-safety_plan" v-model="form.safety_plan" :aria-invalid="Boolean(form.errors.safety_plan)"
                                placeholder="Who will do what next, how the child will be kept safe, and when review will happen."
                                @input="autoGrow"></textarea></label></div>
                </fieldset>

                <footer class="actions"><button v-if="step > 1" type="button" class="secondary"
                        @click="navigateStep(step - 1)">Back</button><span></span><button v-if="step < 5" type="button" class="primary"
                        @click="next">Continue</button><button v-else type="submit" class="primary">Create protected
                        CEFMU case folder</button></footer>
            </form>

            <Modal :show="confirming" aria-labelledby="intake-confirm-title" :closeable="!form.processing" max-width="lg" @close="confirming = false" @closed="confirmationClosed">
                <section class="confirm-modal">
                    <h2 id="intake-confirm-title">Create protected CEFMU case folder?</h2>
                    <p>Confirm that the record contains only information needed for safe case opening and authorized
                        follow-up.</p>
                    <div><button class="secondary" :disabled="form.processing" @click="confirming = false">Review intake</button><button
                            class="primary" :disabled="form.processing" @click="save">{{ form.processing ? 'Creating…' :
                            'Create case folder' }}</button></div>
                </section>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.intake-page {
    max-width: 1180px;
    margin: auto;
    padding: 0 0 32px;
    color: var(--color-slate-900)
}

.start-card,
.intake-card {
    background: white;
    border: 1px solid var(--color-slate-200);
    border-radius: 6px;
    box-shadow: none;
    margin-top: 18px
}

.start-card {
    padding: 28px;
    display: flex;
    align-items: center;
    gap: 18px
}

.start-card h2 {
    margin: 0;
    font-size: 1.12rem
}

.start-card p,
.intake-header p,
.hint {
    color: var(--color-slate-500);
    font-size: .88rem;
    margin: .35rem 0
}

.start-card .primary {
    margin-left: auto
}

.intake-card {
    padding: 22px
}

.intake-header {
    display: flex;
    justify-content: space-between;
    border-bottom: 1px solid var(--color-slate-200);
    padding-bottom: 15px
}

.intake-header h2 {
    margin: 0;
    font-size: 1.12rem
}

.intake-header span {
    align-self: center;
    background: var(--color-brand-100);
    color: var(--color-brand-700);
    border-radius: 4px;
    padding: 6px 10px;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .06em
}
fieldset {
    border: 1px solid var(--color-slate-200);
    border-radius: 6px;
    padding: 18px;
    margin: 0
}

legend {
    font-size: 1rem;
    font-weight: 800;
    padding: 0 7px
}

.intake-grid {
    display: grid;
    gap: 12px;
    margin: 13px 0
}

.two {
    grid-template-columns: repeat(2, minmax(0, 1fr))
}

.three {
    grid-template-columns: repeat(3, minmax(0, 1fr))
}

.four {
    grid-template-columns: repeat(4, minmax(0, 1fr))
}

label {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: .875rem;
    font-weight: 600;
    color: var(--color-slate-700)
}

input,
select,
textarea {
    box-sizing: border-box;
    width: 100%;
    border: 1px solid var(--color-slate-300);
    border-radius: 6px;
    padding: 10px 11px;
    background: white;
    color: var(--color-slate-900);
    font: inherit;
    font-size: .9rem;
    font-weight: 500
}

input:disabled {
    background: var(--color-slate-100)
}

textarea {
    min-height: 80px;
    overflow-y: auto;
    resize: vertical
}

.group-label {
    margin-bottom: 5px
}

.checks {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px
}

.checks label,
.alert-check,
.share-check,
.privacy-check {
    flex-direction: row;
    align-items: center;
    border: 1px solid var(--color-slate-200);
    border-radius: 7px;
    padding: 7px;
    font-size: .72rem;
    font-weight: 650
}

.checks input,
.alert-check input,
.share-check input,
.privacy-check input {
    width: auto
}

.alert-check {
    margin: 0 0 12px;
    background: #fff3f3;
    border-color: #ffd4d8;
    color: #983946
}

.privacy-check {
    margin: 12px 0 4px;
    background: var(--color-brand-50);
    border-color: var(--color-brand-200);
    color: var(--color-brand-900)
}

.privacy-note {
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: var(--color-brand-50);
    border: 1px solid var(--color-brand-200);
    border-radius: 6px;
    padding: 12px;
    color: var(--color-brand-900);
    font-size: .82rem;
    line-height: 1.45
}

.privacy-note strong {
    color: var(--color-brand-900)
}

.urgent,
.warning {
    background: #fff6e8;
    color: #945712;
    border: 1px solid #ffd89a;
    border-radius: 8px;
    padding: 9px 11px;
    font-size: .8rem
}

.notice {
    color: var(--color-brand-700);
    font-size: .8rem
}

.error {
    color: #c6364d
}

.actions {
    display: grid;
    grid-template-columns: auto 1fr auto;
    margin-top: 20px;
    gap: 10px
}

.primary,
.secondary {
    border: 0;
    border-radius: 6px;
    padding: 11px 16px;
    font-weight: 800;
    cursor: pointer
}

.primary {
    background: var(--color-brand-700);
    color: white
}

.primary:disabled {
    opacity: .6
}

.secondary {
    background: white;
    border: 1px solid var(--color-slate-300);
    color: var(--color-slate-700)
}

hr {
    border: 0;
    border-top: 1px solid var(--color-slate-200);
    margin: 18px 0
}

h3 {
    font-size: .92rem;
    margin: 0
}

.confirm-modal {
    background: white;
    max-width: 470px;
    border-radius: 6px;
    padding: 25px;
    border: 1px solid var(--color-slate-200)
}

.confirm-modal h2 {
    margin: 0
}

.confirm-modal p {
    color: var(--color-slate-500);
    line-height: 1.5
}

.confirm-modal div {
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    margin-top: 20px
}

@media(max-width:720px) {
    .intake-page {
        padding: 12px 0 calc(5.75rem + env(safe-area-inset-bottom))
    }

    .start-card {
        align-items: flex-start;
        flex-wrap: wrap;
        padding: 18px
    }

    .start-card .primary {
        width: 100%;
        margin-left: 0
    }

    .intake-card {
        padding: 14px
    }

    .intake-header span {
        display: none
    }
.intake-grid,
    .two,
    .three,
    .four {
        grid-template-columns: 1fr
    }

    .checks {
        grid-template-columns: 1fr
    }

    .actions {
        position: sticky;
        bottom: calc(5rem + env(safe-area-inset-bottom));
        z-index: 10;
        background: white;
        padding-top: 10px
    }

    .actions .primary,
    .actions .secondary {
        min-height: 46px
    }

    }

select {
    appearance: none;
    cursor: pointer;
    padding-right: 38px;
    background-image: linear-gradient(45deg, transparent 50%, var(--color-slate-500) 50%), linear-gradient(135deg, var(--color-slate-500) 50%, transparent 50%);
    background-position: calc(100% - 17px) 52%, calc(100% - 12px) 52%;
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat
}

input[type="checkbox"] {
    appearance: none;
    width: 18px;
    height: 18px;
    padding: 0;
    flex: 0 0 18px;
    border: 2px solid var(--color-slate-400);
    border-radius: 4px;
    background: white;
    cursor: pointer;
    display: inline-grid;
    place-content: center
}

input[type="checkbox"]::after {
    content: "";
    width: 8px;
    height: 4px;
    border: solid white;
    border-width: 0 0 2px 2px;
    transform: rotate(-45deg) scale(0);
    transition: transform .12s
}

input[type="checkbox"]:checked {
    background: var(--color-brand-700);
    border-color: var(--color-brand-700)
}

input[type="checkbox"]:checked::after {
    transform: rotate(-45deg) scale(1)
}

.check-toggle {
    justify-content: center;
    min-height: 44px
}

.check-toggle span {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 18px;
    white-space: nowrap
}

.location-selects {
    margin-top: 14px
}

.classification>span {
    font-size: .875rem;
    font-weight: 600;
    color: var(--color-slate-700)
}

.classification>div {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 7px
}

.classification-card {
    display: flex !important;
    flex-direction: row !important;
    gap: 10px;
    align-items: flex-start;
    border: 1px solid var(--color-slate-200);
    border-radius: 6px;
    padding: 11px 12px;
    cursor: pointer;
    min-height: 64px
}

.classification-card:has(input:checked) {
    border-color: var(--color-brand-600);
    background: var(--color-brand-50)
}

.classification-card>span {
    display: flex;
    flex-direction: column;
    gap: 3px
}

.classification b {
    font-size: .85rem
}

.classification small {
    color: var(--color-slate-600);
    font-size: .75rem;
    font-weight: 500;
    line-height: 1.35
}

legend b {
    display: inline-grid;
    place-items: center;
    width: 22px;
    height: 22px;
    background: var(--color-brand-50);
    color: var(--color-brand-700);
    border-radius: 50%;
    font-size: .75rem;
    margin-right: 7px
}

@media(max-width:720px) {
.classification>div {
        grid-template-columns: 1fr
    }

    .check-toggle {
        justify-content: flex-start
    }

    .actions {
        position: static;
        bottom: auto;
        z-index: auto;
        margin-top: 18px;
        padding-top: 0
    }

    .actions .primary,
    .actions .secondary {
        min-height: 46px
    }
}
input:not([type="checkbox"]), select, textarea, .primary, .secondary {
    min-height: 44px;
}
.checks label, .alert-check, .privacy-check, .share-check {
    min-height: 44px;
    line-height: 1.5;
}
.confirm-modal div {
    flex-wrap: wrap;
}
.actions .primary {
    justify-self: end;
    min-width: 140px;
}
.family-block{margin-top:1rem;border:1px solid #e9e5f5;border-radius:.9rem;padding:1rem;background:#fcfbff}.family-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem}.family-head h3{margin:0}.family-add{min-height:2.75rem;border-radius:.6rem;border:1px solid #c4b5fd;background:#fff;padding:0 .9rem;font-size:.85rem;font-weight:700;color:#5b21b6}.family-row{margin-top:.75rem;border-top:1px dashed #ddd6fe;padding-top:.75rem}.family-remove{min-height:2.75rem;font-size:.8rem;font-weight:700;color:#be123c}
.guidance{margin:.75rem 0;border:1px solid #ddd6fe;border-radius:.9rem;background:#faf8ff;padding:.85rem 1rem}.guidance h3{margin:0 0 .4rem;font-size:.9rem;font-weight:700;color:#4c1d95}.guidance ul{display:grid;gap:.4rem;margin:0;padding:0;list-style:none}.guidance li{border-left:3px solid #a78bfa;border-radius:.4rem;background:#fff;padding:.4rem .6rem;font-size:.82rem;line-height:1.4}.guidance li.hint-urgent{border-left-color:#e11d48;background:#fff1f2}.guidance li.hint-action{border-left-color:#d97706;background:#fffbeb}.guidance p{margin:.4rem 0 0;font-size:.72rem;color:#64748b}
</style>
