<script setup lang="ts">
import { askConfirm } from '@/utils/confirmDialog';
/**
 * One Kalinga form as a working document. Narrative forms (Case Summary,
 * Social Case Study Report) are written in paragraphs per section; the
 * Intervention Plan is a matrix. Saves as the case manager types, keeps
 * retrying while the connection is down, and never overwrites a newer copy
 * saved from another device without asking.
 */
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, CheckCircle2, FileText, Lightbulb, Plus, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { emptyPlanRow, formHasContent, sectionProgress, wordCount, type CaseForm, type FormContent, type FormsDefinition, type PlanRow } from '@/Casework/caseForms';
import { formatPh } from '@/utils/philippineTime';

const props = defineProps<{
  caseId: number; form: CaseForm; definition: FormsDefinition; suggestions: Record<string, string>; canEdit: boolean; planSuggestions?: PlanRow[];
  /** Field mode: save into the encrypted device copy instead of the server; finalizing waits until online. */
  saver?: (content: FormContent) => Promise<boolean>;
}>();
const offline = computed(() => Boolean(props.saver));

const isPlan = computed(() => props.form.type === 'plan');
const sections = computed(() => (isPlan.value ? [] : props.definition.sections[props.form.type as 'summary' | 'study']));
const columns = computed(() => props.definition.planColumns);

const content = reactive<FormContent>({ sections: {}, rows: [] });
const revision = ref(0);
const state = ref<'idle' | 'saving' | 'saved' | 'offline' | 'error' | 'conflict'>('saved');
const lastSaved = ref<string | null>(null);
const conflict = ref<FormContent | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let loading = false;

function load(form: CaseForm) {
  loading = true;
  content.sections = { ...(form.content.sections ?? {}) };
  content.rows = (form.content.rows ?? []).map((row) => ({ ...emptyPlanRow(columns.value), ...row }));
  if (isPlan.value && !content.rows.length) content.rows.push(emptyPlanRow(columns.value));
  revision.value = form.revision;
  lastSaved.value = form.updated_at;
  state.value = 'saved';
  conflict.value = null;
  queueMicrotask(() => { loading = false; });
}
watch(() => [props.form.type, props.form.revision] as const, ([, rev], old) => { if (!old || old[0] !== props.form.type || (rev > revision.value && state.value === 'saved')) load(props.form); }, { immediate: true });

const payload = () => (isPlan.value ? { rows: content.rows } : { sections: content.sections });

async function save(): Promise<boolean> {
  if (!props.canEdit || state.value === 'conflict') return false;
  state.value = 'saving';
  if (props.saver) {
    const ok = await props.saver(JSON.parse(JSON.stringify(payload())));
    state.value = ok ? 'saved' : 'error';
    if (ok) lastSaved.value = new Date().toISOString();
    return ok;
  }
  try {
    const { data } = await axios.put(route('cases.forms.save', [props.caseId, props.form.type]), { revision: revision.value, content: payload() });
    revision.value = data.revision;
    lastSaved.value = data.updated_at;
    state.value = 'saved';
    return true;
  } catch (error: any) {
    if (error?.response?.status === 409) { conflict.value = error.response.data.content; revision.value = error.response.data.revision; state.value = 'conflict'; }
    else if (!error?.response) { state.value = 'offline'; }
    else state.value = 'error';
    return false;
  }
}
function schedule() { if (loading || !props.canEdit) return; if (timer) clearTimeout(timer); if (state.value !== 'conflict') state.value = 'idle'; timer = setTimeout(save, 1500); }
watch(content, schedule, { deep: true });
const retry = () => { if (state.value === 'offline' || state.value === 'idle') void save(); };
const beforeLeave = (event: BeforeUnloadEvent) => { if (['idle', 'saving', 'offline', 'error'].includes(state.value)) { event.preventDefault(); event.returnValue = ''; } };
onMounted(() => { window.addEventListener('online', retry); window.addEventListener('beforeunload', beforeLeave); });
onBeforeUnmount(() => { window.removeEventListener('online', retry); window.removeEventListener('beforeunload', beforeLeave); if (timer) { clearTimeout(timer); if (state.value === 'idle') void save(); } });

function useSaved() { if (!conflict.value) return; loading = true; content.sections = { ...(conflict.value.sections ?? {}) }; content.rows = (conflict.value.rows ?? []).map((row) => ({ ...emptyPlanRow(columns.value), ...row })); conflict.value = null; state.value = 'saved'; queueMicrotask(() => { loading = false; }); }
async function keepMine() { conflict.value = null; state.value = 'idle'; await save(); }

const finalizing = ref(false);
async function finalize() {
  if (timer) { clearTimeout(timer); timer = null; }
  if (state.value !== 'saved' && !(await save())) return;
  finalizing.value = true;
  router.post(route('cases.forms.finalize', [props.caseId, props.form.type]), {}, { preserveScroll: true, onFinish: () => { finalizing.value = false; } });
}

