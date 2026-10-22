<script setup lang="ts">
import { formatPh } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import SectionNav from '@/Components/SectionNav.vue'
import { Head, Link } from '@inertiajs/vue3'
import { Building2, ClipboardList, FileText, ShieldCheck, UserCheck, Users } from '@lucide/vue'

defineProps<{
  metrics: { activeUsers: number; pendingApprovals: number; activeLgus: number; recentAccessChanges: number }
  recentGovernanceEvents: Array<{ id: number; event: string; outcome?: string; user_name?: string; created_at: string }>
}>()

const cards = [
  { key: 'activeUsers', label: 'Active accounts', icon: Users, detail: 'Accounts currently enabled for the system.' },
  { key: 'pendingApprovals', label: 'Pending approvals', icon: UserCheck, detail: 'Registrations awaiting an administrator decision.' },
  { key: 'activeLgus', label: 'Active LGU references', icon: Building2, detail: 'Active LGUs available for scoped assignment.' },
  { key: 'recentAccessChanges', label: 'Access changes this week', icon: ShieldCheck, detail: 'Governance events, not case activity.' },
] as const

const label = (event: string) => event.replace(/[._]/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
</script>

<template>
  <Head title="Administration" />
  <AuthenticatedLayout>
    <section class="mx-auto max-w-6xl space-y-5">
      <SectionNav section="admin" />
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-sm font-bold text-brand-700">System administration</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Administration overview</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Account, assignment, and governance administration. This workspace does not provide client records or case narratives.</p>
      </header>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article v-for="card in cards" :key="card.key" class="ui-panel p-5">
          <component :is="card.icon" class="h-5 w-5 text-brand-700" aria-hidden="true" />
          <p class="mt-4 text-xs font-bold uppercase tracking-wide text-slate-500">{{ card.label }}</p>
          <p class="mt-1 text-3xl font-extrabold text-slate-900">{{ metrics[card.key] }}</p>
          <p class="mt-2 text-xs leading-5 text-slate-500">{{ card.detail }}</p>
        </article>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Link :href="route('admin.index')" class="group ui-panel p-5 transition hover:border-brand-200 hover:shadow-md">
          <Users class="h-5 w-5 text-brand-700" aria-hidden="true" />
          <p class="mt-4 text-sm font-extrabold text-slate-900 group-hover:text-brand-800">User management</p>
          <p class="mt-1 text-xs leading-5 text-slate-500">Accounts, roles, assignments, and registration approvals.</p>
        </Link>
        <Link :href="route('admin.lgus.index')" class="group ui-panel p-5 transition hover:border-brand-200 hover:shadow-md">
          <Building2 class="h-5 w-5 text-brand-700" aria-hidden="true" />
          <p class="mt-4 text-sm font-extrabold text-slate-900 group-hover:text-brand-800">LGU registry</p>
          <p class="mt-1 text-xs leading-5 text-slate-500">Register and manage LGU references from the PSGC dataset.</p>
        </Link>
        <Link :href="route('admin.forms')" class="group ui-panel p-5 transition hover:border-brand-200 hover:shadow-md">
          <FileText class="h-5 w-5 text-brand-700" aria-hidden="true" />
          <p class="mt-4 text-sm font-extrabold text-slate-900 group-hover:text-brand-800">Form builder</p>
          <p class="mt-1 text-xs leading-5 text-slate-500">Configurable supplemental fields for intake, report, and assessment forms.</p>
        </Link>
        <Link :href="route('management.dashboard')" class="group ui-panel p-5 transition hover:border-brand-200 hover:shadow-md">
          <ClipboardList class="h-5 w-5 text-brand-700" aria-hidden="true" />
          <p class="mt-4 text-sm font-extrabold text-slate-900 group-hover:text-brand-800">Central Office analytics</p>
          <p class="mt-1 text-xs leading-5 text-slate-500">Aggregate management reporting. No client identities or narratives.</p>
        </Link>
      </div>

      <section class="ui-panel p-5">
        <h2 class="font-extrabold text-slate-900">Recent access governance activity</h2>
        <p class="mt-1 text-sm text-slate-500">Metadata only. Use the audit log for the full authorized record.</p>
        <div v-if="recentGovernanceEvents.length" class="mt-4 divide-y divide-slate-100">
          <div v-for="event in recentGovernanceEvents" :key="event.id" class="flex items-center justify-between gap-3 py-3 text-sm"><div class="min-w-0"><p class="font-semibold text-slate-800">{{ label(event.event) }}</p><p class="text-xs text-slate-500">{{ event.user_name || 'System' }}</p></div><time class="shrink-0 text-xs text-slate-500">{{ formatPh(event.created_at, 'dateShort') }}</time></div>
        </div>
        <p v-else class="mt-4 text-sm text-slate-500">No recent access-governance activity.</p>
      </section>
    </section>
  </AuthenticatedLayout>
</template>
