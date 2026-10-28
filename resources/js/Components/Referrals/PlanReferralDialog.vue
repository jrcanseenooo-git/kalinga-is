<script setup lang="ts">
/**
 * Conduct a referral from a row of the Intervention Plan. The form starts
 * from the row (purpose and service suggested from the problem and
 * activities, expected date from the row's target end date, reason from the
 * row) and is saved as a referral progress note, so the referral and every
 * status change also appear in the progress notes history.
 */
import { useForm } from '@inertiajs/vue3';
import { Send, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/UI/FormField.vue';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { CPU_RECIPIENTS, EXTERNAL_RECIPIENTS, SERVICE_CATEGORIES, purposesFor } from '@/utils/caseOptions';
import { categoryHint, isHintedCategory, recipientAgency, type CpuOption, type ReferralKind } from '@/Casework/referralRecipients';
import type { PlanRow } from '@/Casework/caseForms';
import { manilaDateTime, manilaInputToUtc } from '@/utils/philippineTime';

const props = defineProps<{
  show: boolean; caseId: number; row: PlanRow | null; rowNumber: number | null; kind?: ReferralKind;
  cpuMembers: CpuOption[]; hasSharingConsent: boolean; referralTargets: { response_days: number; completion_days: number };
}>();
const emit = defineEmits<{ close: []; sent: [] }>();

const today = manilaDateTime().slice(0, 10);
const referralKind = ref<ReferralKind>('cpu');
const failures = ref<string[]>([]);
const form = useForm({
  note_type: 'referral', occurred_at: manilaDateTime(), body: '', action_taken: '', next_steps: '',
  referral: { recipient: '', referred_to: '', lgu_cpu_member_id: null as number | null, purpose: '', service_category: '', target_date: '', lawful_basis: '', plan_row: '' },
});

const rowText = (row: PlanRow | null) => row ? [row.problem, row.objective, row.activities].filter((text) => text?.trim()).join(' ') : '';
function prefill() {
  const row = props.row;
  form.reset();
  form.clearErrors();
  failures.value = [];
  referralKind.value = props.kind ?? 'cpu';
  form.occurred_at = manilaDateTime();
  form.referral.lawful_basis = props.hasSharingConsent ? 'consent' : '';
  form.referral.plan_row = row?.id ?? '';
  form.referral.target_date = row?.target_end && row.target_end >= today ? row.target_end : '';
  form.referral.service_category = categoryHint(rowText(row)) ?? '';
  form.body = row ? [
    `Referral for Intervention Plan item ${props.rowNumber}: ${row.problem?.trim() || 'need not described'}.`,
    row.objective?.trim() ? `Objective: ${row.objective.trim()}` : '',
    row.activities?.trim() ? `Activities: ${row.activities.trim()}` : '',
  ].filter(Boolean).join('\n') : '';
  form.defaults();
}
watch(() => props.show, async (open) => { if (open) { prefill(); await nextTick(); document.getElementById('plan-ref-to')?.focus(); } });

function chooseRecipient(value: string) {
  form.referral.recipient = value;
  const member = value.startsWith('cpu:') ? props.cpuMembers.find((item) => `cpu:${item.id}` === value) : null;
  form.referral.lgu_cpu_member_id = member ? member.id : null;
  form.referral.referred_to = member ? member.name : value;
  if (form.referral.purpose && !purposeOptions.value.includes(form.referral.purpose)) form.referral.purpose = '';
}
function setKind(kind: ReferralKind) { if (referralKind.value !== kind) { referralKind.value = kind; chooseRecipient(''); } }
const purposeOptions = computed(() => purposesFor(recipientAgency(form.referral.recipient, props.cpuMembers)));
watch(() => form.referral.purpose, (purpose) => {
  const hint = categoryHint(purpose);
  if (hint && (!form.referral.service_category || isHintedCategory(form.referral.service_category))) form.referral.service_category = hint;
});
const responseDue = computed(() => { const date = new Date(); date.setDate(date.getDate() + props.referralTargets.response_days); return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }); });

function send() {
  failures.value = [];
  form.transform((data) => ({
    note_type: 'referral', occurred_at: manilaInputToUtc(data.occurred_at), body: data.body, action_taken: data.action_taken,
    next_steps: data.next_steps || (data.referral.referred_to ? `Follow up with ${data.referral.referred_to} on their response by ${responseDue.value}.` : ''),
    referral: { referred_to: data.referral.referred_to, lgu_cpu_member_id: data.referral.lgu_cpu_member_id, purpose: data.referral.purpose, service_category: data.referral.service_category,
      target_date: data.referral.target_date || null, lawful_basis: data.referral.lawful_basis, plan_row: data.referral.plan_row || null },
  })).post(route('cases.notes.store', props.caseId), {
    preserveScroll: true,
    onError: (errors) => { failures.value = Object.values(errors).filter(Boolean); },
    onSuccess: () => { emit('sent'); emit('close'); },
  });
}
const title = computed(() => props.row ? `Conduct a referral - plan item ${props.rowNumber}` : 'Conduct a referral');
</script>

