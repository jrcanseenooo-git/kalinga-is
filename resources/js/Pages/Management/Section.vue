<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GeographicHeatmap from '@/Components/GeographicHeatmap.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'

type Row = { label: string; total: number }
const props = defineProps<{
  section: 'pipeline'|'geography'|'continuity'
  kpis: Record<string, number|string>
  filters: Record<string, string|number|boolean|null|undefined>
  by_status?: Row[]
  by_stage?: Row[]
  by_lgu?: Row[]
  by_province?: Row[]
  by_region?: Row[]
  referrals?: Row[]
  suppression_rule: string
  kpi_notice: string
}>()

const copy = {
  pipeline: { eyebrow: 'Central Office · Case pipeline', title: 'Case pipeline', description: 'Review aggregate case flow and approved timeliness metadata. Use filters only for an approved management question.' },
  geography: { eyebrow: 'Central Office · Geographic coverage', title: 'Geographic coverage', description: 'Review aggregate geographic distribution. This is not a client-location tool and contains no client-level pins.' },
  continuity: { eyebrow: 'Central Office · Service continuity', title: 'Service continuity', description: 'Review aggregate referral and continuity indicators. Linked case activity remains in the authorized casework record.' },
}[props.section]

const filter = reactive({ region: String(props.filters.region || ''), province: String(props.filters.province || ''), municipality: String(props.filters.municipality || ''), status: String(props.filters.status || ''), stage: String(props.filters.stage || ''), risk_level: String(props.filters.risk_level || ''), from: String(props.filters.from || ''), to: String(props.filters.to || ''), aftercare: props.filters.aftercare ?? '', overdue: props.filters.overdue ?? '' })
const applyFilters = () => {
  const params = Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== '' && value !== null && value !== undefined))
  router.get(route('management.section', { section: props.section }), params, { preserveState: true, preserveScroll: true })
}
const resetFilters = () => { Object.assign(filter, { region: '', province: '', municipality: '', status: '', stage: '', risk_level: '', from: '', to: '', aftercare: '', overdue: '' }); applyFilters() }
const focusRegion = (region: string) => { filter.region = region; filter.province = ''; filter.municipality = ''; applyFilters() }
const visibleKpis = () => props.section === 'pipeline'
  ? [['total_cases', props.kpis.total_cases], ['active_cases', props.kpis.active_cases], ['overdue_actions', props.kpis.overdue_actions]]
  : props.section === 'geography'
    ? [['total_cases', props.kpis.total_cases], ['active_cases', props.kpis.active_cases]]
    : [['open_referrals', props.kpis.open_referrals], ['aftercare_cases', props.kpis.aftercare_cases], ['overdue_actions', props.kpis.overdue_actions]]
const groups = (): Array<{ title: string; rows: Row[] }> => {
  if (props.section === 'pipeline') return [{ title: 'Case status', rows: props.by_status || [] }, { title: 'Case stage', rows: props.by_stage || [] }]
  if (props.section === 'geography') return [{ title: 'Province', rows: props.by_province || [] }, { title: 'LGU workload', rows: props.by_lgu || [] }]
  return [{ title: 'Referrals', rows: props.referrals || [] }]
}
</script>

<template>
  <Head :title="copy.title" />
  <AuthenticatedLayout>
    <section class="mx-auto max-w-6xl">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm font-bold text-brand-700">{{ copy.eyebrow }}</p><h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">{{ copy.title }}</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ copy.description }}</p></div><Link :href="route('management.dashboard')" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Management overview</Link></div>

      <form class="mt-5 grid gap-3 ui-panel p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
        <label v-if="section === 'geography'" class="text-sm font-bold">Region<input v-model.trim="filter.region" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <label v-if="section === 'geography'" class="text-sm font-bold">Province<input v-model.trim="filter.province" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <label v-if="section === 'geography'" class="text-sm font-bold">LGU / municipality<input v-model.trim="filter.municipality" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <label v-if="section === 'pipeline'" class="text-sm font-bold">Case status<input v-model.trim="filter.status" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <label v-if="section === 'pipeline'" class="text-sm font-bold">Case stage<input v-model.trim="filter.stage" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <label v-if="section === 'pipeline'" class="text-sm font-bold">Risk metadata<select v-model="filter.risk_level" class="mt-1 block w-full rounded-xl border-slate-300 font-normal"><option value="">All approved levels</option><option value="low">Low</option><option value="moderate">Moderate</option><option value="high">High</option><option value="critical">Critical</option></select></label>
        <label v-if="section === 'continuity'" class="text-sm font-bold">Aftercare<select v-model="filter.aftercare" class="mt-1 block w-full rounded-xl border-slate-300 font-normal"><option value="">All cases</option><option :value="1">With aftercare</option><option :value="0">Without aftercare</option></select></label>
        <label v-if="section !== 'geography'" class="text-sm font-bold">Timeliness<select v-model="filter.overdue" class="mt-1 block w-full rounded-xl border-slate-300 font-normal"><option value="">All cases</option><option :value="1">Overdue action</option></select></label>
        <label class="text-sm font-bold">Opened from<input v-model="filter.from" type="date" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <label class="text-sm font-bold">Opened to<input v-model="filter.to" type="date" class="mt-1 block w-full rounded-xl border-slate-300 font-normal" /></label>
        <div class="flex items-end gap-2"><button class="min-h-11 rounded-xl bg-brand-700 px-4 text-sm font-bold text-white">Apply filters</button><button type="button" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold" @click="resetFilters">Reset</button></div>
      </form>

      <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{{ kpi_notice }} {{ suppression_rule }}</p>
      <GeographicHeatmap v-if="section === 'geography'" class="mt-5" :rows="(by_region || []).map(row => ({ area: row.label, total: row.total }))" :selected-region="filter.region" @region-change="focusRegion" />
      <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><article v-for="[key, value] in visibleKpis()" :key="String(key)" class="ui-panel p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ String(key).replaceAll('_', ' ') }}</p><p class="mt-2 text-3xl font-extrabold text-brand-800">{{ value }}</p></article></div>
      <div class="mt-5 grid gap-5 md:grid-cols-2"><section v-for="group in groups()" :key="group.title" class="ui-panel p-5"><h2 class="font-extrabold text-slate-900">{{ group.title }}</h2><div v-if="group.rows.length" class="mt-3 space-y-2"><div v-for="row in group.rows" :key="row.label" class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm"><span class="min-w-0 truncate">{{ row.label }}</span><b>{{ row.total }}</b></div></div><p v-else class="mt-3 text-sm text-slate-500">No reportable cells under the suppression rule.</p></section></div>
    </section>
  </AuthenticatedLayout>
</template>
