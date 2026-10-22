<script setup lang="ts">
import { computed } from 'vue';
import ChartTable from './DataTable.vue';
import { CHART_COLORS, pct, sumRows, type ChartRow } from './palette';
const props = withDefaults(defineProps<{ title: string; rows: ChartRow[]; centerLabel?: string; colors?: string[] }>(), { centerLabel: 'total', colors: () => CHART_COLORS });
const total = computed(() => sumRows(props.rows));
const offset = (index: number) => props.rows.slice(0, index).reduce((sum, row) => sum + Number(row.total), 0) / Math.max(1, total.value) * 100;
const share = (value: number) => Number(value) / Math.max(1, total.value) * 100;
</script>
<template>
  <div>
    <div v-if="rows.length" class="flex flex-col items-center gap-4 sm:flex-row">
      <svg viewBox="0 0 120 120" class="h-36 w-36 shrink-0" role="img" :aria-label="`${title}: ${total} in total. Details in the legend.`">
        <circle cx="60" cy="60" r="44" fill="none" stroke="#efedf6" stroke-width="16" />
        <circle v-for="(row, index) in rows" :key="row.label" cx="60" cy="60" r="44" pathLength="100" fill="none" :stroke="colors[index % colors.length]" stroke-width="16"
          :stroke-dasharray="`${share(row.total)} ${100 - share(row.total)}`" :stroke-dashoffset="-offset(index)" transform="rotate(-90 60 60)" />
        <text x="60" y="61" text-anchor="middle" fill="#1f1a3d" font-size="22" font-weight="700">{{ total }}</text>
        <text x="60" y="77" text-anchor="middle" fill="#64748b" font-size="9">{{ centerLabel }}</text>
      </svg>
      <ul class="w-full min-w-0 space-y-2 text-sm">
        <li v-for="(row, index) in rows" :key="row.label" class="flex items-center justify-between gap-3">
          <span class="flex min-w-0 items-center gap-2 text-slate-700"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: colors[index % colors.length] }" /><span class="break-words">{{ row.label }}</span></span>
          <span class="shrink-0 text-slate-900"><strong>{{ row.total }}</strong> <span class="text-xs text-slate-500">({{ pct(row.total, total) }}%)</span></span>
        </li>
      </ul>
    </div>
    <p v-else class="text-sm text-slate-600">No publishable data.</p>
  </div>
</template>
