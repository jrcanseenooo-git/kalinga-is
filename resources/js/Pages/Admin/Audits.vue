<script setup lang="ts">
import { formatPh } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import { Head, Link } from '@inertiajs/vue3';

interface AuditEntry {
  id: number;
  event: string;
  user_name?: string;
  auditable_type: string;
  auditable_id?: number;
  outcome?: string;
  ip_address?: string | null;
  created_at: string;
}

defineProps<{
  audits: {
    data: AuditEntry[];
    current_page: number;
    last_page: number;
    next_page_url?: string | null;
    prev_page_url?: string | null;
    total: number;
  };
}>();

const eventLabel = (event: string) => event.replaceAll('.', ' · ').replaceAll('_', ' ');

const eventIcon = (event: string) => {
  if (event.includes('approved') || event.includes('synced') || event.includes('created')) return { bg: 'bg-emerald-50', color: 'text-emerald-600', dot: 'bg-emerald-500' };
  if (event.includes('declined') || event.includes('revoked') || event.includes('deleted')) return { bg: 'bg-rose-50', color: 'text-rose-500', dot: 'bg-rose-500' };
  if (event.includes('updated') || event.includes('assigned') || event.includes('exported')) return { bg: 'bg-amber-50', color: 'text-amber-600', dot: 'bg-amber-500' };
  return { bg: 'bg-slate-50', color: 'text-slate-500', dot: 'bg-slate-400' };
};

const fmtDate = (d: string) => formatPh(d, 'dateTimeShort');
</script>

<template>
  <Head title="Audit logs" />
  <AuthenticatedLayout>
    <section class="w-full max-w-none space-y-5">
      <SectionNav section="admin" />
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-sm font-bold text-brand-700">Administration</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Audit logs</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Immutable activity record for accountability and review. {{ audits.total }} total entries.</p>
      </header>

      <!-- Desktop table -->
      <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
        <table class="w-full text-left text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
              <th class="py-3 pl-5 pr-2 font-bold">Event</th>
              <th class="px-3 py-3 font-bold">User</th>
              <th class="px-3 py-3 font-bold">Target</th>
              <th class="px-3 py-3 font-bold">Outcome</th>
              <th class="px-3 py-3 text-right font-bold">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            <tr v-for="item in audits.data" :key="item.id" class="transition hover:bg-brand-50/40">
              <td class="py-3 pl-5 pr-2">
                <div class="flex items-center gap-3">
                  <span class="h-2 w-2 shrink-0 rounded-full" :class="eventIcon(item.event).dot" />
                  <span class="text-sm font-semibold text-slate-800">{{ eventLabel(item.event) }}</span>
                </div>
              </td>
              <td class="px-3 py-3 text-sm text-slate-500">{{ item.user_name || 'System' }}<span v-if="item.ip_address" class="block text-[11px] text-slate-500">{{ item.ip_address }}</span></td>
              <td class="px-3 py-3">
                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ item.auditable_type }} #{{ item.auditable_id || '-' }}</span>
              </td>
              <td class="px-3 py-3">
                <span v-if="item.outcome" class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ item.outcome }}</span>
                <span v-else class="text-xs text-slate-500">-</span>
              </td>
              <td class="px-3 py-3 text-right text-xs text-slate-500">{{ fmtDate(item.created_at) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!audits.data.length" class="py-16 text-center text-sm text-slate-500">No audit activity recorded.</p>
      </div>

      <!-- Mobile cards -->
      <div class="space-y-1.5 md:hidden">
        <article v-for="item in audits.data" :key="item.id" class="flex items-start gap-3 rounded-xl bg-white px-3.5 py-3 shadow-sm ring-1 ring-slate-200/60">
          <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="eventIcon(item.event).dot" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-slate-800">{{ eventLabel(item.event) }}</p>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
              <span class="text-[11px] text-slate-500">{{ item.user_name || 'System' }}</span>
              <span class="text-slate-200">·</span>
              <time class="text-[11px] text-slate-500">{{ fmtDate(item.created_at) }}</time>
            </div>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
              <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ item.auditable_type }}</span>
              <span v-if="item.outcome" class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ item.outcome }}</span>
            </div>
          </div>
        </article>
        <p v-if="!audits.data.length" class="py-12 text-center text-sm text-slate-500">No audit activity recorded.</p>
      </div>

      <!-- Pagination -->
      <nav v-if="audits.last_page > 1" class="mt-4 flex items-center justify-between rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200/60" aria-label="Audit log pagination">
        <Link v-if="audits.prev_page_url" :href="audits.prev_page_url" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-brand-300">Previous</Link>
        <span v-else />
        <span class="text-sm text-slate-500">Page {{ audits.current_page }} of {{ audits.last_page }}</span>
        <Link v-if="audits.next_page_url" :href="audits.next_page_url" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-brand-300">Next</Link>
        <span v-else />
      </nav>
    </section>
  </AuthenticatedLayout>
</template>
