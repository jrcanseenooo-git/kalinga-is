<script setup lang="ts">
import { askConfirm } from '@/utils/confirmDialog';
import { ageFromBirthdate, calendarDateLabel, formatPh, parseStored, philippineTimestamp } from '@/utils/philippineTime';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { PARTNER_AGENCIES, PROFESSIONAL_ROLES, SHARING_PURPOSES } from '@/utils/caseOptions';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CaseMonitoringPanel from '@/Components/CaseMonitoringPanel.vue';
import type { MonitoringVisit, ProgressNote } from '@/types/monitoring';
import CaseWorkspaceHeader from '@/Components/CaseWorkspaceHeader.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import CaseClientContextPanel from '@/Components/CaseClientContextPanel.vue';
import CaseServicesPanel from '@/Components/CaseServicesPanel.vue';
import PlanMonitoringPanel from '@/Components/Forms/PlanMonitoringPanel.vue';
import CaseClosurePanel from '@/Components/CaseClosurePanel.vue';
import AftercarePanel, { type AftercareData } from '@/Components/Closure/AftercarePanel.vue';
import CaseProfileOverview from '@/Components/CaseProfileOverview.vue';
import KavatPanel from '@/Components/Kavat/KavatPanel.vue';
import CaseFormsPanel from '@/Components/Forms/CaseFormsPanel.vue';
import GrantsPanel from '@/Components/GrantsPanel.vue';
import RemarksThread from '@/Components/RemarksThread.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { progressTone, shortDate, titleCase } from '@/utils/caseDisplay';
import { followUp, type TrackedReferral } from '@/utils/referralTracking';
import { CheckCircle2, Circle, FileText, Plus, ShieldCheck, Sparkles, Upload, UserPlus, Users, X } from '@lucide/vue';
import type { ConsentForm, SupportRelationship } from '@/types/clientContext';
import type { CaseTask } from '@/types/casework';
import type { Remark } from '@/types/remarks';
import type { GrantItem } from '@/types/reports';
import type { PageProps } from '@/types';
import { priorityLabel, type KavatDefinition, type KavatRecord, type KavatBaseline } from '@/Casework/kavat';
import { formHasContent, type CaseForm, type FormType, type FormsDefinition, type PlanDeadline } from '@/Casework/caseForms';
import { peso } from '@/utils/reportFormat';

const props = defineProps<{
    caseRecord: any;
    conferenceSummary?: any | null;
    conferenceActionSummary?: { total: number; pending: number; overdue: number };
    notes: ProgressNote[];
    referrals: any[];
    referralTargets: { response_days: number; completion_days: number };
    referralTransitions?: Record<string, string[]>;
    servicesProvided?: any[];
    cpuMembers?: { id: number; name: string; cpu_role: string; position: string | null; has_account: boolean }[];
    hasSharingConsent?: boolean;
    planDeadline?: PlanDeadline | null;
    aftercare?: AftercareData;
    visits: MonitoringVisit[];
    documents: any[];
    consentForm: ConsentForm;
    relationships: SupportRelationship[];
    remarks: Remark[];
    kavatDefinition: KavatDefinition;
    kavat: KavatRecord[];
    kavatBaseline: KavatBaseline | null;
    formsDefinition: FormsDefinition;
    forms: Record<FormType, CaseForm>;
    formSuggestions: Record<string, string>;
    grants: { items: GrantItem[]; total: number; count: number; types: Record<string, string> };
    canEditForms: boolean;
    customFieldLabels?: Record<string, string>;
    timeline: any[];
    tasks: CaseTask[];
    transitions: string[];
    statusInfo?: { automatic: boolean; reason: string } | null;
    canArchive: boolean;
    canReviewClosure: boolean;
    canGovernRecords: boolean;
    canManageMdt: boolean;
    canGenerateReport: boolean;
    mdtAssignments: any[];
    mdtUsers: any[];
}>();

const page = usePage<PageProps>();
const isCaseManager = computed(() => page.props.auth.permissions.includes('cases.update'));
const transition = useForm({ status: '' });
const documentUpload = useForm<{ document: File | null }>({ document: null });
const mdtAccess = useForm({ user_id: '' as string | number, professional_role: '', organization: '', purpose: '', allowed_categories: ['case_summary'], access_expires_at: '', remarks: '', lawful_basis: '' });
const mdtAction = useForm({ mdt_access_id: '' as string | number, title: '', instructions: '', due_at: '' });

/**
 * Case workspace tabs. The earlier 7-step journey, the Safety & risk,
 * Assessment, Case plan and Services tabs were replaced: safety is the KaVAT,
 * assessment and planning are written in the Kalinga forms, referrals are
 * conducted from the Intervention Plan (Kalinga forms) and services and grants
 * are recorded with the progress notes, which also log every referral.
 */
const workspaceTabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'people', label: 'Client & Intake' },
    { key: 'kavat', label: 'KaVAT' },
    { key: 'forms', label: 'Kalinga forms' },
    { key: 'notes', label: 'Progress notes' },
    { key: 'monitoring', label: 'Monitoring' },
    { key: 'closure', label: 'Closure & aftercare' },
    { key: 'records', label: 'Documents' },
] as const;
type TabKey = typeof workspaceTabs[number]['key'];
/** Old section names still used in links and notifications. */
const LEGACY_TABS: Record<string, TabKey> = { safety: 'kavat', assessment: 'forms', participation: 'forms', care: 'forms', collaboration: 'notes', conference: 'overview' };
const activeSection = ref<TabKey>('overview');
const showAllAlerts = ref(false);
const showMdtAccessForm = ref(false);
const showMdtActionForm = ref(false);
const showUpload = ref(false);
const referralDue = (item: TrackedReferral) => followUp(item, props.referralTargets);
// On small screens the tab content starts far below the tabs; bring the opened panel into view.
watch(activeSection, async section => {
    if (section === 'overview') return;
    await nextTick();
    document.getElementById(`case-panel-${section}`)?.scrollIntoView({ block: 'start' });
});
const closureLoaded = ref(false);
watch(activeSection, section => { if (section === 'closure') closureLoaded.value = true; }, { immediate: true });
const documentStateTone = (state?: string | null) => state === 'cleared' || state === 'sanitized' ? 'success' : state === 'legacy_unverified' ? 'neutral' : progressTone(state);
const resolveTab = (key: string): TabKey | null => workspaceTabs.some(tab => tab.key === key) ? key as TabKey : LEGACY_TABS[key] ?? null;
async function openSection(key: string) {
    const tab = resolveTab(key);
    if (!tab) return;
    activeSection.value = tab;
    await nextTick();
    document.getElementById('case-workspace-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
// Deep link: /cases/{id}?section=notes (old names such as "monitoring" still work).
onMounted(() => {
    const wanted = resolveTab(new URLSearchParams(window.location.search).get('section') ?? '');
    if (wanted) activeSection.value = wanted;
});
const monitoringPanel = ref<{ compose: (kind: 'service_delivery') => void } | null>(null);
/** Referrals and services are written as progress notes. */
async function composeNote(kind: 'service_delivery') { activeSection.value = 'notes'; await nextTick(); monitoringPanel.value?.compose(kind); }
/** Aftercare is recorded by the case manager, also after closure. */
const canEditAftercare = computed(() => page.props.auth.permissions.includes('cases.update') && props.caseRecord.status !== 'ARCHIVED');
const scrollToClosure = () => document.getElementById('case-closure-records')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
const formsPanel = ref<{ openForm: (type: FormType) => Promise<void> } | null>(null);
/** Referrals live with the Intervention Plan. */
async function openPlanReferrals() { await openSection('forms'); await formsPanel.value?.openForm('plan'); }

const orderedCaseTasks = computed(() => [...props.tasks].sort((left, right) => {
    const stateOrder = Number(right.status === 'open') - Number(left.status === 'open');
    const leftDue = parseStored(left.due_at)?.getTime() ?? Infinity;
    const rightDue = parseStored(right.due_at)?.getTime() ?? Infinity;
    return stateOrder || leftDue - rightDue || left.id - right.id;
}));
const nextRecordedTask = computed(() => props.caseRecord.status === 'CLOSED' ? null : orderedCaseTasks.value.find(task => task.status === 'open') ?? null);
async function showCaseTasks() {
    activeSection.value = 'overview';
    await nextTick();
    document.getElementById('case-open-tasks')?.focus();
    document.getElementById('case-open-tasks')?.scrollIntoView({ block: 'center' });
}
const showAllCaseTasks = ref(false);
const visibleCaseTasks = computed(() => showAllCaseTasks.value ? orderedCaseTasks.value : orderedCaseTasks.value.slice(0, 5));
const taskDueLabel = (value: string | null) => value ? philippineTimestamp(value) : 'No due date recorded';

async function moveWorkspaceTab(event: KeyboardEvent, key: TabKey) {
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

const finalKavat = computed(() => props.kavat.filter(item => item.status === 'final'));
const latestKavat = computed(() => finalKavat.value[0] ?? null);
const kavatDraft = computed(() => props.kavat.find(item => item.status === 'draft') ?? null);
const openTasks = computed(() => props.tasks.filter(task => task.status === 'open'));
const overdueTasks = computed(() => openTasks.value.filter(task => task.due_at && new Date(task.due_at) < new Date()));
const openReferrals = computed(() => props.referrals.filter((item: any) => ['PENDING', 'SENT', 'ACCEPTED', 'IN_PROGRESS'].includes(item.status)));
const unreadRemarks = computed(() => props.remarks.filter(remark => !remark.acknowledged_at));
const formState = (type: FormType) => props.forms[type].status === 'final' ? 'final' : formHasContent(type, props.forms[type].content) ? 'draft' : 'not_started';
const formsDone = computed(() => (['summary', 'plan', 'study'] as FormType[]).filter(type => formState(type) === 'final').length);

const manilaToday = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
/** KaVAT: Baseline (screening tool), Midline and Endline. The Endline is due when the case goes to closure review. */
const endlineDue = computed(() => props.caseRecord.status === 'FOR_CLOSURE_REVIEW' && !props.kavat.some((item: KavatRecord) => item.occasion === 'endline'));
const smartAlerts = computed(() => {
    const alerts: { level: 'critical'|'warning'|'info'; text: string; tab?: TabKey }[] = [];
    const status = props.caseRecord.status;
    if (unreadRemarks.value.length) alerts.push({ level: 'warning', text: `${unreadRemarks.value.length} unread ${unreadRemarks.value.length === 1 ? 'remark' : 'remarks'} from your supervisor or monitors`, tab: 'overview' });
    if (latestKavat.value?.score.priority === 'high') alerts.push({ level: 'critical', text: `KaVAT protection score ${latestKavat.value.score.final_score}: High Priority`, tab: 'kavat' });
    if (latestKavat.value?.score.red_flags.length) alerts.push({ level: 'warning', text: `KaVAT red flag: the child refused to answer ${latestKavat.value.score.red_flags.length} ${latestKavat.value.score.red_flags.length === 1 ? 'question' : 'questions'}`, tab: 'kavat' });
    if (!props.kavatBaseline && status !== 'CLOSED') alerts.push({ level: 'warning', text: 'No baseline KaVAT result recorded - enter it from the screening tool', tab: 'kavat' });
    if (kavatDraft.value) alerts.push({ level: 'info', text: 'Finish the KaVAT in progress', tab: 'kavat' });
    else if (endlineDue.value) alerts.push({ level: 'warning', text: 'Administer the Endline KaVAT before closing the case', tab: 'kavat' });
    if (status !== 'CLOSED' && props.planDeadline && !props.planDeadline.submitted_at) {
        if (props.planDeadline.overdue) alerts.push({ level: 'critical', text: `Intervention Plan overdue - it was due ${calendarDateLabel(props.planDeadline.due)} (7 days after intake)`, tab: 'forms' });
        else if ((props.planDeadline.days_left ?? 99) <= 2) alerts.push({ level: 'warning', text: `Submit the Intervention Plan by ${calendarDateLabel(props.planDeadline.due)}`, tab: 'forms' });
    }
    if (overdueTasks.value.length) alerts.push({ level: 'warning', text: `${overdueTasks.value.length} overdue ${overdueTasks.value.length === 1 ? 'task' : 'tasks'}` });
    const pending = props.referrals.filter((r: any) => r.status === 'PENDING' || r.status === 'SENT');
    if (pending.length) alerts.push({ level: 'info', text: `${pending.length} ${pending.length === 1 ? 'referral' : 'referrals'} awaiting response`, tab: 'forms' });
    if (!props.consentForm.document_id) alerts.push({ level: 'info', text: 'No photo of the signed consent / assent form - add it in Client & Intake', tab: 'people' });
    if (status === 'FOR_CLOSURE_REVIEW') alerts.push({ level: 'warning', text: 'Case is for closure review - check the closure checklist', tab: 'closure' });
    if (status === 'FOR_CLOSURE_REVIEW' && !props.aftercare?.plan) alerts.push({ level: 'warning', text: 'Write the aftercare plan before the case is closed', tab: 'closure' });
    if (status === 'CLOSED' && props.aftercare?.suggest_reopen) alerts.push({ level: 'critical', text: 'Aftercare: the child was found at risk - consider reopening the case', tab: 'closure' });
    else if (status === 'CLOSED' && props.aftercare?.next_due && props.aftercare.next_due < manilaToday) alerts.push({ level: 'warning', text: `Aftercare follow-up visit past due (${calendarDateLabel(props.aftercare.next_due)})`, tab: 'closure' });
    return alerts;
});

const tabBadges = computed(() => {
    const badges: Partial<Record<TabKey, { count?: number; urgent?: boolean }>> = {};
    if (unreadRemarks.value.length) badges.overview = { count: unreadRemarks.value.length, urgent: true };
    if (latestKavat.value?.score.priority === 'high') badges.kavat = { urgent: true };
    else if (kavatDraft.value) badges.kavat = { urgent: false };
    if (overdueTasks.value.length) badges.notes = { count: overdueTasks.value.length, urgent: true };
    if (props.caseRecord.status === 'FOR_CLOSURE_REVIEW') badges.closure = { urgent: true };
    // Plan items past their target end date and not done.
    const today = manilaToday;
    const late = (props.forms.plan.content.rows ?? []).filter(row => row.status !== 'done' && row.target_end && row.target_end < today).length;
    if (late) badges.monitoring = { count: late, urgent: true };
    return badges;
});

/** What to do next, in the order of the Kalinga forms. Guidance only; status changes keep their server rules. */
const nextStep = computed(() => {
    const status = props.caseRecord.status;
    if (status === 'CLOSED') {
        if (props.aftercare?.suggest_reopen) return { text: 'The last follow-up visit found the child at risk. Consider reopening the case.', tab: 'closure' as TabKey };
        if (!props.aftercare?.plan) return { text: 'Case closed. Write the aftercare plan for the follow-up visits.', tab: 'closure' as TabKey };
        return { text: props.aftercare.next_due ? `Case closed. Next aftercare follow-up visit: ${calendarDateLabel(props.aftercare.next_due)}.` : 'Case closed. The aftercare follow-up period has ended.', tab: 'closure' as TabKey };
    }
    if (unreadRemarks.value.length) return { text: 'Read and answer the remarks from your supervisor or monitors.', tab: 'overview' as TabKey };
    if (!props.kavatBaseline) return { text: 'Record the baseline KaVAT result from the screening tool.', tab: 'kavat' as TabKey };
    if (kavatDraft.value) return { text: 'Finish the KaVAT in progress with the child.', tab: 'kavat' as TabKey };
    if (formState('summary') === 'not_started') return { text: 'Write the Case Summary Report.', tab: 'forms' as TabKey };
    if (!props.planDeadline?.submitted_at) return { text: `Write and finalize the Intervention Plan with the child and family${props.planDeadline ? ` by ${calendarDateLabel(props.planDeadline.due)}` : ''}.`, tab: 'forms' as TabKey };
    if (endlineDue.value) return { text: 'Administer the Endline KaVAT with the child.', tab: 'kavat' as TabKey };
    if (status === 'FOR_CLOSURE_REVIEW') return { text: 'Complete the closure checklist and closure record.', tab: 'closure' as TabKey };
    if (formState('study') !== 'final' && props.notes.length >= 3) return { text: 'Write the Social Case Study Report (final report with assessment and findings).', tab: 'forms' as TabKey };
    return { text: 'Carry out the plan: conduct referrals from the plan, record progress notes, services and grants.', tab: 'notes' as TabKey };
});

/** Closure checklist: guidance from the record, not a gate. */
const closureChecklist = computed(() => [
    { done: finalKavat.value.some(item => item.occasion === 'endline'), text: 'Endline KaVAT administered', detail: latestKavat.value ? `Latest: ${latestKavat.value.score.final_score} (${priorityLabel(latestKavat.value.score.priority)})` : 'No KaVAT yet', tab: 'kavat' as TabKey },
    { done: formState('plan') === 'final', text: 'Intervention Plan finalized', detail: formState('plan') === 'draft' ? 'Draft' : formState('plan') === 'final' ? 'Finalized' : 'Not started', tab: 'forms' as TabKey },
    { done: formState('study') === 'final', text: 'Social Case Study Report finalized', detail: formState('study') === 'draft' ? 'Draft' : formState('study') === 'final' ? 'Finalized' : 'Not started', tab: 'forms' as TabKey },
    { done: openReferrals.value.length === 0, text: 'No unfinished referrals', detail: openReferrals.value.length ? `${openReferrals.value.length} still open` : 'All referrals completed or closed', tab: 'forms' as TabKey },
    { done: openTasks.value.length === 0, text: 'No open tasks', detail: openTasks.value.length ? `${openTasks.value.length} open` : 'All tasks done', tab: 'overview' as TabKey },
    { done: Boolean(props.aftercare?.plan), text: 'Aftercare plan written', detail: props.aftercare?.plan ? 'Written' : 'Not yet written', tab: 'closure' as TabKey },
    { done: unreadRemarks.value.length === 0, text: 'All remarks read', detail: unreadRemarks.value.length ? `${unreadRemarks.value.length} unread` : 'None waiting', tab: 'overview' as TabKey },
]);

const daysSinceActivity = computed(() => {
    if (!props.caseRecord.last_activity_at) return null;
    return Math.floor((Date.now() - (parseStored(props.caseRecord.last_activity_at)?.getTime() ?? Date.now())) / 86400000);
});
/** Answers to the extra intake fields added in Form options. */
const customAnswers = computed(() => {
    const raw = props.caseRecord.custom_fields;
    const answers: Record<string, string> = typeof raw === 'string' ? (JSON.parse(raw || '{}') as Record<string, string>) : (raw ?? {});
    return Object.entries(answers).map(([key, value]) => ({ key, label: props.customFieldLabels?.[key] ?? key, value: value === 'yes' ? 'Yes' : value === 'no' ? 'No' : String(value) }));
});
const clientAge = computed(() => ageFromBirthdate(props.caseRecord.birthdate));
const priorityTone = (priority: string | null | undefined) => priority === 'high' ? 'overview-card-alert' : priority === 'standard' ? 'overview-card-done' : 'overview-card-pending';

const move = () => transition.patch(route('cases.transition', props.caseRecord.id), { preserveScroll: true });
const statusLabel = (status: string) => status.toLowerCase().replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());
async function startClosure() {
    if (!(await askConfirm({ title: 'Start the closure review?', message: 'The case moves to "For closure review". Complete the closure record in Closure & aftercare; the supervisor reviews it before the case closes.', confirmLabel: 'Start closure review' }))) return;
    transition.status = 'FOR_CLOSURE_REVIEW'; move();
}
async function cancelClosure() {
    if (!(await askConfirm({ title: 'Stop the closure review?', message: 'The case goes back to its working status.', confirmLabel: 'Stop the review' }))) return;
    transition.status = 'IN_PROGRESS'; move();
}
const uploadDocument = () => documentUpload.post(route('cases.documents.upload', props.caseRecord.id), {
    forceFormData: true,
    onSuccess: () => { documentUpload.reset(); showUpload.value = false; },
});
const saveMdtAccess = () => mdtAccess.post(route('cases.mdt.access.store', props.caseRecord.id), { onSuccess: () => mdtAccess.reset() });
const saveMdtAction = () => mdtAction.post(route('cases.mdt.actions.store', props.caseRecord.id), { onSuccess: () => mdtAction.reset() });
const revokeMdtAccess = async (id:number) => { if (await askConfirm({ title: 'Revoke this MDT access now?', message: 'The member loses access to this case immediately.', confirmLabel: 'Revoke access', tone: 'danger' })) useForm({}).delete(route('cases.mdt.access.revoke',[props.caseRecord.id,id])); };
const archiveCase = async () => { if (await askConfirm({ title: 'Archive this case?', message: 'It is hidden from work lists. The client record and audit history are kept.', confirmLabel: 'Archive', tone: 'danger' })) useForm({}).delete(route('cases.destroy', props.caseRecord.id)); };
</script>

<template>
    <Head :title="caseRecord.case_number" />
    <AuthenticatedLayout>
        <section class="case-workspace mx-auto min-w-0 max-w-[1440px]">
            <CaseWorkspaceHeader :case-record="caseRecord" :age="clientAge" :activity-days="daysSinceActivity" :can-generate-report="canGenerateReport" />
            <!-- Status follows the recorded work; only the closure steps are started by hand. -->
            <div class="ui-panel mt-3 flex flex-wrap items-center gap-3 px-4 py-3">
                <Sparkles class="h-5 w-5 shrink-0 text-violet-700" aria-hidden="true" />
                <p class="min-w-0 flex-1 text-sm text-slate-700"><b class="text-slate-900">Status: {{ statusLabel(caseRecord.status) }}.</b> {{ statusInfo?.automatic ? `Updated automatically - ${statusInfo.reason}` : statusInfo?.reason }}</p>
                <button v-if="transitions.includes('FOR_CLOSURE_REVIEW')" type="button" class="ui-button min-h-11" :disabled="transition.processing" @click="startClosure">Start closure review</button>
                <button v-if="caseRecord.status === 'FOR_CLOSURE_REVIEW' && transitions.includes('IN_PROGRESS')" type="button" class="ui-button min-h-11" :disabled="transition.processing" @click="cancelClosure">Stop the closure review</button>
            </div>

            <section id="case-workspace-tabs" class="case-workspace-nav scroll-mt-24" aria-label="Case workspace sections">
                <div class="grid w-full gap-3 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                    <div class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-brand-700">Next step</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ nextStep.text }}</p>
                        <button type="button" class="ui-link min-h-9 text-xs" @click="openSection(nextStep.tab)">Go to {{ workspaceTabs.find(tab => tab.key === nextStep.tab)?.label }}</button>
                        <p v-if="nextRecordedTask" class="mt-2 border-t border-brand-100 pt-2 text-xs text-slate-700"><span class="font-bold uppercase tracking-wider text-brand-700">Next recorded task:</span> {{ nextRecordedTask.title }}, {{ taskDueLabel(nextRecordedTask.due_at) }}. <button type="button" class="min-h-9 px-1 font-semibold text-brand-800 underline" @click="showCaseTasks">View case tasks</button></p>
                    </div>
                    <div aria-label="Case at a glance" class="grid grid-cols-3 gap-2 text-center">
                        <button type="button" class="rounded-xl border px-2 py-2" :class="priorityTone(latestKavat?.score.priority)" @click="openSection('kavat')"><span class="block text-[11px] font-semibold text-slate-600">KaVAT</span><span class="block text-base font-bold text-slate-900">{{ latestKavat?.score.final_score ?? (kavatDraft ? 'In progress' : '-') }}</span><span class="block text-[11px] text-slate-600">{{ latestKavat ? (latestKavat.score.priority === 'high' ? 'High Priority' : 'Standard') : 'Not finalized' }}</span></button>
                        <button type="button" class="rounded-xl border border-[var(--color-line)] bg-white px-2 py-2" @click="openSection('forms')"><span class="block text-[11px] font-semibold text-slate-600">Forms finalized</span><span class="block text-base font-bold text-slate-900">{{ formsDone }} / 3</span><span class="block text-[11px] text-slate-600">Summary, plan, SCSR</span></button>
                        <button type="button" class="rounded-xl border border-[var(--color-line)] bg-white px-2 py-2" @click="openSection('notes')"><span class="block text-[11px] font-semibold text-slate-600">Grants</span><span class="block text-base font-bold text-slate-900">{{ peso(grants.total) }}</span><span class="block text-[11px] text-slate-600">{{ grants.count }} released</span></button>
                    </div>
                </div>
                <div class="case-tab-list" role="tablist" aria-label="Case record sections">
                    <button v-for="tab in workspaceTabs" :id="`case-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :tabindex="activeSection === tab.key ? 0 : -1" @keydown="moveWorkspaceTab($event, tab.key)" :aria-selected="activeSection === tab.key" :aria-controls="`case-panel-${tab.key}`" class="case-tab" :class="activeSection === tab.key ? 'case-tab-active' : ''" @click="activeSection = tab.key">{{ tab.label }}<span v-if="tabBadges[tab.key]" class="case-tab-badge" :class="tabBadges[tab.key]!.urgent ? 'case-tab-badge-urgent' : 'case-tab-badge-info'">{{ tabBadges[tab.key]!.count || '!' }}</span></button>
                </div>
            </section>

            <section v-if="smartAlerts.length" v-show="activeSection === 'overview'" class="ui-panel mt-5 px-4 py-3 sm:px-5" aria-labelledby="case-attention-title">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="case-attention-title" class="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink)]"><AppIcon name="alert" class="h-4 w-4 text-amber-600" />Needs attention<span class="ui-pill bg-amber-50 px-2 py-0.5 text-amber-800">{{ smartAlerts.length }}</span></h2><button v-if="smartAlerts.length > 3" type="button" class="ui-link min-h-9 text-xs" :aria-expanded="showAllAlerts" @click="showAllAlerts = !showAllAlerts">{{ showAllAlerts ? 'Show fewer' : `Show all ${smartAlerts.length}` }}</button></div>
                <ul class="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="(alert, i) in (showAllAlerts ? smartAlerts : smartAlerts.slice(0, 3))" :key="i"><button type="button" class="smart-alert w-full text-left" :class="alert.level === 'critical' ? 'smart-alert-critical' : alert.level === 'warning' ? 'smart-alert-warning' : 'smart-alert-info'" @click="alert.tab ? openSection(alert.tab) : undefined"><span class="smart-alert-icon">{{ alert.level === 'critical' ? '!' : alert.level === 'warning' ? '?' : 'i' }}</span><span class="min-w-0 flex-1 text-sm font-semibold">{{ alert.text }}<span v-if="alert.tab" class="mt-0.5 block text-xs font-medium">Go to {{ workspaceTabs.find(t => t.key === alert.tab)?.label }}</span></span></button></li>
                </ul>
            </section>

            <div class="mt-5 grid min-w-0 grid-cols-1 gap-5" :class="['overview', 'records'].includes(activeSection) ? 'xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]' : ''">
                <div class="min-w-0 space-y-5">
                    <section v-show="activeSection === 'overview'" id="case-panel-overview" role="tabpanel" aria-labelledby="case-tab-overview" class="min-w-0 space-y-5">
                        <section v-if="remarks.length" class="ui-panel p-5" aria-labelledby="case-remarks-title">
                            <h2 id="case-remarks-title" class="ui-section-title">Remarks from supervisor and monitors<span v-if="unreadRemarks.length" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">{{ unreadRemarks.length }} unread</span></h2>
                            <div class="mt-3"><RemarksThread :remarks="remarks" :case-id="Number(caseRecord.id)" :can-reply="isCaseManager" /></div>
                        </section>
                        <CaseProfileOverview :services="servicesProvided ?? []" :record="caseRecord" :relationships="relationships" :notes="notes" :visits="visits" :referrals="referrals" :status-history="timeline" @open="openSection($event)" />
                    </section>

                    <section v-show="activeSection === 'people'" id="case-panel-people" role="tabpanel" aria-labelledby="case-tab-people" class="scroll-mt-24 rounded-lg border border-slate-200 bg-white p-5">
                        <CaseClientContextPanel :record="caseRecord" :relationships="relationships" :consent-form="consentForm" :can-archive="canArchive" @archive="archiveCase" />
                        <section v-if="customAnswers.length" class="mt-5 rounded-xl border border-slate-200 p-4" aria-labelledby="custom-answers-title">
                            <h3 id="custom-answers-title" class="text-sm font-semibold text-slate-900">Additional information (intake)</h3>
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2"><div v-for="item in customAnswers" :key="item.key"><dt class="text-xs text-slate-600">{{ item.label }}</dt><dd class="text-sm text-slate-900">{{ item.value }}</dd></div></dl>
                        </section>
                    </section>

                    <section v-show="activeSection === 'kavat'" id="case-panel-kavat" role="tabpanel" aria-labelledby="case-tab-kavat" class="scroll-mt-24 min-w-0">
                        <KavatPanel :case-id="Number(caseRecord.id)" :definition="kavatDefinition" :records="kavat" :baseline="kavatBaseline" :can-edit="canEditForms" />
                    </section>

                    <section v-show="activeSection === 'forms'" id="case-panel-forms" role="tabpanel" aria-labelledby="case-tab-forms" class="scroll-mt-24 min-w-0">
                        <CaseFormsPanel ref="formsPanel" :case-id="Number(caseRecord.id)" :definition="formsDefinition" :forms="forms" :suggestions="formSuggestions" :can-edit="canEditForms"
                            :intake-at="caseRecord.intake_completed_at ?? caseRecord.opened_at ?? null" :note-count="notes.filter(note => note.record_type !== 'addendum').length"
                            :last-note-at="notes[0]?.occurred_at ?? notes[0]?.created_at ?? null" :plan-deadline="planDeadline ?? null" :referrals="referrals" :referral-targets="referralTargets"
                            :referral-transitions="referralTransitions ?? {}" :cpu-members="cpuMembers ?? []" :has-sharing-consent="Boolean(hasSharingConsent)" @open-notes="openSection('notes')" />
                    </section>

                    <section v-show="activeSection === 'monitoring'" id="case-panel-monitoring" role="tabpanel" aria-labelledby="case-tab-monitoring" class="scroll-mt-24 min-w-0">
                        <PlanMonitoringPanel :plan="forms.plan" :referrals="referrals" :deadline="planDeadline ?? null" :notes="notes" @open-plan="openPlanReferrals" @open-notes="openSection('notes')" />
                    </section>

                    <section v-show="activeSection === 'notes'" id="case-panel-notes" role="tabpanel" aria-labelledby="case-tab-notes" class="scroll-mt-24 min-w-0 space-y-5">
                        <div class="ui-panel p-4 sm:p-5"><CaseMonitoringPanel ref="monitoringPanel" :case-id="caseRecord.id" :active="activeSection === 'notes'" :visits="visits" :notes="notes" :tasks="tasks" :referrals="referrals" :services="servicesProvided ?? []" :referral-targets="referralTargets" :referral-transitions="referralTransitions ?? {}" :client-names="[caseRecord.first_name, caseRecord.last_name].filter(Boolean)" @refer="openPlanReferrals" /></div>
                        <CaseServicesPanel :case-id="Number(caseRecord.id)" :services="servicesProvided ?? []" @compose="composeNote">
                            <GrantsPanel :case-id="Number(caseRecord.id)" :grants="grants" :can-edit="canEditForms" />
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

                    <section v-show="activeSection === 'closure'" id="case-panel-closure" role="tabpanel" aria-labelledby="case-tab-closure" class="scroll-mt-24 min-w-0 space-y-5">
                        <section class="ui-panel p-5" aria-labelledby="closure-checklist-title">
                            <h2 id="closure-checklist-title" class="ui-section-title">Closure checklist</h2>
                            <p class="ui-muted mt-1">Before recommending closure, make sure the case record is complete. This is guidance; the closure record below still needs review and approval.</p>
                            <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                                <li v-for="item in closureChecklist" :key="item.text"><button type="button" class="flex w-full items-start gap-2 rounded-lg border p-3 text-left text-sm" :class="item.done ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-white'" @click="openSection(item.tab)"><component :is="item.done ? CheckCircle2 : Circle" class="mt-0.5 h-4 w-4 shrink-0" :class="item.done ? 'text-emerald-700' : 'text-slate-400'" aria-hidden="true" /><span><span class="block font-semibold text-slate-900">{{ item.text }}<span class="sr-only">{{ item.done ? ' - done' : ' - not yet' }}</span></span><span class="block text-xs text-slate-600">{{ item.detail }}</span></span></button></li>
                            </ul>
                        </section>
                        <AftercarePanel v-if="aftercare" :case-id="Number(caseRecord.id)" :status="caseRecord.status" :aftercare="aftercare" :can-edit="canEditAftercare" @reopen="scrollToClosure" />
                        <div id="case-closure-records"><CaseClosurePanel v-if="closureLoaded" :case-id="Number(caseRecord.id)" @updated="router.reload()" /></div>
                    </section>

                    <section v-show="activeSection === 'records'" id="case-panel-records" role="tabpanel" aria-labelledby="case-tab-records" class="scroll-mt-24 ui-panel overflow-hidden p-0">
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

                <aside v-show="['overview', 'records'].includes(activeSection)" class="min-w-0 space-y-5">
                    <section v-show="activeSection === 'overview'" id="case-panel-conference" class="ui-panel p-5" aria-labelledby="case-conference-title">
                        <h2 id="case-conference-title" class="flex items-center gap-2 ui-section-title"><Users class="h-4 w-4 text-brand-700" aria-hidden="true" />Case conference</h2>
                        <p class="ui-muted mt-1">Held when needed at any point of the case, outside the regular forms.</p>
                        <p v-if="conferenceSummary" class="mt-2 text-sm">Latest: {{ conferenceSummary.conference_type }}, {{ formatPh(conferenceSummary.finalized_at, 'date') }}. {{ conferenceActionSummary?.pending || 0 }} pending / {{ conferenceActionSummary?.overdue || 0 }} overdue actions<span v-if="conferenceSummary.next_review_at">. Next review {{ calendarDateLabel(conferenceSummary.next_review_at) }}</span>.</p>
                        <p v-else class="mt-2 text-sm text-slate-600">No case conference recorded.</p>
                        <Link :href="route('cases.conferences.index', caseRecord.id)" class="ui-button mt-3 min-h-10 text-sm">Open case conferences</Link>
                    </section>
                    <section v-show="activeSection === 'overview'" class="ui-panel p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="ui-section-title">Referrals</h2><button type="button" class="ui-link min-h-9 text-xs" @click="openPlanReferrals">Open</button></div>
                        <div v-if="referrals.length" class="mt-3 space-y-2">
                            <div v-for="item in referrals.slice(0, 5)" :key="item.id" class="rounded-md p-3 text-sm" :class="item.status === 'COMPLETED' ? 'bg-emerald-50 border border-emerald-200' : item.status === 'PENDING' ? 'bg-amber-50 border border-amber-200' : 'bg-slate-50 border border-slate-200'">
                                <p class="font-semibold">{{ item.referred_to || item.service_category }}</p>
                                <p class="mt-1 text-xs" :class="item.status === 'COMPLETED' ? 'text-emerald-700' : item.status === 'PENDING' ? 'text-amber-700' : 'text-slate-600'">{{ item.status.replaceAll('_', ' ') }}<span v-if="item.referred_at"> · Referred {{ formatPh(item.referred_at, 'date') }}</span></p>
                                <p v-if="referralDue(item)" class="mt-0.5 text-xs font-semibold" :class="referralDue(item)!.overdue ? 'text-rose-700' : 'text-slate-600'">{{ referralDue(item)!.label }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-600">No referrals recorded.</p>
                    </section>
                    <section id="case-open-tasks" tabindex="-1" aria-labelledby="case-tasks-title" v-show="activeSection === 'overview'" class="ui-panel p-5">
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
.overview-card-alert { background:#fef2f2; border:1px solid #fecaca; }
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
