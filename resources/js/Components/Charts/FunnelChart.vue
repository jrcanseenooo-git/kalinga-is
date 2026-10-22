<script setup lang="ts">
import { computed } from 'vue';
import { pct, type ChartRow } from './palette';
// Ordered pipeline (e.g. referrals sent -> accepted -> in progress -> completed).
const props = defineProps<{ title: string; steps: ChartRow[]; color?: string }>();
const first = computed(() => Math.max(1, Number(props.steps[0]?.total ?? 0)));
</script>
<template>
  <ol class="space-y-2" :aria-label="title">
    <li v-for="(step, index) in steps" :key="step.label" class="flex items-center gap-3">
      <span class="w-28 shrink-0 text-xs font-medium text-slate-700">{{ step.label }}</span>
      <span class="relative h-8 flex-1">
        <span class="absolute inset-y-0 left-1/2 -translate-x-1/2 rounded-md" :style="{ width: `${Math.max(8, (Number(step.total) / first) * 100)}%`, background: color ?? '#7c5cd6', opacity: 1 - index * 0.15 }" />
        <span class="absolute inset-0 grid place-items-center text-xs font-bold text-white drop-shadow">{{ step.total }}</span>
      </span>
      <span class="w-12 shrink-0 text-right text-xs text-slate-500">{{ index ? `${pct(step.total, Number(steps[0]?.total ?? 0))}%` : '' }}</span>
    </li>
  </ol>
</template>
