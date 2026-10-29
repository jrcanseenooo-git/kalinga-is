<script setup lang="ts">
/**
 * Services provided by type, as a ranked horizontal bar chart: one bar per
 * service type (one hue, the bars show the number of services), the count
 * and share at the end of each bar, and the cases served and amount given
 * under it. Hover or focus a row for its figures; a data table is included.
 */
import { computed, ref } from 'vue';

export type ServiceRow = { label: string; total: number; cases?: number | 'Suppressed'; amount?: number };
const props = defineProps<{ title: string; rows: ServiceRow[] }>();

const rows = computed(() => [...props.rows].sort((a, b) => b.total - a.total));
const max = computed(() => Math.max(1, ...rows.value.map((row) => row.total)));
const total = computed(() => rows.value.reduce((sum, row) => sum + row.total, 0));
const amount = computed(() => rows.value.reduce((sum, row) => sum + (row.amount ?? 0), 0));
const share = (row: ServiceRow) => (total.value ? Math.round((row.total / total.value) * 100) : 0);
const peso = (value: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value);
const cases = (row: ServiceRow) => (row.cases === undefined ? '' : row.cases === 'Suppressed' ? 'cases withheld' : `${row.cases} ${row.cases === 1 ? 'case' : 'cases'} served`);
const detail = (row: ServiceRow) => [cases(row), row.amount ? peso(row.amount) : ''].filter(Boolean).join(' - ');
const describe = (row: ServiceRow) => `${row.label}: ${row.total} ${row.total === 1 ? 'service' : 'services'}, ${share(row)}%${detail(row) ? `, ${detail(row)}` : ''}`;
const active = ref<string | null>(null);
// Gridlines at round steps so the bars can be read against a scale.
const ticks = computed(() => {
  const step = max.value <= 5 ? 1 : max.value <= 10 ? 2 : Math.ceil(max.value / 5 / 5) * 5;
  const top = Math.ceil(max.value / step) * step;
  return { top, values: Array.from({ length: top / step + 1 }, (_, i) => i * step) };
});
const width = (value: number) => `${(value / ticks.value.top) * 100}%`;
</script>

<template>
  <div v-if="rows.length">
    <dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
      <div><dt class="text-xs text-slate-600">Services recorded</dt><dd class="text-xl font-extrabold text-slate-900">{{ total }}</dd></div>
      <div v-if="amount"><dt class="text-xs text-slate-600">Amount given</dt><dd class="text-xl font-extrabold text-slate-900">{{ peso(amount) }}</dd></div>
    </dl>
    <div class="mt-4 grid grid-cols-[minmax(6rem,11rem)_minmax(0,1fr)] gap-x-3 sm:grid-cols-[minmax(8rem,13rem)_minmax(0,1fr)]">
      <span aria-hidden="true"></span>
      <div class="relative h-4 text-[11px] text-slate-600" aria-hidden="true">
        <span v-for="value in ticks.values" :key="value" class="absolute -translate-x-1/2 tabular-nums" :style="{ left: width(value) }">{{ value }}</span>
      </div>
    </div>
    <ol class="mt-1 space-y-1" :aria-label="`${title}. Figures are printed beside each bar.`">
      <li v-for="row in rows" :key="row.label" tabindex="0" class="relative grid grid-cols-[minmax(6rem,11rem)_minmax(0,1fr)] items-center gap-x-3 rounded-lg px-1 py-1.5 outline-none focus-visible:ring-2 focus-visible:ring-brand-300 sm:grid-cols-[minmax(8rem,13rem)_minmax(0,1fr)]"
        :class="active === row.label ? 'bg-slate-50' : ''" :aria-label="describe(row)" @mouseenter="active = row.label" @mouseleave="active = null" @focus="active = row.label" @blur="active = null">
        <span class="min-w-0 text-sm font-semibold leading-5 text-slate-800">{{ row.label }}</span>
        <div class="min-w-0">
          <div class="relative h-7">
            <span v-for="value in ticks.values.slice(1)" :key="value" class="absolute inset-y-0 w-px bg-slate-100" :style="{ left: width(value) }" aria-hidden="true" />
            <div class="relative flex h-full items-center">
              <span class="h-full rounded-r-[4px] bg-emerald-600" :style="{ width: width(row.total), minWidth: '4px' }" aria-hidden="true" />
              <span class="ml-2 whitespace-nowrap text-sm font-bold tabular-nums text-slate-900">{{ row.total }} <span class="font-normal text-slate-600">({{ share(row) }}%)</span></span>
            </div>
          </div>
          <p v-if="detail(row)" class="mt-0.5 text-xs text-slate-600">{{ detail(row) }}</p>
        </div>
        <span v-if="active === row.label" class="pointer-events-none absolute right-2 top-0 z-10 -translate-y-full rounded-md bg-slate-900 px-2 py-1 text-xs text-white shadow" role="tooltip">{{ describe(row) }}</span>
      </li>
    </ol>
    <details class="mt-3 border-t border-slate-100 pt-1">
      <summary class="min-h-11 cursor-pointer py-3 text-xs font-semibold text-brand-700">View data table</summary>
      <table class="w-full text-left text-xs"><caption class="sr-only">{{ title }}</caption>
        <thead><tr><th scope="col" class="py-1.5">Service</th><th scope="col" class="py-1.5 text-right">Services</th><th scope="col" class="py-1.5 text-right">Cases served</th><th scope="col" class="py-1.5 text-right">Amount</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.label" class="border-t border-slate-100"><th scope="row" class="py-1.5 font-normal">{{ row.label }}</th><td class="py-1.5 text-right">{{ row.total }}</td><td class="py-1.5 text-right">{{ row.cases === 'Suppressed' ? 'Withheld' : row.cases ?? '-' }}</td><td class="py-1.5 text-right">{{ row.amount ? peso(row.amount) : '-' }}</td></tr></tbody>
      </table>
    </details>
  </div>
  <p v-else class="text-sm text-slate-600">No services recorded for this selection.</p>
</template>