const insertSuggestion = (key: string) => { content.sections![key] = props.suggestions[key]; };
const addRow = (row?: PlanRow) => content.rows!.push({ ...emptyPlanRow(columns.value), ...(row ?? {}) });
const removeRow = async (index: number) => { if (await askConfirm({ title: 'Remove this row?', confirmLabel: 'Remove', tone: 'danger' })) content.rows!.splice(index, 1); };
const moveRow = (index: number, by: number) => { const rows = content.rows!; const [row] = rows.splice(index, 1); rows.splice(index + by, 0, row); };
const unusedSuggestions = computed(() => (props.planSuggestions ?? []).filter((suggestion) => !content.rows!.some((row) => row.problem.trim() === suggestion.problem)));

const progress = computed(() => sectionProgress(sections.value, content));
const hasContent = computed(() => formHasContent(props.form.type, content));
const saveLabel = computed(() => ({
  idle: 'Typing...', saving: 'Saving...', saved: lastSaved.value ? `Saved${offline.value ? ' on this device' : ''} ${formatPh(lastSaved.value, 'dateTimeShort')}` : 'Nothing written yet',
  offline: 'Offline - your text stays on this page and saves when the connection returns', error: 'Not saved - try again', conflict: 'Changed on another device',
}[state.value]));
const grow = (event: Event) => { const el = event.target as HTMLTextAreaElement; el.style.height = 'auto'; el.style.height = `${Math.min(el.scrollHeight + 2, 900)}px`; };
const autoNote: Record<string, string> = {
  identifying: 'Filled in from the intake when printed: case number, name (as chosen when printing), age, sex, address, education and guardian.',
  incidence: 'Filled in from the CEFMU incidence recorded at intake.',
  interventions: 'Filled in from progress notes, referrals, services provided and grants, oldest first.',
  status: 'Filled in when printed: case status, pending referrals and the latest KaVAT score.',
  family: 'Filled in from the family members recorded for the child.',
  plan: 'Taken from the Intervention Plan matrix.',
};
</script>

