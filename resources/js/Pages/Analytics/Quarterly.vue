<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import { Head, Link } from '@inertiajs/vue3';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import ServicesBars, { type ServiceRow } from '@/Components/Charts/ServicesBars.vue';
import LollipopChart from '@/Components/Charts/LollipopChart.vue';

type Row = { label: string; total: number };
const props = defineProps<{
  period: { year: number; quarter: number; label: string; start: string; end: string };
  totals: Record<string, number | 'Suppressed'>;
  canExport: boolean;
  classification: Row[];
  status: Row[];
  sex: Row[];
  services: ServiceRow[];
  areas: { area: string; total: number }[];
}>();

const quarters = [1, 2, 3, 4];
const pretty = (value: string) => value.replace(/([A-Z])/g, ' $1').replace(/^./, (letter) => letter.toUpperCase());
</script>

<template>
  <Head title="Quarterly monitoring" />
  <AuthenticatedLayout>
    <section class="w-full max-w-none space-y-5">
      <SectionNav section="analytics" />
      <header class="flex flex-col gap-4 ui-panel p-5 sm:flex-row sm:items-end sm:justify-between sm:p-7">
        <div>
          <div class="flex items-center gap-2">
            <Link :href="route('analytics.index')" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-700 hover:text-brand-800">Analytics</Link>
            <span class="text-sm text-slate-500">/</span>
            <p class="text-sm font-bold text-brand-700">Quarterly</p>
          </div>
          <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">{{ period.label }} Report</h1>
          <p class="mt-2 text-sm leading-6 text-slate-600">{{ period.start }} to {{ period.end }}</p>
        </div>
        <a v-if="canExport" :href="route('analytics.quarterly.export', { year: period.year, quarter: period.quarter })" class="shrink-0 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800">Download CSV</a>
      </header>

      <p class="text-sm text-slate-600">Suppressed values are withheld for privacy and do not mean zero. Group charts show publishable counts only.</p>

      <!-- Quarter tabs -->
      <div class="flex gap-1 rounded-2xl border border-slate-200 bg-slate-50 p-1 shadow-sm">
        <Link
          v-for="quarter in quarters" :key="quarter"
          :href="route('analytics.quarterly', { year: period.year, quarter })"
          class="flex-1 rounded-xl px-4 py-2.5 text-center text-sm font-bold transition"
          :class="quarter === period.quarter ? 'bg-white text-brand-800 shadow-sm ring-1 ring-slate-200/60' : 'text-slate-500 hover:text-slate-800'"
        >
          Q{{ quarter }}
        </Link>
      </div>

      <!-- Totals -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article v-for="(value, key) in totals" :key="key" class="ui-panel p-5">
          <p class="text-sm font-semibold text-slate-600">{{ pretty(String(key)) }}</p>
          <p class="mt-2 text-3xl font-extrabold text-brand-800">{{ value }}</p>
        </article>
      </div>

      <!-- Breakdowns: donuts for shares, ranked bars for the services provided -->
      <div class="grid gap-5 md:grid-cols-2">
        <article class="ui-panel p-5"><h2 class="font-extrabold text-slate-900">CEFMU classification</h2><div class="mt-4"><DonutChart title="CEFMU classification" :rows="classification" center-label="cases" /></div></article>
        <article class="ui-panel p-5"><h2 class="font-extrabold text-slate-900">Case status</h2><div class="mt-4"><DonutChart title="Case status" :rows="status" center-label="cases" /></div></article>
        <article class="ui-panel p-5"><h2 class="font-extrabold text-slate-900">Cases by sex</h2><div class="mt-4"><DonutChart title="Cases by sex" :rows="sex" center-label="cases" :colors="['#e0607e', '#3b82d6', '#64748b']" /></div></article>
        <article class="ui-panel p-5"><h2 class="font-extrabold text-slate-900">Services provided</h2><p class="mt-1 text-xs text-slate-600">Services given in the quarter, by type, with the cases served and the amount.</p><div class="mt-4"><ServicesBars title="Services provided" :rows="services" /></div></article>
      </div>

      <!-- Geographic -->
      <article class="ui-panel p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="font-extrabold text-slate-900">Municipality aggregates</h2>
            <p class="mt-1 text-sm text-slate-500">Only groups with at least five records are shown.</p>
          </div>
          <span class="shrink-0 rounded-lg bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ period.start }} - {{ period.end }}</span>
        </div>
        <div v-if="areas.length" class="mt-5"><LollipopChart title="Municipality aggregates" :rows="[...areas].sort((a, b) => b.total - a.total).map(item => ({ label: item.area, total: item.total }))" /></div>
        <p v-else class="mt-5 text-sm text-slate-600">No publishable geographic aggregate for this period.</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>
