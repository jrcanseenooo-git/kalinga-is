<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Building2, HandHeart, Send, Users } from '@lucide/vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ReferralTracker from '@/Components/Referrals/ReferralTracker.vue';
import { followUp, type ReferralTargets, type TrackedReferral } from '@/utils/referralTracking';
import { referralTone, shortDate, titleCase } from '@/utils/caseDisplay';
import { isCpuReferral } from '@/utils/caseOptions';
import type { PageProps } from '@/types';

type CaseReferral = TrackedReferral & { referred_to?: string | null; purpose?: string | null; lgu_cpu_member_id?: number | null };
type ServiceItem = { id: number; service_type: string; specific_detail: string | null; amount: string | number | null; date_provided: string; provided_by: string | null; recorded_by_name?: string | null };
const props = defineProps<{
  caseId: number;
  referrals: CaseReferral[];
  referralTargets: ReferralTargets;
  services?: ServiceItem[];
}>();
const opened = ref<number | null>(null);
const due = (item: CaseReferral) => followUp(item, props.referralTargets);
const emit = defineEmits<{ compose: [kind: 'referral_cpu' | 'referral_external' | 'service_delivery'] }>();
const services = computed(() => props.services ?? []);
const peso = (value: string | number | null) => value === null || value === '' ? '-' : new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
const assistanceTotal = computed(() => services.value.reduce((sum, item) => sum + Number(item.amount || 0), 0));
const byType = computed(() => { const map = new Map<string, number>(); for (const item of services.value) map.set(item.service_type, (map.get(item.service_type) ?? 0) + 1); return [...map.entries()].sort((a, b) => b[1] - a[1]); });
const page = usePage<PageProps>();
const canRefer = computed(() => page.props.auth.permissions.includes('referrals.create'));
const sortedReferrals = computed(() => [...props.referrals].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at))));
const groups = computed(() => [
  { key: 'cpu' as const, title: 'Referrals to CPU members', hint: 'Offices and members of the LGU Child Protection Unit. Members with a Kalinga account get access to this case for the referral only.', icon: Users, compose: 'referral_cpu' as const, button: 'Refer to CPU member', items: sortedReferrals.value.filter(item => isCpuReferral(item)) },
  { key: 'external' as const, title: 'Referrals to external agencies', hint: 'Partner agencies outside the CPU, such as WCPU, PNP, PAO, DepEd, NCIP or NGOs.', icon: Building2, compose: 'referral_external' as const, button: 'Refer to external agency', items: sortedReferrals.value.filter(item => !isCpuReferral(item)) },
]);
const lastUpdate = (item: CaseReferral) => item.completed_at || item.accepted_at || item.referred_at || item.created_at;
</script>

