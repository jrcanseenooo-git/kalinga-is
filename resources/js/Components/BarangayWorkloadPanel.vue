<script setup lang="ts">
import { computed } from 'vue';

type Row = { barangay: string; total: number; active: number };

const props = defineProps<{
  municipality: string;
  boundaryStatus: string;
  rows: Row[];
}>();

const severityBands = [
  { min: 0, max: 0, label: 'No cases', bg: 'bg-slate-100', text: 'text-slate-600', bar: 'bg-slate-200', color: '#e5e7eb' },
  { min: 1, max: 5, label: '1-5', bg: 'bg-violet-50', text: 'text-violet-700', bar: 'bg-violet-200', color: '#ede9fe' },
  { min: 6, max: 15, label: '6-15', bg: 'bg-violet-100', text: 'text-violet-800', bar: 'bg-violet-300', color: '#c4b5fd' },
  { min: 16, max: 30, label: '16-30', bg: 'bg-violet-500', text: 'text-white', bar: 'bg-violet-500', color: '#8b5cf6' },
  { min: 31, max: Infinity, label: '31+', bg: 'bg-violet-800', text: 'text-white', bar: 'bg-brand-700', color: '#5b21b6' },
];

const bandFor = (total: number) => severityBands.find(b => total >= b.min && total <= b.max) ?? severityBands[0];

const sorted = computed(() => [...props.rows].sort((a, b) => b.total - a.total));
const maxTotal = computed(() => Math.max(1, ...props.rows.map(r => r.total)));

const summaryTotal = computed(() => props.rows.reduce((s, r) => s + r.total, 0));
const summaryActive = computed(() => props.rows.reduce((s, r) => s + r.active, 0));
const summaryClosed = computed(() => summaryTotal.value - summaryActive.value);
const barangayCount = computed(() => props.rows.length);
const highestLoad = computed(() => sorted.value[0] ?? null);

const bandDistribution = computed(() =>
  severityBands.map(band => ({
    ...band,
    count: props.rows.filter(r => r.total >= band.min && r.total <= band.max).length,
  })),
);
</script>

<template>
  <section class="brgy-panel" aria-labelledby="barangay-workload-title">
    <!-- Header -->
    <div class="brgy-header">
      <div class="min-w-0">
        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-brand-600">Geographic workload</p>
        <h2 id="barangay-workload-title" class="mt-1 text-lg font-extrabold text-slate-900 sm:text-xl">{{ municipality }}</h2>
        <p class="mt-1 text-sm text-slate-500">Aggregate workload by barangay. No client identities or locations are shown.</p>
      </div>
      <span class="shrink-0 rounded-full bg-amber-50 px-3 py-1.5 text-[10px] font-bold text-amber-800 ring-1 ring-amber-200/60">
        Boundary map pending
      </span>
    </div>

    <!-- Summary tiles -->
    <div v-if="rows.length" class="brgy-summary">
      <div class="summary-tile">
        <p class="summary-label">Total cases</p>
        <p class="summary-value text-brand-800">{{ summaryTotal }}</p>
      </div>
      <div class="summary-tile">
        <p class="summary-label">Active</p>
        <p class="summary-value text-blue-700">{{ summaryActive }}</p>
      </div>
      <div class="summary-tile">
        <p class="summary-label">Closed</p>
        <p class="summary-value text-emerald-700">{{ summaryClosed }}</p>
      </div>
      <div class="summary-tile">
        <p class="summary-label">Barangays</p>
        <p class="summary-value text-slate-800">{{ barangayCount }}</p>
      </div>
    </div>

    <!-- Severity legend + distribution -->
    <div v-if="rows.length" class="brgy-legend">
      <div class="legend-row">
        <span v-for="band in bandDistribution" :key="band.label" class="legend-chip">
          <i class="legend-dot" :style="{ background: band.color }" />
          <span class="text-slate-600">{{ band.label }}</span>
          <strong v-if="band.count" class="text-slate-800">{{ band.count }}</strong>
        </span>
      </div>
    </div>

    <!-- Bar chart ranking -->
    <div v-if="sorted.length" class="brgy-chart" role="list" aria-label="Barangay workload ranking">
      <article
        v-for="(row, i) in sorted"
        :key="row.barangay"
        role="listitem"
        class="brgy-row"
        :aria-label="`${row.barangay}: ${row.total} total, ${row.active} active`"
      >
        <div class="row-rank">{{ i + 1 }}</div>
        <div class="row-body">
          <div class="row-label">
            <span class="row-name">{{ row.barangay }}</span>
            <span class="row-counts">
              <strong>{{ row.total }}</strong>
              <span class="row-active" :class="row.active > 0 ? 'text-blue-700' : 'text-slate-600'">{{ row.active }} active</span>
            </span>
          </div>
          <div class="row-track">
            <div
              class="row-bar-total"
              :class="bandFor(row.total).bar"
              :style="{ width: `${(row.total / maxTotal) * 100}%` }"
            />
            <div
              v-if="row.active > 0"
              class="row-bar-active"
              :style="{ width: `${(row.active / maxTotal) * 100}%` }"
            />
          </div>
        </div>
        <span class="row-badge" :class="[bandFor(row.total).bg, bandFor(row.total).text]">
          {{ bandFor(row.total).label }}
        </span>
      </article>
    </div>

    <!-- Highest load callout -->
    <div v-if="highestLoad && highestLoad.total > 0" class="brgy-callout">
      <p class="text-xs text-slate-500">
        Highest workload: <strong class="text-slate-800">{{ highestLoad.barangay }}</strong> with {{ highestLoad.total }} cases ({{ highestLoad.active }} active).
      </p>
    </div>

    <!-- Empty state -->
    <div v-if="!rows.length" class="brgy-empty">
      <div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100">
        <span class="text-lg text-slate-600">&#9633;</span>
      </div>
      <p class="mt-2 text-sm font-bold text-slate-600">No case records available</p>
      <p class="mt-1 text-xs text-slate-600">No cases are currently assigned in your LGU area.</p>
    </div>
  </section>
