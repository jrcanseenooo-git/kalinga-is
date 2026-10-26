<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { Download, Lock, Search, SlidersHorizontal, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

interface AuditRow {
  id: number; log_id: string; timestamp: string; user: string; role: string; type: string; type_key: string; module: string; action: string;
  record: string; description: string; event: string; old_value: string | null; new_value: string | null; ip_address: string | null; status: string; status_key: string;
}
type Option = { value: string; label: string };
type Filters = Record<'from' | 'to' | 'user' | 'role' | 'module' | 'type' | 'action' | 'status' | 'record' | 'q', string | null>;

const props = defineProps<{
  audits: { data: AuditRow[]; total: number; from: number | null; to: number | null; prev_page_url: string | null; next_page_url: string | null; links: Array<{ url: string | null; label: string; active: boolean }> };
  filters: Filters;
  options: { types: Option[]; modules: string[]; actions: string[]; roles: Option[]; statuses: Option[]; users: Array<{ id: number; name: string }> };
  summary: { today: number; failed: number; security: number; errors: number };
}>();

const form = reactive<Record<keyof Filters, string>>(Object.fromEntries(Object.entries(props.filters).map(([k, v]) => [k, v ?? ''])) as Record<keyof Filters, string>);
const advancedKeys: Array<keyof Filters> = ['from', 'to', 'user', 'role', 'module', 'type', 'action', 'status', 'record'];
const activeCount = computed(() => advancedKeys.filter(key => props.filters[key]).length);
const panelOpen = ref(activeCount.value > 0);
const expanded = ref<number | null>(null);

const params = () => Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
const apply = () => router.get(route('admin.audits'), params(), { preserveState: true, preserveScroll: true, replace: true });
const clearAll = () => { (Object.keys(form) as Array<keyof Filters>).forEach(key => { form[key] = ''; }); apply(); };
const quickType = (type: string) => { form.type = form.type === type ? '' : type; apply(); };
const exportUrl = (format: 'xlsx' | 'csv') => route('admin.audits.export', { ...params(), format });

const tone = (row: AuditRow) => {
  if (row.type_key === 'security' || row.type_key === 'system_error') return 'bg-rose-50 text-rose-700 ring-rose-200';
  if (row.status_key !== 'success') return 'bg-amber-50 text-amber-800 ring-amber-200';
  if (row.type_key.startsWith('data_deletion')) return 'bg-orange-50 text-orange-800 ring-orange-200';
  return 'bg-slate-50 text-slate-700 ring-slate-200';
};
const statusTone = (key: string) => key === 'success' ? 'text-emerald-700' : key === 'denied' ? 'text-amber-800' : 'text-rose-700';
const cards = computed(() => [
  { label: 'Entries today', value: props.summary.today, type: '' },
  { label: 'Failed or denied (7 days)', value: props.summary.failed, type: '' },
  { label: 'Security alerts (7 days)', value: props.summary.security, type: 'security' },
  { label: 'System errors (7 days)', value: props.summary.errors, type: 'system_error' },
]);
</script>

<template>
  <Head title="System audit logs" />
  <AuthenticatedLayout>
    <section class="w-full max-w-none space-y-4">
      <SectionNav section="admin" />
      <header class="ui-panel flex flex-wrap items-start justify-between gap-4 p-5 sm:p-7">
        <div class="min-w-0">
          <p class="text-sm font-bold text-brand-700">Administration</p>
          <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">System audit logs</h1>
          <p class="mt-2 flex max-w-3xl items-start gap-1.5 text-sm leading-6 text-slate-600">
            <Lock class="mt-1 h-4 w-4 shrink-0 text-slate-500" aria-hidden="true" />
            Read-only record of what happened in the system. No one, including administrators, can edit or delete an entry. Passwords, codes and case narratives are never recorded.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a :href="exportUrl('xlsx')" class="ui-button min-h-11 px-3"><Download class="h-4 w-4" aria-hidden="true" />Export Excel</a>
          <a :href="exportUrl('csv')" class="ui-button min-h-11 px-3"><Download class="h-4 w-4" aria-hidden="true" />CSV</a>
        </div>
      </header>

      <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <component :is="card.type ? 'button' : 'div'" v-for="card in cards" :key="card.label" :type="card.type ? 'button' : undefined"
          class="rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200" :class="card.type ? 'transition hover:ring-brand-300' : ''"
          :aria-pressed="card.type ? form.type === card.type : undefined" @click="card.type && quickType(card.type)">
          <p class="text-2xl font-extrabold text-slate-900">{{ card.value }}</p>
          <p class="mt-0.5 text-xs font-semibold text-slate-600">{{ card.label }}</p>
        </component>
      </div>

      <form class="ui-panel space-y-3 p-4" role="search" data-skip-generic-confirm="true" @submit.prevent="apply">
        <div class="flex flex-wrap gap-2">
          <label class="relative min-w-0 flex-1">
            <span class="sr-only">Search the audit logs</span>
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
            <input v-model="form.q" type="search" maxlength="120" placeholder="Search description, user, record, IP or AUD-000125" class="min-h-11 w-full rounded-xl border-slate-300 pl-9 text-sm" />
          </label>
          <button type="submit" class="ui-button-primary min-h-11 px-4">Search</button>
          <button type="button" class="ui-button min-h-11 px-3" :aria-expanded="panelOpen" aria-controls="audit-filters" @click="panelOpen = !panelOpen">
            <SlidersHorizontal class="h-4 w-4" aria-hidden="true" />Filters<span v-if="activeCount" class="rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ activeCount }}</span>
          </button>
        </div>
        <div v-show="panelOpen" id="audit-filters" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="text-xs font-bold text-slate-700">From<input v-model="form.from" type="date" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal" /></label>
          <label class="text-xs font-bold text-slate-700">To<input v-model="form.to" type="date" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal" /></label>
          <label class="text-xs font-bold text-slate-700">User
            <select v-model="form.user" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All users</option><option v-for="u in options.users" :key="u.id" :value="String(u.id)">{{ u.name }}</option></select>
          </label>
          <label class="text-xs font-bold text-slate-700">Role
            <select v-model="form.role" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All roles</option><option v-for="r in options.roles" :key="r.value" :value="r.value">{{ r.label }}</option></select>
          </label>
          <label class="text-xs font-bold text-slate-700">Log type
            <select v-model="form.type" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All types</option><option v-for="t in options.types" :key="t.value" :value="t.value">{{ t.label }}</option></select>
          </label>
          <label class="text-xs font-bold text-slate-700">Module
            <select v-model="form.module" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All modules</option><option v-for="m in options.modules" :key="m" :value="m">{{ m }}</option></select>
          </label>
          <label class="text-xs font-bold text-slate-700">Action
            <select v-model="form.action" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All actions</option><option v-for="a in options.actions" :key="a" :value="a">{{ a }}</option></select>
          </label>
          <label class="text-xs font-bold text-slate-700">Status
            <select v-model="form.status" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal"><option value="">All statuses</option><option v-for="s in options.statuses" :key="s.value" :value="s.value">{{ s.label }}</option></select>
          </label>
          <label class="text-xs font-bold text-slate-700 sm:col-span-2">Record ID<input v-model="form.record" maxlength="80" placeholder="Case number or AUD-000125" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-normal" /></label>
          <div class="flex items-end gap-2 sm:col-span-2">
            <button type="submit" class="ui-button-primary min-h-11 px-4">Apply filters</button>
            <button type="button" class="ui-button min-h-11 px-3" @click="clearAll"><X class="h-4 w-4" aria-hidden="true" />Clear</button>
          </div>
        </div>
      </form>

      <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold text-slate-600" aria-live="polite">{{ audits.total }} {{ audits.total === 1 ? 'entry' : 'entries' }}</p>

        <table class="hidden w-full text-left text-sm lg:table">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-600">
              <th scope="col" class="py-3 pl-4 pr-2">Log ID / time</th>
              <th scope="col" class="px-2 py-3">User</th>
              <th scope="col" class="px-2 py-3">Type / module</th>
              <th scope="col" class="px-2 py-3">Action</th>
              <th scope="col" class="px-2 py-3">Record</th>
              <th scope="col" class="px-2 py-3">Description</th>
              <th scope="col" class="px-2 py-3">Change</th>
              <th scope="col" class="py-3 pl-2 pr-4">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in audits.data" :key="row.id" class="align-top hover:bg-brand-50/40">
              <td class="py-3 pl-4 pr-2"><span class="font-mono text-xs font-bold text-slate-800">{{ row.log_id }}</span><span class="block text-xs text-slate-600">{{ row.timestamp }}</span></td>
              <td class="px-2 py-3"><span class="font-semibold text-slate-800">{{ row.user }}</span><span class="block text-xs text-slate-600">{{ row.role }}</span><span v-if="row.ip_address" class="block font-mono text-[11px] text-slate-500">{{ row.ip_address }}</span></td>
              <td class="px-2 py-3"><span class="inline-block rounded-md px-2 py-0.5 text-[11px] font-bold ring-1" :class="tone(row)">{{ row.type }}</span><span class="mt-1 block text-xs text-slate-600">{{ row.module }}</span></td>
              <td class="px-2 py-3 font-mono text-xs font-bold text-slate-700">{{ row.action }}</td>
              <td class="px-2 py-3 font-mono text-xs text-slate-700">{{ row.record }}</td>
              <td class="px-2 py-3 text-slate-800">{{ row.description }}</td>
              <td class="px-2 py-3 text-xs text-slate-700"><template v-if="row.old_value || row.new_value"><span class="text-slate-500">{{ row.old_value || '-' }}</span> → <span class="font-semibold">{{ row.new_value || '-' }}</span></template><span v-else class="text-slate-400">-</span></td>
              <td class="py-3 pl-2 pr-4 text-xs font-bold" :class="statusTone(row.status_key)">{{ row.status }}</td>
            </tr>
          </tbody>
        </table>

        <ul class="divide-y divide-slate-100 lg:hidden">
          <li v-for="row in audits.data" :key="row.id">
            <button type="button" class="w-full px-4 py-3 text-left" :aria-expanded="expanded === row.id" @click="expanded = expanded === row.id ? null : row.id">
              <div class="flex items-start justify-between gap-2">
                <p class="min-w-0 font-semibold text-slate-900">{{ row.description }}</p>
                <span class="shrink-0 text-xs font-bold" :class="statusTone(row.status_key)">{{ row.status }}</span>
              </div>
              <p class="mt-0.5 text-xs text-slate-600">{{ row.user }} · {{ row.timestamp }}</p>
              <div class="mt-1.5 flex flex-wrap gap-1.5"><span class="rounded-md px-2 py-0.5 text-[10px] font-bold ring-1" :class="tone(row)">{{ row.type }}</span><span class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[10px] font-bold text-slate-700">{{ row.action }}</span></div>
            </button>
            <dl v-if="expanded === row.id" class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1 bg-slate-50 px-4 py-3 text-xs">
              <dt class="font-bold text-slate-600">Log ID</dt><dd class="font-mono">{{ row.log_id }}</dd>
              <dt class="font-bold text-slate-600">Role</dt><dd>{{ row.role }}</dd>
              <dt class="font-bold text-slate-600">Module</dt><dd>{{ row.module }}</dd>
              <dt class="font-bold text-slate-600">Record ID</dt><dd class="break-all font-mono">{{ row.record }}</dd>
              <template v-if="row.old_value || row.new_value"><dt class="font-bold text-slate-600">Old / new</dt><dd>{{ row.old_value || '-' }} → {{ row.new_value || '-' }}</dd></template>
              <dt class="font-bold text-slate-600">IP address</dt><dd class="font-mono">{{ row.ip_address || '-' }}</dd>
            </dl>
          </li>
        </ul>

        <p v-if="!audits.data.length" class="py-14 text-center text-sm text-slate-600">No audit entries match these filters.</p>
        <Pagination :previous="audits.prev_page_url" :next="audits.next_page_url" :from="audits.from" :to="audits.to" :total="audits.total" :links="audits.links" />
      </div>
    </section>
  </AuthenticatedLayout>
</template>
