<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { HandHeart, RefreshCw, Send } from '@lucide/vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ReferralTracker from '@/Components/Referrals/ReferralTracker.vue';
import { followUp, type ReferralTargets, type TrackedReferral } from '@/utils/referralTracking';
import { progressTone, referralTone, shortDate, titleCase } from '@/utils/caseDisplay';
import type { PlanIntervention } from '@/types/planning';
import type { PageProps } from '@/types';

type CaseReferral = TrackedReferral & { referred_to?: string | null; purpose?: string | null };
type ServiceItem = { id: number; service_type: string; specific_detail: string | null; amount: string | number | null; date_provided: string; provided_by: string | null; recorded_by_name?: string | null };
const props = defineProps<{
  caseId: number;
  referrals: CaseReferral[];
  interventions: PlanIntervention[];
  planStatus: 'idle' | 'loading' | 'ready' | 'denied' | 'failed';
  hasPlan: boolean;
  referralTargets: ReferralTargets;
  services?: ServiceItem[];
}>();
const opened = ref<number | null>(null);
const due = (item: CaseReferral) => followUp(item, props.referralTargets);
const emit = defineEmits<{ reloadPlan: []; compose: [kind: 'referral' | 'service_delivery'] }>();
const services = computed(() => props.services ?? []);
const peso = (value: string | number | null) => value === null || value === '' ? '-' : new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
const assistanceTotal = computed(() => services.value.reduce((sum, item) => sum + Number(item.amount || 0), 0));
const byType = computed(() => { const map = new Map<string, number>(); for (const item of services.value) map.set(item.service_type, (map.get(item.service_type) ?? 0) + 1); return [...map.entries()].sort((a, b) => b[1] - a[1]); });
const page = usePage<PageProps>();
const canRefer = computed(() => page.props.auth.permissions.includes('referrals.create'));
const sortedReferrals = computed(() => [...props.referrals].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at))));
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

    <!-- Referrals -->
    <section class="ui-panel overflow-hidden p-0" aria-labelledby="case-referrals-title">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
        <div>
          <h2 id="case-referrals-title" class="ui-section-title">Referrals</h2>
          <p class="ui-muted mt-0.5">Referrals are written as progress notes, so the reason stays with the referral. Track and update each one here or from its note.</p>
        </div>
        <button v-if="canRefer" type="button" class="ui-button ui-button-primary min-h-10 gap-1.5 text-sm" @click="emit('compose', 'referral')"><Send class="h-4 w-4" aria-hidden="true" />New referral</button>
      </header>
      <table v-if="sortedReferrals.length" class="ui-table ui-table-stack">
        <thead><tr><th scope="col">Date</th><th scope="col">Referred to</th><th scope="col">Purpose</th><th scope="col">Status</th><th scope="col">Remarks</th><th scope="col"><span class="sr-only">Tracking</span></th></tr></thead>
        <tbody>
          <template v-for="item in sortedReferrals" :key="item.id">
          <tr>
            <td data-label="Date" class="whitespace-nowrap">{{ shortDate(lastUpdate(item)) }}</td>
            <td data-label="Referred to" class="font-medium text-slate-800">{{ item.referred_to || item.provider || 'Not selected' }}</td>
            <td data-label="Purpose">{{ item.purpose || item.service_category }}</td>
            <td data-label="Status"><div><StatusBadge :label="titleCase(item.status)" :tone="referralTone(item.status)" dot /><span v-if="due(item)" class="mt-1 block text-xs font-medium" :class="due(item)!.overdue ? 'text-rose-700' : due(item)!.tone === 'warning' ? 'text-amber-800' : 'text-slate-600'">{{ due(item)!.label }}</span></div></td>
            <td data-label="Remarks" class="max-w-xs"><span class="line-clamp-2 break-words text-slate-600" :title="item.reason ?? ''">{{ item.reason || '-' }}</span></td>
            <td class="text-right"><button type="button" class="ui-link min-h-9 text-xs" :aria-expanded="opened === item.id" :aria-controls="`case-referral-track-${item.id}`" @click="opened = opened === item.id ? null : item.id">{{ opened === item.id ? 'Hide tracking' : 'Track' }}<span class="sr-only"> {{ item.service_category }}</span></button></td>
          </tr>
          <tr v-if="opened === item.id" :id="`case-referral-track-${item.id}`" class="bg-[#faf9fd] hover:bg-[#faf9fd]"><td colspan="6" class="px-5 py-4"><ReferralTracker :referral="item" :targets="referralTargets" /><slot name="status-form" :referral="item" /></td></tr>
          </template>
        </tbody>
      </table>
      <p v-if="!sortedReferrals.length" class="px-5 py-6 text-sm text-slate-600">No referrals recorded for this case.</p>
    </section>

    <!-- Interventions -->
    <section class="ui-panel overflow-hidden p-0" aria-labelledby="case-interventions-title">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
        <div>
          <h2 id="case-interventions-title" class="ui-section-title">Interventions and services</h2>
          <p class="ui-muted mt-0.5">Services planned under the current case plan. Add or update them in the case plan.</p>
        </div>
        <div class="flex gap-2">
          <button v-if="planStatus === 'failed'" type="button" class="ui-button min-h-10 gap-1.5 text-sm" @click="emit('reloadPlan')"><RefreshCw class="h-4 w-4" aria-hidden="true" />Retry</button>
          <Link :href="route('cases.plans.workspace', caseId)" class="ui-button min-h-10 text-sm">{{ hasPlan ? 'Manage in case plan' : 'Open case plan' }}</Link>
        </div>
      </header>
      <p v-if="planStatus === 'loading' || planStatus === 'idle'" role="status" class="px-5 py-6 text-sm text-slate-600">Loading interventions...</p>
      <p v-else-if="planStatus === 'denied'" class="px-5 py-6 text-sm text-slate-600">Interventions are available in the case plan workspace for authorized staff.</p>
      <p v-else-if="planStatus === 'failed'" role="alert" class="px-5 py-6 text-sm text-rose-800">Interventions could not be loaded. Check your connection and retry.</p>
      <template v-else>
        <table v-if="interventions.length" class="ui-table ui-table-stack">
          <thead><tr><th scope="col">Service type</th><th scope="col">Activity</th><th scope="col">Provider / responsible</th><th scope="col">Target</th><th scope="col">Status</th><th scope="col">Next action</th></tr></thead>
          <tbody>
            <tr v-for="item in interventions" :key="item.id">
              <td data-label="Service" class="font-medium text-slate-800">{{ item.service_category }}</td>
              <td data-label="Activity" class="max-w-xs"><span class="line-clamp-2 break-words">{{ item.activity || '-' }}</span></td>
              <td data-label="Provider">{{ item.provider_name || item.responsible_office || '-' }}</td>
              <td data-label="Target" class="whitespace-nowrap">{{ shortDate(item.target_date) || '-' }}</td>
              <td data-label="Status"><StatusBadge :label="titleCase(item.progress)" :tone="progressTone(item.progress)" dot /></td>
              <td data-label="Next action" class="max-w-xs"><span class="line-clamp-2 break-words text-slate-600">{{ item.next_action || '-' }}</span></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!interventions.length" class="px-5 py-6 text-sm text-slate-600">{{ hasPlan ? 'No interventions in the current case plan yet.' : 'No case plan yet. Interventions are planned in the case plan.' }}</p>
      </template>
    </section>

    <slot />
  </div>
</template>
