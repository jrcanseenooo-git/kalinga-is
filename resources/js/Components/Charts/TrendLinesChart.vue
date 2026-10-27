<script setup lang="ts">
/** Two trend lines (for example new and closed cases per month). Withheld values break the line instead of drawing zero. */
import { computed } from 'vue';
type Value = number | 'Suppressed';
type Series = { key: string; label: string; color: string };
const props = defineProps<{ title: string; rows: Array<{ label: string } & Record<string, Value | string>>; series: Series[] }>();
const num = (v: unknown) => (typeof v === 'number' ? v : null);
const max = computed(() => Math.max(3, Math.ceil(Math.max(0, ...props.rows.flatMap(row => props.series.map(s => num(row[s.key]) ?? 0))) / 3) * 3));
const x = (i: number) => 36 + (i * 440) / Math.max(1, props.rows.length - 1);
const y = (v: number) => 160 - (v * 128) / max.value;
const segments = (key: string) => { const out: string[][] = []; let cur: string[] = []; props.rows.forEach((row, i) => { const v = num(row[key]); if (v === null) { if (cur.length) out.push(cur); cur = []; } else cur.push(`${x(i)},${y(v)}`); }); if (cur.length) out.push(cur); return out; };
const short = (label: string) => label.split(' ')[0];
</script>
<template>
  <div>
    <svg viewBox="0 0 500 200" class="block w-full" role="img" :aria-label="`${title}. Figures are listed in the table below.`">
      <g v-for="n in [0, 1, 2, 3]" :key="n"><line x1="36" :y1="160 - n * 42.7" x2="476" :y2="160 - n * 42.7" stroke="#ece9f5" /><text x="28" :y="164 - n * 42.7" text-anchor="end" fill="#64748b" font-size="11">{{ Math.round((max * n) / 3) }}</text></g>
      <template v-for="s in series" :key="s.key">
        <polyline v-for="(seg, i) in segments(s.key)" :key="i" :points="seg.join(' ')" fill="none" :stroke="s.color" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
        <template v-for="(row, i) in rows" :key="`${s.key}-${i}`"><circle v-if="num(row[s.key]) !== null" :cx="x(i)" :cy="y(num(row[s.key])!)" r="3.5" fill="#fff" :stroke="s.color" stroke-width="2" /></template>
      </template>
      <text v-for="(row, i) in rows" :key="row.label" :x="x(i)" y="186" text-anchor="middle" fill="#64748b" font-size="10">{{ short(row.label) }}</text>
    </svg>
    <ul class="mt-1 flex flex-wrap gap-4 text-xs text-slate-700"><li v-for="s in series" :key="s.key" class="flex items-center gap-1.5"><span class="h-0.5 w-5 rounded" :style="{ background: s.color }"></span>{{ s.label }}</li></ul>
  </div>
</template>
