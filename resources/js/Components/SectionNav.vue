<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ section: 'cases' | 'admin' | 'analytics' }>();

const permissions = computed(() => new Set<string>((usePage().props.auth as any).permissions ?? []));
const can = (p: string) => permissions.value.has(p);
const current = (name: string) => route().current(name);

interface SectionLink {
  label: string;
  route: string;
  visible: boolean;
  secondary?: boolean;
}

const links = computed<SectionLink[]>(() => {

  if (props.section === 'admin') return [
    { label: 'Overview', route: 'admin.overview', visible: can('admin.manage') },
    { label: 'Users', route: 'admin.index', visible: can('admin.manage') },
    { label: 'LGU registry', route: 'admin.lgus.index', visible: can('admin.manage') },
    { label: 'Audit logs', route: 'admin.audits', visible: can('admin.manage') },
    { label: 'Form builder', route: 'admin.forms', visible: can('admin.manage') },
    { label: 'Providers', route: 'providers.index', visible: can('admin.manage') },
  ].filter(l => l.visible);

  if (props.section === 'analytics') return [
    { label: 'Reports', route: 'analytics.index', visible: can('analytics.view') },
    { label: 'Quarterly', route: 'analytics.quarterly', visible: can('analytics.quarterly') },
  ].filter(l => l.visible);

  return [
    { label: 'Case registry', route: 'cases.index', visible: can('cases.view') },
    { label: 'Identity review', route: 'identity.review.index', visible: can('cases.view') },
    { label: 'Intake screening', route: 'reports.index', visible: can('cases.view'), secondary: true },
  ].filter(l => l.visible);
});
</script>

<template>
  <nav v-if="links.length > 1" class="flex flex-wrap gap-1 border-b border-slate-200" aria-label="Section navigation">
    <Link
      v-for="link in links"
      :key="link.route"
      :href="route(link.route)"
      :aria-current="current(link.route) ? 'page' : undefined"
      class="inline-flex min-h-11 shrink-0 items-center border-b-2 px-3.5 py-2 text-sm whitespace-nowrap transition"
      :class="[
        current(link.route) ? 'border-brand-700 text-brand-900 font-semibold' : 'border-transparent hover:text-slate-900',
        link.secondary && !current(link.route) ? 'text-slate-600 font-medium' : !current(link.route) ? 'text-slate-600 font-semibold' : '',
      ]"
    >{{ link.label }}</Link>
  </nav>
</template>