<template>
  <Modal :show="show" max-width="2xl" :aria-label="title" @close="emit('close')">
    <form class="kalinga-form space-y-4 p-5" data-skip-generic-confirm="true" autocomplete="off" @submit.prevent="send">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900"><Send class="h-5 w-5 text-sky-700" aria-hidden="true" />{{ title }}</h2>
          <p v-if="row?.problem" class="mt-1 text-sm text-slate-600">{{ row.problem }}</p>
        </div>
        <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-lg hover:bg-slate-100" aria-label="Close" :disabled="form.processing" @click="emit('close')"><X class="h-5 w-5" aria-hidden="true" /></button>
      </div>
      <div v-if="failures.length" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"><p class="font-semibold">Check the referral:</p><ul class="mt-1 list-disc pl-5"><li v-for="item in failures" :key="item">{{ item }}</li></ul></div>
      <fieldset :disabled="form.processing" class="space-y-4">
        <legend class="sr-only">Referral details</legend>
        <div role="radiogroup" aria-label="Referral to" class="flex flex-wrap gap-2">
          <label v-for="option in [{ value: 'cpu', label: 'Referral to CPU member' }, { value: 'external', label: 'Referral to external agency' }]" :key="option.value" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm" :class="referralKind === option.value ? 'border-sky-600 bg-sky-50 font-semibold text-sky-950' : 'border-slate-300 text-slate-800'">
            <input type="radio" name="plan-ref-kind" class="h-4 w-4 shrink-0" :value="option.value" :checked="referralKind === option.value" @change="setKind(option.value as ReferralKind)">{{ option.label }}
          </label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <FormField id="plan-ref-to" label="Refer to" :error="form.errors['referral.referred_to'] || form.errors['referral.lgu_cpu_member_id']" required :hint="referralKind === 'cpu' ? 'CPU members with a Kalinga account get access to this case for the referral only.' : 'The agency receives the referral outside the system; track its response here.'">
            <select id="plan-ref-to" :value="form.referral.recipient" required class="ui-field" @change="chooseRecipient(($event.target as HTMLSelectElement).value)">
              <option value="">{{ referralKind === 'cpu' ? 'Select CPU member or office' : 'Select external agency' }}</option>
              <template v-if="referralKind === 'cpu'">
                <optgroup v-if="cpuMembers.length" label="CPU members of this LGU"><option v-for="member in cpuMembers" :key="member.id" :value="`cpu:${member.id}`">{{ member.name }} - {{ member.cpu_role }}{{ member.has_account ? '' : ' (no account)' }}</option></optgroup>
                <optgroup label="CPU offices"><option v-for="option in CPU_RECIPIENTS" :key="option" :value="option">{{ option }}</option></optgroup>
              </template>
              <optgroup v-else label="External agencies"><option v-for="option in EXTERNAL_RECIPIENTS" :key="option" :value="option">{{ option }}</option></optgroup>
            </select>
          </FormField>
          <FormField id="plan-ref-purpose" label="Purpose of referral" :error="form.errors['referral.purpose']" required><OptionSelect id="plan-ref-purpose" v-model="form.referral.purpose" :options="purposeOptions.map((value) => ({ value, label: value }))" required /></FormField>
          <FormField id="plan-ref-category" label="Service category" :error="form.errors['referral.service_category']" required hint="Suggested from the plan item and the purpose; change it if needed."><OptionSelect id="plan-ref-category" v-model="form.referral.service_category" :options="SERVICE_CATEGORIES" required /></FormField>
          <FormField id="plan-ref-target" label="Expected by (optional)" :error="form.errors['referral.target_date']" hint="From the plan item's target end date."><input id="plan-ref-target" v-model="form.referral.target_date" type="date" :min="today" class="ui-field"></FormField>
          <FormField id="plan-ref-basis" label="Basis for sharing information" :error="form.errors['referral.lawful_basis']" required class="sm:col-span-2">
            <select id="plan-ref-basis" v-model="form.referral.lawful_basis" required class="ui-field"><option value="">Select basis</option><option value="consent" :disabled="!hasSharingConsent">Documented consent{{ hasSharingConsent ? '' : ' (no active sharing consent on record)' }}</option><option value="legal_obligation">Required by law</option><option value="vital_interests">Protection of life or health</option><option value="public_task">Public function of the office</option></select>
          </FormField>
          <FormField id="plan-ref-body" label="Reason / background for the receiving office" :error="form.errors.body" required class="sm:col-span-2" hint="Only what the receiving office needs. Saved as a progress note.">
            <textarea id="plan-ref-body" v-model="form.body" rows="4" maxlength="5000" required class="ui-field"></textarea>
          </FormField>
          <FormField id="plan-ref-time" label="When it was sent (Philippine time)" :error="form.errors.occurred_at" required><input id="plan-ref-time" v-model="form.occurred_at" type="datetime-local" required class="ui-field"></FormField>
        </div>
        <p class="text-xs text-sky-900">Sent now. Response due in {{ referralTargets.response_days }} days; completion due {{ referralTargets.completion_days }} days after acceptance.</p>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="ui-button-primary min-h-12 px-5"><Send class="h-4 w-4" aria-hidden="true" />Send the referral</button>
          <button type="button" class="ui-button min-h-12" @click="emit('close')">Cancel</button>
        </div>
      </fieldset>
    </form>
  </Modal>
</template>