<template>
  <article class="ui-panel overflow-hidden p-0" :aria-labelledby="`form-${form.type}-title`">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--color-line)] bg-brand-50 px-4 py-3">
      <div class="min-w-0">
        <h3 :id="`form-${form.type}-title`" class="ui-section-title">{{ form.title }}</h3>
        <p class="mt-1 text-xs text-slate-600">
          <template v-if="!isPlan">{{ progress.written }} of {{ progress.total }} sections written. </template>
          <template v-else>{{ content.rows!.length }} {{ content.rows!.length === 1 ? 'row' : 'rows' }}. </template>
          <span v-if="form.versions.length">Last finalized: version {{ form.versions[0].version }}, {{ formatPh(form.versions[0].created_at, 'dateShort') }}.</span>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs" :class="['offline', 'error', 'conflict'].includes(state) ? 'font-semibold text-rose-700' : 'text-slate-600'" role="status" aria-live="polite">{{ canEdit ? saveLabel : 'Read only' }}</span>
        <Link v-if="!offline" :href="route('cases.reports.show', [caseId, form.type])" class="ui-button min-h-10 px-3 text-sm"><FileText class="h-4 w-4" aria-hidden="true" />Preview / print</Link>
        <button v-if="canEdit && !offline" type="button" class="ui-button-primary min-h-10 px-3 text-sm" :disabled="!hasContent || finalizing || state === 'conflict'" @click="finalize"><CheckCircle2 class="h-4 w-4" aria-hidden="true" />Finalize version {{ (form.versions[0]?.version ?? 0) + 1 }}</button>
      </div>
    </header>

    <div v-if="state === 'conflict'" role="alert" class="m-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
      <p class="font-semibold">This form was saved from another device or tab while you were writing.</p>
      <p class="mt-1">Use the saved copy (your latest changes here are dropped), or keep yours (it replaces the saved copy).</p>
      <div class="mt-3 flex flex-wrap gap-2"><button type="button" class="ui-button" @click="useSaved">Use the saved copy</button><button type="button" class="ui-button-primary" @click="keepMine">Keep mine</button></div>
    </div>

    <div v-if="!isPlan" class="divide-y divide-[var(--color-line)]">
      <section v-for="section in sections" :key="section.key" class="px-4 py-4" :aria-labelledby="`fs-${form.type}-${section.key}`">
        <h4 :id="`fs-${form.type}-${section.key}`" class="text-sm font-semibold text-slate-900">{{ section.title }}</h4>
        <p v-if="section.kind === 'auto'" class="mt-1 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">{{ autoNote[section.key] }}</p>
        <template v-else>
          <p v-if="section.guide" :id="`fs-guide-${form.type}-${section.key}`" class="mt-1 text-xs leading-5 text-slate-600">{{ section.guide }}</p>
          <button v-if="canEdit && suggestions[section.key] && !(content.sections![section.key] ?? '').trim()" type="button" class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-medium text-sky-900" @click="insertSuggestion(section.key)"><Lightbulb class="h-3.5 w-3.5" aria-hidden="true" />Start from the intake: "{{ suggestions[section.key].slice(0, 70) }}{{ suggestions[section.key].length > 70 ? '...' : '' }}"</button>
          <label :for="`fs-input-${form.type}-${section.key}`" class="sr-only">{{ section.title }}</label>
          <textarea :id="`fs-input-${form.type}-${section.key}`" v-model="content.sections![section.key]" :readonly="!canEdit" rows="5" maxlength="30000" spellcheck="true" :aria-describedby="section.guide ? `fs-guide-${form.type}-${section.key}` : undefined" class="ui-field mt-2 font-serif text-[15px] leading-7" @input="grow" @focus="grow"></textarea>
          <p class="mt-1 text-right text-[11px] text-slate-600">{{ wordCount(content.sections![section.key] ?? '') }} words. Leave a blank line between paragraphs.</p>
        </template>
      </section>
    </div>

    <div v-else class="space-y-3 p-4">
      <div v-if="canEdit && unusedSuggestions.length" class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950">
        <p class="flex items-center gap-1.5 font-semibold"><Lightbulb class="h-4 w-4" aria-hidden="true" />Needs from the latest KaVAT</p>
        <ul class="mt-2 flex flex-wrap gap-2"><li v-for="item in unusedSuggestions" :key="item.problem"><button type="button" class="rounded-lg border border-sky-300 bg-white px-3 py-1.5 text-xs" @click="addRow(item)"><Plus class="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />{{ item.problem }}</button></li></ul>
      </div>
      <div class="hidden overflow-x-auto lg:block">
        <table class="w-full table-fixed border-collapse text-sm">
          <thead><tr class="bg-slate-50 text-left text-xs text-slate-700"><th class="w-8 p-2">#</th><th v-for="(label, key) in columns" :key="key" class="p-2">{{ label }}</th><th class="w-24 p-2"><span class="sr-only">Row actions</span></th></tr></thead>
          <tbody>
            <tr v-for="(row, index) in content.rows" :key="index" class="border-t border-slate-200 align-top">
              <td class="p-2 text-xs text-slate-600">{{ index + 1 }}</td>
              <td v-for="(label, key) in columns" :key="key" class="p-1"><label :for="`plan-${index}-${key}`" class="sr-only">{{ label }}, row {{ index + 1 }}</label><textarea :id="`plan-${index}-${key}`" v-model="row[key]" :readonly="!canEdit" rows="3" maxlength="3000" class="ui-field min-h-20 text-sm" @input="grow" @focus="grow"></textarea></td>
              <td class="p-1"><div v-if="canEdit" class="flex gap-1"><button type="button" class="rounded p-1.5 hover:bg-slate-100" :disabled="index === 0" :aria-label="`Move row ${index + 1} up`" @click="moveRow(index, -1)"><ArrowUp class="h-4 w-4" /></button><button type="button" class="rounded p-1.5 hover:bg-slate-100" :disabled="index === content.rows!.length - 1" :aria-label="`Move row ${index + 1} down`" @click="moveRow(index, 1)"><ArrowDown class="h-4 w-4" /></button><button type="button" class="rounded p-1.5 text-rose-700 hover:bg-rose-50" :aria-label="`Remove row ${index + 1}`" @click="removeRow(index)"><Trash2 class="h-4 w-4" /></button></div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <ol class="space-y-3 lg:hidden">
        <li v-for="(row, index) in content.rows" :key="index" class="rounded-xl border border-slate-200 p-3">
          <div class="flex items-center justify-between"><p class="text-xs font-semibold text-slate-600">Row {{ index + 1 }}</p><button v-if="canEdit" type="button" class="rounded p-1.5 text-rose-700" :aria-label="`Remove row ${index + 1}`" @click="removeRow(index)"><Trash2 class="h-4 w-4" /></button></div>
          <div v-for="(label, key) in columns" :key="key" class="mt-2"><label :for="`plan-m-${index}-${key}`" class="text-xs font-medium text-slate-700">{{ label }}</label><textarea :id="`plan-m-${index}-${key}`" v-model="row[key]" :readonly="!canEdit" rows="2" maxlength="3000" class="ui-field mt-1 text-sm" @input="grow"></textarea></div>
        </li>
      </ol>
      <button v-if="canEdit && content.rows!.length < 60" type="button" class="ui-button" @click="addRow()"><Plus class="h-4 w-4" aria-hidden="true" />Add a row</button>
    </div>
  </article>
</template>
