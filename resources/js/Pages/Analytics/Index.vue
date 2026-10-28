<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import DrillMap, { type MapLevel } from '@/Components/Analytics/DrillMap.vue';
import TrendLinesChart from '@/Components/Charts/TrendLinesChart.vue';
import { formatPh } from '@/utils/philippineTime';
import { ChevronRight, FileDown } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Sparkles } from '@lucide/vue';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import FunnelChart from '@/Components/Charts/FunnelChart.vue';
import TrendAreaChart from '@/Components/Charts/TrendAreaChart.vue';
import LollipopChart from '@/Components/Charts/LollipopChart.vue';
import AgeSexChart, { type AgeSexRow } from '@/Components/Charts/AgeSexChart.vue';
import ServicesBars, { type ServiceRow } from '@/Components/Charts/ServicesBars.vue';
import ColumnChart from '@/Components/Charts/ColumnChart.vue';
import ChartTable from '@/Components/Charts/DataTable.vue';

type Row = { label: string; total: number };
type StatusRow = { status: string; total: number };
type AreaRow = { area: string; total: number };
type Count = number | 'Suppressed';
type TrendRow = { label: string; total: Count };

const props = defineProps<{
  status: StatusRow[];
  geo: AreaRow[];
  referrals: StatusRow[];
  trend: TrendRow[];
  sex: Row[];
  classification: Row[];
  age: Row[];
  ageSex: AgeSexRow[];
  services: ServiceRow[];
  planSubmission: { averageDays: number | null; submitted: Count; onTime: Count; late: Count; overdue: Count; targetDays: number };
  totals: Record<string, Count>;
  canExport: boolean;
  canQuarterly: boolean;
  filters: { region: string; province: string; municipality: string; barangay: string; year: number | null; quarter: number | null };
  level: MapLevel;
  children: Array<{ name: string; total: number; active: number }>;
  monthly: Array<{ label: string; opened: Count; closed: Count }>;
  quarters: TrendRow[];
  kavatPriority: Row[];
  grantsTotal: number | 'Suppressed';
  exportedBy: { name: string; at: string };
}>();

// Area and period selection: every figure on the page follows it.
const go = (changes: Partial<typeof props.filters>) => {
  const next = { ...props.filters, ...changes };
  const query = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value !== null && value !== undefined));
  router.get(route('analytics.index'), query, { preserveScroll: true, preserveState: false });
};
const selectArea = (name: string) => {
  const f = props.filters;
  if (props.level === 'region') go({ region: name, province: '', municipality: '', barangay: '' });
  else if (props.level === 'province') go({ province: name, municipality: '', barangay: '' });
  else if (props.level === 'municipality') go({ municipality: name, barangay: '' });
  else go({ barangay: f.barangay === name ? '' : name });
};
const up = () => {
  const f = props.filters;
  if (f.barangay) go({ barangay: '' }); else if (f.municipality) go({ municipality: '', barangay: '' }); else if (f.province) go({ province: '' }); else go({ region: '' });
};
const crumbs = computed(() => {
  const f = props.filters;
  return [{ label: 'Philippines', to: { region: '', province: '', municipality: '', barangay: '' } }, ...(f.region ? [{ label: f.region, to: { province: '', municipality: '', barangay: '' } }] : []),
    ...(f.province ? [{ label: f.province, to: { municipality: '', barangay: '' } }] : []), ...(f.municipality ? [{ label: f.municipality, to: { barangay: '' } }] : []), ...(f.barangay ? [{ label: f.barangay, to: {} }] : [])];
});
const thisYear = new Date().getFullYear();
const years = Array.from({ length: thisYear - 2023 }, (_, i) => thisYear - i);
const periodLabel = computed(() => props.filters.year ? (props.filters.quarter ? `Q${props.filters.quarter} ${props.filters.year}` : `Year ${props.filters.year}`) : 'All time');
const areaLabel = computed(() => crumbs.value.map(c => c.label).join(' > '));
const peso = (value: number | 'Suppressed') => value === 'Suppressed' ? 'Withheld' : new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value);

