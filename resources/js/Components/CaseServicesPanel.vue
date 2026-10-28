<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { HandHeart } from '@lucide/vue';
import { shortDate } from '@/utils/caseDisplay';
import type { PageProps } from '@/types';

/** Services provided to the child and family (referrals are with the Intervention Plan). */
type ServiceItem = { id: number; service_type: string; specific_detail: string | null; amount: string | number | null; date_provided: string; provided_by: string | null; recorded_by_name?: string | null };
const props = defineProps<{ caseId: number; services?: ServiceItem[] }>();
const emit = defineEmits<{ compose: [kind: 'service_delivery'] }>();
const services = computed(() => props.services ?? []);
const peso = (value: string | number | null) => value === null || value === '' ? '-' : new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
const assistanceTotal = computed(() => services.value.reduce((sum, item) => sum + Number(item.amount || 0), 0));
const byType = computed(() => { const map = new Map<string, number>(); for (const item of services.value) map.set(item.service_type, (map.get(item.service_type) ?? 0) + 1); return [...map.entries()].sort((a, b) => b[1] - a[1]); });
const page = usePage<PageProps>();
const canRefer = computed(() => page.props.auth.permissions.includes('referrals.create'));
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

    <slot />
  </div>
</template>
