<script setup lang="ts">
/**
 * The Kalinga forms in their order: Intake Sheet, Case Summary Report,
 * Social Case Study Report, Intervention Plan and Referrals, Progress Notes.
 * The case manager writes the summary, case study and plan here; referrals
 * are conducted from the Intervention Plan (from a row, prefilled).
 */
import { Link, usePage } from '@inertiajs/vue3';
import { AlarmClock, CheckCircle2, Circle, CircleDashed, FileText, PenLine } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import CaseFormEditor from '@/Components/Forms/CaseFormEditor.vue';
import CaseReferralsPanel, { type CaseReferral } from '@/Components/Referrals/CaseReferralsPanel.vue';
import PlanReferralDialog from '@/Components/Referrals/PlanReferralDialog.vue';
import ReferralStatusForm from '@/Components/Referrals/ReferralStatusForm.vue';
import { formHasContent, type CaseForm, type FormType, type FormsDefinition, type PlanDeadline, type PlanRow } from '@/Casework/caseForms';
import type { CpuOption, ReferralKind } from '@/Casework/referralRecipients';
import type { ReferralTargets } from '@/utils/referralTracking';
import type { PageProps } from '@/types';
import { calendarDateLabel, formatPh } from '@/utils/philippineTime';

const props = withDefaults(defineProps<{
  caseId: number; definition: FormsDefinition; forms: Record<FormType, CaseForm>; suggestions: Record<string, string>; canEdit: boolean;
  intakeAt: string | null; noteCount: number; lastNoteAt: string | null;
  planDeadline?: PlanDeadline | null; referrals?: CaseReferral[]; referralTargets?: ReferralTargets; referralTransitions?: Record<string, string[]>;
  cpuMembers?: CpuOption[]; hasSharingConsent?: boolean;
}>(), { planDeadline: null, referrals: () => [], referralTargets: () => ({ response_days: 5, completion_days: 30 }), referralTransitions: () => ({}), cpuMembers: () => [], hasSharingConsent: false });
const emit = defineEmits<{ openNotes: [] }>();

