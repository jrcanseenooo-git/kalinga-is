<script setup lang="ts">
/**
 * Dashboard = to-do list (owner instruction). Only what needs doing, most
 * urgent first; figures and charts are in Analytics.
 */
import { formatPh, manilaHour } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CaseActionQueue from '@/Components/CaseActionQueue.vue';
import KalingaSuggests from '@/Components/Dashboard/KalingaSuggests.vue';
import Panel from '@/Components/UI/Panel.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { BarChart3, CalendarDays, CheckCircle2, ChevronRight, CircleAlert, Info, ListTodo, MapPin } from '@lucide/vue';
import type { CaseActionRow } from '@/types/casework';
import type { PageProps } from '@/types';

type Todo = { tone: 'urgent' | 'action' | 'info'; title: string; text: string; href: string; when?: string | null };
const props = defineProps<{
  asOf: string;
  todos: Array<{ key: string; title: string; items: Todo[] }>;
  suggestions?: Array<{ kind: string; tone: 'urgent' | 'action' | 'info'; title: string; text: string; case_id: number; case_number: string; section: string }>;
  actionQueue?: CaseActionRow[];
  upcomingActivities?: Array<{ id: number; case_id: number | null; case_number: string | null; title: string; due_at: string }>;
  attention: { dueSoon?: number; overdueActions?: number };
  metrics: { activeCases: number | null };
  scopeLabel: string;
  roleLabel: string;
}>();
const page = usePage<PageProps>();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const greeting = computed(() => { const hour = manilaHour(); return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'; });
const todayLong = formatPh(new Date(), 'weekdayLong');
const showAllUpcoming = ref(false);
const visibleUpcoming = computed(() => showAllUpcoming.value ? (props.upcomingActivities ?? []) : (props.upcomingActivities ?? []).slice(0, 3));
const toneIcon = { urgent: CircleAlert, action: ListTodo, info: Info };
const toneClass = { urgent: 'border-rose-200 bg-rose-50 text-rose-900', action: 'border-brand-100 bg-white text-slate-900', info: 'border-sky-100 bg-sky-50/60 text-slate-900' };
const todoCount = computed(() => props.todos.reduce((sum, group) => sum + group.items.length, 0) + (props.suggestions?.length ?? 0) + (props.actionQueue?.length ?? 0));
const isExternal = (href: string) => /^https?:/.test(href) && !href.startsWith(window.location.origin);
</script>

<template>
  <Head title="Dashboard" />
  <AuthenticatedLayout>
    <div id="worker-dashboard" class="mx-auto max-w-[1280px] space-y-5">
      <header class="flex flex-col gap-4 min-[1024px]:flex-row min-[1024px]:items-center min-[1024px]:justify-between">
        <div class="min-w-0">
          <h1 class="text-xs font-semibold uppercase tracking-wider text-brand-700">Dashboard - your to-do list</h1>
          <p class="mt-1 text-2xl font-bold tracking-tight text-[var(--color-ink)] sm:text-[30px]">{{ greeting }}, {{ page.props.auth.user.name.split(' ')[0] }}!</p>
          <p class="mt-1 text-sm text-slate-600">{{ todoCount ? `${todoCount} ${todoCount === 1 ? 'thing needs' : 'things need'} your attention.` : 'Nothing needs your attention right now.' }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
          <div class="flex min-h-14 items-center gap-3 rounded-xl border border-[var(--color-line)] bg-white px-4 py-2 shadow-[var(--shadow-card)]"><CalendarDays aria-hidden="true" class="h-5 w-5 text-brand-700" /><div><p class="text-[11px] text-slate-600">Today</p><p class="text-sm font-semibold text-slate-900">{{ todayLong }}</p></div></div>
          <div class="flex min-h-14 items-center gap-3 rounded-xl border border-[var(--color-line)] bg-white px-4 py-2 shadow-[var(--shadow-card)]"><MapPin aria-hidden="true" class="h-5 w-5 text-brand-700" /><div><p class="text-[11px] text-slate-600">{{ roleLabel }}</p><p class="text-sm font-semibold text-slate-900">{{ scopeLabel || 'Authorized workspace' }}</p></div></div>
        </div>
      </header>

      <div class="grid items-start gap-5 min-[900px]:grid-cols-[minmax(0,1.6fr)_minmax(260px,1fr)]">
        <div class="min-w-0 space-y-5">
          <KalingaSuggests v-if="suggestions?.length" :items="suggestions" />
          <CaseActionQueue v-if="can('cases.update')" :rows="actionQueue ?? []" :as-of="asOf" :compact="Boolean(suggestions?.length)" />
          <section v-for="group in todos" :key="group.key" class="ui-panel overflow-hidden p-0" :aria-labelledby="`todo-${group.key}`">
            <header class="flex items-center justify-between gap-2 border-b border-[var(--color-line)] px-4 py-3"><h2 :id="`todo-${group.key}`" class="ui-section-title">{{ group.title }}</h2><span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-800">{{ group.items.length }}</span></header>
            <ul class="space-y-2 p-3">
              <li v-for="(item, index) in group.items" :key="index">
                <component :is="isExternal(item.href) ? 'a' : Link" :href="item.href" class="flex min-h-14 items-start gap-3 rounded-xl border p-3 transition hover:shadow-md" :class="toneClass[item.tone]">
                  <component :is="toneIcon[item.tone]" class="mt-0.5 h-5 w-5 shrink-0" :class="item.tone === 'urgent' ? 'text-rose-700' : 'text-brand-700'" aria-hidden="true" />
                  <span class="min-w-0 flex-1"><span class="block text-sm font-semibold">{{ item.title }}</span><span class="block text-xs text-slate-700">{{ item.text }}</span><span v-if="item.when" class="mt-0.5 block text-[11px] text-slate-600">{{ formatPh(item.when, 'dateTimeShort') }}</span></span>
                  <ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                </component>
              </li>
            </ul>
          </section>
          <section v-if="!todoCount" class="ui-panel flex flex-col items-center gap-2 p-8 text-center"><CheckCircle2 class="h-8 w-8 text-emerald-600" aria-hidden="true" /><h2 class="text-base font-semibold text-slate-900">All caught up</h2><p class="max-w-md text-sm text-slate-600">New items appear here when something needs you: a case next step, an invitation, a closure to approve or an account to review.</p></section>
        </div>

        <div class="space-y-5">
          <Panel v-if="can('activities.manage')" id="upcoming-activities-title" title="Coming up" description="Your next dated activities (Philippine time).">
            <template #actions><Link :href="route('calendar.index')" class="ui-link">Calendar</Link></template>
            <ul v-if="upcomingActivities?.length" class="space-y-2"><li v-for="item in visibleUpcoming" :key="item.id"><Link :href="item.case_id ? route('cases.show', item.case_id) : route('calendar.index')" class="group flex min-h-11 items-center gap-3 rounded-lg p-1.5 hover:bg-[#faf9fd]"><time :datetime="item.due_at" class="ui-date-badge"><span class="text-lg font-bold">{{ formatPh(item.due_at, 'day') }}</span><span class="mt-1 text-[10px] font-semibold">{{ formatPh(item.due_at, 'monthShort').toUpperCase() }}</span></time><div class="min-w-0 flex-1"><p class="break-words text-sm font-semibold text-slate-900 group-hover:text-brand-800">{{ item.title }}</p><p class="mt-0.5 text-xs text-slate-600">{{ item.case_number || 'Not linked to a case' }}</p></div><span class="flex shrink-0 items-center gap-1 text-xs text-slate-600"><AppIcon name="clock" class="h-3.5 w-3.5" />{{ formatPh(item.due_at, 'time') }}</span></Link></li></ul>
            <button v-if="(upcomingActivities?.length ?? 0) > 3" type="button" class="ui-link mt-2" :aria-expanded="showAllUpcoming" @click="showAllUpcoming = !showAllUpcoming">{{ showAllUpcoming ? 'Show fewer' : `Show all ${upcomingActivities?.length}` }}</button>
            <p v-if="!upcomingActivities?.length" class="text-sm text-slate-600">Nothing scheduled.</p>
          </Panel>
          <nav class="ui-panel grid gap-2 p-4" aria-label="Shortcuts">
            <Link v-if="can('cases.create')" :href="route('cases.create')" class="ui-button justify-start">New case intake</Link>
            <Link v-if="can('cases.update')" :href="route('cases.index')" class="ui-button justify-start">My cases<span v-if="metrics.activeCases !== null" class="ml-auto rounded-full bg-brand-50 px-2 text-xs text-brand-800">{{ metrics.activeCases }} active</span></Link>
            <Link v-if="can('cases.monitor')" :href="route('monitoring.cases.index')" class="ui-button justify-start">Cases I monitor</Link>
            <Link v-if="can('analytics.view')" :href="route('analytics.index')" class="ui-button justify-start"><BarChart3 class="h-4 w-4" aria-hidden="true" />Analytics and map</Link>
            <Link v-if="can('offline.use')" :href="route('offline.workspace')" class="ui-button justify-start">Field mode (no signal)</Link>
          </nav>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
