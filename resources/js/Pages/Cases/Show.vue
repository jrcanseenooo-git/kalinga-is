<script setup lang="ts">
import { ageFromBirthdate, formatPh, parseStored, philippineTimestamp } from '@/utils/philippineTime';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { PARTNER_AGENCIES, PROFESSIONAL_ROLES, SHARING_PURPOSES } from '@/utils/caseOptions';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CaseMonitoringPanel from '@/Components/CaseMonitoringPanel.vue';
import type { MonitoringVisit, ProgressNote } from '@/types/monitoring';
import CaseWorkspaceHeader from '@/Components/CaseWorkspaceHeader.vue';
import AppIcon from '@/Components/AppIcon.vue';
import CaseAssessmentPanel from '@/Components/CaseAssessmentPanel.vue';
import CaseParticipationPanel from '@/Components/CaseParticipationPanel.vue';
import CaseSafetyPanel, { type RiskHistoryItem } from '@/Components/CaseSafetyPanel.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import CaseClientContextPanel from '@/Components/CaseClientContextPanel.vue';
import CaseServicesPanel from '@/Components/CaseServicesPanel.vue';
import ReferralStatusForm from '@/Components/Referrals/ReferralStatusForm.vue';
import CaseClosurePanel from '@/Components/CaseClosurePanel.vue';
import CasePlanGoalsPanel from '@/Components/CasePlanGoalsPanel.vue';
import CaseProfileOverview from '@/Components/CaseProfileOverview.vue';
import CaseReportsPanel from '@/Components/CaseReportsPanel.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { useCurrentCasePlan } from '@/composables/useCurrentCasePlan';
import { progressTone, shortDate, titleCase } from '@/utils/caseDisplay';
import { followUp, type TrackedReferral } from '@/utils/referralTracking';
import { FileText, Plus, ShieldCheck, Upload, UserPlus, X } from '@lucide/vue';
import type { ConsentEntry, SupportRelationship } from '@/types/clientContext';
import type { CaseTask } from '@/types/casework';

const props = defineProps<{
    caseRecord: any;
    assessmentSummary: any | null;
    childParticipationSummary: any | null;
    conferenceSummary?: any | null;
    conferenceActionSummary?: { total: number; pending: number; overdue: number };
    planSummary?: { version:number; status:string; review_due_at?:string|null; goal_count:number; open_intervention_count:number } | null;
    notes: ProgressNote[];
    referrals: any[];
    referralTargets: { response_days: number; completion_days: number };
    referralTransitions?: Record<string, string[]>;
    servicesProvided?: any[];
    cpuMembers?: { id: number; name: string; cpu_role: string; position: string | null; has_account: boolean }[];
    hasSharingConsent?: boolean;
    visits: MonitoringVisit[];
    documents: any[];
    legacyAssessmentSummary?: { count: number; latest: { id:number; status:string; version:number; updated_at:string } | null };
    riskAssessments: RiskHistoryItem[];
    consents: ConsentEntry[];
    relationships: SupportRelationship[];
    timeline: any[];
    tasks: CaseTask[];
    transitions: string[];
    assignableWorkers: any[];
    canAssignCase?: boolean;
    canArchive: boolean;
    canReviewClosure: boolean;
    canAssessRisk: boolean;
    canGovernRecords: boolean;
    canManageMdt: boolean;
    canGenerateReport: boolean;
    assignmentUpdated?: boolean;
    mdtAssignments: any[];
    mdtUsers: any[];
}>();

