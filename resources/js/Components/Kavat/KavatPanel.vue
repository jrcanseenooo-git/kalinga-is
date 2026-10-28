<script setup lang="ts">
/**
 * Kalinga Vulnerability Assessment Tool.
 *
 * The KaVAT is administered three times. The Baseline is done before intake
 * in the project's separate tool (a Google Sheet); here only its result is
 * recorded. The Midline and the Endline are answered in Kalinga: the case
 * manager reads each statement to the child and taps the answer; answers save
 * as they go and the score updates live. Finalizing locks it; "Correct"
 * reopens a finalized KaVAT to fix answers (the reason is recorded). The
 * history shows the three results side by side.
 */
import { askConfirm } from '@/utils/confirmDialog';
import axios from 'axios';
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowDownToLine, CheckCircle2, ClipboardList, Pencil, ShieldCheck, Sparkles, Trash2, TrendingDown, TrendingUp, Undo2 } from '@lucide/vue';
import TextSizeControl from '@/Components/UI/TextSizeControl.vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import FormField from '@/Components/UI/FormField.vue';
import ConsentPhoto from '@/Components/Intake/ConsentPhoto.vue';
import { emptyKavatAnswers, priorityLabel, scoreKavat, type KavatAnswers, type KavatBaseline, type KavatDefinition, type KavatRecord } from '@/Casework/kavat';
import { calendarDateLabel } from '@/utils/philippineTime';

const props = defineProps<{
  caseId: number; definition: KavatDefinition; records: KavatRecord[]; canEdit: boolean; baseline?: KavatBaseline | null;
  /** Field mode: answers save into the encrypted device copy; finalizing waits until online. */
  saver?: (answers: KavatAnswers, notes: string | null) => Promise<boolean>;
}>();
const emit = defineEmits<{ start: [value: { occasion: string; administered_at: string }] }>();
const offline = computed(() => Boolean(props.saver));

const draft = computed(() => props.records.find((item) => item.status === 'draft') ?? null);
const finals = computed(() => props.records.filter((item) => item.status === 'final'));
const viewing = ref<number | null>(null);
const viewed = computed(() => finals.value.find((item) => item.id === viewing.value) ?? null);

const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const startOccasions = computed(() => (props.definition.startOccasions ?? ['midline', 'endline']).map((code) => [code, props.definition.occasions[code] ?? code] as const));
const recordedOccasions = computed(() => new Set(props.records.map((item) => item.occasion)));
/** Smart default: the next KaVAT not yet recorded (Midline, then Endline). */
const openOccasions = computed(() => startOccasions.value.filter(([code]) => !recordedOccasions.value.has(code)));
const start = useForm({ occasion: openOccasions.value[0]?.[0] ?? 'midline', administered_at: today });
watch(openOccasions, (list) => { if (list.length && !list.some(([code]) => code === start.occasion)) start.occasion = list[0][0]; });
const startTitle = computed(() => `Start the ${props.definition.occasions[start.occasion] ?? 'KaVAT'}`);
const begin = () => offline.value ? emit('start', { occasion: start.occasion, administered_at: start.administered_at }) : start.post(route('cases.kavat.store', props.caseId), { preserveScroll: true });

/* Baseline result from the separate tool. */
const editingBaseline = ref(!props.baseline && props.canEdit && !offline.value);
const baselineForm = useForm({ administered_at: props.baseline?.administered_at ?? '', empowerment_rate: props.baseline?.empowerment_rate ?? '' as number | '', safety_rate: props.baseline?.safety_rate ?? '' as number | '', notes: props.baseline?.notes ?? '', photo: '', remove_photo: false });
const rate = (value: number | '') => value === '' || Number.isNaN(Number(value)) ? null : Math.min(100, Math.max(0, Number(value)));
const baselinePreview = computed(() => {
  const empowerment = rate(baselineForm.empowerment_rate);
  const safety = rate(baselineForm.safety_rate);
  if (empowerment === null || safety === null) return null;
  const final = Math.round((empowerment * 0.4 + safety * 0.6) * 100) / 100;
  return { final, priority: final < props.definition.threshold ? 'high' : 'standard' };
});
function saveBaseline() {
  baselineForm.post(route('cases.kavat.baseline', props.caseId), { preserveScroll: true, onSuccess: () => { editingBaseline.value = false; baselineForm.photo = ''; } });
}
watch(() => props.baseline, (value) => { if (value) { baselineForm.defaults({ administered_at: value.administered_at, empowerment_rate: value.empowerment_rate, safety_rate: value.safety_rate, notes: value.notes ?? '', photo: '', remove_photo: false }); baselineForm.reset(); } });

