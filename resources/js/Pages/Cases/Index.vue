<script setup lang="ts">
import { formatPh } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import type { CaseListItem, CaseListPage } from '@/types/casework';
import { understandCaseSearch, type Understood } from '@/utils/caseSearch';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, ChevronRight, Plus, Search, SlidersHorizontal, Sparkles, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { priorityTone, statusTone, titleCase } from '@/utils/caseDisplay';

type Filters = { search:string; status?:string; active?:boolean; attention?:boolean; region?:string; priority?:string; from?:string; to?:string; sort?:string };
const props = defineProps<{ cases:CaseListPage; canCreate:boolean; statusCounts?:Record<string,number>; filters:Filters }>();

const search = ref(props.filters.search || '');
const selectedStatus = ref(props.filters.status ?? '');
const activeOnly = ref(props.filters.active ?? false);
const priority = ref(props.filters.priority ?? '');
const openedFrom = ref(props.filters.from ?? '');
const openedTo = ref(props.filters.to ?? '');
const sort = ref(props.filters.sort ?? 'recent');
const statuses = ['OPEN','IN_PROGRESS','AWAITING_REFERRAL','FOR_FOLLOW_UP','FOR_CLOSURE_REVIEW','CLOSED','REOPENED'];
const extraFilters = computed(() => [props.filters.priority, props.filters.from || props.filters.to, props.filters.sort && props.filters.sort !== 'recent', props.filters.status && !['FOR_FOLLOW_UP','FOR_CLOSURE_REVIEW','CLOSED'].includes(props.filters.status)].filter(Boolean).length);
const filtersOpen = ref(extraFilters.value > 0);

/** What the smart search read from the last typed phrase (shown as chips). */
const understood = ref<Understood[]>([]);
const literal = ref('');

function visit(params: Record<string, string | number | boolean | undefined>) {
  const query = Object.fromEntries(Object.entries(params).filter(([, value]) => value !== '' && value !== undefined && value !== false).map(([key, value]) => [key, value === true ? 1 : value]));
  router.get(route('cases.index'), query, { preserveState: true, replace: true });
}
const currentParams = () => ({ search: search.value.trim(), status: selectedStatus.value, active: activeOnly.value, attention: props.filters.attention, region: props.filters.region, priority: priority.value, from: openedFrom.value, to: openedTo.value, sort: sort.value === 'recent' ? '' : sort.value });

function submitSearch() {
  const typed = search.value.trim();
  const { filters, understood: read } = understandCaseSearch(typed);
  understood.value = read; literal.value = read.length ? typed : '';
  if (read.length) {
    search.value = filters.search ?? '';
    if (filters.status) selectedStatus.value = filters.status;
    if (filters.priority) priority.value = filters.priority;
    if (filters.active) activeOnly.value = true;
    if (filters.from) { openedFrom.value = filters.from; openedTo.value = filters.to ?? ''; }
  }
  visit({ ...currentParams(), attention: filters.attention || props.filters.attention });
}
/** Undo the smart reading and search the exact words typed. */
function searchLiterally() {
  const typed = literal.value; understood.value = []; literal.value = '';
  search.value = typed; selectedStatus.value = ''; priority.value = ''; activeOnly.value = false; openedFrom.value = ''; openedTo.value = '';
  visit({ search: typed, region: props.filters.region, sort: sort.value === 'recent' ? '' : sort.value });
}

const count = (key: string) => props.statusCounts?.[key];
const views = computed(() => [
  { key:'attention', label:'Needs action', count:count('needs_action'), params:{attention:1}, active:Boolean(props.filters.attention), tone:'attention' },
  { key:'all', label:'All', count:count('all_cases'), params:{}, active:!props.filters.status && !props.filters.active && !props.filters.attention, tone:'' },
  { key:'active', label:'Active', count:count('active'), params:{active:1}, active:!props.filters.status && Boolean(props.filters.active) && !props.filters.attention, tone:'' },
  { key:'follow', label:'For follow-up', count:count('follow_up'), params:{status:'FOR_FOLLOW_UP'}, active:props.filters.status==='FOR_FOLLOW_UP', tone:'' },
  { key:'review', label:'Closure review', count:count('closure_review'), params:{status:'FOR_CLOSURE_REVIEW'}, active:props.filters.status==='FOR_CLOSURE_REVIEW', tone:'' },
  { key:'closed', label:'Closed', count:count('closed'), params:{status:'CLOSED'}, active:props.filters.status==='CLOSED', tone:'' },
]);

