<script setup lang="ts">
import { computed } from 'vue';
import type { ChartRow } from './palette';
// Vertical columns: used only for ordered groups such as age bands (a histogram).
const props = defineProps<{ title: string; rows: ChartRow[]; color?: string }>();
const max = computed(() => Math.max(1, ...props.rows.map(r => Number(r.total))));
</script>
<template>
  <div v-if="rows.length" class="flex h-40 items-end gap-2" role="img" :aria-label="`${title}. Values are printed above each column.`">
    <div v-for="row in rows" :key="row.label" class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1">
      <span class="text-xs font-semibold text-slate-800">{{ row.total }}</span>
      <span class="w-full max-w-12 rounded-t-md" :style="{ height: `${Math.max(4, (Number(row.total) / max) * 110)}px`, background: color ?? '#8b5cf6' }" />
      <span class="w-full truncate text-center text-[11px] text-slate-600" :title="row.label">{{ row.label }}</span>
    </div>
  </div>
  <p v-else class="text-sm text-slate-600">No publishable data.</p>
</template>