<template>
  <div class="space-y-5">
    <!-- Services provided (highlighted, as in the earlier Kalinga design) -->
    <section class="overflow-hidden rounded-xl border-2 border-emerald-200 bg-white shadow-[var(--shadow-card)]" aria-labelledby="case-services-title">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-emerald-100 bg-emerald-50/70 px-5 py-4">
        <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-600 text-white"><HandHeart class="h-5 w-5" aria-hidden="true" /></span><div><h2 id="case-services-title" class="ui-section-title">Services provided</h2><p class="text-xs text-emerald-900">What the child and family actually received.</p></div></div>
        <div class="flex flex-wrap items-center gap-3"><p class="text-right text-xs text-emerald-900"><span class="block text-lg font-bold">{{ peso(assistanceTotal) }}</span>total assistance</p><button v-if="canRefer" type="button" class="ui-button ui-button-primary min-h-10 gap-1.5 text-sm" @click="emit('compose', 'service_delivery')"><HandHeart class="h-4 w-4" aria-hidden="true" />Record service</button></div>
      </header>
      <div v-if="byType.length" class="flex flex-wrap gap-2 px-5 pt-4"><span v-for="[type, count] in byType" :key="type" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-900">{{ type }} - {{ count }}</span></div>
      <table v-if="services.length" class="ui-table ui-table-stack mt-2">
        <thead><tr><th scope="col">Date provided</th><th scope="col">Service</th><th scope="col">Details</th><th scope="col">Provided by</th><th scope="col" class="text-right">Amount</th></tr></thead>
        <tbody><tr v-for="item in services" :key="item.id"><td data-label="Date" class="whitespace-nowrap">{{ shortDate(item.date_provided) }}</td><td data-label="Service" class="font-medium text-slate-800">{{ item.service_type }}</td><td data-label="Details" class="max-w-xs break-words">{{ item.specific_detail || '-' }}</td><td data-label="Provided by">{{ item.provided_by || '-' }}</td><td data-label="Amount" class="text-right font-semibold">{{ item.amount ? peso(item.amount) : '-' }}</td></tr></tbody>
      </table>
      <p v-else class="px-5 py-6 text-sm text-slate-600">No services recorded yet. Record each service given, such as medical, financial, transport or educational help.</p>
    </section>

    <!-- Referrals: CPU members and external agencies are tracked separately -->
    <section v-for="group in groups" :key="group.key" class="ui-panel overflow-hidden p-0" :aria-labelledby="`case-referrals-${group.key}`">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
        <div class="flex min-w-0 items-start gap-3">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="group.key === 'cpu' ? 'bg-brand-100 text-brand-800' : 'bg-sky-100 text-sky-800'"><component :is="group.icon" class="h-5 w-5" aria-hidden="true" /></span>
          <div class="min-w-0"><h2 :id="`case-referrals-${group.key}`" class="ui-section-title">{{ group.title }} ({{ group.items.length }})</h2><p class="ui-muted mt-0.5 max-w-2xl">{{ group.hint }}</p></div>
        </div>
        <button v-if="canRefer" type="button" class="ui-button min-h-10 gap-1.5 text-sm" @click="emit('compose', group.compose)"><Send class="h-4 w-4" aria-hidden="true" />{{ group.button }}</button>
      </header>
      <table v-if="group.items.length" class="ui-table ui-table-stack">
        <thead><tr><th scope="col">Date</th><th scope="col">Referred to</th><th scope="col">Purpose</th><th scope="col">Status</th><th scope="col">Remarks</th><th scope="col"><span class="sr-only">Tracking</span></th></tr></thead>
        <tbody>
          <template v-for="item in group.items" :key="item.id">
          <tr>
            <td data-label="Date" class="whitespace-nowrap">{{ shortDate(lastUpdate(item)) }}</td>
            <td data-label="Referred to" class="font-medium text-slate-800">{{ item.referred_to || 'Not selected' }}</td>
            <td data-label="Purpose">{{ item.purpose || item.service_category }}</td>
            <td data-label="Status"><div><StatusBadge :label="titleCase(item.status)" :tone="referralTone(item.status)" dot /><span v-if="due(item)" class="mt-1 block text-xs font-medium" :class="due(item)!.overdue ? 'text-rose-700' : due(item)!.tone === 'warning' ? 'text-amber-800' : 'text-slate-600'">{{ due(item)!.label }}</span></div></td>
            <td data-label="Remarks" class="max-w-xs"><span class="line-clamp-2 break-words text-slate-600" :title="item.reason ?? ''">{{ item.reason || '-' }}</span></td>
            <td class="text-right"><button type="button" class="ui-link min-h-9 text-xs" :aria-expanded="opened === item.id" :aria-controls="`case-referral-track-${item.id}`" @click="opened = opened === item.id ? null : item.id">{{ opened === item.id ? 'Hide tracking' : 'Track' }}<span class="sr-only"> {{ item.service_category }}</span></button></td>
          </tr>
          <tr v-if="opened === item.id" :id="`case-referral-track-${item.id}`" class="bg-[#faf9fd] hover:bg-[#faf9fd]"><td colspan="6" class="px-5 py-4"><ReferralTracker :referral="item" :targets="referralTargets" /><slot name="status-form" :referral="item" /></td></tr>
          </template>
        </tbody>
      </table>
      <p v-else class="px-5 py-5 text-sm text-slate-600">None yet.</p>
    </section>

    <slot />
  </div>
</template>
