<script setup lang="ts">
/**
 * Field mode: record a progress note, a monitoring visit or (for a closed
 * case in aftercare) a follow-up visit with no signal. Same fields as the
 * case page; saved encrypted on the device and sent at the next sync.
 */
import { computed, ref, watch } from 'vue';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { NOTE_TYPES, VISIT_LOCATIONS } from '@/utils/caseOptions';
import { manilaDateTime, manilaInputToUtc } from '@/utils/philippineTime';

export type FieldUpdate = { entity_type: 'case_note' | 'monitoring_visit' | 'aftercare_visit'; payload: Record<string, unknown> };
const props = defineProps<{ caseId: number; closed: boolean; aftercareDue?: string | null; busy: boolean }>();
const emit = defineEmits<{ save: [update: FieldUpdate] }>();

/** Referrals and services need the connection (they are sent to other offices), so they are not offered here. */
const noteTypes = NOTE_TYPES.filter((type) => !['referral', 'service_delivery'].includes(type.value));
const kind = ref<'case_note' | 'monitoring_visit' | 'aftercare_visit'>(props.closed ? 'aftercare_visit' : 'case_note');
watch(() => [props.caseId, props.closed] as const, () => { kind.value = props.closed ? 'aftercare_visit' : 'case_note'; reset(); });
const now = () => manilaDateTime();
const noteType = ref('contact');
const noteTypeOther = ref('');
const occurredAt = ref(now());
const body = ref('');
const actionTaken = ref('');
const nextSteps = ref('');
const location = ref('');
const risks = ref('');
const visitedOn = ref(now().slice(0, 10));
const status = ref('');
const today = now().slice(0, 10);
function reset() { body.value = ''; actionTaken.value = ''; nextSteps.value = ''; location.value = ''; risks.value = ''; noteTypeOther.value = ''; status.value = ''; occurredAt.value = now(); visitedOn.value = now().slice(0, 10); }
defineExpose({ reset });

const valid = computed(() => {
  if (!body.value.trim()) return false;
  if (kind.value === 'case_note') return noteType.value !== 'other' || noteTypeOther.value.trim().length > 0;
  if (kind.value === 'aftercare_visit') return Boolean(status.value) && body.value.trim().length >= 5;
  return true;
});
function save() {
  if (!valid.value || props.busy) return;
  const text = body.value.trim();
  if (kind.value === 'case_note') {
    emit('save', { entity_type: 'case_note', payload: { case_id: props.caseId, body: text, note_type: noteType.value, note_type_other: noteType.value === 'other' ? noteTypeOther.value.trim() : null,
      occurred_at: manilaInputToUtc(occurredAt.value), action_taken: actionTaken.value.trim() || null, next_steps: nextSteps.value.trim() || null } });
  } else if (kind.value === 'monitoring_visit') {
    emit('save', { entity_type: 'monitoring_visit', payload: { case_id: props.caseId, visited_at: visitedOn.value, observations: text, location: location.value.trim() || null, risks: risks.value.trim() || null, next_steps: nextSteps.value.trim() || null } });
  } else {
    emit('save', { entity_type: 'aftercare_visit', payload: { case_id: props.caseId, visited_on: visitedOn.value, status: status.value, notes: text } });
  }
}
const STATUSES = [{ value: 'stable', label: 'Stable' }, { value: 'needs_support', label: 'Needs support' }, { value: 'at_risk', label: 'At risk' }];
</script>

<template>
  <div class="mt-6 space-y-4 border-t pt-6">
    <h3 class="font-bold">Record field update</h3>
    <div v-if="!closed" role="radiogroup" aria-label="What to record" class="flex flex-wrap gap-2">
      <label v-for="option in [{ value: 'case_note', label: 'Progress note' }, { value: 'monitoring_visit', label: 'Monitoring visit' }]" :key="option.value" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-4 text-sm font-semibold" :class="kind === option.value ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-slate-300'">
        <input v-model="kind" type="radio" name="field-update-kind" :value="option.value" class="h-4 w-4">{{ option.label }}
      </label>
    </div>
    <p v-else class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-950">Closed case in aftercare.<template v-if="aftercareDue"> Follow-up visit due {{ aftercareDue }}.</template> Record the follow-up visit here.</p>

    <template v-if="kind === 'case_note'">
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-bold">Record type<select v-model="noteType" class="mt-2 w-full rounded-xl border-slate-300"><option v-for="type in noteTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
        <label class="text-sm font-bold">When it happened<input v-model="occurredAt" type="datetime-local" :max="`${today}T23:59`" class="mt-2 w-full rounded-xl border-slate-300"></label>
        <label v-if="noteType === 'other'" class="text-sm font-bold sm:col-span-2">Specify the record type<input v-model="noteTypeOther" maxlength="120" class="mt-2 w-full rounded-xl border-slate-300" placeholder="For example: phone call with the school"></label>
      </div>
      <label class="block text-sm font-bold">Case note<textarea v-model="body" rows="6" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" placeholder="Who was met, where, and what was observed or discussed." /></label>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-bold">Action taken (optional)<textarea v-model="actionTaken" rows="2" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" /></label>
        <label class="text-sm font-bold">Next steps (optional)<textarea v-model="nextSteps" rows="2" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" /></label>
      </div>
    </template>
    <template v-else-if="kind === 'monitoring_visit'">
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-bold">Date of the visit<input v-model="visitedOn" type="date" :max="today" class="mt-2 w-full rounded-xl border-slate-300"></label>
        <label class="text-sm font-bold">Visit location<OptionSelect v-model="location" :options="VISIT_LOCATIONS" /></label>
      </div>
      <label class="block text-sm font-bold">Observations<textarea v-model="body" rows="6" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" placeholder="Record factual field observations only." /></label>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-bold">Risks observed (optional)<textarea v-model="risks" rows="2" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" /></label>
        <label class="text-sm font-bold">Next steps (optional)<textarea v-model="nextSteps" rows="2" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" /></label>
      </div>
    </template>
    <template v-else>
      <label class="block max-w-xs text-sm font-bold">Date of the visit<input v-model="visitedOn" type="date" :max="today" class="mt-2 w-full rounded-xl border-slate-300"></label>
      <fieldset><legend class="text-sm font-bold">How is the child?</legend>
        <div class="mt-2 flex flex-wrap gap-2"><label v-for="option in STATUSES" :key="option.value" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border-2 px-4 text-sm font-semibold" :class="status === option.value ? (option.value === 'at_risk' ? 'border-rose-600 bg-rose-50' : option.value === 'needs_support' ? 'border-amber-500 bg-amber-50' : 'border-emerald-600 bg-emerald-50') : 'border-slate-300'"><input v-model="status" type="radio" name="field-aftercare-status" :value="option.value" class="h-4 w-4">{{ option.label }}</label></div>
      </fieldset>
      <label class="block text-sm font-bold">What was seen and discussed<textarea v-model="body" rows="5" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" /></label>
      <p v-if="status === 'at_risk'" class="rounded-lg bg-rose-50 p-2 text-xs text-rose-900">When it syncs, your supervisor is told and reopening the case is suggested.</p>
    </template>
    <button type="button" class="rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white disabled:opacity-50" :disabled="busy || !valid" @click="save">Save encrypted update on this device</button>
  </div>
</template>