/* Answering. */
const answers = reactive<KavatAnswers>(emptyKavatAnswers());
const notes = ref('');
const revision = ref(0);
const state = ref<'idle' | 'saving' | 'saved' | 'error' | 'conflict'>('idle');
const conflict = ref<KavatAnswers | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let loading = false;

function load(record: KavatRecord | null) {
  loading = true;
  const source = record?.answers ?? emptyKavatAnswers();
  answers.part1 = { ...(source.part1 ?? {}) };
  answers.part2 = { ...(source.part2 ?? {}) };
  answers.details = { ...(source.details ?? {}) };
  notes.value = record?.notes ?? '';
  revision.value = record?.revision ?? 0;
  state.value = 'idle';
  conflict.value = null;
  queueMicrotask(() => { loading = false; });
}
watch(() => draft.value?.id, () => load(draft.value), { immediate: true });

const score = computed(() => scoreKavat(props.definition, answers));
const progress = computed(() => Math.round((score.value.answered / score.value.questions) * 100));
const part1Answered = computed(() => Object.keys(answers.part1).length);
const part2Answered = computed(() => Object.keys(answers.part2).length);

async function save() {
  if (!draft.value || state.value === 'conflict') return;
  state.value = 'saving';
  if (props.saver) {
    state.value = (await props.saver(JSON.parse(JSON.stringify(answers)), notes.value || null)) ? 'saved' : 'error';
    return;
  }
  try {
    const { data } = await axios.put(route('cases.kavat.save', [props.caseId, draft.value.id]), { revision: revision.value, answers, notes: notes.value || null });
    revision.value = data.revision;
    state.value = 'saved';
  } catch (error: any) {
    if (error?.response?.status === 409) { state.value = 'conflict'; conflict.value = error.response.data.answers; revision.value = error.response.data.revision; }
    else state.value = 'error';
  }
}
function schedule() { if (loading || !props.canEdit) return; if (timer) clearTimeout(timer); state.value = 'idle'; timer = setTimeout(save, 1200); }
watch(answers, schedule, { deep: true });
watch(notes, schedule);
onBeforeUnmount(() => { if (timer) { clearTimeout(timer); void save(); } });

function useSaved() { if (!conflict.value) return; loading = true; answers.part1 = { ...conflict.value.part1 }; answers.part2 = { ...conflict.value.part2 }; answers.details = { ...(conflict.value.details ?? {}) }; conflict.value = null; state.value = 'saved'; queueMicrotask(() => { loading = false; }); }
async function keepMine() { conflict.value = null; state.value = 'idle'; await save(); }

/** Smart help: jump to the first statement or question still unanswered. */
async function nextUnanswered() {
  const statement = props.definition.domains.flatMap((domain) => domain.statements).find((item) => !answers.part1[String(item.number)]);
  const question = statement ? null : props.definition.sections.flatMap((section) => section.questions).find((item) => !answers.part2[item.key]);
  const id = statement ? `kavat-item-p1-${statement.number}` : question ? `kavat-item-p2-${question.key}` : null;
  if (!id) return;
  await nextTick();
  const element = document.getElementById(id);
  element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  element?.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true });
}

