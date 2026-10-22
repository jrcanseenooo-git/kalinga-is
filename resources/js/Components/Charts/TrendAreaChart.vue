<script setup lang="ts">
import { computed } from 'vue';
type Point = { label: string; total: number | 'Suppressed' };
const props = defineProps<{ title: string; rows: Point[] }>();
const values = computed(() => props.rows.map(row => (typeof row.total === 'number' ? row.total : null)));
const max = computed(() => Math.max(3, Math.ceil(Math.max(0, ...values.value.map(v => v ?? 0)) / 3) * 3));
const x = (i: number) => 36 + i * 428 / Math.max(1, props.rows.length - 1);
const y = (v: number) => 160 - v * 128 / max.value;
// Segments skip suppressed months so a withheld value is never drawn as zero.
const segments = computed(() => { const out: string[][] = []; let cur: string[] = []; values.value.forEach((v, i) => { if (v === null) { if (cur.length) out.push(cur); cur = []; } else cur.push(`${x(i)},${y(v)}`); }); if (cur.length) out.push(cur); return out; });
const area = (seg: string[]) => { const first = seg[0].split(',')[0]; const last = seg[seg.length - 1].split(',')[0]; return `M${first},160 L${seg.join(' L')} L${last},160 Z`; };
</script>
<template>
  <svg viewBox="0 0 500 200" class="block w-full" role="img" :aria-label="`${title}. Values are shown on each point.`">
    <defs><linearGradient id="trend-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#7c5cd6" stop-opacity=".22" /><stop offset="100%" stop-color="#7c5cd6" stop-opacity="0" /></linearGradient></defs>
    <g v-for="n in [0, 1, 2, 3]" :key="n"><line x1="36" :y1="160 - n * 42.7" x2="464" :y2="160 - n * 42.7" stroke="#ece9f5" /><text x="28" :y="164 - n * 42.7" text-anchor="end" fill="#64748b" font-size="11">{{ Math.round(max * n / 3) }}</text></g>
    <template v-for="(seg, i) in segments" :key="i"><path v-if="seg.length > 1" :d="area(seg)" fill="url(#trend-fill)" /><polyline :points="seg.join(' ')" fill="none" stroke="#7c5cd6" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" /></template>
    <g v-for="(row, i) in rows" :key="row.label">
      <template v-if="typeof row.total === 'number'"><circle :cx="x(i)" :cy="y(row.total)" r="4.5" fill="#fff" stroke="#7c5cd6" stroke-width="2.5" /><text :x="x(i)" :y="y(row.total) - 10" text-anchor="middle" fill="#1f1a3d" font-size="11" font-weight="600">{{ row.total }}</text></template>
      <text v-else :x="x(i)" y="150" text-anchor="middle" fill="#94a3b8" font-size="10">withheld</text>
      <text :x="x(i)" y="186" text-anchor="middle" fill="#64748b" font-size="11">{{ row.label }}</text>
    </g>
  </svg>
</template>
