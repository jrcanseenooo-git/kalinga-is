<script setup lang="ts">
/**
 * Grants log of the case. The only grant released through Kalinga is the
 * Transportation Support Allowance (owner, 2026-10-28): each release is
 * recorded with the amount, date, purpose of the travel and who received it.
 * Wrong entries are voided with a reason, never deleted.
 */
import { useForm } from '@inertiajs/vue3';
import { Bus, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/Components/UI/FormField.vue';
import type { GrantItem } from '@/types/reports';
import { calendarDateLabel } from '@/utils/philippineTime';
import { peso } from '@/utils/reportFormat';

const props = defineProps<{ caseId: number; grants: { items: GrantItem[]; total: number; count: number; types: Record<string, string>; purposes?: string[] }; canEdit: boolean }>();
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const adding = ref(false);
const RECEIVERS = ['The child', 'Parent', 'Guardian', 'Primary caregiver'];
const form = useForm({ amount: '' as string | number, released_at: today, purpose: '', received_by: '' });
const submit = () => form.post(route('cases.grants.store', props.caseId), { preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false; } });
const voiding = ref<number | null>(null);
const voidForm = useForm({ reason: '' });
const voidGrant = (grant: GrantItem) => voidForm.post(route('cases.grants.void', [props.caseId, grant.id]), { preserveScroll: true, onSuccess: () => { voidForm.reset(); voiding.value = null; } });
const active = computed(() => props.grants.items.filter((item) => !item.voided_at));
const latest = computed(() => active.value[0] ?? null);
const title = computed(() => Object.values(props.grants.types)[0] ?? 'Transportation Support Allowance');
</script>

<template>
  <section class="ui-panel overflow-hidden p-0" aria-labelledby="grants-title">
    <header class="ui-panel-header flex-wrap gap-2">
      <div class="flex min-w-0 items-start gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-800"><Bus class="h-5 w-5" aria-hidden="true" /></span>
        <div class="min-w-0"><h3 id="grants-title" class="ui-section-title">Grants log</h3><p class="text-xs text-slate-600">{{ title }}</p></div>
      </div>
      <button v-if="canEdit" type="button" class="ui-button min-h-11 px-3 text-sm" :aria-expanded="adding" aria-controls="grant-form" @click="adding = !adding"><Plus class="h-4 w-4" aria-hidden="true" />Record a release</button>
    </header>
    <dl class="grid grid-cols-3 gap-2 px-4 pt-4 text-sm">
      <div class="rounded-lg bg-amber-50 p-3"><dt class="text-xs text-amber-900">Total released</dt><dd class="text-lg font-bold text-slate-900">{{ peso(grants.total) }}</dd></div>
      <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-600">Releases</dt><dd class="text-lg font-bold text-slate-900">{{ grants.count }}</dd></div>
      <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-600">Last release</dt><dd class="font-semibold text-slate-900">{{ latest ? calendarDateLabel(latest.released_at) : '-' }}</dd></div>
    </dl>
    <form v-if="adding" id="grant-form" class="m-4 grid gap-3 rounded-xl border border-amber-200 bg-amber-50/50 p-4 sm:grid-cols-2" data-skip-generic-confirm="true" @submit.prevent="submit">
      <p class="text-sm font-semibold text-slate-900 sm:col-span-2">{{ title }}</p>
      <FormField id="grant-amount" label="Amount (PHP)" :error="form.errors.amount" required><input id="grant-amount" v-model="form.amount" type="number" min="1" step="0.01" inputmode="decimal" class="ui-field" required></FormField>
      <FormField id="grant-date" label="Date released" :error="form.errors.released_at" required><input id="grant-date" v-model="form.released_at" type="date" :max="today" class="ui-field" required></FormField>
      <FormField id="grant-purpose" label="Purpose of the travel" :error="form.errors.purpose" required class="sm:col-span-2" hint="Choose or type, for example: Referral to a service provider.">
        <input id="grant-purpose" v-model="form.purpose" list="grant-purposes" maxlength="255" class="ui-field" required>
        <datalist id="grant-purposes"><option v-for="item in grants.purposes ?? []" :key="item" :value="item" /></datalist>
      </FormField>
      <div class="flex flex-wrap gap-2 sm:col-span-2" aria-label="Common purposes"><button v-for="item in (grants.purposes ?? []).slice(0, 4)" :key="item" type="button" class="rounded-full border px-3 py-1.5 text-xs" :class="form.purpose === item ? 'border-amber-500 bg-amber-100 font-semibold text-amber-950' : 'border-slate-300 bg-white text-slate-700'" @click="form.purpose = item">{{ item }}</button></div>
      <FormField id="grant-received" label="Received by" :error="form.errors.received_by" required hint="The child or the parent / guardian who received the allowance.">
        <input id="grant-received" v-model="form.received_by" list="grant-receivers" maxlength="160" class="ui-field" required>
        <datalist id="grant-receivers"><option v-for="item in RECEIVERS" :key="item" :value="item" /></datalist>
      </FormField>
      <div class="flex flex-wrap items-end gap-2 sm:col-span-2"><button type="submit" class="ui-button-primary min-h-11" :disabled="form.processing">Save release</button><button type="button" class="ui-button min-h-11" @click="adding = false">Cancel</button></div>
    </form>
    <table v-if="grants.items.length" class="ui-table ui-table-stack mt-3">
      <thead><tr><th>Date released</th><th>Purpose</th><th>Amount</th><th>Received by</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <tr v-for="item in grants.items" :key="item.id" :class="item.voided_at ? 'text-slate-600 line-through' : ''">
          <td data-label="Date">{{ calendarDateLabel(item.released_at) }}</td>
          <td data-label="Purpose">{{ item.purpose || '-' }}<span v-if="item.grant_type !== 'transportation'" class="block text-xs text-slate-600">{{ item.type_label }} (recorded earlier)</span><span v-if="item.voided_at" class="block text-xs no-underline">Voided: {{ item.void_reason }}</span></td>
          <td data-label="Amount" class="font-semibold">{{ peso(item.amount) }}</td>
          <td data-label="Received by">{{ item.received_by || '-' }}</td>
          <td>
            <button v-if="canEdit && !item.voided_at && voiding !== item.id" type="button" class="min-h-9 text-xs text-rose-700 underline" @click="voiding = item.id">Void</button>
            <form v-if="voiding === item.id" class="flex flex-wrap gap-1" data-skip-generic-confirm="true" @submit.prevent="voidGrant(item)"><label :for="`void-${item.id}`" class="sr-only">Reason for voiding</label><input :id="`void-${item.id}`" v-model="voidForm.reason" placeholder="Reason" minlength="5" maxlength="500" required class="ui-field min-h-9 text-xs"><button type="submit" class="ui-button min-h-9 px-2 text-xs" :disabled="voidForm.processing">Void</button><p v-if="voidForm.errors.reason" class="w-full text-xs text-rose-700">{{ voidForm.errors.reason }}</p></form>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="px-4 py-5 text-sm text-slate-600">No transportation allowance released yet.</p>
  </section>
</template>