const finalizing = ref(false);
const correctionReason = ref('');
const finalizeError = ref('');
const correcting = computed(() => Boolean(draft.value?.correcting));
async function finalize() {
  if (!draft.value) return;
  finalizeError.value = '';
  if (correcting.value && correctionReason.value.trim().length < 5) {
    finalizeError.value = 'Say why the answers were corrected (at least 5 characters).';
    document.getElementById('kavat-correction-reason')?.focus();
    return;
  }
  if (timer) { clearTimeout(timer); timer = null; }
  await save();
  if (state.value !== 'saved') return;
  finalizing.value = true;
  router.post(route('cases.kavat.finalize', [props.caseId, draft.value.id]), correcting.value ? { correction_reason: correctionReason.value.trim() } : {}, {
    preserveScroll: true,
    onSuccess: () => { correctionReason.value = ''; },
    onError: (errors) => { finalizeError.value = errors.correction_reason ?? Object.values(errors)[0] ?? ''; },
    onFinish: () => { finalizing.value = false; },
  });
}
async function discard() {
  if (!draft.value) return;
  const ok = correcting.value
    ? await askConfirm({ title: 'Cancel the correction?', message: 'The changes are dropped and the finalized answers are kept.', confirmLabel: 'Cancel the correction' })
    : await askConfirm({ title: 'Discard this KaVAT?', message: 'The answers recorded so far are deleted. This cannot be undone.', confirmLabel: 'Discard', tone: 'danger' });
  if (ok) router.delete(route('cases.kavat.destroy', [props.caseId, draft.value.id]), { preserveScroll: true });
}
/** Reopen a finalized KaVAT to correct answers recorded by mistake. */
async function correct(record: KavatRecord) {
  if (!(await askConfirm({ title: `Correct the ${occasionLabel(record)}?`, message: 'The answers open for editing. The current result is kept until you finalize the correction with the reason, or cancel it.', confirmLabel: 'Correct the answers' }))) return;
  router.post(route('cases.kavat.correct', [props.caseId, record.id]), {}, { preserveScroll: true, onSuccess: () => document.getElementById('kavat-title')?.scrollIntoView({ behavior: 'smooth' }) });
}

const answerChoices = computed(() => Object.entries(props.definition.answers) as [string, string][]);
const levelTone = (level: number | null) => level === 0 ? 'border-rose-300 bg-rose-50 text-rose-900' : level === 1 ? 'border-amber-300 bg-amber-50 text-amber-950' : level === 2 ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600';
const priorityTone = (priority: string | null | undefined) => priority === 'high' ? 'bg-rose-100 text-rose-900 border-rose-300' : priority === 'standard' ? 'bg-emerald-100 text-emerald-900 border-emerald-300' : 'bg-slate-100 text-slate-700 border-slate-300';
const saveLabel = computed(() => ({ idle: 'Changes not yet saved', saving: 'Saving...', saved: offline.value ? 'All answers saved on this device' : 'All answers saved', error: offline.value ? 'Not saved on this device - free storage and retry' : 'Not saved - check your connection', conflict: 'Changed on another device' }[state.value]));
const occasionLabel = (record: { occasion: string }) => props.definition.occasions[record.occasion] ?? record.occasion;

/* History: the three KaVATs side by side. */
type Slot = { key: string; label: string; date: string | null; empowerment: number | null; safety: number | null; final: number | null; priority: string | null; record: KavatRecord | null; draft: boolean };
const slots = computed<Slot[]>(() => [
  { key: 'baseline', label: 'Baseline', date: props.baseline?.administered_at ?? null, empowerment: props.baseline ? Number(props.baseline.empowerment_rate) : null, safety: props.baseline ? Number(props.baseline.safety_rate) : null,
    final: props.baseline ? Number(props.baseline.final_score) : null, priority: props.baseline?.priority ?? null, record: null, draft: false },
  ...startOccasions.value.map(([code, label]) => {
    const record = props.records.find((item) => item.occasion === code) ?? null;
    const shown = record && (record.status === 'final' || record.correcting);
    return { key: code, label, date: record?.administered_at ?? null, empowerment: shown && !record.correcting ? Number(record.score.empowerment_rate) : null, safety: shown && !record.correcting ? Number(record.score.safety_rate) : null,
      final: shown ? (record.correcting ? record.previous_final_score ?? null : Number(record.score.final_score)) : null, priority: shown && !record.correcting ? record.score.priority : null, record, draft: record?.status === 'draft' };
  }),
  // Older KaVATs recorded before the three-administration rule (kept for the record).
  ...finals.value.filter((item) => !startOccasions.value.some(([code]) => code === item.occasion)).map((item) => ({ key: `legacy-${item.id}`, label: occasionLabel(item), date: item.administered_at,
    empowerment: Number(item.score.empowerment_rate), safety: Number(item.score.safety_rate), final: Number(item.score.final_score), priority: item.score.priority, record: item, draft: false })),
]);
const delta = (slot: Slot) => slot.key === 'baseline' || slot.final === null || !props.baseline ? null : Math.round((slot.final - Number(props.baseline.final_score)) * 100) / 100;
const latest = computed(() => [...slots.value].reverse().find((slot) => slot.final !== null) ?? null);
const change = computed(() => latest.value ? delta(latest.value) : null);
const allDone = computed(() => !openOccasions.value.length);
</script>

