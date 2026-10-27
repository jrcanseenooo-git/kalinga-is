<script setup lang="ts">
/** Grants log of the case: every grant released, with the running total. Wrong entries are voided with a reason, never deleted. */
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/Components/UI/FormField.vue';
import type { GrantItem } from '@/types/reports';
import { calendarDateLabel } from '@/utils/philippineTime';
import { peso } from '@/utils/reportFormat';

const props = defineProps<{ caseId: number; grants: { items: GrantItem[]; total: number; count: number; types: Record<string, string> }; canEdit: boolean }>();
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const adding = ref(false);
const form = useForm({ grant_type: '', amount: '' as string | number, released_at: today, fund_source: '', purpose: '', received_by: '' });
const submit = () => form.post(route('cases.grants.store', props.caseId), { preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false; } });
const voiding = ref<number | null>(null);
const voidForm = useForm({ reason: '' });
const voidGrant = (grant: GrantItem) => voidForm.post(route('cases.grants.void', [props.caseId, grant.id]), { preserveScroll: true, onSuccess: () => { voidForm.reset(); voiding.value = null; } });
const byType = computed(() => {
  const totals = new Map<string, number>();
  for (const item of props.grants.items) if (!item.voided_at) totals.set(item.type_label, (totals.get(item.type_label) ?? 0) + item.amount);
  return [...totals.entries()];
});
</script>

<template>
  <section class="ui-panel overflow-hidden p-0" aria-labelledby="grants-title">
    <header class="ui-panel-header flex-wrap gap-2">
      <div><h3 id="grants-title" class="ui-section-title">Grants log</h3><p class="text-xs text-slate-600">{{ grants.count }} {{ grants.count === 1 ? 'grant' : 'grants' }} released - total <b class="text-slate-900">{{ peso(grants.total) }}</b></p></div>
      <button v-if="canEdit" type="button" class="ui-button min-h-10 px-3 text-sm" :aria-expanded="adding" @click="adding = !adding"><Plus class="h-4 w-4" aria-hidden="true" />Record a grant</button>
    </header>
    <form v-if="adding" class="grid gap-3 border-b border-[var(--color-line)] bg-brand-50/40 p-4 sm:grid-cols-3" data-skip-generic-confirm="true" @submit.prevent="submit">
      <FormField id="grant-type" label="Type of grant" :error="form.errors.grant_type" required><select id="grant-type" v-model="form.grant_type" class="ui-field" required><option value="" disabled>Choose</option><option v-for="(label, code) in grants.types" :key="code" :value="code">{{ label }}</option></select></FormField>
      <FormField id="grant-amount" label="Amount (PHP)" :error="form.errors.amount" required><input id="grant-amount" v-model="form.amount" type="number" min="1" step="0.01" inputmode="decimal" class="ui-field" required></FormField>
      <FormField id="grant-date" label="Date released" :error="form.errors.released_at" required><input id="grant-date" v-model="form.released_at" type="date" :max="today" class="ui-field" required></FormField>
      <FormField id="grant-fund" label="Fund source" :error="form.errors.fund_source" hint="For example: LGU, DSWD Field Office, partner NGO"><input id="grant-fund" v-model="form.fund_source" maxlength="160" class="ui-field"></FormField>
      <FormField id="grant-purpose" label="Purpose" :error="form.errors.purpose"><input id="grant-purpose" v-model="form.purpose" maxlength="255" class="ui-field"></FormField>
      <FormField id="grant-received" label="Received by" :error="form.errors.received_by" hint="The child or the parent / guardian"><input id="grant-received" v-model="form.received_by" maxlength="160" class="ui-field"></FormField>
      <div class="flex gap-2 sm:col-span-3"><button type="submit" class="ui-button-primary" :disabled="form.processing">Save grant</button><button type="button" class="ui-button" @click="adding = false">Cancel</button></div>
    </form>
    <ul v-if="byType.length > 1" class="flex flex-wrap gap-2 px-4 pt-3 text-xs"><li v-for="[label, amount] in byType" :key="label" class="rounded-full bg-slate-100 px-2.5 py-1">{{ label }}: <b>{{ peso(amount) }}</b></li></ul>
    <table v-if="grants.items.length" class="ui-table ui-table-stack">
      <thead><tr><th>Date</th><th>Grant</th><th>Amount</th><th>Fund source</th><th>Received by</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <tr v-for="item in grants.items" :key="item.id" :class="item.voided_at ? 'text-slate-600 line-through' : ''">
          <td data-label="Date">{{ calendarDateLabel(item.released_at) }}</td>
          <td data-label="Grant">{{ item.type_label }}<span v-if="item.purpose" class="block text-xs text-slate-600">{{ item.purpose }}</span><span v-if="item.voided_at" class="block text-xs no-underline">Voided: {{ item.void_reason }}</span></td>
          <td data-label="Amount" class="font-semibold">{{ peso(item.amount) }}</td>
          <td data-label="Fund source">{{ item.fund_source || '-' }}</td>
          <td data-label="Received by">{{ item.received_by || '-' }}</td>
          <td>
            <button v-if="canEdit && !item.voided_at && voiding !== item.id" type="button" class="text-xs text-rose-700 underline" @click="voiding = item.id">Void</button>
            <form v-if="voiding === item.id" class="flex flex-wrap gap-1" data-skip-generic-confirm="true" @submit.prevent="voidGrant(item)"><label :for="`void-${item.id}`" class="sr-only">Reason for voiding</label><input :id="`void-${item.id}`" v-model="voidForm.reason" placeholder="Reason" minlength="5" maxlength="500" required class="ui-field min-h-9 text-xs"><button type="submit" class="ui-button min-h-9 px-2 text-xs" :disabled="voidForm.processing">Void</button><p v-if="voidForm.errors.reason" class="w-full text-xs text-rose-700">{{ voidForm.errors.reason }}</p></form>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="px-4 py-5 text-sm text-slate-600">No grants recorded yet.</p>
  </section>
</template>