const status = titleCase;
const area = (item:CaseListItem) => [item.municipality, item.province].filter(Boolean).join(', ') || item.region || 'Area not recorded';
const opened = (item:CaseListItem) => formatPh(item.opened_at || item.created_at, 'date', 'Not recorded');
const openedShort = (item:CaseListItem) => formatPh(item.opened_at || item.created_at, 'dateShort');
const initials = (item:CaseListItem) => `${item.first_name?.[0] ?? ''}${item.last_name?.[0] ?? ''}`.toUpperCase() || '?';
const flagged = (item:CaseListItem) => ['high','urgent'].includes(item.priority);
const stepHref = (item:CaseListItem) => `${route('cases.show', item.id)}?section=${encodeURIComponent(item.next_step?.section ?? '')}`;
</script>

<template>
  <Head title="Cases" />
  <AuthenticatedLayout>
    <section class="w-full max-w-none space-y-3 sm:space-y-4">
      <SectionNav section="cases" />

      <header class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-bold tracking-tight text-[var(--color-ink)] sm:text-2xl">My cases</h1>
          <p class="text-sm text-slate-600"><span class="font-semibold text-slate-800">{{ count('active') ?? 0 }}</span> active<template v-if="count('needs_action')"> · <Link :href="route('cases.index', { attention: 1 })" class="font-semibold text-amber-800 hover:underline">{{ count('needs_action') }} need action</Link></template></p>
        </div>
        <Link v-if="canCreate" :href="route('cases.create')" class="ui-button-primary min-h-11 shrink-0" aria-label="New case intake"><Plus class="h-4 w-4" aria-hidden="true"/><span class="max-sm:hidden">New case intake</span><span class="sm:hidden" aria-hidden="true">Intake</span></Link>
      </header>

      <section class="ui-panel overflow-hidden">
        <form data-skip-generic-confirm="true" class="border-b border-slate-100 p-3 sm:p-4" role="search" aria-label="Find cases" @submit.prevent="submitSearch">
          <div class="flex gap-2">
            <label for="case-search" class="sr-only">Search cases</label>
            <div class="relative min-w-0 flex-1">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
              <input id="case-search" v-model="search" type="search" class="ui-field w-full pl-10" placeholder="Search cases" aria-describedby="case-search-hint" />
            </div>
            <button type="button" class="ui-button relative min-h-11 shrink-0 px-3" :aria-expanded="filtersOpen" aria-controls="case-filters" @click="filtersOpen = !filtersOpen"><SlidersHorizontal class="h-4 w-4" aria-hidden="true"/><span class="max-sm:sr-only">Filters</span><span v-if="extraFilters" class="grid h-5 min-w-5 place-items-center rounded-full bg-brand-700 px-1 text-[11px] font-bold text-white">{{ extraFilters }}</span></button>
            <button type="submit" class="ui-button-primary min-h-11 shrink-0 px-3" aria-label="Search"><Search class="h-4 w-4 sm:hidden" aria-hidden="true"/><span class="max-sm:hidden">Search</span></button>
          </div>
          <p v-if="!understood.length" id="case-search-hint" class="mt-1.5 flex items-center gap-1 text-xs text-slate-500"><Sparkles class="h-3.5 w-3.5 shrink-0 text-brand-600" aria-hidden="true"/><span>Tip: type plain words like <em>urgent</em>, <em>follow-up</em>, <em>needs action</em> or <em>opened this month</em>.</span></p>

          <div v-if="understood.length" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs" aria-live="polite">
            <span class="inline-flex items-center gap-1 font-semibold text-brand-800"><Sparkles class="h-3.5 w-3.5" aria-hidden="true"/>Understood:</span>
            <span v-for="item in understood" :key="item.label + item.value" class="rounded-full bg-brand-50 px-2 py-0.5 font-medium text-brand-800">{{ item.label }}: {{ item.value }}</span>
            <button type="button" class="ui-link min-h-8 text-xs" @click="searchLiterally">Search the exact words instead</button>
          </div>

          <div v-show="filtersOpen" id="case-filters" class="mt-3 grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block text-sm font-medium text-slate-700">Status<select v-model="selectedStatus" aria-label="Status" class="mt-1 min-h-11 w-full rounded-md border-slate-300"><option value="">All statuses</option><option v-for="value in statuses" :key="value" :value="value">{{ status(value) }}</option></select></label>
            <label class="block text-sm font-medium text-slate-700">Priority<select v-model="priority" aria-label="Priority" class="mt-1 min-h-11 w-full rounded-md border-slate-300"><option value="">All priorities</option><option v-for="value in ['low','normal','high','urgent']" :key="value" :value="value">{{ status(value) }}</option></select></label>
            <label class="block text-sm font-medium text-slate-700">Opened from<input v-model="openedFrom" aria-label="Opened from" type="date" class="mt-1 min-h-11 w-full rounded-md border-slate-300" /></label>
            <label class="block text-sm font-medium text-slate-700">Opened through<input v-model="openedTo" aria-label="Opened through" type="date" :min="openedFrom || undefined" class="mt-1 min-h-11 w-full rounded-md border-slate-300" /></label>
            <label class="block text-sm font-medium text-slate-700">Sort cases<select v-model="sort" aria-label="Sort cases" class="mt-1 min-h-11 w-full rounded-md border-slate-300"><option value="recent">Recently updated</option><option value="opened_asc">Oldest opening date</option><option value="opened_desc">Newest opening date</option><option value="priority">Recorded priority</option></select></label>
            <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 lg:col-span-5">
              <label class="inline-flex min-h-11 items-center gap-2 text-sm text-slate-700"><input v-model="activeOnly" type="checkbox" />Active only</label>
              <div class="flex gap-2"><Link :href="route('cases.index')" class="ui-button min-h-10">Clear filters</Link><button type="submit" class="ui-button-primary min-h-10">Apply</button></div>
            </div>
          </div>
        </form>

        <nav class="flex gap-1.5 overflow-x-auto border-b border-slate-100 px-3 py-2 sm:px-4" aria-label="Case status views">
          <Link v-for="view in views" :key="view.key" :href="route('cases.index', view.params)" preserve-scroll class="view-pill" :class="[view.active ? 'view-pill-active' : '', view.tone === 'attention' && view.count ? 'view-pill-attention' : '']" :aria-current="view.active ? 'page' : undefined">
            <Sparkles v-if="view.tone === 'attention'" class="h-3.5 w-3.5" aria-hidden="true"/>{{ view.label }}<span v-if="view.count !== undefined" class="view-count">{{ view.count }}</span>
          </Link>
        </nav>
        <p v-if="filters.region" class="border-b border-slate-100 px-4 py-2 text-sm text-slate-600">Region: {{ filters.region }}. <Link :href="route('cases.index')" class="font-semibold text-brand-800">Clear filters</Link></p>

        <template v-if="cases.data.length">
          <!-- Desktop and tablet: one compact table -->
          <table class="hidden w-full text-sm md:table" aria-label="Authorized case folders">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-600"><tr><th scope="col" class="px-4 py-2.5">Client and case number</th><th scope="col" class="px-4 py-2.5">Next step</th><th scope="col" class="hidden px-4 py-2.5 lg:table-cell">Area</th><th scope="col" class="hidden px-4 py-2.5 xl:table-cell">Opened</th><th scope="col" class="px-4 py-2.5">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in cases.data" :key="row.id" class="case-row">
                <td class="px-4 py-2.5"><Link :href="route('cases.show', row.id)" class="flex min-h-10 items-center gap-3"><span class="avatar" aria-hidden="true">{{ initials(row) }}</span><span class="min-w-0"><span class="block font-semibold text-slate-900">{{ row.first_name }} {{ row.last_name }}</span><span class="block text-xs text-slate-500">{{ row.case_number }}<template v-if="row.category"> · {{ row.category }}</template></span></span></Link></td>
                <td class="px-4 py-2.5"><Link v-if="row.next_step" :href="stepHref(row)" class="step" :class="`step-${row.next_step.tone}`"><span class="step-dot" aria-hidden="true"/>{{ row.next_step.title }}<ArrowRight class="h-3.5 w-3.5 opacity-60" aria-hidden="true"/></Link><span v-else class="text-xs text-slate-500">Up to date</span></td>
                <td class="hidden max-w-56 px-4 py-2.5 text-xs text-slate-600 lg:table-cell">{{ area(row) }}</td>
                <td class="hidden whitespace-nowrap px-4 py-2.5 text-xs text-slate-600 xl:table-cell">{{ opened(row) }}</td>
                <td class="px-4 py-2.5"><span class="flex flex-wrap items-center gap-1.5"><StatusBadge :label="status(row.status)" :tone="statusTone(row.status)" /><StatusBadge v-if="flagged(row)" :label="status(row.priority)" :tone="priorityTone(row.priority)" /></span></td>
              </tr>
            </tbody>
          </table>

          <!-- Phones: a light list, one line of facts and the next step -->
          <ul class="divide-y divide-slate-100 md:hidden" aria-label="Authorized case folders">
            <li v-for="row in cases.data" :key="row.id" class="relative">
              <Link :href="route('cases.show', row.id)" class="flex items-start gap-3 px-3 py-3 active:bg-slate-50">
                <span class="avatar mt-0.5" aria-hidden="true">{{ initials(row) }}</span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-center gap-2"><span class="min-w-0 flex-1 truncate font-semibold text-slate-900">{{ row.first_name }} {{ row.last_name }}</span><StatusBadge :label="status(row.status)" :tone="statusTone(row.status)" /></span>
                  <span class="mt-0.5 block truncate text-xs text-slate-500">{{ row.case_number }} · {{ row.municipality || area(row) }}<template v-if="openedShort(row)"> · {{ openedShort(row) }}</template></span>
                  <span v-if="row.next_step || flagged(row)" class="mt-1 flex items-center gap-2 text-xs">
                    <span v-if="flagged(row)" class="rounded bg-rose-50 px-1.5 py-0.5 font-semibold text-rose-700">{{ status(row.priority) }}</span>
                    <span v-if="row.next_step" class="step min-w-0" :class="`step-${row.next_step.tone}`"><span class="step-dot" aria-hidden="true"/><span class="truncate">{{ row.next_step.title }}</span></span>
                  </span>
                </span>
                <ChevronRight class="mt-2 h-4 w-4 shrink-0 text-slate-500" aria-hidden="true"/>
              </Link>
            </li>
          </ul>
        </template>
        <div v-else class="p-10 text-center">
          <p class="font-semibold text-slate-800">{{ filters.attention ? 'Nothing needs action right now' : 'No cases found' }}</p>
          <p class="mt-1 text-sm text-slate-500">{{ filters.attention ? 'Every case in your list is up to date with the suggestion rules.' : 'Try fewer words or clear the filters.' }}</p>
          <div class="mt-3 flex justify-center gap-2"><Link :href="route('cases.index')" class="ui-button min-h-10"><X class="h-4 w-4" aria-hidden="true"/>Clear filters</Link><Link v-if="canCreate && !filters.attention" :href="route('cases.create')" class="ui-button-primary min-h-10">Start new case intake</Link></div>
        </div>

        <Pagination :links="cases.links" :previous="cases.prev_page_url" :next="cases.next_page_url" :from="cases.from" :to="cases.to" :total="cases.total" />
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.view-pill{display:inline-flex;min-height:2.25rem;flex-shrink:0;align-items:center;gap:.35rem;border-radius:999px;border:1px solid transparent;padding:0 .8rem;font-size:.82rem;font-weight:600;color:#475569;white-space:nowrap}
.view-pill:hover{background:#f8fafc}
.view-pill-active{background:#f5f3ff;border-color:#ddd6fe;color:#5b21b6}
.view-pill-attention{color:#92400e}.view-pill-attention.view-pill-active{background:#fffbeb;border-color:#fcd34d;color:#92400e}
.view-count{border-radius:999px;background:#f1f5f9;padding:0 .4rem;font-size:.72rem;line-height:1.25rem;color:#475569}.view-pill-active .view-count{background:#ede9fe;color:#5b21b6}.view-pill-attention .view-count{background:#fef3c7;color:#92400e}
.avatar{display:grid;height:2.25rem;width:2.25rem;flex-shrink:0;place-items:center;border-radius:999px;background:#f5f3ff;color:#5b21b6;font-size:.75rem;font-weight:700}
.case-row:hover{background:#faf9fe}.case-row:hover .font-semibold{color:#5b21b6}
.step{display:inline-flex;max-width:100%;align-items:center;gap:.4rem;font-size:.78rem;font-weight:600}.step-dot{height:.5rem;width:.5rem;flex-shrink:0;border-radius:999px;background:currentColor}
.step-urgent{color:#be123c}.step-action{color:#b45309}.step-info{color:#475569}
a.step:hover{text-decoration:underline}
</style>
