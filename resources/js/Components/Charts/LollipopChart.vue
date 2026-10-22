<script setup lang="ts">
import { computed } from 'vue';
import type { ChartRow } from './palette';
// Ranked dot-and-stem chart: easier to compare many areas than a block of bars.
const props = defineProps<{ title: string; rows: ChartRow[]; color?: string }>();
const max = computed(() => Math.max(1, ...props.rows.map(r => Number(r.total))));
</script>
<template>
  <ul class="space-y-3" :aria-label="title">
    <li v-for="(row, index) in rows" :key="row.label" class="grid grid-cols-[1.5rem_minmax(0,9rem)_1fr_2.5rem] items-center gap-2 text-sm">
      <span class="text-xs font-semibold text-slate-500">{{ index + 1 }}</span>
      <span class="truncate text-slate-700" :title="row.label">{{ row.label }}</span>
      <span class="relative h-4" aria-hidden="true"><span class="absolute left-0 top-1/2 h-0.5 -translate-y-1/2 rounded bg-slate-200" :style="{ width: '100%' }" /><span class="absolute left-0 top-1/2 h-0.5 -translate-y-1/2 rounded" :style="{ width: `${(Number(row.total) / max) * 100}%`, background: color ?? '#7c5cd6' }" /><span class="absolute top-1/2 h-3.5 w-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow" :style="{ left: `${(Number(row.total) / max) * 100}%`, background: color ?? '#7c5cd6' }" /></span>
      <strong class="text-right text-slate-900">{{ row.total }}</strong>
    </li>
  </ul>
</template>
