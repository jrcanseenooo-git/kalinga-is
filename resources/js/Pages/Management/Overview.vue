<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link } from '@inertiajs/vue3'
import { BarChart3, ChevronRight, FolderOpen, Map, Route, ShieldCheck, TriangleAlert, Send, HeartHandshake, Activity } from '@lucide/vue'

defineProps<{ kpis: Record<string, number|string>; suppression_rule: string; kpi_notice: string }>()

const workspaces = [
  { section: 'pipeline', title: 'Case pipeline', icon: BarChart3, description: 'Stages, status, approved risk metadata, and timeliness indicators.' },
  { section: 'geography', title: 'Geographic coverage', icon: Map, description: 'Aggregated regional, provincial, and LGU coverage.' },
  { section: 'continuity', title: 'Service continuity', icon: Route, description: 'Referrals, aftercare, and overdue-action context.' },
]
// Presentation only: tone and icon per known KPI key, with a neutral fallback for any other key.
const style: Record<string, { tone: string; tile: string; figure: string; icon: unknown }> = {
  total_cases: { tone: 'ui-kpi-brand', tile: 'bg-brand-100 text-brand-700', figure: 'text-brand-900', icon: FolderOpen },
  active_cases: { tone: 'ui-kpi-info', tile: 'bg-sky-100 text-sky-700', figure: 'text-sky-900', icon: Activity },
  overdue_actions: { tone: 'ui-kpi-danger', tile: 'bg-rose-100 text-rose-700', figure: 'text-rose-700', icon: TriangleAlert },
  open_referrals: { tone: 'ui-kpi-warning', tile: 'bg-amber-100 text-amber-700', figure: 'text-amber-800', icon: Send },
  aftercare_cases: { tone: 'ui-kpi-success', tile: 'bg-emerald-100 text-emerald-700', figure: 'text-emerald-800', icon: HeartHandshake },
}
const fallback = { tone: 'ui-kpi-brand', tile: 'bg-brand-100 text-brand-700', figure: 'text-brand-900', icon: BarChart3 }
const look = (key: string | number) => style[String(key)] ?? fallback
const label = (key: string | number) => String(key).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const suppressed = (value: number | string) => value === 'Suppressed'
</script>

<template>
  <Head title="National dashboard" />
  <AuthenticatedLayout>
    <section class="mx-auto max-w-[1440px] space-y-5">
      <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">Central Office</p>
          <h1 class="mt-1 text-2xl font-bold tracking-tight text-[var(--color-ink)] sm:text-[28px]">CEFMU national management dashboard</h1>
          <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">Aggregate figures across pilot LGUs. No case files, client identities or narratives are shown here.</p>
        </div>
        <p class="inline-flex items-center gap-2 self-start rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 sm:self-auto"><ShieldCheck class="h-4 w-4" aria-hidden="true" />Aggregate-only view</p>
      </header>

      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <article v-for="(value, key) in kpis" :key="key" class="ui-kpi" :class="look(key).tone">
          <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :class="look(key).tile"><component :is="look(key).icon" class="h-5 w-5" aria-hidden="true" /></span>
            <div class="min-w-0">
              <p v-if="suppressed(value)" class="text-2xl font-bold leading-none" :class="look(key).figure">&lt;5<span class="ml-1 align-middle text-[11px] font-semibold text-slate-500">suppressed</span></p>
              <p v-else class="text-[30px] font-bold leading-none tracking-tight" :class="look(key).figure">{{ value }}</p>
              <p class="mt-2 text-sm font-semibold text-slate-700">{{ label(key) }}</p>
            </div>
          </div>
        </article>
      </div>

      <div class="grid gap-4 md:grid-cols-3">
        <Link v-for="workspace in workspaces" :key="workspace.section" :href="route('management.section', { section: workspace.section })" class="ui-panel group p-5 transition hover:border-brand-200 hover:shadow-md">
          <span class="grid h-11 w-11 place-items-center rounded-xl bg-brand-50 text-brand-700"><component :is="workspace.icon" class="h-5 w-5" aria-hidden="true" /></span>
          <h2 class="mt-4 text-base font-semibold text-[var(--color-ink)]">{{ workspace.title }}</h2>
          <p class="mt-1 text-sm leading-6 text-slate-600">{{ workspace.description }}</p>
          <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-700">Open workspace<ChevronRight class="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" /></span>
        </Link>
      </div>

      <div class="flex items-start gap-3 rounded-xl border border-[var(--color-line)] bg-white p-4 text-sm leading-6 text-slate-600"><ShieldCheck class="mt-0.5 h-5 w-5 shrink-0 text-emerald-700" aria-hidden="true" /><p>{{ suppression_rule }} {{ kpi_notice }} Downloaded summaries follow the same aggregate-only rules.</p></div>
    </section>
  </AuthenticatedLayout>
</template>
