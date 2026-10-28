<script setup lang="ts">
/**
 * The Kalinga forms in their order: Intake Sheet, Case Summary Report,
 * Social Case Study Report, Intervention Plan and Referrals, Progress Notes.
 * The case manager writes the summary, case study and plan here.
 */
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, Circle, CircleDashed, FileText, PenLine } from '@lucide/vue';
import { computed, ref } from 'vue';
import CaseFormEditor from '@/Components/Forms/CaseFormEditor.vue';
import { formHasContent, type CaseForm, type FormType, type FormsDefinition, type PlanRow } from '@/Casework/caseForms';
import { calendarDateLabel, formatPh } from '@/utils/philippineTime';

const props = defineProps<{
  caseId: number; definition: FormsDefinition; forms: Record<FormType, CaseForm>; suggestions: Record<string, string>; canEdit: boolean;
  intakeAt: string | null; noteCount: number; lastNoteAt: string | null; planSuggestions?: PlanRow[];
}>();
const emit = defineEmits<{ openNotes: [] }>();

const open = ref<FormType | null>(null);
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
        <div class="mt-2 flex flex-wrap gap-1.5">
          <button v-if="step.form" type="button" class="ui-button min-h-9 px-2.5 text-xs" :aria-expanded="open === step.form" @click="open = open === step.form ? null : step.form!"><PenLine class="h-3.5 w-3.5" aria-hidden="true" />{{ open === step.form ? 'Close' : canEdit ? (step.state === 'todo' ? 'Write' : 'Continue') : 'Read' }}</button>
          <button v-else-if="step.key === 'notes'" type="button" class="ui-button min-h-9 px-2.5 text-xs" @click="emit('openNotes')"><PenLine class="h-3.5 w-3.5" aria-hidden="true" />Notes</button>
          <Link :href="route('cases.reports.show', [caseId, step.key])" class="ui-button min-h-9 px-2.5 text-xs" :aria-label="`Print ${printTitle(step)}`"><FileText class="h-3.5 w-3.5" aria-hidden="true" />{{ step.key === 'plan' ? 'Print Intervention Plan' : 'Print' }}</Link>
        </div>
      </li>
    </ol>
    <CaseFormEditor v-if="open" :key="open" :case-id="caseId" :form="forms[open]" :definition="definition" :suggestions="suggestions" :can-edit="canEdit" :plan-suggestions="planSuggestions" />
    <p v-else class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-600">Choose a form above to write or read it.</p>
  </section>
</template>
