<script setup lang="ts">
/**
 * Services provided by type: one horizontal bar per type, ranked, with the
 * number of services, the number of cases served and the amount given
 * printed beside it (one hue: the bars show magnitude only).
 */
import { computed } from 'vue';

export type ServiceRow = { label: string; total: number; cases: number | 'Suppressed'; amount: number };
const props = defineProps<{ title: string; rows: ServiceRow[] }>();
const max = computed(() => Math.max(1, ...props.rows.map((row) => row.total)));
const total = computed(() => props.rows.reduce((sum, row) => sum + row.total, 0));
const peso = (value: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value);
</script>

<template>
  <div v-if="rows.length">
    <p class="text-xs text-slate-600"><b class="text-slate-900">{{ total }}</b> services recorded</p>
    <ol class="mt-3 space-y-3" :aria-label="`${title}. Figures are printed beside each bar.`">
      <li v-for="row in rows" :key="row.label" class="grid gap-1">
        <div class="flex items-baseline justify-between gap-2 text-sm"><span class="min-w-0 truncate font-semibold text-slate-800" :title="row.label">{{ row.label }}</span><span class="shrink-0 tabular-nums font-bold text-slate-900">{{ row.total }}</span></div>
        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full bg-emerald-600" :style="{ width: `${Math.max(3, (row.total / max) * 100)}%` }" /></div>
        <p class="text-xs text-slate-600">{{ row.cases === 'Suppressed' ? 'Cases withheld' : `${row.cases} ${row.cases === 1 ? 'case' : 'cases'} served` }}<template v-if="row.amount"> - {{ peso(row.amount) }}</template></p>
      </li>
    </ol>
  </div>
  <p v-else class="text-sm text-slate-600">No publishable data.</p>
</template>
