<script setup lang="ts">
import { computed } from 'vue';
import { CHART_COLORS, pct, sumRows, type ChartRow } from './palette';
// 10 x 10 waffle: each square is 1% of the total; good for "share of services".
const props = defineProps<{ title: string; rows: ChartRow[] }>();
const total = computed(() => sumRows(props.rows));
const cells = computed(() => {
  const out: string[] = []; let used = 0;
  props.rows.forEach((row, index) => { const n = index === props.rows.length - 1 ? 100 - used : Math.round((Number(row.total) / Math.max(1, total.value)) * 100); for (let i = 0; i < n && out.length < 100; i++) out.push(CHART_COLORS[index % CHART_COLORS.length]); used += n; });
  while (out.length < 100) out.push('#efedf6');
  return out;
});
</script>
<template>
  <div v-if="rows.length" class="flex flex-col gap-4 sm:flex-row sm:items-center">
    <div class="grid w-36 shrink-0 grid-cols-10 gap-0.5" role="img" :aria-label="`${title}: ${total} in total, each square about 1 percent.`"><span v-for="(color, i) in cells" :key="i" class="aspect-square rounded-[2px]" :style="{ background: color }" /></div>
    <ul class="min-w-0 flex-1 space-y-1.5 text-xs">
      <li v-for="(row, index) in rows" :key="row.label" class="flex items-center justify-between gap-2"><span class="flex min-w-0 items-center gap-2 text-slate-700"><span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: CHART_COLORS[index % CHART_COLORS.length] }" /><span class="truncate" :title="row.label">{{ row.label }}</span></span><span class="shrink-0"><strong>{{ row.total }}</strong> <span class="text-slate-500">({{ pct(row.total, total) }}%)</span></span></li>
    </ul>
  </div>
  <p v-else class="text-sm text-slate-600">No publishable data.</p>
</template>
