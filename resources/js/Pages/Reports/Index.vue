<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { CLIENT_CATEGORIES } from '@/utils/caseOptions';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import { Head, useForm } from '@inertiajs/vue3';
import LocationSelects from '@/Components/LocationSelects.vue';
import { computed, ref } from 'vue';
import { formatPh } from '@/utils/philippineTime';

const props = defineProps<{
  items: any[];
  reportTransitions: Record<string, string[]>;
}>();

const client = useForm({ first_name: '', last_name: '', birthdate: '', sex: '', region: '', province: '', barangay: '', municipality: '', category: '' });

const moveReport = (id: number, status: string) => useForm({ status }).patch(route('reports.transition', id));
const createCase = (id: number) => client.post(route('reports.case.store', id));
const displayStatus = (s: string) => s.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());

const statusFilter = ref<'all' | 'pending' | 'accepted' | 'resolved'>('all');
const pendingStatuses = ['RECEIVED', 'UNDER_REVIEW', 'PENDING'];
const resolvedStatuses = ['CASE_CREATED', 'REJECTED', 'DUPLICATE', 'REFERRED_OUT'];

const filteredItems = computed(() => {
  if (statusFilter.value === 'all') return props.items;
  if (statusFilter.value === 'pending') return props.items.filter(i => pendingStatuses.includes(i.status));
  if (statusFilter.value === 'accepted') return props.items.filter(i => ['ACCEPTED', 'VALIDATED'].includes(i.status));
  return props.items.filter(i => resolvedStatuses.includes(i.status));
});

const pendingCount = computed(() => props.items.filter(i => pendingStatuses.includes(i.status)).length);
const acceptedCount = computed(() => props.items.filter(i => ['ACCEPTED', 'VALIDATED'].includes(i.status)).length);

const statusTone = (status: string) => {
  if (['CASE_CREATED'].includes(status)) return 'bg-emerald-50 text-emerald-800 border-emerald-200';
  if (['ACCEPTED', 'VALIDATED'].includes(status)) return 'bg-blue-50 text-blue-800 border-blue-200';
  if (['REJECTED', 'DUPLICATE'].includes(status)) return 'bg-slate-100 text-slate-500 border-slate-200';
  if (['REFERRED_OUT'].includes(status)) return 'bg-amber-50 text-amber-800 border-amber-200';
  return 'bg-brand-50 text-brand-700 border-brand-200';
};

const fmtDate = (d: string) => formatPh(d, 'date');
</script>

<template>
  <Head title="Intake screening" />
  <AuthenticatedLayout>
    <section class="mx-auto w-full max-w-5xl space-y-5">
      <SectionNav section="cases" />
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Cases</p>
        <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Intake screening</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Optional pathway - public concern submissions endorsed to your LGU. Most cases are registered directly through the intake sheet.</p>
        <div v-if="items.length" class="mt-4 flex flex-wrap gap-2">
          <span v-if="pendingCount" class="rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700">{{ pendingCount }} pending</span>
          <span v-if="acceptedCount" class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">{{ acceptedCount }} accepted</span>
          <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">{{ items.length }} total</span>
        </div>
      </header>

      <nav v-if="items.length" class="flex gap-1 rounded-xl bg-slate-100 p-1">
        <button v-for="tab in ([
          { key: 'all', label: `All (${items.length})` },
          { key: 'pending', label: `Pending (${pendingCount})` },
          { key: 'accepted', label: `Accepted (${acceptedCount})` },
          { key: 'resolved', label: 'Resolved' },
        ] as const)" :key="tab.key" type="button" class="flex-1 rounded-lg px-3 py-2 text-sm font-bold transition-colors" :class="statusFilter === tab.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900'" @click="statusFilter = tab.key">{{ tab.label }}</button>
      </nav>

      <div v-if="filteredItems.length" class="space-y-3">
        <article v-for="item in filteredItems" :key="item.id" class="ui-panel p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <p class="font-bold text-slate-900">{{ item.reference_no }}</p>
              <p class="mt-1 text-sm text-slate-500 line-clamp-2">{{ item.narrative || 'No narrative provided' }}</p>
              <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                <span v-if="item.first_name">{{ item.first_name }} {{ item.last_name || '' }}</span>
                <span v-if="item.endorsed_municipality">{{ item.endorsed_municipality }}</span>
                <span v-if="item.source">Source: {{ item.source }}</span>
                <span v-if="item.created_at">{{ fmtDate(item.created_at) }}</span>
              </div>
            </div>
            <span class="shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-bold" :class="statusTone(item.status)">{{ displayStatus(item.status) }}</span>
          </div>

          <div v-if="reportTransitions[item.status]?.length" class="mt-4 flex flex-wrap gap-2">
            <button v-for="status in reportTransitions[item.status]" :key="status" @click="moveReport(item.id, status)" class="rounded-lg border border-brand-200 px-3 py-1.5 text-xs font-bold text-brand-700 transition hover:bg-brand-50">{{ displayStatus(status) }}</button>
          </div>

          <form v-if="item.status === 'ACCEPTED'" @submit.prevent="createCase(item.id)" class="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-4">
            <p class="mb-3 text-sm font-bold text-slate-700">Create case from accepted report</p>
            <div class="grid gap-3 sm:grid-cols-2">
              <input v-model="client.first_name" required placeholder="Client first name" class="rounded-lg border-slate-300 text-sm">
              <input v-model="client.last_name" required placeholder="Client last name" class="rounded-lg border-slate-300 text-sm">
              <input v-model="client.birthdate" type="date" class="rounded-lg border-slate-300 text-sm">
              <select v-model="client.sex" class="rounded-lg border-slate-300 text-sm"><option value="">Sex (optional)</option><option>Female</option><option>Male</option><option>Other</option></select>
              <div class="sm:col-span-2"><LocationSelects v-model:region="client.region" v-model:province="client.province" v-model:municipality="client.municipality" v-model:barangay="client.barangay" /></div>
              <OptionSelect v-model="client.category" :options="CLIENT_CATEGORIES" :allow-other="false" />
              <div class="flex items-end"><button :disabled="client.processing" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-800">Create case folder</button></div>
            </div>
          </form>
        </article>
      </div>

      <div v-else-if="items.length && !filteredItems.length" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
        <p class="text-sm text-slate-500">No reports match this filter.</p>
      </div>

      <div v-else class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
        <p class="text-sm text-slate-500">No reports endorsed to your LGU.</p>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