// PDF: the browser saves the page as shown, with a watermark naming the user and the time. The export is audited first.
const printing = ref(false);
const printedAt = ref(formatPh(new Date().toISOString(), 'dateTimeShort'));
const watermark = computed(() => `${props.exportedBy.name} - ${printedAt.value} - CONFIDENTIAL`);
async function exportPdf() {
  printing.value = true;
  try {
    await axios.post(route('analytics.printed'), { ...Object.fromEntries(Object.entries(props.filters).filter(([, v]) => v !== '' && v !== null)) });
    printedAt.value = formatPh(new Date().toISOString(), 'dateTimeShort');
    await nextTick();
    window.print();
  }
  finally { printing.value = false; }
}

const shown = (value: Count) => (value === 'Suppressed' ? 'Withheld' : value);
const chartTotal = (rows: any[]) => rows.reduce((sum: number, r: any) => sum + Number(r.total), 0);
const displayStatus = (s: string) => s.toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());

const numericTrend = computed(() => props.trend.filter((row): row is Row => typeof row.total === 'number'));
const statusRows = computed(() => props.status.map(item => ({ label: displayStatus(item.status), total: Number(item.total) })));
const referralRows = computed(() => props.referrals.map(item => ({ label: displayStatus(item.status), total: Number(item.total) })));
const geoRows = computed(() => [...props.geo].sort((a, b) => b.total - a.total).map(item => ({ label: item.area, total: Number(item.total) })));
const refCount = (...statuses: string[]) => props.referrals.filter(item => statuses.includes(item.status.toUpperCase())).reduce((sum, item) => sum + Number(item.total), 0);
const referralFunnel = computed(() => [
  { label: 'Sent', total: refCount('SENT', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED', 'DECLINED', 'RETURNED', 'UNAVAILABLE') },
  { label: 'Accepted', total: refCount('ACCEPTED', 'IN_PROGRESS', 'COMPLETED') },
  { label: 'In progress', total: refCount('IN_PROGRESS', 'COMPLETED') },
  { label: 'Completed', total: refCount('COMPLETED') },
]);
const notProceeded = computed(() => refCount('DECLINED', 'RETURNED', 'UNAVAILABLE', 'WITHDRAWN'));
/** Simple, explainable rules over aggregate figures (no case data, no external AI). */
const insights = computed(() => {
  const out: { title: string; text: string; tone: 'good' | 'warn' | 'info' }[] = [];
  const nums = props.trend.map(row => (typeof row.total === 'number' ? row.total : null));
  // The last month is still running, so compare the two most recent complete months.
  const last = nums.at(-2), prev = nums.at(-3);
  if (last !== null && last !== undefined && prev !== null && prev !== undefined && prev > 0) {
    const change = Math.round(((last - prev) / prev) * 100);
    if (Math.abs(change) >= 25) out.push({ title: change > 0 ? `New cases up ${change}%` : `New cases down ${-change}%`, text: `${props.trend.at(-2)?.label}: ${last} vs ${prev} the month before.${change > 0 ? ' Check worker caseloads and intake follow-up.' : ''}`, tone: change > 0 ? 'warn' : 'info' });
  }
  const sent = referralFunnel.value[0].total, done = referralFunnel.value[3].total;
  if (sent >= 5) { const rate = Math.round((done / sent) * 100); out.push({ title: `${rate}% of referrals completed`, text: rate < 50 ? 'Many referrals are still open or did not proceed. Review overdue follow-ups.' : 'Most referred services were delivered.', tone: rate < 50 ? 'warn' : 'good' }); }
  if (notProceeded.value >= 3) out.push({ title: `${notProceeded.value} referrals did not proceed`, text: 'Look at the reasons recorded: they point to service gaps in the area.', tone: 'warn' });
  const open = props.status.filter(item => !['CLOSED'].includes(item.status.toUpperCase())).reduce((sum, item) => sum + Number(item.total), 0);
  const all = props.status.reduce((sum, item) => sum + Number(item.total), 0);
  if (all) out.push({ title: `${Math.round((open / all) * 100)}% of cases are still active`, text: `${open} of ${all} publishable cases are not yet closed.`, tone: 'info' });
  if (geoRows.value.length >= 2) out.push({ title: `${geoRows.value[0].label} has the most cases`, text: `${geoRows.value[0].total} cases, ${Math.round((geoRows.value[0].total / Math.max(1, geoRows.value.reduce((s, r) => s + r.total, 0))) * 100)}% of the publishable total.`, tone: 'info' });
  return out.slice(0, 4);
});
const trendTotal = computed(() => numericTrend.value.length === props.trend.length ? chartTotal(numericTrend.value) : 'Suppressed');
</script>

<template>
  <Head title="Reports & analytics" />
  <AuthenticatedLayout>
    <section class="analytics-report w-full max-w-none space-y-5">
      <div class="print:hidden"><SectionNav section="analytics" /></div>
      <!-- Printed report heading (the PDF export); not shown on screen. -->
      <header class="report-head hidden print:block" aria-hidden="true">
        <div class="flex items-start justify-between gap-6 border-b-2 border-brand-700 pb-3">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-brand-700">Department of Social Welfare and Development - Kalinga CEFMU Case Management Information System</p>
            <p class="mt-1 text-2xl font-extrabold text-slate-900">Analytics report: {{ areaLabel }}</p>
            <p class="text-sm text-slate-700">Period: {{ periodLabel }}</p>
          </div>
          <dl class="shrink-0 text-right text-xs text-slate-700">
            <div><dt class="inline font-semibold">Prepared by: </dt><dd class="inline">{{ exportedBy.name }}</dd></div>
            <div><dt class="inline font-semibold">Printed: </dt><dd class="inline">{{ printedAt }}</dd></div>
            <div class="mt-1 font-bold text-rose-800">CONFIDENTIAL - aggregate figures only</div>
          </dl>
        </div>
        <p class="mt-2 text-xs text-slate-600">Figures come from the case records in Kalinga IS for the area and period above. Groups smaller than five are withheld ("Suppressed") to protect the children; withheld does not mean zero.</p>
      </header>
      <header class="flex flex-col gap-4 ui-panel p-5 sm:flex-row sm:items-end sm:justify-between sm:p-7 print:hidden">
        <div>
          <p class="text-sm font-bold text-brand-700">Analytics</p>
          <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Operational summary</h1>
          <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Choose an area on the map and a period; every figure on this page follows the choice. Small groups are withheld for privacy.</p>
          <p class="mt-1 hidden text-sm font-semibold text-slate-900 print:block">{{ areaLabel }} - {{ periodLabel }}</p>
        </div>
        <div class="flex shrink-0 gap-2">
          <Link v-if="canQuarterly" :href="route('analytics.quarterly')" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:border-brand-300 hover:text-brand-700">Quarterly reports</Link>
          <a v-if="canExport" :href="route('analytics.export')" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:border-brand-300">Download CSV</a>
          <button v-if="canExport" type="button" class="inline-flex items-center gap-1.5 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60" :disabled="printing" @click="exportPdf"><FileDown class="h-4 w-4" aria-hidden="true" />Export PDF</button>
        </div>
      </header>

      <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <nav aria-label="Area" class="flex min-w-0 flex-wrap items-center gap-1 text-sm"><template v-for="(crumb, index) in crumbs" :key="index"><ChevronRight v-if="index" class="h-4 w-4 text-slate-400" aria-hidden="true" /><button type="button" class="min-h-9 rounded-lg px-2 font-semibold" :class="index === crumbs.length - 1 ? 'text-slate-900' : 'text-brand-700 hover:bg-brand-50'" :aria-current="index === crumbs.length - 1 ? 'location' : undefined" @click="go(crumb.to)">{{ crumb.label }}</button></template></nav>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Period">
          <label class="sr-only" for="analytics-year">Year</label>
          <select id="analytics-year" class="ui-field min-h-10 w-auto py-1 text-sm" :value="filters.year ?? ''" @change="go({ year: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null, quarter: null })"><option value="">All time</option><option v-for="year in years" :key="year" :value="year">{{ year }}</option></select>
          <div v-if="filters.year" class="inline-flex overflow-hidden rounded-lg border border-slate-200" role="group" aria-label="Quarter"><button v-for="q in [null, 1, 2, 3, 4]" :key="String(q)" type="button" class="min-h-10 px-3 text-sm" :class="filters.quarter === q ? 'bg-brand-700 text-white' : 'bg-white text-slate-700 hover:bg-brand-50'" :aria-pressed="filters.quarter === q" @click="go({ quarter: q })">{{ q ? `Q${q}` : 'Whole year' }}</button></div>
        </div>
      </div>
      <p class="text-sm text-slate-600">Showing <b>{{ areaLabel }}</b>, <b>{{ periodLabel }}</b>. Withheld values are hidden for privacy and do not mean zero.</p>

      <DrillMap :level="level" :rows="children" :region="filters.region" :province="filters.province" :municipality="filters.municipality" :barangay="filters.barangay" @select="selectArea" @back="up" />

      <!-- Totals -->
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <article v-for="(item, idx) in [
          { key: 'cases', label: 'Total cases', icon: '#', tone: 'violet' },
          { key: 'active', label: 'Active cases', icon: '+', tone: 'blue' },
          { key: 'highPriority', label: 'KaVAT High Priority', icon: '!', tone: 'amber' },
          { key: 'openReferrals', label: 'Open referrals', icon: '>', tone: 'amber' },
          { key: 'closed', label: 'Closed cases', icon: '=', tone: 'blue' },
          { key: 'clients', label: 'Unique clients', icon: 'o', tone: 'violet' },
        ]" :key="idx" class="total-card" :class="`total-${item.tone}`">
          <div class="total-icon" aria-hidden="true">{{ item.icon }}</div>
          <div class="min-w-0">
            <p class="break-words text-sm font-semibold text-slate-600">{{ item.label }}</p>
            <p class="mt-1 break-words text-2xl font-extrabold sm:text-3xl">{{ props.totals[item.key] ?? 0 }}</p>
          </div>
        </article>
      </div>

      <article class="total-card total-violet"><div class="total-icon" aria-hidden="true">P</div><div class="min-w-0"><p class="text-sm font-semibold text-slate-600">Total grants disbursed</p><p class="mt-1 break-words text-2xl font-extrabold sm:text-3xl">{{ peso(grantsTotal) }}</p><p class="text-xs text-slate-600">{{ typeof totals.grantsCount === 'number' ? `${totals.grantsCount} grants` : 'Number of grants withheld' }}, from the grants log of each case (voided entries excluded).</p></div></article>

      <!-- Who the children are (owner, 2026-10-28: on top) -->
      <div class="grid gap-5 lg:grid-cols-2 min-[1600px]:grid-cols-[1fr_1fr_1.3fr]">
        <article class="chart-card"><h2>CEFMU classification</h2><div class="mt-4"><DonutChart title="CEFMU classification" :rows="props.classification" center-label="cases" /></div><ChartTable title="CEFMU classification" :rows="props.classification" label-header="Classification" /></article>
        <article class="chart-card"><h2>Cases by age</h2><p class="mt-1 text-xs text-slate-500">Exact current age in years.</p><div class="mt-4"><ColumnChart title="Cases by age" :rows="props.age" /></div><ChartTable title="Cases by age" :rows="props.age" label-header="Age" /></article>
        <article class="chart-card lg:col-span-2 min-[1600px]:col-span-1"><h2>Age and sex</h2><p class="mt-1 text-xs text-slate-500">Exact current age; girls on the left, boys on the right.</p><div class="mt-4"><AgeSexChart title="Age and sex" :rows="props.ageSex" /></div></article>
      </div>

      <article class="chart-card" aria-labelledby="plan-time-title">
        <h2 id="plan-time-title">Intervention Plan submission time</h2>
        <p class="mt-1 text-xs text-slate-500">Days from the intake to the first finalized Intervention Plan. Target: within {{ planSubmission.targetDays }} days.</p>
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-xl bg-brand-50 p-3"><dt class="text-xs font-semibold text-brand-800">Average</dt><dd class="text-2xl font-extrabold text-slate-900">{{ planSubmission.averageDays === null ? 'Withheld' : `${planSubmission.averageDays} days` }}</dd></div>
          <div class="rounded-xl bg-emerald-50 p-3"><dt class="text-xs font-semibold text-emerald-900">On time</dt><dd class="text-2xl font-extrabold text-slate-900">{{ shown(planSubmission.onTime) }}</dd></div>
          <div class="rounded-xl bg-amber-50 p-3"><dt class="text-xs font-semibold text-amber-900">Submitted late</dt><dd class="text-2xl font-extrabold text-slate-900">{{ shown(planSubmission.late) }}</dd></div>
          <div class="rounded-xl bg-rose-50 p-3"><dt class="text-xs font-semibold text-rose-900">Not yet submitted, past due</dt><dd class="text-2xl font-extrabold text-slate-900">{{ shown(planSubmission.overdue) }}</dd></div>
        </dl>
      </article>

      <div class="grid gap-5 xl:grid-cols-[1.4fr_1fr]">
        <article class="chart-card"><h2>New and closed cases</h2><p class="mt-0.5 text-xs text-slate-500">Last 12 months in the selected area (period filter not applied).</p><div class="mt-3"><TrendLinesChart title="New and closed cases per month" :rows="monthly" :series="[{ key: 'opened', label: 'New cases', color: '#7c5cd6' }, { key: 'closed', label: 'Closed cases', color: '#2f9e6b' }]" /></div><ChartTable title="New and closed cases" :rows="monthly.map(row => ({ label: row.label, total: row.opened as number }))" label-header="Month" value-header="New cases" /></article>
        <article class="chart-card"><h2>New cases by quarter</h2><p class="mt-0.5 text-xs text-slate-500">Last 8 quarters in the selected area.</p><div class="mt-3"><TrendAreaChart title="New cases by quarter" :rows="quarters" /></div></article>
      </div>

      <!-- Smart insights (rule-based, computed from the publishable figures on this page) -->
      <section v-if="insights.length" class="chart-card insights" aria-labelledby="insights-title">
        <h2 id="insights-title" class="flex items-center gap-2"><Sparkles class="h-4 w-4 text-brand-700" aria-hidden="true" />What the numbers say</h2>
        <ul class="mt-3 grid gap-2 md:grid-cols-2"><li v-for="(item, index) in insights" :key="index" class="insight" :class="`insight-${item.tone}`"><span class="font-semibold">{{ item.title }}</span><span class="text-slate-600">{{ item.text }}</span></li></ul>
        <p class="mt-2 text-xs text-slate-500">Plain rules over the figures above; no case-level data and no external AI. Check before using in reports.</p>
      </section>

      <div class="grid gap-5 lg:grid-cols-2">
        <article class="chart-card"><h2>Case status distribution</h2><p class="mt-1 text-xs text-slate-500">Groups below minimum cell size are suppressed.</p><div class="mt-4"><DonutChart title="Case status distribution" :rows="statusRows" center-label="cases" /></div><ChartTable title="Case status distribution" :rows="statusRows" /></article>
        <article class="chart-card"><h2>Reporting Pipeline</h2><p class="mt-1 text-xs text-slate-500">How many referrals moved to each step, and the share of those sent.</p><div class="mt-4"><FunnelChart title="Reporting Pipeline" :steps="referralFunnel" /></div><p v-if="notProceeded" class="mt-3 text-xs text-slate-600">{{ notProceeded }} referral(s) did not proceed (declined, returned, unavailable or withdrawn).</p><ChartTable title="Referral status" :rows="referralRows" label-header="Status" value-header="Referrals" /></article>
      </div>

      <div class="grid gap-5 xl:grid-cols-[1.4fr_1fr]">
        <article class="chart-card"><div class="flex items-center justify-between gap-3"><div><h2>Monthly intake</h2><p class="mt-0.5 text-xs text-slate-500">Last 6 months</p></div><span class="chart-tag">{{ trendTotal === 'Suppressed' ? 'Total withheld' : `${trendTotal} total` }}</span></div><div class="mt-3"><TrendAreaChart title="Monthly intake" :rows="props.trend" /></div><ChartTable title="Monthly intake" :rows="props.trend.map(row => ({ label: row.label, total: row.total as number }))" label-header="Month" /></article>
        <article class="chart-card"><h2>Cases by area</h2><p class="mt-1 text-xs text-slate-500">Ranked; PSGC-ready geographic workload.</p><div class="mt-4"><LollipopChart v-if="geoRows.length" title="Cases by area" :rows="geoRows" /><p v-else class="text-sm text-slate-600">No publishable area data.</p></div></article>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <article class="chart-card"><h2>Services provided</h2><p class="mt-1 text-xs text-slate-500">By type: services given, cases served and amount.</p><div class="mt-4"><ServicesBars title="Services provided" :rows="props.services" /></div><ChartTable title="Services provided" :rows="props.services.map(row => ({ label: row.label, total: row.total }))" label-header="Service" value-header="Services" /></article>
        <article class="chart-card"><h2>Cases by sex</h2><div class="mt-4"><DonutChart title="Cases by sex" :rows="props.sex" center-label="cases" :colors="['#e0607e', '#3b82d6', '#64748b']" /></div></article>
      </div>
      <article class="chart-card"><h2>KaVAT priority (latest per case)</h2><p class="mt-1 text-xs text-slate-500">From finalized KaVAT results only.</p><div class="mt-4"><DonutChart v-if="kavatPriority.length" title="KaVAT priority" :rows="kavatPriority" center-label="cases" :colors="['#e0607e', '#2f9e6b']" /><p v-else class="text-sm text-slate-600">No finalized KaVAT to show here.</p></div></article>
      <div class="print-watermark" aria-hidden="true"><span v-for="n in 12" :key="n">{{ watermark }}</span></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.chart-card {
  border: 1px solid #e6e9ef;
  border-radius: 1.15rem;
  background: #fff;
  padding: 1.25rem;
  box-shadow: 0 1px 2px rgb(15 23 42 / .04);
}
.chart-card h2 {
  font-weight: 800;
  color: #1e293b;
}
.chart-tag {
  border-radius: .5rem;
  background: var(--color-brand-50);
  padding: .35rem .6rem;
  font-size: .7rem;
  font-weight: 700;
  color: var(--color-brand-700);
}
.total-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  border-radius: 1.15rem;
  border: 1px solid #e6e9ef;
  background: #fff;
  padding: 1.25rem;
  box-shadow: 0 1px 2px rgb(15 23 42 / .04);
}
.total-icon {
  display: grid;
  height: 2.75rem;
  width: 2.75rem;
  place-items: center;
  border-radius: .875rem;
  font-size: 1.1rem;
  font-weight: 800;
}
.total-violet .total-icon { background: var(--color-brand-50); color: var(--color-brand-600); }
.total-violet p:last-child { color: var(--color-brand-800); }
.total-blue .total-icon { background: #eff6ff; color: #3b82f6; }
.total-blue p:last-child { color: #1d4ed8; }
.total-amber .total-icon { background: #fffbeb; color: #d97706; }
.total-amber p:last-child { color: #92400e; }
.insights{background:linear-gradient(135deg,#faf8ff,#fff)}
.insight{display:flex;flex-direction:column;gap:.15rem;border-radius:.8rem;border:1px solid #e9e5f5;background:#fff;padding:.65rem .8rem;font-size:.82rem}
.insight-warn{border-color:#fcd34d;background:#fffbeb}.insight-good{border-color:#a7f3d0;background:#ecfdf5}

/* PDF export: the page as shown, with a diagonal watermark naming the user and time. */
.print-watermark{display:none}
@media print {
  .print-watermark{position:fixed;inset:0;z-index:50;display:flex;flex-direction:column;justify-content:space-around;align-items:center;pointer-events:none;transform:rotate(-30deg)}
  .print-watermark span{font-size:18px;font-weight:700;color:rgb(91 33 182 / .12);white-space:nowrap}
  .chart-card,.total-card,.analytics-report :deep(section[aria-labelledby="drill-map-title"]){break-inside:avoid}
  .analytics-report{page:analytics;gap:0}
  .analytics-report > * + *{margin-top:10px}
  .chart-card,.total-card{box-shadow:none!important}
  /* Compact report: charts two per row, totals four per row, smaller drawings. */
  .analytics-report > div.grid:has(> .chart-card){grid-template-columns:repeat(2,minmax(0,1fr))!important}
  .analytics-report > div.grid:has(> .total-card){grid-template-columns:repeat(4,minmax(0,1fr))!important}
  .analytics-report :deep(svg){max-height:58mm}
  /* Report order: heading, totals, grants, then the map and charts. */
  .analytics-report{display:flex!important;flex-direction:column}
  .analytics-report > *{order:4}
  .analytics-report > .report-head{order:0}
  .analytics-report > div.grid:has(> .total-card){order:1}
  .analytics-report > .total-card{order:2}
  .total-card p.text-2xl{overflow-wrap:normal;word-break:normal;font-size:1.35rem}
  .analytics-report :deep(section[aria-labelledby="drill-map-title"] svg){max-height:70mm}
  .analytics-report :deep(section[aria-labelledby="drill-map-title"] ul){max-height:none}
}
@page analytics {
  size: A4 landscape;
  margin: 12mm 10mm 14mm;
  @bottom-left { content: "Kalinga IS analytics report - CONFIDENTIAL"; font-size: 8pt; color: #64748b; }
  @bottom-right { content: "Page " counter(page) " of " counter(pages); font-size: 8pt; color: #64748b; }
}
@media (max-width: 640px) {
  .total-card { padding: 1rem; gap: .75rem; }
  .total-icon { height: 2.25rem; width: 2.25rem; font-size: .9rem; border-radius: .65rem; }
  .total-card p:last-child { font-size: 1.5rem; }
}
</style>