</template>

<style scoped>
.brgy-panel {
  border: 1px solid #e6e9ef;
  border-radius: 1.15rem;
  background: #fff;
  padding: 1.25rem;
  box-shadow: 0 1px 2px rgb(15 23 42 / .04);
}
.brgy-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: .75rem;
}

/* Summary tiles */
.brgy-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .5rem;
  margin-top: 1rem;
}
.summary-tile {
  border-radius: .75rem;
  background: #f8fafc;
  padding: .65rem .75rem;
  text-align: center;
}
.summary-label {
  font-size: .6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: #475569;
}
.summary-value {
  margin-top: .15rem;
  font-size: 1.35rem;
  font-weight: 800;
  line-height: 1.2;
}

/* Severity legend */
.brgy-legend {
  margin-top: .85rem;
  padding: .55rem .65rem;
  border-radius: .65rem;
  background: #f8fafc;
}
.legend-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: .65rem;
}
.legend-chip {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  font-size: .68rem;
}
.legend-dot {
  display: inline-block;
  width: .6rem;
  height: .6rem;
  border-radius: .15rem;
  border: 1px solid rgb(0 0 0 / .08);
}

/* Bar chart */
.brgy-chart {
  margin-top: .85rem;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.brgy-row {
  display: flex;
  align-items: center;
  gap: .5rem;
  padding: .55rem .65rem;
  border-radius: .65rem;
  transition: background .12s ease;
}
.brgy-row:hover {
  background: #f8fafc;
}
.row-rank {
  width: 1.5rem;
  flex-shrink: 0;
  font-size: .65rem;
  font-weight: 800;
  color: #475569;
  text-align: center;
}
.row-body {
  flex: 1;
  min-width: 0;
}
.row-label {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: .5rem;
}
.row-name {
  font-size: .82rem;
  font-weight: 700;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
.row-counts {
  display: flex;
  align-items: baseline;
  gap: .4rem;
  flex-shrink: 0;
  font-size: .75rem;
}
.row-counts strong {
  color: #1e293b;
}
.row-active {
  font-size: .65rem;
  font-weight: 600;
}
.row-track {
  position: relative;
  margin-top: .3rem;
  height: .45rem;
  border-radius: .25rem;
  background: #f1f5f9;
  overflow: hidden;
}
.row-bar-total {
  position: absolute;
  inset: 0 auto 0 0;
  border-radius: .25rem;
  min-width: 4px;
  transition: width .25s ease;
}
.row-bar-active {
  position: absolute;
  inset: 0 auto 0 0;
  height: 100%;
  border-radius: .25rem;
  background: #3b82f6;
  opacity: .55;
  min-width: 3px;
  transition: width .25s ease;
}
.row-badge {
  flex-shrink: 0;
  border-radius: .4rem;
  padding: .2rem .45rem;
  font-size: .6rem;
  font-weight: 700;
  line-height: 1;
}

/* Callout */
.brgy-callout {
  margin-top: .65rem;
  padding: .55rem .75rem;
  border-radius: .65rem;
  border: 1px solid #e2e8f0;
  background: #fafafa;
}

/* Empty state */
.brgy-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 3rem 1rem;
}

/* Mobile */
@media (max-width: 640px) {
  .brgy-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .summary-value {
    font-size: 1.1rem;
  }
  .row-badge {
    display: none;
  }
  .brgy-row {
    padding: .45rem .35rem;
  }
  .row-name {
    font-size: .75rem;
  }
  .row-counts {
    font-size: .68rem;
  }
  .legend-row {
    gap: .4rem;
  }
  .legend-chip {
    font-size: .6rem;
  }
}
</style>
