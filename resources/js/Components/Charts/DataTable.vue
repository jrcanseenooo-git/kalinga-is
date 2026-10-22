<script setup lang="ts">
import type { ChartRow } from './palette';
defineProps<{ title: string; rows: ChartRow[]; labelHeader?: string; valueHeader?: string }>();
</script>
<template>
  <details class="mt-3 border-t border-slate-100 pt-1">
    <summary class="min-h-11 cursor-pointer py-3 text-xs font-semibold text-brand-700">View data table</summary>
    <table class="w-full text-left text-xs"><caption class="sr-only">{{ title }}</caption>
      <thead><tr><th scope="col" class="py-1.5">{{ labelHeader ?? 'Group' }}</th><th scope="col" class="py-1.5 text-right">{{ valueHeader ?? 'Cases' }}</th></tr></thead>
      <tbody><tr v-for="row in rows" :key="row.label" class="border-t border-slate-100"><th scope="row" class="py-1.5 font-normal">{{ row.label }}</th><td class="py-1.5 text-right">{{ row.total }}</td></tr></tbody>
    </table>
  </details>
</template>