<template>
  <section aria-labelledby="kavat-title" class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 id="kavat-title" class="ui-section-title text-lg">Kalinga Vulnerability Assessment Tool (KaVAT)</h2>
        <p class="ui-muted mt-1 max-w-3xl">The KaVAT is done three times: the Baseline before intake (in the project's screening tool - record its result below), the Midline and the Endline (answered here). Final protection score = empowerment x 40% + safety x 60%; below {{ definition.threshold }} is High Priority.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span v-if="latest" class="rounded-full border px-3 py-1 text-xs font-semibold" :class="priorityTone(latest.priority)">Latest ({{ latest.label }}): {{ latest.final }} - {{ priorityLabel(latest.priority) }}</span>
        <TextSizeControl v-if="draft" />
      </div>
    </header>

    <!-- Baseline result from the separate tool -->
    <section class="ui-panel p-4 sm:p-5" aria-labelledby="kavat-baseline-title">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700"><ClipboardList class="h-5 w-5" aria-hidden="true" /></span>
          <div class="min-w-0">
            <h3 id="kavat-baseline-title" class="ui-section-title">Baseline KaVAT (before intake)</h3>
            <p class="ui-muted">From the project's screening tool. Only the result is recorded here.</p>
          </div>
        </div>
        <button v-if="baseline && canEdit && !offline && !editingBaseline" type="button" class="ui-button" @click="editingBaseline = true"><Pencil class="h-4 w-4" aria-hidden="true" />Correct</button>
      </div>
      <dl v-if="baseline && !editingBaseline" class="mt-4 grid gap-2 text-sm sm:grid-cols-4">
        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-600">Date</dt><dd class="font-semibold">{{ calendarDateLabel(baseline.administered_at) }}</dd></div>
        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-600">Empowerment</dt><dd class="font-semibold">{{ baseline.empowerment_rate }}%</dd></div>
        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-600">Safety</dt><dd class="font-semibold">{{ baseline.safety_rate }}%</dd></div>
        <div class="rounded-lg border p-3" :class="priorityTone(baseline.priority)"><dt class="text-xs">Final protection score</dt><dd class="font-bold">{{ baseline.final_score }} - {{ priorityLabel(baseline.priority) }}</dd></div>
        <p v-if="baseline.notes" class="whitespace-pre-wrap text-sm text-slate-700 sm:col-span-4"><b>Notes:</b> {{ baseline.notes }}</p>
        <p v-if="baseline.document_id" class="sm:col-span-4"><a :href="route('cases.documents.download', [caseId, baseline.document_id])" target="_blank" rel="noopener" class="ui-link">View the photo of the baseline sheet</a></p>
      </dl>
      <p v-else-if="!baseline && (!canEdit || offline)" class="mt-3 text-sm text-slate-600">No baseline result recorded yet.</p>
      <form v-if="editingBaseline" class="mt-4 space-y-4" data-skip-generic-confirm="true" @submit.prevent="saveBaseline">
        <div class="grid gap-4 sm:grid-cols-3">
          <FormField id="kavat-baseline-date" label="Date administered" :error="baselineForm.errors.administered_at" required>
            <input id="kavat-baseline-date" v-model="baselineForm.administered_at" type="date" :max="today" class="ui-field min-h-12 text-base" required>
          </FormField>
          <FormField id="kavat-baseline-empowerment" label="Empowerment rate (%)" :error="baselineForm.errors.empowerment_rate" hint="From the sheet, 0 to 100." required>
            <input id="kavat-baseline-empowerment" v-model="baselineForm.empowerment_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" class="ui-field min-h-12 text-base" required>
          </FormField>
          <FormField id="kavat-baseline-safety" label="Safety rate (%)" :error="baselineForm.errors.safety_rate" hint="From the sheet, 0 to 100." required>
            <input id="kavat-baseline-safety" v-model="baselineForm.safety_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" class="ui-field min-h-12 text-base" required>
          </FormField>
        </div>
        <p v-if="baselinePreview" class="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm" :class="priorityTone(baselinePreview.priority)" role="status">
          <Sparkles class="h-4 w-4" aria-hidden="true" />Final protection score <b>{{ baselinePreview.final }}</b> - {{ priorityLabel(baselinePreview.priority) }}. Check that it matches the sheet.
        </p>
        <FormField id="kavat-baseline-notes" label="Notes (optional)" :error="baselineForm.errors.notes"><textarea id="kavat-baseline-notes" v-model="baselineForm.notes" rows="2" maxlength="2000" class="ui-field"></textarea></FormField>
        <ConsentPhoto v-model:photo="baselineForm.photo" :error="baselineForm.errors.photo" id-prefix="kavat-baseline" heading="Photo or screenshot of the baseline sheet" description="Optional. Kept in the case folder, seen only by the case team." :with-type="false" />
        <label v-if="baseline?.document_id && !baselineForm.photo" class="flex min-h-11 items-center gap-2 text-sm"><input v-model="baselineForm.remove_photo" type="checkbox" class="h-5 w-5 rounded border-slate-400">Remove the current photo</label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="ui-button-primary min-h-12 px-5 text-base" :disabled="baselineForm.processing">Save the baseline result</button>
          <button v-if="baseline" type="button" class="ui-button min-h-12" :disabled="baselineForm.processing" @click="baselineForm.reset(); editingBaseline = false">Cancel</button>
        </div>
      </form>
    </section>

    <!-- Start the Midline or the Endline -->
    <form v-if="!draft && canEdit && !allDone" class="ui-panel space-y-3 p-4 sm:p-5" data-skip-generic-confirm="true" @submit.prevent="begin">
      <p class="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-950" role="status">
        <Sparkles class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        {{ openOccasions.length === 2 ? 'Next: the Midline KaVAT. The Endline is done when the case is ready for closure.' : 'Next: the Endline KaVAT, done when the case is ready for closure.' }}
      </p>
      <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <FormField id="kavat-occasion" label="KaVAT" :error="start.errors.occasion" required>
          <select id="kavat-occasion" v-model="start.occasion" class="ui-field min-h-12 text-base"><option v-for="[code, label] in openOccasions" :key="code" :value="code">{{ label }}</option></select>
        </FormField>
        <FormField id="kavat-date" label="Date administered" :error="start.errors.administered_at" required>
          <input id="kavat-date" v-model="start.administered_at" type="date" :max="today" class="ui-field min-h-12 text-base" required>
        </FormField>
        <button type="submit" class="ui-button-primary min-h-12 px-5 text-base" :disabled="start.processing"><ShieldCheck class="h-5 w-5" aria-hidden="true" />{{ startTitle }}</button>
      </div>
    </form>
    <p v-else-if="!draft && canEdit && allDone" class="flex items-start gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900" role="status"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />All three KaVATs are recorded. Use Correct on a result below to fix answers.</p>

    <div v-if="draft" class="kalinga-form grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
      <div class="min-w-0 space-y-5">
        <p v-if="correcting" class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950" role="status"><Pencil class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Correcting the {{ occasionLabel(draft) }}. The finalized result ({{ draft.previous_final_score }}) is kept until you finalize this correction with the reason.</p>
        <div v-if="state === 'conflict'" role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
          <p class="font-semibold">These answers were changed on another device or tab.</p>
          <p class="mt-1">Choose which answers to keep.</p>
          <div class="mt-3 flex flex-wrap gap-2"><button type="button" class="ui-button" @click="useSaved">Use the saved answers</button><button type="button" class="ui-button-primary" @click="keepMine">Keep my answers</button></div>
        </div>

        <fieldset :disabled="!canEdit" class="ui-panel overflow-hidden p-0">
          <legend class="sr-only">Part 1</legend>
          <div class="border-b border-[var(--color-line)] bg-brand-50 px-4 py-3"><h3 class="ui-section-title">Part 1 - Empowerment (25 statements)</h3><p class="mt-1 text-sm text-slate-600">Oo / Hindi Sigurado / Hindi.</p></div>
          <section v-for="domain in definition.domains" :key="domain.code" class="border-b border-[var(--color-line)] px-4 py-4 last:border-b-0" :aria-labelledby="`kavat-domain-${domain.code}`">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h4 :id="`kavat-domain-${domain.code}`" class="font-semibold text-slate-900">Domain {{ domain.code }}. {{ domain.title }}</h4>
              <span class="text-sm text-slate-600">{{ score.domains.find((d) => d.code === domain.code)?.score }} / {{ domain.statements.length * 5 }}</span>
            </div>
            <ol class="mt-3 space-y-3">
              <li v-for="statement in domain.statements" :id="`kavat-item-p1-${statement.number}`" :key="statement.number" class="scroll-mt-28 rounded-xl border p-3 sm:p-4" :class="answers.part1[statement.number] ? 'border-slate-200' : 'border-slate-300 bg-white'">
                <p class="text-base leading-7 text-slate-900"><b>{{ statement.number }}.</b> {{ statement.text }}</p>
                <div role="radiogroup" :aria-label="`Answer to statement ${statement.number}`" class="mt-3 grid grid-cols-3 gap-2">
                  <label v-for="[code, label] in answerChoices" :key="code" class="kavat-choice" :class="answers.part1[statement.number] === code ? 'is-on' : ''">
                    <input v-model="answers.part1[statement.number]" type="radio" :name="`kavat-p1-${statement.number}`" :value="code" class="sr-only">{{ label }}
                  </label>
                </div>
              </li>
            </ol>
          </section>
        </fieldset>

        <fieldset :disabled="!canEdit" class="ui-panel overflow-hidden p-0">
          <legend class="sr-only">Part 2</legend>
          <div class="border-b border-[var(--color-line)] bg-brand-50 px-4 py-3"><h3 class="ui-section-title">Part 2 - Objective risk (9 sections)</h3><p class="mt-1 text-sm text-slate-600">"Refused to Answer" is a red flag. In the sensitive sections it scores the highest risk.</p></div>
          <section v-for="section in definition.sections" :key="section.number" class="border-b border-[var(--color-line)] px-4 py-4 last:border-b-0">
            <h4 class="font-semibold text-slate-900">{{ section.number }}. {{ section.title }} <span v-if="section.sensitive" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">sensitive - ask with care</span></h4>
            <div v-for="question in section.questions" :id="`kavat-item-p2-${question.key}`" :key="question.key" class="mt-3 scroll-mt-28">
              <p class="text-base leading-7 text-slate-800">{{ question.text }}</p>
              <div role="radiogroup" :aria-label="question.text" class="mt-2 grid gap-2 sm:grid-cols-2">
                <label v-for="option in question.options" :key="option.code" class="kavat-choice justify-start text-left" :class="answers.part2[question.key] === option.code ? 'is-on' : ''">
                  <input v-model="answers.part2[question.key]" type="radio" :name="`kavat-p2-${question.key}`" :value="option.code" class="sr-only">
                  <span class="min-w-0 flex-1">{{ option.label }}</span>
                </label>
              </div>
              <template v-for="option in question.options" :key="`d-${option.code}`">
                <div v-if="option.detail && answers.part2[question.key] === option.code" class="mt-2 max-w-md">
                  <label :for="`kavat-detail-${question.key}`" class="text-sm font-medium text-slate-700">{{ option.detail }}</label>
                  <input :id="`kavat-detail-${question.key}`" v-model="answers.details![question.key]" type="text" maxlength="255" class="ui-field mt-1 min-h-12 text-base">
                </div>
              </template>
            </div>
          </section>
        </fieldset>

        <div class="ui-panel p-4">
          <FormField id="kavat-notes" label="Case manager's notes (optional)" hint="Observations while administering, for example the child's reaction or why a question was refused.">
            <textarea id="kavat-notes" v-model="notes" rows="3" maxlength="5000" class="ui-field text-base" :disabled="!canEdit"></textarea>
          </FormField>
        </div>
      </div>

      <aside class="space-y-4 xl:sticky xl:top-24" aria-label="Live KaVAT score">
        <div class="ui-panel p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ occasionLabel(draft) }} - {{ calendarDateLabel(draft.administered_at) }}</p>
          <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Answered"><div class="h-full bg-brand-600 transition-all" :style="{ width: progress + '%' }"></div></div>
          <p class="mt-1 text-sm text-slate-600">{{ score.answered }} of {{ score.questions }} answered</p>
          <button v-if="!score.complete && canEdit" type="button" class="ui-button mt-2 w-full" @click="nextUnanswered"><ArrowDownToLine class="h-4 w-4" aria-hidden="true" />Go to the next unanswered</button>
          <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Part 1</dt><dd class="font-semibold">{{ score.part1 }} / {{ definition.part1Max }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Part 2</dt><dd class="font-semibold">{{ score.part2 }} / {{ definition.part2Max }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Empowerment</dt><dd class="font-semibold">{{ part1Answered ? `${score.empowerment_rate}%` : '-' }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Safety</dt><dd class="font-semibold">{{ part2Answered ? `${score.safety_rate}%` : '-' }}</dd></div>
          </dl>
          <div class="mt-3 rounded-lg border p-3 text-center" :class="priorityTone(score.priority)">
            <p class="text-xs">Final protection score</p>
            <p class="text-2xl font-bold">{{ score.final_score ?? '-' }}</p>
            <p class="text-xs font-semibold">{{ priorityLabel(score.priority) }}</p>
            <p v-if="baseline && score.final_score !== null" class="mt-1 text-xs">Baseline {{ baseline.final_score }} ({{ score.final_score - baseline.final_score >= 0 ? '+' : '' }}{{ Math.round((score.final_score - baseline.final_score) * 100) / 100 }})</p>
          </div>
          <ul v-if="score.red_flags.length" class="mt-3 space-y-1 text-xs text-rose-900">
            <li v-for="flag in score.red_flags" :key="flag.question" class="flex items-start gap-1"><AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Refused: {{ flag.title }}</li>
          </ul>
          <p class="mt-3 text-xs" :class="state === 'error' || state === 'conflict' ? 'text-rose-700' : 'text-slate-600'" role="status">{{ saveLabel }}</p>
          <p v-if="offline" class="mt-3 rounded-lg bg-sky-50 p-2 text-xs text-sky-900">Saved on this device and sent when you sync. Finalize it from the case page when you are back online.</p>
          <FormField v-if="correcting && canEdit && !offline" id="kavat-correction-reason" label="Reason for the correction" class="mt-3" :error="finalizeError" required>
            <textarea id="kavat-correction-reason" v-model="correctionReason" rows="2" maxlength="1000" class="ui-field text-base" placeholder="For example: answer to statement 7 was recorded wrongly"></textarea>
          </FormField>
          <p v-else-if="finalizeError" class="mt-3 text-sm text-rose-700" role="alert">{{ finalizeError }}</p>
          <div v-if="canEdit && !offline" class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="ui-button-primary min-h-12 flex-1" :disabled="!score.complete || finalizing || state === 'conflict'" @click="finalize"><CheckCircle2 class="h-4 w-4" aria-hidden="true" />{{ correcting ? 'Finalize the correction' : 'Finalize' }}</button>
            <button type="button" class="ui-button min-h-12" @click="discard"><component :is="correcting ? Undo2 : Trash2" class="h-4 w-4" aria-hidden="true" /><span class="sr-only sm:not-sr-only">{{ correcting ? 'Cancel' : 'Discard' }}</span></button>
          </div>
          <p v-if="canEdit && !offline && !score.complete" class="mt-2 text-xs text-slate-600">Answer every statement and question to finalize.</p>
        </div>
        <div class="ui-panel p-4">
          <h3 class="text-sm font-semibold">Domain results</h3>
          <ul class="mt-2 space-y-2">
            <li v-for="domain in score.domains" :key="domain.code" class="rounded-lg border p-2 text-xs" :class="levelTone(domain.level)">
              <p class="font-semibold">{{ domain.code }}. {{ domain.title }} - {{ domain.score }}/{{ domain.max }}</p>
              <p v-if="domain.band">{{ domain.band }}: {{ domain.interpretation }}</p>
            </li>
          </ul>
        </div>
      </aside>
    </div>

    <section class="ui-panel overflow-hidden p-0" aria-labelledby="kavat-history-title">
      <header class="ui-panel-header">
        <h3 id="kavat-history-title" class="ui-section-title">KaVAT results</h3>
        <span v-if="change !== null" class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold" :class="change >= 0 ? 'bg-emerald-100 text-emerald-900' : 'bg-rose-100 text-rose-900'">
          <component :is="change >= 0 ? TrendingUp : TrendingDown" class="h-3.5 w-3.5" aria-hidden="true" />{{ change >= 0 ? '+' : '' }}{{ change }} since the baseline
        </span>
      </header>
      <ol class="grid gap-3 p-4 md:grid-cols-3">
        <li v-for="slot in slots" :key="slot.key" class="flex min-w-0 flex-col rounded-xl border p-4" :class="slot.final === null ? 'border-dashed border-slate-300 bg-slate-50/60' : 'border-slate-200 bg-white'" :data-kavat-slot="slot.key">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <h4 class="font-bold text-slate-900">{{ slot.label }}</h4>
              <p class="text-sm text-slate-600">{{ slot.date ? calendarDateLabel(slot.date) : (slot.key === 'baseline' ? 'Before intake' : 'Not yet done') }}</p>
            </div>
            <span v-if="slot.draft" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-950">{{ slot.record?.correcting ? 'Being corrected' : 'In progress' }}</span>
          </div>
          <template v-if="slot.final !== null">
            <div class="mt-3 rounded-lg border p-3 text-center" :class="priorityTone(slot.priority)">
              <p class="text-xs">Final protection score</p>
              <p class="text-3xl font-bold leading-tight">{{ slot.final }}</p>
              <p v-if="slot.priority" class="text-xs font-semibold">{{ priorityLabel(slot.priority) }}</p>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full" :class="slot.priority === 'high' ? 'bg-rose-400' : 'bg-emerald-500'" :style="{ width: `${Math.min(100, slot.final)}%` }"></div></div>
            <dl v-if="slot.empowerment !== null" class="mt-3 grid grid-cols-2 gap-2 text-sm">
              <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Empowerment</dt><dd class="font-semibold">{{ slot.empowerment }}%</dd></div>
              <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Safety</dt><dd class="font-semibold">{{ slot.safety }}%</dd></div>
            </dl>
            <p v-if="delta(slot) !== null" class="mt-2 inline-flex items-center gap-1 text-sm font-semibold" :class="(delta(slot) ?? 0) >= 0 ? 'text-emerald-800' : 'text-rose-800'">
              <component :is="(delta(slot) ?? 0) >= 0 ? TrendingUp : TrendingDown" class="h-4 w-4" aria-hidden="true" />{{ (delta(slot) ?? 0) >= 0 ? '+' : '' }}{{ delta(slot) }} vs the baseline
            </p>
            <p v-if="slot.record?.corrected_at" class="mt-2 text-xs text-slate-600">Corrected {{ calendarDateLabel(slot.record.corrected_at) }}<span v-if="slot.record.correction_reason">: {{ slot.record.correction_reason }}</span></p>
            <p v-if="slot.record?.administered_by" class="mt-1 text-xs text-slate-600">By {{ slot.record.administered_by }}</p>
            <div v-if="slot.record && slot.record.status === 'final'" class="mt-auto flex flex-wrap gap-2 pt-3">
              <button type="button" class="ui-button min-h-11 px-3 text-sm" :aria-expanded="viewing === slot.record.id" @click="viewing = viewing === slot.record.id ? null : slot.record.id">{{ viewing === slot.record.id ? 'Hide domains' : 'Domains' }}</button>
              <button v-if="canEdit && !offline && !draft" type="button" class="ui-button min-h-11 px-3 text-sm" :aria-label="`Correct the ${slot.label}`" @click="correct(slot.record)"><Pencil class="h-4 w-4" aria-hidden="true" />Correct</button>
            </div>
          </template>
          <p v-else class="mt-3 text-sm text-slate-600">{{ slot.key === 'baseline' ? 'Record the baseline result above.' : slot.draft ? 'Being answered above.' : slot.key === 'endline' ? 'Done when the case is ready for closure.' : 'Done during case management.' }}</p>
        </li>
      </ol>
      <div v-if="viewed" class="grid gap-2 border-t border-[var(--color-line)] p-4 sm:grid-cols-2">
        <h4 class="font-semibold sm:col-span-2">{{ occasionLabel(viewed) }} - domain results</h4>
        <div v-for="domain in viewed.score.domains" :key="domain.code" class="rounded-lg border p-2 text-xs" :class="levelTone(domain.level)"><p class="font-semibold">{{ domain.code }}. {{ domain.title }} - {{ domain.score }}/{{ domain.max }}</p><p>{{ domain.band }}: {{ domain.interpretation }}</p></div>
        <p v-if="viewed.notes" class="whitespace-pre-wrap text-sm text-slate-700 sm:col-span-2"><b>Notes:</b> {{ viewed.notes }}</p>
      </div>
    </section>
  </section>
</template>

<style scoped>
.kavat-choice { display: flex; min-height: 3rem; cursor: pointer; align-items: center; justify-content: center; gap: .5rem; border: 2px solid var(--color-slate-300); border-radius: .75rem; background: #fff; padding: .5rem .75rem; text-align: center; font-size: 1rem; line-height: 1.35rem; color: var(--color-slate-800); transition: background-color .15s, border-color .15s; }
.kavat-choice:hover { border-color: var(--color-brand-400); }
.kavat-choice.is-on { border-color: var(--color-brand-800); background: var(--color-brand-800); color: #fff; font-weight: 600; }
.kavat-choice:has(input:focus-visible) { outline: 3px solid var(--color-brand-300); outline-offset: 2px; }
fieldset:disabled .kavat-choice { cursor: default; opacity: .75; }
</style>
