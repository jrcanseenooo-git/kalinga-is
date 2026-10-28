<script setup lang="ts">
/**
 * Age and sex by exact current age (a population pyramid): girls to the
 * left, boys to the right, one row per year of age. Counts are printed next
 * to every bar, a legend names both sides, and withheld cells say so (they
 * are not zero). Hover or focus a row for its figures.
 */
import { computed, ref } from 'vue';

export type AgeSexRow = { age: string; female: number | null; male: number | null };
const props = defineProps<{ title: string; rows: AgeSexRow[] }>();
const FEMALE = '#e0607e';
const MALE = '#3b82d6';
const max = computed(() => Math.max(1, ...props.rows.flatMap((row) => [row.female ?? 0, row.male ?? 0])));
const width = (value: number | null) => `${value ? Math.max(3, (value / max.value) * 100) : 0}%`;
const label = (row: AgeSexRow) => `${row.age === 'Not recorded' ? 'Age not recorded' : `Age ${row.age}`}: ${row.female === null ? 'female withheld' : `${row.female} female`}, ${row.male === null ? 'male withheld' : `${row.male} male`}`;
const active = ref<string | null>(null);
const withheld = computed(() => props.rows.some((row) => row.female === null || row.male === null));
const totals = computed(() => ({ female: props.rows.reduce((sum, row) => sum + (row.female ?? 0), 0), male: props.rows.reduce((sum, row) => sum + (row.male ?? 0), 0) }));
</script>

<template>
  <div v-if="rows.length" class="mx-auto max-w-3xl">
    <ul class="mb-3 flex flex-wrap items-center gap-4 text-xs text-slate-700" aria-label="Legend">
      <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" :style="{ background: FEMALE }" aria-hidden="true" />Female{{ withheld ? '' : ` (${totals.female})` }}</li>
      <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" :style="{ background: MALE }" aria-hidden="true" />Male{{ withheld ? '' : ` (${totals.male})` }}</li>
      <li v-if="withheld" class="text-slate-600">Small groups are withheld for privacy (not zero).</li>
    </ul>
    <div class="grid grid-cols-[minmax(0,1fr)_3.25rem_minmax(0,1fr)] gap-x-1 text-[11px] font-semibold text-slate-600" aria-hidden="true"><span class="text-right">Female</span><span class="text-center">Age</span><span>Male</span></div>
    <ol class="mt-1 space-y-[2px]" role="list" :aria-label="`${title}. Counts are printed beside each bar.`">
      <li v-for="row in rows" :key="row.age" tabindex="0" class="relative grid grid-cols-[minmax(0,1fr)_3.25rem_minmax(0,1fr)] items-center gap-x-1 rounded outline-none focus-visible:ring-2 focus-visible:ring-brand-300"
        :class="active === row.age ? 'bg-slate-50' : ''" :aria-label="label(row)" @mouseenter="active = row.age" @mouseleave="active = null" @focus="active = row.age" @blur="active = null">
        <div class="flex h-6 items-center justify-end gap-1">
          <span class="text-xs tabular-nums" :class="row.female === null ? 'italic text-slate-600' : 'text-slate-800'">{{ row.female === null ? 'withheld' : row.female || '' }}</span>
          <span class="h-5 rounded-l" :style="{ width: width(row.female), background: FEMALE }" />
        </div>
        <span class="text-center text-xs font-semibold tabular-nums text-slate-800">{{ row.age === 'Not recorded' ? 'n/r' : row.age }}</span>
        <div class="flex h-6 items-center gap-1">
          <span class="h-5 rounded-r" :style="{ width: width(row.male), background: MALE }" />
          <span class="text-xs tabular-nums" :class="row.male === null ? 'italic text-slate-600' : 'text-slate-800'">{{ row.male === null ? 'withheld' : row.male || '' }}</span>
        </div>
        <span v-if="active === row.age" class="pointer-events-none absolute left-1/2 top-full z-10 mt-1 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white shadow" role="tooltip">{{ label(row) }}</span>
      </li>
    </ol>
    <details class="mt-3 border-t border-slate-100 pt-1">
      <summary class="min-h-11 cursor-pointer py-3 text-xs font-semibold text-brand-700">View data table</summary>
      <table class="w-full text-left text-xs"><caption class="sr-only">{{ title }}</caption>
        <thead><tr><th scope="col" class="py-1.5">Age</th><th scope="col" class="py-1.5 text-right">Female</th><th scope="col" class="py-1.5 text-right">Male</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.age" class="border-t border-slate-100"><th scope="row" class="py-1.5 font-normal">{{ row.age }}</th><td class="py-1.5 text-right">{{ row.female ?? 'Withheld' }}</td><td class="py-1.5 text-right">{{ row.male ?? 'Withheld' }}</td></tr></tbody>
      </table>
    </details>
  </div>
  <p v-else class="text-sm text-slate-600">No publishable data.</p>
</template>
