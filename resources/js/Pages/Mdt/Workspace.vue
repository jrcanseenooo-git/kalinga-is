<script setup lang="ts">
import { formatPh } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
  assignments: Array<{
    id: number;
    case_id: number;
    case_number: string;
    professional_role: string;
    organization: string;
    purpose: string;
    status: string;
    pending_actions: number;
    access_expires_at: string | null;
  }>;
  lguSummary?: Record<string, number>;
}>();

const statusColor = (s: string) => {
  if (s === 'active') return { bg: 'bg-emerald-50', text: 'text-emerald-700' };
  if (s === 'expired') return { bg: 'bg-slate-100', text: 'text-slate-600' };
  if (s === 'revoked') return { bg: 'bg-rose-50', text: 'text-rose-700' };
  return { bg: 'bg-violet-50', text: 'text-violet-700' };
};

const fmtDate = (d: string | null) => d ? formatPh(d, 'date') : 'Not recorded';
</script>

<template>
  <Head title="MDT collaboration" />
  <AuthenticatedLayout>
    <section class="w-full max-w-5xl">
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-sm font-bold text-brand-700">My assigned work</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-900 sm:text-3xl">My referred cases</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Only case-specific assignments appear here. Access is time-limited and restricted to the information needed for the stated purpose.</p>
      </header>

      <!-- LGU CPU workload summary -->
      <article v-if="lguSummary" class="mt-5 rounded-2xl border border-violet-100 bg-violet-50/40 p-4">
        <p class="text-sm font-extrabold text-violet-900">LGU CPU workload</p>
        <p class="mt-1 text-xs text-slate-600">Aggregate workload only. It does not permit unrelated case browsing.</p>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
          <div v-for="(value, key) in lguSummary" :key="key" class="rounded-xl bg-white p-3 shadow-sm">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">{{ String(key).replaceAll('_', ' ') }}</p>
            <p class="mt-1 text-xl font-extrabold text-violet-800">{{ value }}</p>
          </div>
        </div>
      </article>

      <!-- Assignments -->
      <div v-if="assignments.length" class="mt-5 grid gap-4 sm:grid-cols-2">
        <article v-for="item in assignments" :key="item.id" class="rounded-xl border border-slate-200 bg-white p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500">{{ item.case_number }}</p>
              <h2 class="mt-1 text-sm font-extrabold text-slate-900">{{ item.professional_role }}</h2>
            </div>
            <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-[10px] font-bold capitalize" :class="[statusColor(item.status).bg, statusColor(item.status).text]">
              {{ item.status.replaceAll('_', ' ') }}
            </span>
          </div>
          <div class="mt-3 space-y-1.5">
            <p class="text-sm text-slate-700"><span class="font-bold text-slate-500">Office:</span> {{ item.organization }}</p>
            <p class="text-sm text-slate-600"><span class="font-bold text-slate-500">Purpose:</span> {{ item.purpose }}</p>
            <p class="text-sm font-semibold text-brand-900">Assignment #{{ item.id }} - {{ item.pending_actions }} awaiting response</p>
          </div>
          <p class="mt-3 text-[11px] text-slate-500">Access expires: {{ fmtDate(item.access_expires_at) }}</p>
          <Link :href="route('cases.mdt.workspace', {case: item.case_id, mdt_access_id: item.id})" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
            Open assignment
          </Link>
        </article>
      </div>

      <!-- Empty state -->
      <div v-else class="mt-8 flex flex-col items-center rounded-2xl border border-dashed border-slate-300 py-16">
        <div class="grid h-14 w-14 place-items-center rounded-2xl bg-violet-50">
          <span class="text-xl text-violet-600">🔒</span>
        </div>
        <p class="mt-3 text-sm font-bold text-slate-600">No active MDT assignments</p>
        <p class="mt-1 text-xs text-slate-500">MDT membership alone does not provide access to any case.</p>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
