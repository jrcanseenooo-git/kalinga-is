<script setup lang="ts">
/**
 * Administration > Form options: the dropdown lists used in the forms, the
 * risk factor points, and extra intake fields, with a preview of the intake.
 * Before an option in use is renamed or retired, the page shows how many open
 * cases and saved intakes use it and asks to confirm.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import FormField from '@/Components/UI/FormField.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertTriangle, ArrowDown, ArrowUp, Eye, ListChecks, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';

type OptionRow = { id: number; set_name: string; code: string; label: string; definition: string | null; weight: number | string | null; sort_order: number; active: boolean | number };
type OptionSet = { key: string; title: string; description: string | null; kind: 'text' | 'coded'; weighted: boolean; options: OptionRow[] };
type Field = { id: number; key: string; label: string; type: string; required: boolean | number; options: string | null; help_text: string | null; active: boolean | number | null };
const props = defineProps<{ sets: OptionSet[]; riskRule: { moderate: number; high: number; critical: number }; fields: Field[] }>();

const tab = ref<'lists' | 'fields' | 'preview'>('lists');
const selectedKey = ref(props.sets[0]?.key ?? '');
const selected = computed(() => props.sets.find(set => set.key === selectedKey.value) ?? null);
const showRetired = ref(false);
const visibleOptions = computed(() => (selected.value?.options ?? []).filter(option => showRetired.value || Boolean(option.active)));

// Editing one option at a time.
const editing = ref<OptionRow | null>(null);
const draft = ref({ label: '', definition: '', weight: '' as string | number, active: true });
const warning = ref<{ cases: number; intakes: number } | null>(null);
const saving = ref(false);
const editError = ref('');
function edit(option: OptionRow) { editing.value = option; warning.value = null; editError.value = ''; draft.value = { label: option.label, definition: option.definition ?? '', weight: option.weight ?? '', active: Boolean(option.active) }; }
async function saveOption(confirmed = false, override?: Partial<typeof draft.value>, target?: OptionRow) {
  const option = target ?? editing.value;
  if (!option || !selected.value) return;
  const values = { ...draft.value, ...override };
  saving.value = true; editError.value = '';
  try {
    await axios.put(route('admin.options.update', [selected.value.key, option.code]), { label: values.label, definition: values.definition || null, weight: values.weight === '' ? null : values.weight,
      sort_order: option.sort_order, active: values.active, confirmed });
    editing.value = null; warning.value = null;
    router.reload({ only: ['sets'] });
  } catch (error: any) {
    if (error?.response?.status === 409) { warning.value = error.response.data.usage; if (target) { editing.value = target; draft.value = { ...draft.value, ...values }; } }
    else editError.value = Object.values(error?.response?.data?.errors ?? {}).flat().join(' ') || 'Could not save. Try again.';
  } finally { saving.value = false; }
}
const toggle = (option: OptionRow) => { draft.value = { label: option.label, definition: option.definition ?? '', weight: option.weight ?? '', active: !option.active }; void saveOption(false, { active: !option.active }, option); };
async function move(option: OptionRow, step: number) {
  const list = selected.value?.options ?? [];
  const index = list.findIndex(item => item.id === option.id);
  const other = list[index + step];
  if (!other || !selected.value) return;
  const set = selected.value.key;
  await axios.put(route('admin.options.update', [set, option.code]), { label: option.label, definition: option.definition, weight: option.weight, sort_order: other.sort_order, active: Boolean(option.active), confirmed: true });
  await axios.put(route('admin.options.update', [set, other.code]), { label: other.label, definition: other.definition, weight: other.weight, sort_order: option.sort_order, active: Boolean(other.active), confirmed: true });
  router.reload({ only: ['sets'] });
}
const adding = useForm({ label: '', definition: '', weight: 1 as number | string });
const addOption = () => selected.value && adding.post(route('admin.options.store', selected.value.key), { preserveScroll: true, onSuccess: () => adding.reset() });
const rule = useForm({ ...props.riskRule });
const saveRule = () => rule.put(route('admin.options.risk-rule'), { preserveScroll: true });

// Extra intake fields.
const fieldForm = useForm({ id: null as number | null, label: '', type: 'text', options: [] as string[], required: false, help_text: '' });
const optionsText = ref('');
const typeLabels: Record<string, string> = { text: 'Short answer', textarea: 'Paragraph', select: 'Dropdown', date: 'Date', number: 'Number', yes_no: 'Yes / No' };
function editField(field: Field) { fieldForm.id = field.id; fieldForm.label = field.label; fieldForm.type = field.type; fieldForm.required = Boolean(field.required); fieldForm.help_text = field.help_text ?? ''; optionsText.value = (JSON.parse(field.options || '[]') as string[]).join('\n'); }
function resetField() { fieldForm.reset(); fieldForm.id = null; optionsText.value = ''; }
const saveField = () => fieldForm.transform(data => ({ ...data, options: optionsText.value.split('\n').map(item => item.trim()).filter(Boolean) })).post(route('admin.intake-fields.store'), { preserveScroll: true, onSuccess: resetField });
const toggleField = (field: Field) => router.patch(route('admin.intake-fields.toggle', field.id), {}, { preserveScroll: true });
const fieldOptions = (field: Field) => JSON.parse(field.options || '[]') as string[];

// Preview: the intake steps as a case manager sees them, with the current lists and extra fields.
const setByKey = (key: string) => props.sets.find(set => set.key === key)?.options.filter(option => Boolean(option.active)) ?? [];
const preview = computed(() => [
  { step: '1. Privacy notice', items: [{ label: 'How the privacy notice was given', type: 'Choice' }, { label: 'Consent / assent form photo', type: 'Camera or upload' }] },
  { step: '2. Child & safe contact', items: [{ label: 'Name, alias, birthdate, sex', type: 'Text' }, { label: 'Civil status', type: 'Dropdown', options: setByKey('civil_statuses') }, { label: 'Religion', type: 'Dropdown', options: setByKey('religions') },
    { label: 'Indigenous Peoples group', type: 'Dropdown', options: setByKey('ip_groups') }, { label: 'Highest education reached', type: 'Dropdown', options: setByKey('education_levels') },
    { label: 'Disability or support need', type: 'Dropdown', options: setByKey('disability_types') }, { label: 'Present and permanent address', type: 'Address' },
    { label: 'Caregiver and family members (relationship, education, occupation)', type: 'Table', options: setByKey('relationships') }] },
  { step: '3. Case details', items: [{ label: 'CEFMU classification and incidence', type: 'Choices' }, { label: 'Referred by', type: 'Dropdown', options: setByKey('referral_sources') },
    ...props.fields.filter(field => field.active !== false && field.active !== 0).map(field => ({ label: field.label + (field.required ? ' *' : ''), type: typeLabels[field.type] ?? field.type, options: fieldOptions(field).map(label => ({ label })), extra: true }))] },
  { step: '4. Safety triage', items: [{ label: 'Risk factors (points add up to the suggested risk level)', type: 'Checkboxes', options: setByKey('intake_risk_factors') }, { label: 'Initial risk level', type: 'Choice' }] },
]);
</script>

<template>
  <Head title="Form options" />
  <AuthenticatedLayout>
    <section class="mx-auto max-w-6xl space-y-5">
      <SectionNav section="admin" />
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-sm font-bold text-brand-700">Administration</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Form options</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Edit the dropdown lists used in the forms, the risk factor points and extra intake fields. Options are never deleted: a retired option disappears from the forms but saved records keep it.</p>
      </header>
      <nav class="ui-tabs" aria-label="Form options sections">
        <button type="button" class="ui-tab" :class="tab === 'lists' ? 'ui-tab-active' : ''" :aria-current="tab === 'lists' ? 'page' : undefined" @click="tab = 'lists'"><ListChecks class="h-4 w-4" aria-hidden="true" />Dropdown lists</button>
        <button type="button" class="ui-tab" :class="tab === 'fields' ? 'ui-tab-active' : ''" :aria-current="tab === 'fields' ? 'page' : undefined" @click="tab = 'fields'"><Plus class="h-4 w-4" aria-hidden="true" />Extra intake fields</button>
        <button type="button" class="ui-tab" :class="tab === 'preview' ? 'ui-tab-active' : ''" :aria-current="tab === 'preview' ? 'page' : undefined" @click="tab = 'preview'"><Eye class="h-4 w-4" aria-hidden="true" />Preview the intake</button>
      </nav>

      <div v-if="tab === 'lists'" class="grid items-start gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="ui-panel p-2" aria-label="Lists">
          <label for="option-set-picker" class="sr-only">List</label>
          <select id="option-set-picker" v-model="selectedKey" class="ui-field lg:hidden"><option v-for="set in sets" :key="set.key" :value="set.key">{{ set.title }}</option></select>
          <ul class="hidden lg:block"><li v-for="set in sets" :key="set.key"><button type="button" class="flex min-h-11 w-full items-center justify-between gap-2 rounded-lg px-3 text-left text-sm" :class="selectedKey === set.key ? 'bg-brand-50 font-semibold text-brand-900' : 'hover:bg-slate-50'" :aria-current="selectedKey === set.key ? 'true' : undefined" @click="selectedKey = set.key; editing = null">{{ set.title }}<span class="text-xs text-slate-600">{{ set.options.filter(o => o.active).length }}</span></button></li></ul>
        </aside>
        <section v-if="selected" class="ui-panel overflow-hidden p-0" :aria-labelledby="`set-${selected.key}`">
          <header class="border-b border-[var(--color-line)] px-4 py-3">
            <h2 :id="`set-${selected.key}`" class="ui-section-title">{{ selected.title }}</h2>
            <p class="text-xs text-slate-600">Used in: {{ selected.description }} {{ selected.kind === 'text' ? 'Records keep the words chosen at the time.' : 'Records keep a code; you can rename, reorder and retire.' }}</p>
            <label class="mt-2 inline-flex min-h-9 items-center gap-2 text-xs"><input v-model="showRetired" type="checkbox" class="rounded border-slate-300">Show retired options</label>
          </header>
          <div v-if="selected.key === 'intake_risk_factors'" class="border-b border-[var(--color-line)] bg-amber-50/50 p-4">
            <form class="flex flex-wrap items-end gap-3" data-skip-generic-confirm="true" @submit.prevent="saveRule">
              <p class="w-full text-sm font-semibold text-slate-900">Risk level from total points</p>
              <FormField id="rule-moderate" label="Moderate from" :error="rule.errors.moderate"><input id="rule-moderate" v-model="rule.moderate" type="number" min="0" step="0.5" class="ui-field w-28"></FormField>
              <FormField id="rule-high" label="High from" :error="rule.errors.high"><input id="rule-high" v-model="rule.high" type="number" min="0" step="0.5" class="ui-field w-28"></FormField>
              <FormField id="rule-critical" label="Critical from" :error="rule.errors.critical"><input id="rule-critical" v-model="rule.critical" type="number" min="0" step="0.5" class="ui-field w-28"></FormField>
              <button type="submit" class="ui-button min-h-11" :disabled="rule.processing">Save points</button>
            </form>
          </div>
          <ul class="divide-y divide-slate-100">
            <li v-for="(option, index) in visibleOptions" :key="option.id" class="px-4 py-3" :class="option.active ? '' : 'bg-slate-50 text-slate-600'">
              <div v-if="editing?.id !== option.id" class="flex flex-wrap items-center gap-2">
                <span class="min-w-0 flex-1"><span class="block text-sm font-medium" :class="option.active ? 'text-slate-900' : 'line-through'">{{ option.label }}</span><span v-if="option.definition" class="block text-xs text-slate-600">{{ option.definition }}</span></span>
                <span v-if="selected.weighted" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ option.weight }} pts</span>
                <span v-if="!option.active" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs">Retired</span>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-lg hover:bg-slate-100" :disabled="index === 0" :aria-label="`Move ${option.label} up`" @click="move(option, -1)"><ArrowUp class="h-4 w-4" /></button>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-lg hover:bg-slate-100" :disabled="index === visibleOptions.length - 1" :aria-label="`Move ${option.label} down`" @click="move(option, 1)"><ArrowDown class="h-4 w-4" /></button>
                <button type="button" class="ui-button min-h-9 px-3 text-xs" @click="edit(option)">Edit</button>
                <button type="button" class="ui-button min-h-9 px-3 text-xs" :disabled="saving" @click="toggle(option)">{{ option.active ? 'Retire' : 'Use again' }}</button>
              </div>
              <form v-else class="grid gap-3 sm:grid-cols-2" data-skip-generic-confirm="true" @submit.prevent="saveOption(false)">
                <FormField id="opt-label" label="Label" required><input id="opt-label" v-model="draft.label" required maxlength="160" class="ui-field"></FormField>
                <FormField v-if="selected.weighted" id="opt-weight" label="Points"><input id="opt-weight" v-model="draft.weight" type="number" min="0" max="20" step="0.5" class="ui-field"></FormField>
                <FormField id="opt-def" label="What this means (shown next to the option)" class="sm:col-span-2"><input id="opt-def" v-model="draft.definition" maxlength="300" class="ui-field"></FormField>
                <div v-if="warning" role="alert" class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 sm:col-span-2"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>This option is used by <b>{{ warning.cases }}</b> open {{ warning.cases === 1 ? 'case' : 'cases' }} and <b>{{ warning.intakes }}</b> saved {{ warning.intakes === 1 ? 'intake' : 'intakes' }}. {{ selected.kind === 'text' ? 'They keep the old words; new entries use the change.' : 'They keep their answer; only how it is shown or offered changes.' }}</span></div>
                <p v-if="editError" role="alert" class="text-sm text-rose-700 sm:col-span-2">{{ editError }}</p>
                <div class="flex flex-wrap gap-2 sm:col-span-2"><button v-if="!warning" type="submit" class="ui-button-primary" :disabled="saving">Save</button><button v-else type="button" class="ui-button-primary" :disabled="saving" @click="saveOption(true)">Save anyway</button><button type="button" class="ui-button" @click="editing = null; warning = null">Cancel</button></div>
              </form>
            </li>
          </ul>
          <form class="grid gap-3 border-t border-[var(--color-line)] bg-brand-50/40 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]" data-skip-generic-confirm="true" @submit.prevent="addOption">
            <FormField id="new-opt-label" label="New option" :error="adding.errors.label"><input id="new-opt-label" v-model="adding.label" required maxlength="160" class="ui-field"></FormField>
            <FormField id="new-opt-def" label="What it means (optional)" :error="adding.errors.definition"><input id="new-opt-def" v-model="adding.definition" maxlength="300" class="ui-field"></FormField>
            <div class="flex items-end gap-2"><FormField v-if="selected.weighted" id="new-opt-weight" label="Points"><input id="new-opt-weight" v-model="adding.weight" type="number" min="0" max="20" step="0.5" class="ui-field w-24"></FormField><button type="submit" class="ui-button-primary min-h-11" :disabled="adding.processing"><Plus class="h-4 w-4" aria-hidden="true" />Add</button></div>
          </form>
        </section>
      </div>

      <div v-else-if="tab === 'fields'" class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <form class="ui-panel space-y-3 p-5" data-skip-generic-confirm="true" @submit.prevent="saveField">
          <h2 class="ui-section-title">{{ fieldForm.id ? 'Change field' : 'Add a field to the intake' }}</h2>
          <p class="text-xs text-slate-600">Extra fields appear in step 3 of the intake under "Additional information". Do not ask for information the protection process does not need.</p>
          <FormField id="field-label" label="Question" :error="fieldForm.errors.label" required><input id="field-label" v-model="fieldForm.label" required maxlength="120" class="ui-field"></FormField>
          <FormField id="field-type" label="Type of answer" :error="fieldForm.errors.type"><select id="field-type" v-model="fieldForm.type" class="ui-field"><option v-for="(label, value) in typeLabels" :key="value" :value="value">{{ label }}</option></select></FormField>
          <FormField v-if="fieldForm.type === 'select'" id="field-options" label="Choices (one per line)" :error="fieldForm.errors.options"><textarea id="field-options" v-model="optionsText" rows="4" class="ui-field"></textarea></FormField>
          <FormField id="field-help" label="Help text (optional)" :error="fieldForm.errors.help_text"><input id="field-help" v-model="fieldForm.help_text" maxlength="255" class="ui-field"></FormField>
          <label class="flex min-h-10 items-center gap-2 text-sm"><input v-model="fieldForm.required" type="checkbox" class="rounded border-slate-300">Answer required</label>
          <div class="flex gap-2"><button type="submit" class="ui-button-primary" :disabled="fieldForm.processing">{{ fieldForm.id ? 'Save field' : 'Add field' }}</button><button v-if="fieldForm.id" type="button" class="ui-button" @click="resetField">Cancel</button></div>
        </form>
        <section class="ui-panel overflow-hidden p-0" aria-labelledby="fields-title">
          <h2 id="fields-title" class="border-b border-[var(--color-line)] px-4 py-3 ui-section-title">Extra fields ({{ fields.length }})</h2>
          <ul class="divide-y divide-slate-100"><li v-for="field in fields" :key="field.id" class="flex flex-wrap items-center gap-2 px-4 py-3" :class="field.active === false || field.active === 0 ? 'bg-slate-50 text-slate-600' : ''">
            <span class="min-w-0 flex-1"><span class="block text-sm font-medium">{{ field.label }}<span v-if="field.required"> *</span></span><span class="block text-xs text-slate-600">{{ typeLabels[field.type] ?? field.type }}<template v-if="field.type === 'select'">: {{ fieldOptions(field).join(', ') }}</template></span></span>
            <button type="button" class="ui-button min-h-9 px-3 text-xs" @click="editField(field)">Edit</button>
            <button type="button" class="ui-button min-h-9 px-3 text-xs" @click="toggleField(field)">{{ field.active === false || field.active === 0 ? 'Show' : 'Hide' }}</button>
          </li><li v-if="!fields.length" class="px-4 py-5 text-sm text-slate-600">No extra fields yet.</li></ul>
        </section>
      </div>

      <section v-else class="ui-panel space-y-4 p-5" aria-label="Preview of the intake">
        <p class="text-sm text-slate-600">The intake as case managers see it now, with the current lists and extra fields (marked "Added").</p>
        <ol class="space-y-4">
          <li v-for="part in preview" :key="part.step" class="rounded-xl border border-slate-200 p-4">
            <h3 class="text-sm font-bold text-slate-900">{{ part.step }}</h3>
            <ul class="mt-2 space-y-2">
              <li v-for="item in part.items" :key="item.label" class="rounded-lg bg-slate-50 p-3 text-sm">
                <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900">{{ item.label }}<span class="rounded bg-white px-1.5 py-0.5 text-[11px] text-slate-600 ring-1 ring-slate-200">{{ item.type }}</span><span v-if="(item as any).extra" class="rounded bg-brand-100 px-1.5 py-0.5 text-[11px] font-semibold text-brand-900">Added</span></p>
                <p v-if="(item as any).options?.length" class="mt-1 text-xs text-slate-600">{{ (item as any).options.map((o: any) => o.label + (o.weight ? ` (${o.weight} pts)` : '')).join(' | ') }}</p>
              </li>
            </ul>
          </li>
        </ol>
      </section>
    </section>
  </AuthenticatedLayout>
</template>