const open = ref<FormType | null>(null);
const page = usePage<PageProps>();
const canRefer = computed(() => page.props.auth.permissions.includes('referrals.create'));
/** Open a form from elsewhere on the case page (for example the Overview referrals card). */
async function openForm(type: FormType) {
  open.value = type;
  await nextTick();
  document.getElementById(`form-${type}-title`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
defineExpose({ openForm });

/* Referrals with the Intervention Plan: conducted from a row (prefilled) or on their own. */
const referralRow = ref<PlanRow | null>(null);
const referralRowNumber = ref<number | null>(null);
const referralKind = ref<ReferralKind>('cpu');
const referralOpen = ref(false);
function conduct(row: PlanRow, number: number) { referralRow.value = { ...row }; referralRowNumber.value = number; referralKind.value = 'cpu'; referralOpen.value = true; }
function referWithoutRow(kind: ReferralKind) { referralRow.value = null; referralRowNumber.value = null; referralKind.value = kind; referralOpen.value = true; }
const planRows = computed(() => props.forms.plan.content.rows ?? []);
const planRowLabel = (key: string) => { const index = planRows.value.findIndex((row) => row.id === key); return index === -1 ? null : `Plan item ${index + 1}${planRows.value[index].problem ? `: ${planRows.value[index].problem}` : ''}`; };

/* The 7-day deadline of the Intervention Plan. */
const deadlineText = computed(() => {
  const deadline = props.planDeadline;
  if (!deadline) return null;
  if (deadline.submitted_at) return { tone: 'text-emerald-800', text: `Submitted ${calendarDateLabel(deadline.submitted_at)} - ${deadline.days_to_submit} ${deadline.days_to_submit === 1 ? 'day' : 'days'} after intake` };
  if (deadline.overdue) return { tone: 'text-rose-700', text: `Overdue - was due ${calendarDateLabel(deadline.due)}` };
  const left = deadline.days_left ?? 0;
  return { tone: left <= 2 ? 'text-amber-800' : 'text-slate-700', text: `Due ${calendarDateLabel(deadline.due)} - ${left === 0 ? 'today' : `${left} ${left === 1 ? 'day' : 'days'} left`}` };
});
type Step = { key: string; title: string; state: 'done' | 'progress' | 'todo'; detail: string; form?: FormType };
const formState = (form: CaseForm): Step['state'] => form.status === 'final' ? 'done' : formHasContent(form.type, form.content) ? 'progress' : 'todo';
const formDetail = (form: CaseForm) => form.status === 'final' ? `Finalized version ${form.versions[0]?.version}${form.versions[0] ? `, ${formatPh(form.versions[0].created_at, 'dateShort')}` : ''}`
  : formHasContent(form.type, form.content) ? `Draft${form.updated_at ? `, saved ${formatPh(form.updated_at, 'dateShort')}` : ''}${form.versions.length ? ` (version ${form.versions[0].version} finalized earlier)` : ''}` : 'Not started';
const steps = computed<Step[]>(() => Object.entries(props.definition.sequence).map(([key, title]) => {
  if (key === 'intake') return { key, title, state: props.intakeAt ? 'done' : 'progress', detail: props.intakeAt ? `Completed ${calendarDateLabel(props.intakeAt)}` : 'Intake still being completed' };
  if (key === 'notes') return { key, title, state: props.noteCount ? 'progress' : 'todo', detail: props.noteCount ? `${props.noteCount} ${props.noteCount === 1 ? 'note' : 'notes'}${props.lastNoteAt ? `, latest ${formatPh(props.lastNoteAt, 'dateShort')}` : ''}` : 'No progress notes yet' };
  const form = props.forms[key as FormType];
  return { key, title, state: formState(form), detail: formDetail(form), form: key as FormType };
}));
const printTitle = (step: Step) => step.key === 'plan' ? 'Kalinga Intervention Plan' : step.title;
const icon = (state: Step['state']) => (state === 'done' ? CheckCircle2 : state === 'progress' ? CircleDashed : Circle);
const tone = (state: Step['state']) => (state === 'done' ? 'text-emerald-700' : state === 'progress' ? 'text-amber-700' : 'text-slate-400');
</script>

<template>
  <section aria-labelledby="kalinga-forms-title" class="space-y-5">
    <header>
      <h2 id="kalinga-forms-title" class="ui-section-title text-lg">Kalinga forms</h2>
      <p class="ui-muted mt-1 max-w-3xl">Write the Case Summary Report and the Social Case Study Report in paragraphs, and the Intervention Plan as a table. The case study starts from what the Case Summary says. Your writing saves as you type. Finalize a form to keep a numbered copy; you can keep improving it after.</p>
    </header>
    <ol class="grid gap-3 md:grid-cols-5" aria-label="Order of the Kalinga forms">
      <li v-for="(step, index) in steps" :key="step.key" class="flex flex-col rounded-xl border bg-white p-3" :class="open === step.form && step.form ? 'border-brand-500 ring-2 ring-brand-200' : 'border-[var(--color-line)]'">
        <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><component :is="icon(step.state)" class="h-4 w-4" :class="tone(step.state)" aria-hidden="true" />Step {{ index + 1 }}</p>
        <h3 class="mt-1 text-sm font-semibold leading-5 text-slate-900">{{ step.title.replace('Kalinga ', '') }}</h3>
        <p class="mt-1 flex-1 text-xs text-slate-600">{{ step.detail }}</p>
        <p v-if="step.key === 'plan' && deadlineText" class="mt-1 flex items-start gap-1 text-xs font-semibold" :class="deadlineText.tone" data-plan-deadline><AlarmClock class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ deadlineText.text }}</p>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <button v-if="step.form" type="button" class="ui-button min-h-9 px-2.5 text-xs" :aria-expanded="open === step.form" @click="open = open === step.form ? null : step.form!"><PenLine class="h-3.5 w-3.5" aria-hidden="true" />{{ open === step.form ? 'Close' : canEdit ? (step.state === 'todo' ? 'Write' : 'Continue') : 'Read' }}</button>
          <button v-else-if="step.key === 'notes'" type="button" class="ui-button min-h-9 px-2.5 text-xs" @click="emit('openNotes')"><PenLine class="h-3.5 w-3.5" aria-hidden="true" />Notes</button>
          <Link :href="route('cases.reports.show', [caseId, step.key])" class="ui-button min-h-9 px-2.5 text-xs" :aria-label="`Print ${printTitle(step)}`"><FileText class="h-3.5 w-3.5" aria-hidden="true" />{{ step.key === 'plan' ? 'Print Intervention Plan' : 'Print' }}</Link>
        </div>
      </li>
    </ol>
    <CaseFormEditor v-if="open" :key="open" :case-id="caseId" :form="forms[open]" :definition="definition" :suggestions="suggestions" :can-edit="canEdit"
      :referrals="referrals" :can-refer="canRefer" @conduct="conduct" />
    <p v-else class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-600">Choose a form above to write or read it.</p>
    <section v-if="open === 'plan'" aria-labelledby="plan-referrals-title" class="space-y-3">
      <div>
        <h3 id="plan-referrals-title" class="ui-section-title">Referrals</h3>
        <p class="ui-muted mt-1 max-w-3xl">Conduct a referral from a plan row (it starts from the row) or on its own below. Each referral is also kept in the progress notes, with every status update.</p>
      </div>
      <CaseReferralsPanel :case-id="caseId" :referrals="referrals" :referral-targets="referralTargets" :plan-row-label="planRowLabel" @refer="referWithoutRow">
        <template #status-form="{ referral }"><ReferralStatusForm :referral-id="referral.id" :status="referral.status" :allowed-statuses="referralTransitions[referral.status] ?? []" /></template>
      </CaseReferralsPanel>
    </section>
    <PlanReferralDialog :show="referralOpen" :case-id="caseId" :row="referralRow" :row-number="referralRowNumber" :kind="referralKind" :cpu-members="cpuMembers"
      :has-sharing-consent="hasSharingConsent" :referral-targets="referralTargets" @close="referralOpen = false" />
  </section>
</template>
