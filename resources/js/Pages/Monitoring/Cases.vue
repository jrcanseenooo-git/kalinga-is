<script setup lang="ts">
/** Case monitoring table for the LGU Head, Field Office and Project Manager (secured names only). */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { riskTone, statusTone, titleCase } from '@/utils/caseDisplay';
import { formatPh } from '@/utils/philippineTime';
import { Head, Link, router } from '@inertiajs/vue3';
import { MessageSquareText, Search, ShieldCheck, SlidersHorizontal, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

type Row = { id: number; case_number: string; name: string; case_manager: string | null; lgu: string | null; region: string | null; status: string; stage: string | null;
    priority: string; risk_level: string | null; opened_at: string | null; last_activity_at: string | null; open_remarks: number };
type Filters = { search?: string; status?: string; region?: string; lgu?: number | string; worker?: number | string; risk?: string; remarks?: boolean | string; sort?: string };
const props = defineProps<{
    cases: { data: Row[]; total: number; from: number | null; to: number | null; prev_page_url: string | null; next_page_url: string | null; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: Filters; scopeLabel: string;
    summary: { total: number; active: number; closureReview: number; highRisk: number };
    options: { statuses: string[]; regions: string[]; lgus: Array<{ id: number; name: string; region: string | null }>; workers: Array<{ id: number; name: string }> };
}>();

const form = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', region: props.filters.region ?? '', lgu: String(props.filters.lgu ?? ''),
    worker: String(props.filters.worker ?? ''), risk: props.filters.risk ?? '', remarks: Boolean(props.filters.remarks), sort: props.filters.sort ?? 'recent' });
const advanced = computed(() => [form.region, form.lgu, form.worker, form.risk, form.remarks, form.sort !== 'recent'].filter(Boolean).length);
const open = ref(advanced.value > 0);
const lgus = computed(() => props.options.lgus.filter(lgu => !form.region || lgu.region === form.region));
const apply = () => {
    const query: Record<string, string | number> = { ...form, remarks: form.remarks ? 1 : '', sort: form.sort === 'recent' ? '' : form.sort };
    router.get(route('monitoring.cases.index'), Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '')), { preserveState: true, replace: true });
};
const quick = (key: 'status' | 'risk' | 'remarks', value: string | boolean) => {
    Object.assign(form, { status: '', risk: '', remarks: false }, { [key]: value });
    apply();
};
const clear = () => { Object.assign(form, { search: '', status: '', region: '', lgu: '', worker: '', risk: '', remarks: false, sort: 'recent' }); apply(); };
const cards = computed(() => [
    { label: 'Cases', value: props.summary.total, action: () => quick('status', '') },
    { label: 'Active', value: props.summary.active, action: () => quick('status', '') },
    { label: 'High or critical risk', value: props.summary.highRisk, action: () => quick('risk', 'high') },
    { label: 'Waiting for closure review', value: props.summary.closureReview, action: () => quick('status', 'FOR_CLOSURE_REVIEW') },
]);
</script>