const transition = useForm({ status: '' });
const documentUpload = useForm<{ document: File | null }>({ document: null });
const assignment = useForm({ assigned_to: '' as string | number, expected_version: Number(props.caseRecord.offline_version) });
const mdtAccess = useForm({ user_id: '' as string | number, professional_role: '', organization: '', purpose: '', allowed_categories: ['case_summary'], access_expires_at: '', remarks: '', lawful_basis: '' });
const mdtAction = useForm({ mdt_access_id: '' as string | number, title: '', instructions: '', due_at: '' });
const activeSection = ref<'overview'|'safety'|'assessment'|'participation'|'conference'|'collaboration'|'care'|'monitoring'|'people'|'closure'|'records'>('overview');
// Sections merged into a journey step: child participation lives under Assessment,
// case conferences under Monitoring & review. Old keys still work (alerts, links).
watch(activeSection, section => {
    if (section === 'participation') activeSection.value = 'assessment';
    else if (section === 'conference') activeSection.value = 'monitoring';
});
watch(activeSection, async section => {
    if (section === 'safety' || section === 'assessment' || section === 'participation' || section === 'monitoring' || section === 'people') { await nextTick(); document.getElementById(`case-panel-${section}`)?.scrollIntoView({ block: 'start' }); }
});
const showAllAlerts = ref(false);
// Goals and interventions load only when the Case plan or Services tab is opened.
const casePlan = useCurrentCasePlan(() => Number(props.caseRecord.id));
watch(activeSection, section => { if ((section === 'care' || section === 'collaboration') && props.planSummary) casePlan.load(); }, { immediate: true });
const showMdtAccessForm = ref(false);
const showMdtActionForm = ref(false);
const showUpload = ref(false);
const referralDue = (item: TrackedReferral) => followUp(item, props.referralTargets);
// The closure workspace is lazy-loaded the first time its tab is opened.
const closureLoaded = ref(false);
watch(activeSection, section => { if (section === 'closure') closureLoaded.value = true; }, { immediate: true });
const documentStateTone = (state?: string | null) => state === 'cleared' || state === 'sanitized' ? 'success' : state === 'legacy_unverified' ? 'neutral' : progressTone(state);
async function openSection(key: typeof workspaceTabs[number]['key']) {
    activeSection.value = key;
    await nextTick();
    document.getElementById('case-workspace-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
// Case page tabs follow the 7-step CEFMU case journey (see the system review doc).
// Deep link from suggestions and alerts: /cases/{id}?section=monitoring
onMounted(() => {
    const wanted = new URLSearchParams(window.location.search).get('section');
    if (wanted && workspaceTabs.some(tab => tab.key === wanted)) activeSection.value = wanted as typeof activeSection.value;
});
const monitoringPanel = ref<{ compose: (kind: 'referral' | 'service_delivery') => void } | null>(null);
/** Referrals and services are written as progress notes; open the note form on the Progress notes tab. */
async function composeNote(kind: 'referral' | 'service_delivery') { activeSection.value = 'monitoring'; await nextTick(); monitoringPanel.value?.compose(kind); }
const workspaceTabs = [
    { key: 'overview', label: 'Overview', step: null },
    { key: 'people', label: 'Client & Intake', step: 1 },
    { key: 'safety', label: 'Safety & risk', step: 2 },
    { key: 'assessment', label: 'Assessment', step: 3 },
    { key: 'care', label: 'Case plan', step: 4 },
    { key: 'collaboration', label: 'Services & referrals', step: 5 },
    { key: 'monitoring', label: 'Progress notes', step: 6 },
    { key: 'closure', label: 'Closure & aftercare', step: 7 },
    { key: 'records', label: 'Documents', step: null },
] as const;
const nextAction = computed(() => {
    if (props.caseRecord.status === 'CLOSED') return 'Review retained history or use the authorized reopening workflow when appropriate.';
    if (props.caseRecord.status === 'OPEN') return 'Record the first assessment or field contact.';
    if (props.caseRecord.status === 'FOR_FOLLOW_UP') return 'Complete the next follow-up and document the outcome.';
    if (props.caseRecord.status === 'FOR_CLOSURE_REVIEW') return 'Complete the closure record for supervisor review.';
    return 'Review the latest activity and continue the care plan.';
});

const orderedCaseTasks = computed(() => [...props.tasks].sort((left, right) => {
    const stateOrder = Number(right.status === 'open') - Number(left.status === 'open');
    const leftDue = parseStored(left.due_at)?.getTime() ?? Infinity;
    const rightDue = parseStored(right.due_at)?.getTime() ?? Infinity;
    return stateOrder || leftDue - rightDue || left.id - right.id;
}));
const nextRecordedTask = computed(() => props.caseRecord.status === 'CLOSED' ? null : orderedCaseTasks.value.find(task => task.status === 'open') ?? null);
const showAllCaseTasks = ref(false);
const visibleCaseTasks = computed(() => showAllCaseTasks.value ? orderedCaseTasks.value : orderedCaseTasks.value.slice(0, 5));
const taskDueLabel = (value: string | null) => value ? philippineTimestamp(value) : 'No due date recorded';

function caseDate(value: string | null): string {
    if (!value) return 'Not recorded';
    return formatPh(value, 'date', 'Date unavailable');
}

async function showCaseTasks() {
    activeSection.value = 'care';
    await nextTick();
    document.getElementById('case-open-tasks')?.focus();
    document.getElementById('case-open-tasks')?.scrollIntoView({ block: 'center' });
}

async function moveWorkspaceTab(event: KeyboardEvent, key: typeof workspaceTabs[number]['key']) {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    const index = workspaceTabs.findIndex(tab => tab.key === key);
    let next = index;
    if (event.key === 'ArrowRight') next = (index + 1) % workspaceTabs.length;
    else if (event.key === 'ArrowLeft') next = (index - 1 + workspaceTabs.length) % workspaceTabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = workspaceTabs.length - 1;
    else return;
    event.preventDefault();
    activeSection.value = workspaceTabs[next].key;
    await nextTick();
    document.getElementById(`case-tab-${activeSection.value}`)?.focus();
}

const smartAlerts = computed(() => {
    const alerts: { level: 'critical'|'warning'|'info'; text: string; tab?: string }[] = [];
    const risk = props.caseRecord.current_risk_level;
    if (risk === 'critical' || risk === 'high') alerts.push({ level: 'critical', text: `Risk level is ${risk} - safety review required`, tab: 'safety' });
    if (nextRiskReview.value && riskReviewState.value === 'overdue') alerts.push({ level: 'critical', text: 'Risk reassessment is overdue', tab: 'safety' });
    if (props.riskAssessments.length === 0 && props.caseRecord.status !== 'OPEN') alerts.push({ level: 'warning', text: 'No safety and risk assessment recorded', tab: 'safety' });
    if (props.assessmentSummary?.status !== 'final' && props.caseRecord.status !== 'OPEN') alerts.push({ level: 'warning', text: 'No comprehensive assessment finalized', tab: 'assessment' });
    if (!props.planSummary && !['OPEN', 'CLOSED'].includes(props.caseRecord.status)) alerts.push({ level: 'warning', text: 'No active case plan', tab: 'care' });
    if (props.planSummary?.review_due_at && new Date(props.planSummary.review_due_at) < new Date()) alerts.push({ level: 'warning', text: 'Plan review is overdue', tab: 'care' });
    const overdueTasks = props.tasks.filter((t: any) => t.status === 'open' && t.due_at && new Date(t.due_at) < new Date());
    if (overdueTasks.length) alerts.push({ level: 'warning', text: `${overdueTasks.length} overdue task(s)` });
    const pendingReferrals = props.referrals.filter((r: any) => r.status === 'PENDING' || r.status === 'SENT');
    if (pendingReferrals.length) alerts.push({ level: 'info', text: `${pendingReferrals.length} referral(s) awaiting response` });
    if (props.consents.length === 0) alerts.push({ level: 'info', text: 'No consent record - document lawful basis', tab: 'people' });
    if (props.caseRecord.status === 'FOR_CLOSURE_REVIEW' && !props.caseRecord.closure_summary) alerts.push({ level: 'warning', text: 'Closure summary required before case can close', tab: 'closure' });
    return alerts;
});

// Journey tracker. A step counts as done when its record exists; this is guidance,
// not a gate - status changes keep their existing server rules.
type StepState = 'done' | 'current' | 'pending';
const journeySteps = computed(() => {
    const status = String(props.caseRecord.status ?? '');
    const base = [
        { key: 'people', n: 1, label: 'Intake', done: Boolean(props.caseRecord.intake_completed_at || props.caseRecord.opened_at), next: 'Complete the child profile, safe contact and consent records.' },
        { key: 'safety', n: 2, label: 'Safety & risk', done: props.riskAssessments.length > 0, next: 'Record a safety and risk assessment for the child.' },
        { key: 'assessment', n: 3, label: 'Assessment', done: props.assessmentSummary?.status === 'final', next: props.assessmentSummary ? 'Finish and finalize the comprehensive assessment, including the child\'s participation.' : 'Start the comprehensive assessment and record the child\'s participation.' },
        { key: 'care', n: 4, label: 'Case plan', done: Boolean(props.planSummary), next: 'Create the case plan: goals, interventions and a review date.' },
        { key: 'collaboration', n: 5, label: 'Services & referrals', done: props.referrals.length > 0 || Number(props.planSummary?.open_intervention_count ?? 0) > 0, next: 'Deliver the planned services and refer to partner agencies as needed.' },
        { key: 'monitoring', n: 6, label: 'Progress notes', done: props.visits.length > 0 || props.notes.length > 0, next: 'Record visits and progress notes; review the plan or hold a case conference when needed.' },
        { key: 'closure', n: 7, label: 'Closure & aftercare', done: status === 'CLOSED', next: status === 'FOR_CLOSURE_REVIEW' ? 'Write the closure summary so the case can be reviewed for closure.' : status === 'CLOSED' ? 'Case closed. Follow the aftercare plan and keep the record for review.' : 'When goals are met, move the case to For Closure Review.' },
    ] as const;
    let currentKey: string;
    if (status === 'CLOSED' || status === 'FOR_CLOSURE_REVIEW') currentKey = 'closure';
    else currentKey = base.find(step => !step.done && step.key !== 'closure')?.key ?? 'monitoring';
    const currentIndex = base.findIndex(step => step.key === currentKey);
    return base.map((step, index) => ({ ...step, state: (step.key === currentKey && !(status === 'CLOSED')) ? 'current' as StepState : (step.done || index < currentIndex ? (step.done ? 'done' as StepState : 'pending' as StepState) : 'pending' as StepState) }));
});
const currentStep = computed(() => journeySteps.value.find(step => step.state === 'current') ?? journeySteps.value.at(-1)!);

const tabBadges = computed(() => {
    const badges: Record<string, { count?: number; urgent?: boolean }> = {};
    const critAlerts = smartAlerts.value.filter(a => a.level === 'critical' && a.tab === 'safety');
    if (critAlerts.length) badges.safety = { count: critAlerts.length, urgent: true };
    if (props.assessmentSummary?.status !== 'final' && props.caseRecord.status !== 'OPEN') badges.assessment = { urgent: false };
    if (props.tasks.filter((t: any) => t.status === 'open' && t.due_at && new Date(t.due_at) < new Date()).length) badges.monitoring = { count: props.tasks.filter((t: any) => t.status === 'open' && t.due_at && new Date(t.due_at) < new Date()).length, urgent: true };
    if (props.caseRecord.status === 'FOR_CLOSURE_REVIEW' && !props.caseRecord.closure_summary) badges.closure = { urgent: true };
    return badges;
});

const daysSinceActivity = computed(() => {
    if (!props.caseRecord.last_activity_at) return null;
    return Math.floor((Date.now() - (parseStored(props.caseRecord.last_activity_at)?.getTime() ?? Date.now())) / 86400000);
});

const clientAge = computed(() => {
    return ageFromBirthdate(props.caseRecord.birthdate);
});
const nextRiskReview = computed(() => props.riskAssessments.filter((item) => item.assessment.status === 'final' && item.assessment.next_reassessment_at).sort((a, b) => (parseStored(a.assessment.next_reassessment_at)?.getTime() ?? 0) - (parseStored(b.assessment.next_reassessment_at)?.getTime() ?? 0))[0]);
const riskReviewState = computed(() => !nextRiskReview.value ? null : (parseStored(nextRiskReview.value.assessment.next_reassessment_at)?.getTime() ?? Infinity) < Date.now() ? 'overdue' : 'scheduled');
const assessmentPanel = ref<InstanceType<typeof CaseAssessmentPanel> | null>(null);
const loadComprehensive = async (id?: number) => { await nextTick(); await assessmentPanel.value?.load(id); };

const participationPanel = ref<InstanceType<typeof CaseParticipationPanel> | null>(null);
const loadParticipation = async (id?: number) => { await nextTick(); await participationPanel.value?.load(id); };

const move = () => transition.patch(route('cases.transition', props.caseRecord.id));
const uploadDocument = () => documentUpload.post(route('cases.documents.upload', props.caseRecord.id), {
    forceFormData: true,
    onSuccess: () => { documentUpload.reset(); showUpload.value = false; },
});
const saveAssignment = () => {
    assignment.expected_version = Number(props.caseRecord.offline_version);
    assignment.patch(route('cases.assign', props.caseRecord.id), {
        onSuccess: () => assignment.reset(),
        onError: () => {
            if (!props.assignableWorkers.some(worker => Number(worker.id) === Number(assignment.assigned_to))) assignment.assigned_to = '';
        },
    });
};
const saveMdtAccess = () => mdtAccess.post(route('cases.mdt.access.store', props.caseRecord.id), { onSuccess: () => mdtAccess.reset() });
const saveMdtAction = () => mdtAction.post(route('cases.mdt.actions.store', props.caseRecord.id), { onSuccess: () => mdtAction.reset() });
const revokeMdtAccess = (id:number) => { if (window.confirm('Revoke this MDT access immediately?')) useForm({}).delete(route('cases.mdt.access.revoke',[props.caseRecord.id,id])); };
const archiveCase = () => { if (window.confirm('Archive this case? The case will be hidden from work lists, while its client record and audit history remain preserved.')) useForm({}).delete(route('cases.destroy', props.caseRecord.id)); };
</script>

<template>
    <Head :title="caseRecord.case_number" />
    <AuthenticatedLayout>
        <section class="case-workspace mx-auto min-w-0 max-w-[1440px]">
            <CaseWorkspaceHeader :case-record="caseRecord" :age="clientAge" :activity-days="daysSinceActivity" :can-generate-report="canGenerateReport" />
            <p v-if="assignmentUpdated" role="status" class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">Case assignment updated.</p>
            <details v-if="transitions.length" class="ui-panel mt-3 px-4 py-2">
                <summary class="min-h-11 cursor-pointer py-3 text-sm font-semibold text-brand-800">Case status actions</summary>
                <div class="flex flex-wrap gap-2 pb-2"><button v-for="status in transitions" :key="status" type="button" @click="transition.status = status; move()" class="ui-button text-xs">Move to {{ status.toLowerCase().replaceAll('_', ' ') }}</button></div>
            </details>


            <section id="case-workspace-tabs" class="case-workspace-nav scroll-mt-24" aria-label="Case workspace sections">
                <div class="min-w-0 flex-1">
                    <h2 class="sr-only">Case journey</h2>
                    <ol class="journey" aria-label="Case journey steps">
                        <li v-for="step in journeySteps" :key="step.key" class="journey-step" :class="`journey-${step.state}`">
                            <button type="button" class="journey-button" :aria-current="step.state === 'current' ? 'step' : undefined" :aria-controls="`case-panel-${step.key}`" @click="openSection(step.key)">
                                <span class="journey-dot" aria-hidden="true">{{ step.state === 'done' ? '&#10003;' : step.n }}</span>
                                <span class="journey-label">{{ step.label }}<span class="sr-only">{{ step.state === 'done' ? ' - recorded' : step.state === 'current' ? ' - current step' : ' - not yet recorded' }}</span></span>
                            </button>
                        </li>
                    </ol>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        <div class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-brand-700">Step {{ currentStep.n }} of 7 - {{ currentStep.label }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">{{ currentStep.next }}</p>
                            <button type="button" class="ui-link min-h-9 text-xs" @click="openSection(currentStep.key)">Go to {{ currentStep.label.toLowerCase() }}</button>
                        </div>
                        <div class="rounded-xl border border-[var(--color-line)] bg-white px-4 py-3">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600">{{ nextRecordedTask ? 'Next recorded task' : 'Workflow guidance' }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ nextRecordedTask?.title ?? nextAction }}</p>
                            <p v-if="nextRecordedTask" class="text-xs text-slate-600">{{ taskDueLabel(nextRecordedTask.due_at) }}. <button type="button" class="min-h-9 px-1 font-semibold text-brand-800 underline" @click="showCaseTasks">View case tasks</button></p>
                        </div>
                    </div>
                </div>
                <div class="case-tab-list" role="tablist" aria-label="Case record sections">
                    <button v-for="tab in workspaceTabs" :id="`case-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :tabindex="activeSection === tab.key ? 0 : -1" @keydown="moveWorkspaceTab($event, tab.key)" :aria-selected="activeSection === tab.key" :aria-controls="`case-panel-${tab.key}`" class="case-tab" :class="activeSection === tab.key ? 'case-tab-active' : ''" @click="activeSection = tab.key"><span v-if="tab.step" aria-hidden="true" class="case-tab-step">{{ tab.step }}</span>{{ tab.label }}<span v-if="tabBadges[tab.key]" class="case-tab-badge" :class="tabBadges[tab.key].urgent ? 'case-tab-badge-urgent' : 'case-tab-badge-info'">{{ tabBadges[tab.key].count || '!' }}</span></button>
                </div>
            </section>

            <section v-if="smartAlerts.length" v-show="activeSection === 'overview'" class="ui-panel mt-5 px-4 py-3 sm:px-5" aria-labelledby="case-attention-title">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="case-attention-title" class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)]"><AppIcon name="alert" class="h-4 w-4 text-amber-600" />Needs attention<span class="ui-pill bg-amber-50 px-2 py-0.5 text-amber-800">{{ smartAlerts.length }}</span></h2><button v-if="smartAlerts.length > 3" type="button" class="ui-link min-h-9 text-xs" :aria-expanded="showAllAlerts" @click="showAllAlerts = !showAllAlerts">{{ showAllAlerts ? 'Show fewer' : `Show all ${smartAlerts.length}` }}</button></div>
                <ul class="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="(alert, i) in (showAllAlerts ? smartAlerts : smartAlerts.slice(0, 3))" :key="i"><button type="button" class="smart-alert w-full text-left" :class="alert.level === 'critical' ? 'smart-alert-critical' : alert.level === 'warning' ? 'smart-alert-warning' : 'smart-alert-info'" @click="alert.tab ? openSection(alert.tab as any) : undefined"><span class="smart-alert-icon">{{ alert.level === 'critical' ? '!' : alert.level === 'warning' ? '?' : 'i' }}</span><span class="min-w-0 flex-1 text-sm font-semibold">{{ alert.text }}<span v-if="alert.tab" class="mt-0.5 block text-xs font-medium">Go to {{ workspaceTabs.find(t => t.key === alert.tab)?.label }}</span></span></button></li>
                </ul>
            </section>
            <div class="mt-5 grid min-w-0 grid-cols-1 gap-5" :class="activeSection === 'overview' ? 'xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]' : ''">
                <div class="min-w-0 space-y-5">
                    <section v-show="activeSection === 'overview'" id="case-panel-overview" role="tabpanel" aria-labelledby="case-tab-overview" class="min-w-0">
                      <CaseProfileOverview :services="servicesProvided ?? []" :record="caseRecord" :relationships="relationships" :notes="notes" :visits="visits" :referrals="referrals" :status-history="timeline" @open="openSection($event)">
                        <div class="mt-5 grid gap-3 sm:grid-cols-3">
                            <button type="button" class="overview-card" :class="assessmentSummary?.status === 'final' ? 'overview-card-done' : 'overview-card-pending'" @click="activeSection='assessment'; if (assessmentSummary) loadComprehensive(assessmentSummary.id)">
                                <span class="overview-card-status">{{ assessmentSummary ? 'V' + assessmentSummary.version + ' - ' + assessmentSummary.status : 'Not started' }}</span>
                                <span class="overview-card-title">Comprehensive assessment</span>
                                <span v-if="assessmentSummary" class="overview-card-detail">Assessed {{ assessmentSummary.assessment_date }}</span>
                                <span v-else class="overview-card-detail">Tap to begin</span>
                            </button>
                            <button type="button" class="overview-card" :class="childParticipationSummary ? 'overview-card-done' : 'overview-card-pending'" @click="activeSection='participation'; if (childParticipationSummary) loadParticipation(childParticipationSummary.id)">
                                <span class="overview-card-status">{{ childParticipationSummary ? 'Finalized' : 'No finalized record' }}</span>
                                <span class="overview-card-title">Child participation</span>
                                <span v-if="childParticipationSummary" class="overview-card-detail">{{ childParticipationSummary.decision_title || 'Restricted participation record' }}</span>
                                <span v-else class="overview-card-detail">Tap to begin</span>
                            </button>
                            <Link :href="route('cases.plans.workspace',caseRecord.id)" class="overview-card" :class="planSummary ? 'overview-card-done' : 'overview-card-pending'">
                                <span class="overview-card-status">{{ planSummary ? 'V' + planSummary.version + ' - ' + planSummary.status.replaceAll('_',' ') : 'No active plan' }}</span>
                                <span class="overview-card-title">Plan & continuity</span>
                                <span v-if="planSummary" class="overview-card-detail">{{ planSummary.goal_count }} goal(s) · {{ planSummary.open_intervention_count }} open<span v-if="planSummary.review_due_at" :class="new Date(planSummary.review_due_at) < new Date() ? 'text-rose-600 font-bold' : ''"> · review {{ planSummary.review_due_at }}</span></span>
                                <span v-else class="overview-card-detail">Tap to create plan</span>
                            </Link>
                        </div>

                      </CaseProfileOverview>
                      <CaseReportsPanel class="mt-5" :case-id="caseRecord.id" :can-print="canGenerateReport" :intake-at="caseRecord.intake_completed_at ?? caseRecord.opened_at ?? null" :note-count="notes.filter(note => note.record_type !== 'addendum').length" :last-note-at="notes[0]?.occurred_at ?? notes[0]?.created_at ?? null" :plan="planSummary" :assessment-status="assessmentSummary?.status ?? null" @open="openSection($event)" />
                    </section>
                    <section v-show="activeSection === 'overview' || activeSection === 'monitoring'" id="case-panel-conference" class="ui-panel p-5">
                        <h2 class="font-bold">Case Conference</h2>
                        <p v-if="conferenceSummary" class="mt-2 text-sm">Latest finalized: {{ conferenceSummary.conference_type }} · {{ conferenceSummary.finalized_at }} · {{ conferenceActionSummary?.pending || 0 }} pending / {{ conferenceActionSummary?.overdue || 0 }} overdue action(s)<span v-if="conferenceSummary.next_review_at"> · next review {{ conferenceSummary.next_review_at }}</span></p>
                        <p v-else class="mt-2 text-sm text-slate-600">No finalized conference is recorded.</p>
                        <p class="mt-2 text-xs text-slate-600">Overview excludes conference narratives, child views, and restricted health information.</p>
                        <Link :href="route('cases.conferences.index', caseRecord.id)" class="mt-3 inline-block rounded-md border px-3 py-2 text-sm font-bold text-brand-700">Open Case Conferences</Link>
                    </section>
                    <section v-show="activeSection === 'assessment'" id="case-panel-assessment" role="tabpanel" aria-labelledby="case-tab-assessment" class="scroll-mt-24 rounded-lg border border-slate-200 bg-white p-5">
                      <CaseAssessmentPanel ref="assessmentPanel" :case-id="Number(caseRecord.id)" :active="activeSection === 'assessment'" />
                    </section>

                    <section v-show="activeSection === 'assessment'" id="case-panel-participation" aria-label="Child participation" class="scroll-mt-24 rounded-lg border border-slate-200 bg-white p-5">
                      <p class="mb-3 text-[11px] font-bold uppercase tracking-wider text-brand-700">Step 3 - Child participation</p>
                      <CaseParticipationPanel ref="participationPanel" :case-id="Number(caseRecord.id)" :active="activeSection === 'assessment'" />
                    </section>

                    <section v-show="activeSection === 'collaboration'" id="case-panel-collaboration" role="tabpanel" aria-labelledby="case-tab-collaboration" class="min-w-0">
                      <CaseServicesPanel :case-id="Number(caseRecord.id)" :referrals="referrals" :services="servicesProvided ?? []" @compose="composeNote" :interventions="casePlan.interventions.value" :plan-status="planSummary ? casePlan.status.value : 'ready'" :has-plan="Boolean(planSummary)" :referral-targets="referralTargets" @reload-plan="casePlan.load(true)">
                        <template #status-form="{ referral }"><ReferralStatusForm :referral-id="referral.id" :status="referral.status" :allowed-statuses="referralTransitions?.[referral.status] ?? []" /></template>
                        <section class="ui-panel overflow-hidden p-0" aria-labelledby="case-mdt-title">
                          <header class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
                            <div class="min-w-0"><h2 id="case-mdt-title" class="ui-section-title">MDT / CPU referral pathway</h2><p class="ui-muted mt-0.5 max-w-2xl">Grant purpose-limited, time-bound access. Membership alone does not give an MDT member access to this case.</p></div>
                            <div v-if="canManageMdt" class="flex flex-wrap gap-2">
                              <button v-if="mdtAssignments.length" type="button" class="ui-button min-h-10 gap-1.5 text-sm" :aria-expanded="showMdtActionForm" aria-controls="case-mdt-action-form" @click="showMdtActionForm = !showMdtActionForm; showMdtAccessForm = false"><Plus class="h-4 w-4" aria-hidden="true" />Assign MDT action</button>
                              <button type="button" class="ui-button ui-button-primary min-h-10 gap-1.5 text-sm" :aria-expanded="showMdtAccessForm" aria-controls="case-mdt-access-form" @click="showMdtAccessForm = !showMdtAccessForm; showMdtActionForm = false"><UserPlus class="h-4 w-4" aria-hidden="true" />Add MDT member</button>
                            </div>
                          </header>
                          <div v-if="canManageMdt">
                            <form v-show="showMdtAccessForm || mdtAccess.isDirty" id="case-mdt-access-form" aria-labelledby="case-mdt-access-title" @submit.prevent="saveMdtAccess" class="m-4 rounded-xl border border-brand-200 bg-brand-50/50 p-4 text-sm"><div class="flex items-start justify-between gap-2"><h3 id="case-mdt-access-title" class="font-semibold text-slate-900">Add MDT member / request input</h3><button type="button" class="grid h-9 w-9 place-items-center rounded-lg hover:bg-white" aria-label="Close add MDT member" @click="showMdtAccessForm = false"><X class="h-4 w-4" aria-hidden="true" /></button></div><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-xs font-semibold text-slate-600">MDT member<select v-model="mdtAccess.user_id" required class="ui-field mt-1"><option value="">Select MDT member</option><option v-for="user in mdtUsers" :key="user.id" :value="user.id">{{user.name}}</option></select></label><label class="text-xs font-semibold text-slate-600">Professional role<OptionSelect v-model="mdtAccess.professional_role" :options="PROFESSIONAL_ROLES" required /></label><label class="text-xs font-semibold text-slate-600">Organization / office<OptionSelect v-model="mdtAccess.organization" :options="PARTNER_AGENCIES" required /></label><label class="text-xs font-semibold text-slate-600">Access expires<input v-model="mdtAccess.access_expires_at" required type="datetime-local" class="ui-field mt-1"></label><label class="text-xs font-semibold text-slate-600 sm:col-span-2">Purpose of involvement<OptionSelect v-model="mdtAccess.purpose" :options="SHARING_PURPOSES" required /></label></div><fieldset class="mt-3"><legend class="text-xs font-semibold text-slate-600">Permitted data categories</legend><div class="mt-2 flex flex-wrap gap-3 text-xs"><label v-for="item in [['case_summary','Case summary'],['case_stage','Stage/status'],['case_plan','Case plan'],['referral_service','Referral/service']]" :key="item[0]" class="flex min-h-9 items-center gap-1.5"><input v-model="mdtAccess.allowed_categories" type="checkbox" :value="item[0]">{{item[1]}}</label></div></fieldset><button class="ui-button-primary mt-3">Grant MDT access</button></form>
                            <form v-if="mdtAssignments.length" v-show="showMdtActionForm || mdtAction.isDirty" id="case-mdt-action-form" aria-labelledby="case-mdt-action-title" @submit.prevent="saveMdtAction" class="m-4 rounded-xl border border-[var(--color-line)] bg-slate-50/60 p-4 text-sm"><div class="flex items-start justify-between gap-2"><h3 id="case-mdt-action-title" class="font-semibold text-slate-900">Assign MDT action</h3><button type="button" class="grid h-9 w-9 place-items-center rounded-lg hover:bg-white" aria-label="Close assign MDT action" @click="showMdtActionForm = false"><X class="h-4 w-4" aria-hidden="true" /></button></div><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-xs font-semibold text-slate-600">MDT assignment<select v-model="mdtAction.mdt_access_id" required class="ui-field mt-1"><option value="">Select active MDT assignment</option><option v-for="item in mdtAssignments.filter(item=>!item.revoked_at)" :key="item.id" :value="item.id">{{item.member_name}} · {{item.professional_role}}</option></select></label><label class="text-xs font-semibold text-slate-600">Due<input v-model="mdtAction.due_at" type="datetime-local" class="ui-field mt-1"></label><label class="text-xs font-semibold text-slate-600 sm:col-span-2">Assigned action<input v-model="mdtAction.title" required placeholder="Assigned action" class="ui-field mt-1"></label><label class="text-xs font-semibold text-slate-600 sm:col-span-2">Instructions<textarea v-model="mdtAction.instructions" rows="3" placeholder="Minimum-necessary instructions" class="ui-field mt-1"></textarea></label></div><button class="ui-button-primary mt-3">Assign action</button></form>
                            <table v-if="mdtAssignments.length" class="ui-table ui-table-stack"><thead><tr><th scope="col">Member</th><th scope="col">Organization and purpose</th><th scope="col">Access</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="item in mdtAssignments" :key="item.id"><td data-label="Member"><div><span class="block font-semibold text-slate-800">{{item.member_name}}</span><span class="block text-xs text-slate-500">{{item.professional_role}}</span></div></td><td data-label="Purpose" class="max-w-sm"><div><span class="block">{{item.organization}}</span><span class="block text-xs text-slate-500">{{item.purpose}}</span></div></td><td data-label="Access"><div><StatusBadge :label="item.revoked_at ? 'Revoked' : titleCase(item.status)" :tone="item.revoked_at ? 'neutral' : 'info'" dot /><span class="mt-1 block text-xs text-slate-500">expires {{shortDate(item.access_expires_at)}}</span></div></td><td class="text-right"><button v-if="!item.revoked_at" type="button" @click="revokeMdtAccess(item.id)" class="rounded-lg border border-rose-300 px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Revoke access</button></td></tr></tbody></table>
                            <p v-else class="px-5 py-6 text-sm text-slate-600">No MDT members have access to this case.</p>
                          </div>
                          <p v-else class="m-5 rounded-md bg-slate-50 p-4 text-sm text-slate-600">You do not have authority to manage MDT access for this case.</p>
                        </section>
                      </CaseServicesPanel>
                    </section>
                    <section v-show="activeSection === 'safety'" id="case-panel-safety" role="tabpanel" aria-labelledby="case-tab-safety" class="scroll-mt-24 rounded-lg border border-slate-200 bg-white p-5">
                        <CaseSafetyPanel :case-id="Number(caseRecord.id)" :can-assess="canAssessRisk" :current-risk="caseRecord.current_risk_level ?? null" :history="riskAssessments" :next-review="nextRiskReview?.assessment.next_reassessment_at" :review-overdue="riskReviewState === 'overdue'" />
                    </section>

                    <section v-show="activeSection === 'people'" id="case-panel-people" role="tabpanel" aria-labelledby="case-tab-people" class="scroll-mt-24 rounded-lg border border-slate-200 bg-white p-5">
                        <CaseClientContextPanel :record="caseRecord" :relationships="relationships" :consents="consents" :can-archive="canArchive" @archive="archiveCase" />
                    </section>

                    <section v-show="activeSection === 'overview' || activeSection === 'people'" v-if="canAssignCase" class="ui-panel p-5">
                        <h2 class="font-bold">Assignment</h2><p class="mt-1 text-sm text-slate-600">Reassign this case using your existing assignment authority.</p><p v-if="!assignableWorkers.length" class="mt-3 text-sm text-slate-700">{{ caseRecord.lgu_id ? 'No active case workers have a current LGU assignment for this case.' : 'A verified case LGU assignment is required before reassignment. Request an authorized jurisdiction review.' }}</p><form v-else @submit.prevent="saveAssignment" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]"><label class="min-w-0 text-sm font-semibold text-slate-700">Assign case worker<select v-model="assignment.assigned_to" required class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="">Select worker</option><option v-for="worker in assignableWorkers" :key="worker.id" :value="worker.id">{{ worker.name }}</option></select></label><button :disabled="assignment.processing || !assignment.assigned_to" class="rounded-md bg-brand-700 px-4 py-2 text-sm font-bold text-white">Update assignment</button></form><p v-if="assignment.errors.assigned_to || assignment.errors.expected_version" role="alert" class="mt-2 text-sm text-rose-700">{{ assignment.errors.assigned_to || assignment.errors.expected_version }}</p></section>

                    <section v-show="activeSection === 'assessment' && legacyAssessmentSummary?.count" class="ui-panel p-5">
                        <h2 class="font-bold">Earlier assessment records</h2>
                        <p class="mt-1 text-sm text-slate-600">Use the dedicated Assessment workspace for the canonical, versioned social case assessment. Legacy generic assessments remain preserved for migration and reconciliation only.</p>
                        <p v-if="legacyAssessmentSummary?.count" class="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-600">{{ legacyAssessmentSummary.count }} legacy assessment record(s) retained. Latest: version {{ legacyAssessmentSummary.latest?.version }} · {{ legacyAssessmentSummary.latest?.status }}.</p>
                        </section>

                    <section v-show="activeSection === 'care'" id="case-panel-care" role="tabpanel" aria-labelledby="case-tab-care" class="min-w-0">
                        <CasePlanGoalsPanel :case-id="Number(caseRecord.id)" :summary="planSummary ?? null" :goals="casePlan.goals.value" :interventions="casePlan.interventions.value" :plan-status="planSummary ? casePlan.status.value : 'ready'" @reload-plan="casePlan.load(true)" />
                    </section>

                    <section v-show="activeSection === 'closure'" id="case-panel-closure" role="tabpanel" aria-labelledby="case-tab-closure" class="min-w-0">
                        <CaseClosurePanel v-if="closureLoaded" :case-id="Number(caseRecord.id)" @updated="router.reload()" />
                    </section>

                    
                    <section v-show="activeSection === 'monitoring'" id="case-panel-monitoring" role="tabpanel" aria-labelledby="case-tab-monitoring" class="scroll-mt-24 ui-panel p-4 sm:p-5"><CaseMonitoringPanel ref="monitoringPanel" :case-id="caseRecord.id" :active="activeSection==='monitoring'" :visits="visits" :notes="notes" :tasks="tasks" :referrals="referrals" :services="servicesProvided ?? []" :cpu-members="cpuMembers ?? []" :referral-targets="referralTargets" :referral-transitions="referralTransitions ?? {}" :has-sharing-consent="Boolean(hasSharingConsent)" :client-names="[caseRecord.first_name, caseRecord.last_name].filter(Boolean)"/></section>

                    <section v-show="activeSection === 'records'" id="case-panel-records" role="tabpanel" aria-labelledby="case-tab-records" class="ui-panel overflow-hidden p-0">
                        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
                            <div><h2 class="ui-section-title">Documents</h2><p class="ui-muted mt-0.5">Case files stored privately. PDF, image, Word, or Word Open XML files up to 10 MB.</p></div>
                            <div class="flex flex-wrap gap-2"><Link v-if="canGovernRecords" :href="route('cases.records.governance.index', caseRecord.id)" class="ui-button min-h-10 gap-1.5 text-sm"><ShieldCheck class="h-4 w-4" aria-hidden="true" />Open records governance</Link><button type="button" class="ui-button ui-button-primary min-h-10 gap-1.5 text-sm" :aria-expanded="showUpload" aria-controls="case-document-upload" @click="showUpload = !showUpload"><Upload class="h-4 w-4" aria-hidden="true" />Upload document</button></div>
                        </header>
                        <form v-show="showUpload || documentUpload.document" id="case-document-upload" @submit.prevent="uploadDocument" class="m-4 flex flex-col gap-3 rounded-xl border border-dashed border-brand-300 bg-brand-50/40 p-4 sm:flex-row sm:items-end">
                            <label class="block min-w-0 flex-1 text-sm font-semibold text-slate-700">Choose case document<input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="mt-1 block w-full max-w-full text-sm font-normal file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-brand-800" @change="documentUpload.document = ($event.target as HTMLInputElement).files?.[0] || null" /></label>
                            <button :disabled="documentUpload.processing || !documentUpload.document" class="ui-button-primary shrink-0 disabled:opacity-50">{{ documentUpload.processing ? 'Uploading...' : 'Upload file' }}</button>
                        </form>
                        <p v-if="documentUpload.errors.document" role="alert" class="mx-5 mt-2 text-sm text-rose-600">{{ documentUpload.errors.document }}</p>
                        <table v-if="documents.length" class="ui-table ui-table-stack">
                            <thead><tr><th scope="col">File name</th><th scope="col">Category</th><th scope="col">Uploaded</th><th scope="col">Uploaded by</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody><tr v-for="item in documents" :key="item.id || `restricted-${item.created_at}`">
                                <td data-label="File"><div class="min-w-0"><span class="flex min-w-0 items-start gap-2"><FileText class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden="true" /><span class="font-medium text-slate-800 [overflow-wrap:anywhere]">{{ item.name }}</span></span><span v-if="item.version" class="ml-6 block text-xs text-slate-500">Version {{ item.version }}</span></div></td>
                                <td data-label="Category">{{ item.classification || 'Unclassified' }}</td>
                                <td data-label="Uploaded" class="whitespace-nowrap">{{ shortDate(item.created_at) }}</td>
                                <td data-label="By">{{ item.uploaded_by_name || '-' }}</td>
                                <td data-label="Status"><StatusBadge :label="item.restricted ? 'Restricted' : titleCase(item.scan_state)" :tone="item.restricted ? 'neutral' : documentStateTone(item.scan_state)" /></td>
                                <td class="text-right"><a v-if="item.id && (item.scan_state === 'legacy_unverified' || item.scan_state === 'cleared' || item.scan_state === 'sanitized')" :href="route('cases.documents.download', [caseRecord.id, item.id])" class="ui-link text-sm">Download</a><span v-else class="text-xs font-semibold text-amber-700">{{ item.restricted ? 'Restricted' : 'Awaiting scan' }}</span></td>
                            </tr></tbody>
                        </table>
                        <p v-else class="px-5 py-6 text-sm text-slate-600">No documents in this folder.</p>
                    </section>
                </div>

                <aside v-show="['overview', 'care', 'records'].includes(activeSection)" class="min-w-0 space-y-5">
                    <section v-show="activeSection === 'overview'" class="ui-panel p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="ui-section-title">Referrals</h2></div>
                        <div v-if="referrals.length" class="mt-3 space-y-2">
                            <div v-for="item in referrals" :key="item.id" class="rounded-md p-3 text-sm" :class="item.status === 'COMPLETED' ? 'bg-emerald-50 border border-emerald-200' : item.status === 'PENDING' ? 'bg-amber-50 border border-amber-200' : 'bg-slate-50 border border-slate-200'">
                                <p class="font-semibold">{{ item.provider || item.service_category }}</p>
                                <p class="mt-1 text-xs" :class="item.status === 'COMPLETED' ? 'text-emerald-700' : item.status === 'PENDING' ? 'text-amber-700' : 'text-slate-600'">{{ item.status.replaceAll('_', ' ') }}<span v-if="item.referred_at"> · Referred {{ formatPh(item.referred_at, 'date') }}</span></p>
                                <p v-if="referralDue(item)" class="mt-0.5 text-xs font-semibold" :class="referralDue(item)!.overdue ? 'text-rose-700' : 'text-slate-600'">{{ referralDue(item)!.label }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-600">No referrals recorded.</p>
                    </section>

                    

                    <section id="case-open-tasks" tabindex="-1" aria-labelledby="case-tasks-title" v-show="activeSection === 'overview' || activeSection === 'care'" class="ui-panel p-5">
                        <h2 id="case-tasks-title" class="ui-section-title">Tasks</h2>
                        <div v-if="tasks.length" id="case-task-records" class="mt-3 space-y-2">
                            <div v-for="task in visibleCaseTasks" :key="task.id" class="rounded-lg border p-2.5 text-sm" :class="task.status === 'open' && task.due_at && new Date(task.due_at) < new Date() ? 'border-rose-200 bg-rose-50' : task.status === 'completed' ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200'">
                                <p class="font-semibold" :class="task.status === 'completed' ? 'text-emerald-700 line-through' : ''">{{ task.title }}</p>
                                <p class="mt-0.5 text-xs text-slate-600">{{ task.status }}<span v-if="task.due_at"> · due {{ taskDueLabel(task.due_at) }}</span></p>
                            </div>
                            <button v-if="tasks.length > 5" type="button" class="ui-link text-xs" :aria-expanded="showAllCaseTasks" aria-controls="case-task-records" @click="showAllCaseTasks = !showAllCaseTasks">{{ showAllCaseTasks ? 'Show fewer case tasks' : `Show all ${tasks.length} case tasks` }}</button>
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-600">No tasks linked to this case.</p>
                    </section>
                    <section v-show="activeSection === 'records'" class="ui-panel p-5"><h2 class="font-bold">Case activity</h2><div class="mt-3 space-y-3"><div v-for="item in timeline" :key="item.id" class="text-sm"><p class="font-semibold text-slate-700">{{ item.event }}</p><p class="text-xs text-slate-600">{{ item.created_at }}</p></div><p v-if="!timeline.length" class="text-sm text-slate-600">No recorded activity yet.</p></div></section>
                </aside>
            </div>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.case-workspace-nav { display:flex; flex-wrap:wrap; align-items:center; gap:1rem; margin-top:1rem; border:1px solid var(--color-line); border-radius:.75rem; background:#fff; padding:1rem 1.25rem .25rem; box-shadow:var(--shadow-card); }
.case-tab-list { display:flex; flex:none; flex-wrap:nowrap; gap:.25rem; width:100%; min-width:0; max-width:100%; overflow-x:auto; border-top:1px solid var(--color-line); padding-top:.25rem; scrollbar-width:thin; }
.case-tab { position:relative; flex-shrink:0; min-height:44px; white-space:nowrap; border:0; border-bottom:2px solid transparent; border-radius:0; background:#fff; padding:.55rem .5rem; font-size:.8rem; font-weight:600; color:#475569; transition:.15s ease; }
.case-tab:hover { color:var(--color-brand-800); }
.case-tab:focus-visible { outline:2px solid var(--color-brand-700); outline-offset:2px; }
.case-tab-active { border-color:var(--color-brand-600); color:var(--color-brand-800); }
.case-tab-step { display:inline-grid; place-items:center; width:1.25rem; height:1.25rem; margin-right:.3rem; border-radius:999px; background:#efedf6; color:#5a3a95; font-size:.68rem; font-weight:800; }
.case-tab-active .case-tab-step { background:var(--color-brand-700); color:#fff; }
.journey { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:.25rem; }
.journey-step { position:relative; min-width:0; }
.journey-step:not(:last-child)::after { content:''; position:absolute; top:1.05rem; left:calc(50% + 1.1rem); right:calc(-50% + 1.1rem); height:2px; background:#e7e5f0; }
.journey-done:not(:last-child)::after { background:#7c5cd6; }
.journey-button { display:flex; width:100%; min-height:44px; flex-direction:column; align-items:center; gap:.35rem; border:0; background:transparent; padding:0 .15rem; text-align:center; }
.journey-dot { display:grid; place-items:center; width:2.1rem; height:2.1rem; border-radius:999px; border:2px solid #d9d5e8; background:#fff; color:#64748b; font-size:.8rem; font-weight:800; }
.journey-done .journey-dot { border-color:#7c5cd6; background:#7c5cd6; color:#fff; }
.journey-current .journey-dot { border-color:var(--color-brand-700); background:#f3eefe; color:var(--color-brand-800); box-shadow:0 0 0 4px #ede4ff; }
.journey-label { font-size:.72rem; font-weight:600; line-height:1.15; color:#475569; }
.journey-current .journey-label { color:var(--color-brand-800); font-weight:800; }
@media (max-width:640px) { .journey-label { font-size:.6rem; } .journey-dot { width:1.75rem; height:1.75rem; } .journey-step:not(:last-child)::after { top:.9rem; } }
.case-tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:1.1rem; height:1.1rem; margin-left:.35rem; padding:0 .3rem; border-radius:4px; font-size:.65rem; font-weight:800; line-height:1; }
.case-tab-badge-urgent { background:#fef2f2; color:#b91c1c; }
.case-tab-badge-info { background:var(--color-brand-50); color:var(--color-brand-700); }


.smart-alert { display:flex; align-items:center; gap:.6rem; border-radius:.75rem; padding:.6rem .85rem; transition:.15s ease; }
.smart-alert:hover { filter:brightness(.97); }
.smart-alert-critical { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.smart-alert-warning { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.smart-alert-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
.smart-alert-icon { display:flex; align-items:center; justify-content:center; width:1.5rem; height:1.5rem; border-radius:4px; font-size:.7rem; font-weight:900; flex-shrink:0; }
.smart-alert-critical .smart-alert-icon { background:#fee2e2; color:#991b1b; }
.smart-alert-warning .smart-alert-icon { background:#fef3c7; color:#92400e; }
.smart-alert-info .smart-alert-icon { background:#dbeafe; color:#1e40af; }

.overview-card { display:flex; flex-direction:column; gap:.25rem; border-radius:.75rem; padding:.85rem 1rem; text-align:left; transition:.15s ease; cursor:pointer; }
.overview-card:hover { filter:brightness(.97); transform:translateY(-1px); }
.overview-card-done { background:#f0fdf4; border:1px solid #bbf7d0; }
.overview-card-pending { background:#f8fafc; border:1px solid #e2e8f0; }
.overview-card-status { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.overview-card-done .overview-card-status { color:#15803d; }
.overview-card-pending .overview-card-status { color:#475569; }
.overview-card-title { font-size:.85rem; font-weight:700; color:#1e293b; }
.overview-card-detail { font-size:.75rem; color:#64748b; }

@media (max-width:640px) {
    .case-workspace-nav { align-items:stretch; gap:.75rem; padding:.85rem; }
    .case-workspace-nav>div:first-child { flex:none; width:100%; }
    .case-tab-list { display:flex; width:100%; min-width:0; }
    .case-tab { min-width:0; min-height:2.75rem; padding:.55rem .45rem; overflow-wrap:anywhere; }
    .smart-alert { padding:.5rem .7rem; }
}
.case-workspace :where(input:not([type=checkbox]), select, textarea) { min-width:0; max-width:100%; }
.case-workspace :where(button, input:not([type=checkbox]), select) { min-height:44px; }
</style>
