<script setup lang="ts">
/**
 * Kalinga Vulnerability Assessment Tool. The case manager reads each
 * statement to the child and records the answer; answers save as they go
 * and the score updates live. Finalizing locks the administration; a later
 * review is a new administration, so the history shows the trend.
 */
import axios from 'axios';
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ShieldCheck, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import FormField from '@/Components/UI/FormField.vue';
import { emptyKavatAnswers, priorityLabel, scoreKavat, type KavatAnswers, type KavatDefinition, type KavatRecord } from '@/Casework/kavat';
import { calendarDateLabel } from '@/utils/philippineTime';

const props = defineProps<{
  caseId: number; definition: KavatDefinition; records: KavatRecord[]; canEdit: boolean;
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
const start = useForm({ occasion: finals.value.length ? 'review' : 'baseline', administered_at: today });
const begin = () => offline.value ? emit('start', { occasion: start.occasion, administered_at: start.administered_at }) : start.post(route('cases.kavat.store', props.caseId), { preserveScroll: true });

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

const finalizing = ref(false);
async function finalize() {
  if (!draft.value) return;
  if (timer) { clearTimeout(timer); timer = null; }
  await save();
  if (state.value !== 'saved') return;
  finalizing.value = true;
  router.post(route('cases.kavat.finalize', [props.caseId, draft.value.id]), {}, { preserveScroll: true, onFinish: () => { finalizing.value = false; } });
}
function discard() {
  if (!draft.value || !window.confirm('Discard the answers recorded so far? This cannot be undone.')) return;
  router.delete(route('cases.kavat.destroy', [props.caseId, draft.value.id]), { preserveScroll: true });
}

const answerChoices = computed(() => Object.entries(props.definition.answers) as [string, string][]);
const levelTone = (level: number | null) => level === 0 ? 'border-rose-300 bg-rose-50 text-rose-900' : level === 1 ? 'border-amber-300 bg-amber-50 text-amber-950' : level === 2 ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600';
const priorityTone = (priority: string | null | undefined) => priority === 'high' ? 'bg-rose-100 text-rose-900 border-rose-300' : priority === 'standard' ? 'bg-emerald-100 text-emerald-900 border-emerald-300' : 'bg-slate-100 text-slate-700 border-slate-300';
const saveLabel = computed(() => ({ idle: 'Changes not yet saved', saving: 'Saving...', saved: offline.value ? 'All answers saved on this device' : 'All answers saved', error: offline.value ? 'Not saved on this device - free storage and retry' : 'Not saved - check your connection', conflict: 'Changed on another device' }[state.value]));
const occasionLabel = (code: string) => props.definition.occasions[code] ?? code;
const trendMax = 100;
</script>

<template>
  <section aria-labelledby="kavat-title" class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 id="kavat-title" class="ui-section-title text-lg">Kalinga Vulnerability Assessment Tool (KaVAT)</h2>
        <p class="ui-muted mt-1 max-w-3xl">Read each statement to the child in the language they understand. Part 1 measures empowerment; Part 2 records objective risk. Final protection score = empowerment x 40% + safety x 60%; below {{ definition.threshold }} is High Priority.</p>
      </div>
      <span v-if="finals[0]" class="rounded-full border px-3 py-1 text-xs font-semibold" :class="priorityTone(finals[0].score.priority)">Latest: {{ finals[0].score.final_score }} - {{ priorityLabel(finals[0].score.priority) }}</span>
    </header>

    <form v-if="!draft && canEdit" class="ui-panel grid gap-3 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-skip-generic-confirm="true" @submit.prevent="begin">
      <FormField id="kavat-occasion" label="Occasion" :error="start.errors.occasion" required>
        <select id="kavat-occasion" v-model="start.occasion" class="ui-field"><option v-for="(label, code) in definition.occasions" :key="code" :value="code">{{ label }}</option></select>
      </FormField>
      <FormField id="kavat-date" label="Date administered" :error="start.errors.administered_at" required>
        <input id="kavat-date" v-model="start.administered_at" type="date" :max="today" class="ui-field" required>
      </FormField>
      <button type="submit" class="ui-button-primary" :disabled="start.processing"><ShieldCheck class="h-4 w-4" aria-hidden="true" />{{ finals.length ? 'Start a new KaVAT' : 'Start the KaVAT' }}</button>
    </form>

    <div v-if="draft" class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
      <div class="min-w-0 space-y-5">
        <div v-if="state === 'conflict'" role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
          <p class="font-semibold">These answers were changed on another device or tab.</p>
          <p class="mt-1">Choose which answers to keep.</p>
          <div class="mt-3 flex flex-wrap gap-2"><button type="button" class="ui-button" @click="useSaved">Use the saved answers</button><button type="button" class="ui-button-primary" @click="keepMine">Keep my answers</button></div>
        </div>

        <fieldset :disabled="!canEdit" class="ui-panel overflow-hidden p-0">
          <legend class="sr-only">Part 1</legend>
          <div class="border-b border-[var(--color-line)] bg-brand-50 px-4 py-3"><h3 class="ui-section-title">Part 1 - Empowerment (25 statements)</h3><p class="mt-1 text-xs text-slate-600">Oo / Hindi Sigurado / Hindi. Statements marked "reversed" score the other way.</p></div>
          <section v-for="domain in definition.domains" :key="domain.code" class="border-b border-[var(--color-line)] px-4 py-4 last:border-b-0" :aria-labelledby="`kavat-domain-${domain.code}`">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h4 :id="`kavat-domain-${domain.code}`" class="text-sm font-semibold text-slate-900">Domain {{ domain.code }}. {{ domain.title }}</h4>
              <span class="text-xs text-slate-600">{{ score.domains.find((d) => d.code === domain.code)?.score }} / {{ domain.statements.length * 5 }}</span>
            </div>
            <ol class="mt-3 space-y-3">
              <li v-for="statement in domain.statements" :key="statement.number" class="rounded-lg border border-slate-200 p-3">
                <p class="text-sm leading-6 text-slate-900"><b>{{ statement.number }}.</b> {{ statement.text }} <span v-if="statement.reversed" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">reversed</span></p>
                <div role="radiogroup" :aria-label="`Answer to statement ${statement.number}`" class="mt-2 flex flex-wrap gap-2">
                  <label v-for="[code, label] in answerChoices" :key="code" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm" :class="answers.part1[statement.number] === code ? 'border-brand-500 bg-brand-50 font-semibold text-brand-900' : 'border-slate-300 bg-white'">
                    <input v-model="answers.part1[statement.number]" type="radio" :name="`kavat-p1-${statement.number}`" :value="code" class="text-brand-600">{{ label }}
                  </label>
                </div>
              </li>
            </ol>
          </section>
        </fieldset>

        <fieldset :disabled="!canEdit" class="ui-panel overflow-hidden p-0">
          <legend class="sr-only">Part 2</legend>
          <div class="border-b border-[var(--color-line)] bg-brand-50 px-4 py-3"><h3 class="ui-section-title">Part 2 - Objective risk (9 sections)</h3><p class="mt-1 text-xs text-slate-600">"Refused to Answer" is a red flag. In the sensitive sections it scores the highest risk.</p></div>
          <section v-for="section in definition.sections" :key="section.number" class="border-b border-[var(--color-line)] px-4 py-4 last:border-b-0">
            <h4 class="text-sm font-semibold text-slate-900">{{ section.number }}. {{ section.title }} <span v-if="section.sensitive" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-900">sensitive - ask with care</span></h4>
            <div v-for="question in section.questions" :key="question.key" class="mt-3">
              <p class="text-sm leading-6 text-slate-800">{{ question.text }}</p>
              <div role="radiogroup" :aria-label="question.text" class="mt-2 grid gap-2 sm:grid-cols-2">
                <label v-for="option in question.options" :key="option.code" class="flex min-h-10 cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm" :class="answers.part2[question.key] === option.code ? 'border-brand-500 bg-brand-50 font-semibold text-brand-900' : 'border-slate-300 bg-white'">
                  <input v-model="answers.part2[question.key]" type="radio" :name="`kavat-p2-${question.key}`" :value="option.code" class="mt-1 text-brand-600">
                  <span class="min-w-0 flex-1">{{ option.label }} <span class="text-xs font-normal text-slate-700">({{ option.points }} pts)</span></span>
                </label>
              </div>
              <template v-for="option in question.options" :key="`d-${option.code}`">
                <div v-if="option.detail && answers.part2[question.key] === option.code" class="mt-2 max-w-md">
                  <label :for="`kavat-detail-${question.key}`" class="text-xs font-medium text-slate-700">{{ option.detail }}</label>
                  <input :id="`kavat-detail-${question.key}`" v-model="answers.details![question.key]" type="text" maxlength="255" class="ui-field mt-1">
                </div>
              </template>
            </div>
          </section>
        </fieldset>

        <div class="ui-panel p-4">
          <FormField id="kavat-notes" label="Case manager's notes (optional)" hint="Observations while administering, for example the child's reaction or why a question was refused.">
            <textarea id="kavat-notes" v-model="notes" rows="3" maxlength="5000" class="ui-field" :disabled="!canEdit"></textarea>
          </FormField>
        </div>
      </div>

      <aside class="space-y-4 xl:sticky xl:top-24" aria-label="Live KaVAT score">
        <div class="ui-panel p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ occasionLabel(draft.occasion) }} - {{ calendarDateLabel(draft.administered_at) }}</p>
          <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Answered"><div class="h-full bg-brand-600" :style="{ width: progress + '%' }"></div></div>
          <p class="mt-1 text-xs text-slate-600">{{ score.answered }} of {{ score.questions }} answered</p>
          <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Part 1</dt><dd class="font-semibold">{{ score.part1 }} / {{ definition.part1Max }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Part 2</dt><dd class="font-semibold">{{ score.part2 }} / {{ definition.part2Max }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Empowerment</dt><dd class="font-semibold">{{ score.empowerment_rate }}%</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-600">Safety</dt><dd class="font-semibold">{{ score.safety_rate }}%</dd></div>
          </dl>
          <div class="mt-3 rounded-lg border p-3 text-center" :class="priorityTone(score.priority)">
            <p class="text-xs">Final protection score</p>
            <p class="text-2xl font-bold">{{ score.final_score ?? '-' }}</p>
            <p class="text-xs font-semibold">{{ priorityLabel(score.priority) }}</p>
          </div>
          <ul v-if="score.red_flags.length" class="mt-3 space-y-1 text-xs text-rose-900">
            <li v-for="flag in score.red_flags" :key="flag.question" class="flex items-start gap-1"><AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Refused: {{ flag.title }}</li>
          </ul>
          <p class="mt-3 text-xs" :class="state === 'error' || state === 'conflict' ? 'text-rose-700' : 'text-slate-600'" role="status">{{ saveLabel }}</p>
          <p v-if="offline" class="mt-3 rounded-lg bg-sky-50 p-2 text-xs text-sky-900">Saved on this device and sent when you sync. Finalize it from the case page when you are back online.</p>
          <div v-if="canEdit && !offline" class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="ui-button-primary flex-1" :disabled="!score.complete || finalizing || state === 'conflict'" @click="finalize"><CheckCircle2 class="h-4 w-4" aria-hidden="true" />Finalize</button>
            <button type="button" class="ui-button" @click="discard"><Trash2 class="h-4 w-4" aria-hidden="true" /><span class="sr-only sm:not-sr-only">Discard</span></button>
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

    <section v-if="finals.length" class="ui-panel overflow-hidden p-0" aria-labelledby="kavat-history-title">
      <header class="ui-panel-header"><h3 id="kavat-history-title" class="ui-section-title">KaVAT history</h3><span class="text-xs text-slate-600">{{ finals.length }} finalized</span></header>
      <div class="flex items-end gap-3 overflow-x-auto px-4 pt-4" aria-label="Final protection score over time">
        <div v-for="item in [...finals].reverse()" :key="`bar-${item.id}`" class="flex w-16 shrink-0 flex-col items-center gap-1">
          <span class="text-xs font-semibold">{{ item.score.final_score }}</span>
          <div class="w-8 rounded-t" :class="item.score.priority === 'high' ? 'bg-rose-400' : 'bg-emerald-500'" :style="{ height: `${Math.max(4, (Number(item.score.final_score) / trendMax) * 96)}px` }"></div>
          <span class="text-[11px] text-slate-600">{{ calendarDateLabel(item.administered_at) }}</span>
        </div>
      </div>
      <table class="ui-table ui-table-stack mt-3">
        <thead><tr><th>Date</th><th>Occasion</th><th>Empowerment</th><th>Safety</th><th>Final score</th><th>Priority</th><th><span class="sr-only">Details</span></th></tr></thead>
        <tbody>
          <tr v-for="item in finals" :key="item.id">
            <td data-label="Date">{{ calendarDateLabel(item.administered_at) }}<br><span class="text-xs text-slate-600">{{ item.administered_by }}</span></td>
            <td data-label="Occasion">{{ occasionLabel(item.occasion) }}</td>
            <td data-label="Empowerment">{{ item.score.empowerment_rate }}%</td>
            <td data-label="Safety">{{ item.score.safety_rate }}%</td>
            <td data-label="Final score" class="font-semibold">{{ item.score.final_score }}</td>
            <td data-label="Priority"><span class="rounded-full border px-2 py-0.5 text-xs font-semibold" :class="priorityTone(item.score.priority)">{{ priorityLabel(item.score.priority) }}</span></td>
            <td><button type="button" class="ui-button min-h-9 px-3 text-xs" :aria-expanded="viewing === item.id" @click="viewing = viewing === item.id ? null : item.id">{{ viewing === item.id ? 'Hide' : 'Domains' }}</button></td>
          </tr>
        </tbody>
      </table>
      <div v-if="viewed" class="grid gap-2 border-t border-[var(--color-line)] p-4 sm:grid-cols-2">
        <div v-for="domain in viewed.score.domains" :key="domain.code" class="rounded-lg border p-2 text-xs" :class="levelTone(domain.level)"><p class="font-semibold">{{ domain.code }}. {{ domain.title }} - {{ domain.score }}/{{ domain.max }}</p><p>{{ domain.band }}: {{ domain.interpretation }}</p></div>
        <p v-if="viewed.notes" class="whitespace-pre-wrap text-sm text-slate-700 sm:col-span-2"><b>Notes:</b> {{ viewed.notes }}</p>
      </div>
    </section>
    <p v-else-if="!draft" class="ui-muted">No KaVAT recorded yet{{ canEdit ? '. Start the baseline KaVAT at the beginning of case management.' : '.' }}</p>
  </section>
</template>