<template>
    <Head title="Cases" />
    <AuthenticatedLayout>
        <section class="mx-auto w-full max-w-7xl space-y-4">
            <PageHeader title="Cases" eyebrow="Monitoring" :description="`Cases in ${scopeLabel}. Children are shown by alias or masked name only.`" />

            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <button v-for="card in cards" :key="card.label" type="button" class="rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-brand-300" @click="card.action">
                    <span class="block text-2xl font-extrabold text-slate-900">{{ card.value }}</span><span class="text-xs font-semibold text-slate-600">{{ card.label }}</span>
                </button>
            </div>

            <form class="ui-panel space-y-3 p-4" role="search" aria-label="Find cases" data-skip-generic-confirm="true" @submit.prevent="apply">
                <div class="flex flex-wrap gap-2">
                    <label class="relative min-w-0 flex-1"><span class="sr-only">Search by case number or alias</span>
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                        <input v-model="form.search" type="search" maxlength="80" placeholder="Case number or alias" class="min-h-11 w-full rounded-xl border-slate-300 pl-9 text-sm">
                    </label>
                    <select v-model="form.status" aria-label="Status" class="min-h-11 rounded-xl border-slate-300 text-sm" @change="apply"><option value="">All statuses</option><option v-for="s in options.statuses" :key="s" :value="s">{{ titleCase(s) }}</option></select>
                    <button type="submit" class="ui-button-primary min-h-11 px-4">Search</button>
                    <button type="button" class="ui-button min-h-11 px-3" :aria-expanded="open" aria-controls="monitor-filters" @click="open = !open"><SlidersHorizontal class="h-4 w-4" aria-hidden="true" />Filters<span v-if="advanced" class="rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ advanced }}</span></button>
                </div>
                <div v-show="open" id="monitor-filters" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label v-if="options.regions.length > 1" class="text-xs font-bold text-slate-700">Region<select v-model="form.region" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal" @change="form.lgu = ''"><option value="">All regions</option><option v-for="r in options.regions" :key="r" :value="r">{{ r }}</option></select></label>
                    <label v-if="lgus.length > 1" class="text-xs font-bold text-slate-700">LGU<select v-model="form.lgu" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All LGUs</option><option v-for="l in lgus" :key="l.id" :value="String(l.id)">{{ l.name }}</option></select></label>
                    <label class="text-xs font-bold text-slate-700">Case manager<select v-model="form.worker" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All case managers</option><option v-for="w in options.workers" :key="w.id" :value="String(w.id)">{{ w.name }}</option></select></label>
                    <label class="text-xs font-bold text-slate-700">Risk level<select v-model="form.risk" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">Any</option><option value="critical">Critical</option><option value="high">High</option><option value="moderate">Moderate</option><option value="low">Low</option></select></label>
                    <label class="text-xs font-bold text-slate-700">Sort<select v-model="form.sort" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="recent">Latest activity</option><option value="risk">Highest risk first</option><option value="oldest">Oldest first</option><option value="case">Case number</option></select></label>
                    <label class="flex min-h-11 items-center gap-2 self-end text-sm font-semibold text-slate-700"><input v-model="form.remarks" type="checkbox" class="rounded border-slate-300 text-brand-700">Remarks not yet read</label>
                    <div class="flex items-end gap-2"><button type="submit" class="ui-button-primary min-h-11 px-4">Apply</button><button type="button" class="ui-button min-h-11 px-3" @click="clear"><X class="h-4 w-4" aria-hidden="true" />Clear</button></div>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <p class="flex items-center gap-1.5 border-b border-slate-100 px-4 py-2.5 text-xs font-semibold text-slate-600"><ShieldCheck class="h-4 w-4 text-emerald-600" aria-hidden="true" />{{ cases.total }} {{ cases.total === 1 ? 'case' : 'cases' }} · secured names</p>
                <table class="hidden w-full text-left text-sm md:table">
                    <thead><tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                        <th scope="col" class="py-3 pl-4 pr-2">Case / client</th><th scope="col" class="px-2 py-3">Case manager</th><th scope="col" class="px-2 py-3">LGU / region</th>
                        <th scope="col" class="px-2 py-3">Status</th><th scope="col" class="px-2 py-3">Risk</th><th scope="col" class="py-3 pl-2 pr-4">Last activity</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in cases.data" :key="row.id" class="hover:bg-brand-50/40">
                            <td class="py-3 pl-4 pr-2"><Link :href="route('monitoring.cases.show', row.id)" class="font-semibold text-brand-800 hover:underline">{{ row.case_number }}</Link>
                                <span class="block font-mono text-xs text-slate-700">{{ row.name }}</span>
                                <span v-if="row.open_remarks" class="mt-1 inline-flex items-center gap-1 text-[11px] font-bold text-amber-800"><MessageSquareText class="h-3.5 w-3.5" aria-hidden="true" />{{ row.open_remarks }} unread remark{{ row.open_remarks === 1 ? '' : 's' }}</span></td>
                            <td class="px-2 py-3 text-slate-800">{{ row.case_manager || 'Not assigned' }}</td>
                            <td class="px-2 py-3"><span class="text-slate-800">{{ row.lgu || '-' }}</span><span class="block text-xs text-slate-600">{{ row.region }}</span></td>
                            <td class="px-2 py-3"><StatusBadge :label="titleCase(row.status)" :tone="statusTone(row.status)" /></td>
                            <td class="px-2 py-3"><StatusBadge v-if="row.risk_level" :label="titleCase(row.risk_level)" :tone="riskTone(row.risk_level)" /><span v-else class="text-slate-500">-</span></td>
                            <td class="py-3 pl-2 pr-4 text-xs text-slate-600">{{ formatPh(row.last_activity_at, 'dateShort', '-') }}</td>
                        </tr>
                    </tbody>
                </table>
                <ul class="divide-y divide-slate-100 md:hidden" aria-label="Cases">
                    <li v-for="row in cases.data" :key="row.id">
                        <Link :href="route('monitoring.cases.show', row.id)" class="block px-4 py-3">
                            <div class="flex items-start justify-between gap-2"><span class="font-semibold text-brand-800">{{ row.case_number }}</span><StatusBadge :label="titleCase(row.status)" :tone="statusTone(row.status)" /></div>
                            <p class="font-mono text-xs text-slate-700">{{ row.name }}</p>
                            <p class="mt-1 text-xs text-slate-600">{{ row.case_manager || 'Not assigned' }} · {{ row.lgu }}<template v-if="row.risk_level"> · {{ titleCase(row.risk_level) }} risk</template></p>
                            <p v-if="row.open_remarks" class="mt-1 text-[11px] font-bold text-amber-800">{{ row.open_remarks }} unread remark{{ row.open_remarks === 1 ? '' : 's' }}</p>
                        </Link>
                    </li>
                </ul>
                <p v-if="!cases.data.length" class="py-14 text-center text-sm text-slate-600">No cases match.</p>
                <Pagination :previous="cases.prev_page_url" :next="cases.next_page_url" :from="cases.from" :to="cases.to" :total="cases.total" :links="cases.links" />
            </div>
        </section>
    </AuthenticatedLayout>
</template>
