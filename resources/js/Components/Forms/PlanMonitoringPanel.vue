<script setup lang="ts">
/**
 * Monitoring of the Intervention Plan (read only): the status of each plan
 * item against its target dates, the referrals conducted from it, the 7-day
 * submission deadline and the latest updates recorded in the progress notes.
 * Changes are made in Kalinga forms > Intervention Plan and Referrals.
 */
import { AlarmClock, CalendarRange, CheckCircle2, CircleDashed, Circle, PenLine } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { PLAN_ROW_STATUSES, planRowHasText, type CaseForm, type PlanDeadline, type PlanRow } from '@/Casework/caseForms';
import { referralTone, titleCase } from '@/utils/caseDisplay';
import { calendarDateLabel, formatPh, manilaDateTime } from '@/utils/philippineTime';

type Referral = { id: number; status: string; referred_to?: string | null; purpose?: string | null; service_category?: string | null; plan_row_key?: string | null };
type Note = { id: number; note_type?: string | null; body: string; occurred_at?: string | null; created_at?: string | null; record_type?: string | null };
const props = defineProps<{ plan: CaseForm; referrals: Referral[]; deadline: PlanDeadline | null; notes: Note[] }>();
const emit = defineEmits<{ openPlan: []; openNotes: [] }>();

const today = manilaDateTime().slice(0, 10);
const rows = computed(() => (props.plan.content.rows ?? []).filter(planRowHasText));
type Timing = { label: string; tone: 'success' | 'danger' | 'warning' | 'info' | 'neutral' };
function timing(row: PlanRow): Timing {
  if (row.status === 'done') return { label: 'Done', tone: 'success' };
  if (row.target_end && row.target_end < today) return { label: `Overdue since ${calendarDateLabel(row.target_end)}`, tone: 'danger' };
  if (row.target_end) {
    const days = Math.round((Date.parse(row.target_end) - Date.parse(today)) / 86400000);
    if (days <= 7) return { label: days === 0 ? 'Due today' : `Due in ${days} ${days === 1 ? 'day' : 'days'}`, tone: 'warning' };
  }
  if (row.status === 'not_started' && row.target_start && row.target_start < today) return { label: 'Should have started', tone: 'warning' };
  if (!row.target_start && !row.target_end) return { label: 'No target dates', tone: 'neutral' };
  return { label: 'On track', tone: 'info' };
}
const counts = computed(() => ({
  done: rows.value.filter((row) => row.status === 'done').length,
  progress: rows.value.filter((row) => row.status === 'in_progress').length,
  open: rows.value.filter((row) => row.status !== 'done' && row.status !== 'in_progress').length,
  overdue: rows.value.filter((row) => timing(row).tone === 'danger').length,
}));
const percent = computed(() => (rows.value.length ? Math.round((counts.value.done / rows.value.length) * 100) : 0));
const rowReferrals = (row: PlanRow) => props.referrals.filter((item) => item.plan_row_key && item.plan_row_key === row.id);
const icon = (status: string) => (status === 'done' ? CheckCircle2 : status === 'in_progress' ? CircleDashed : Circle);
const updates = computed(() => props.notes.filter((note) => note.record_type !== 'addendum').slice(0, 5));
const deadlineText = computed(() => {
  const deadline = props.deadline;
  if (!deadline) return null;
  if (deadline.submitted_at) return { tone: 'text-emerald-800', text: `Plan submitted ${calendarDateLabel(deadline.submitted_at)}, ${deadline.days_to_submit} ${deadline.days_to_submit === 1 ? 'day' : 'days'} after intake` };
  if (deadline.overdue) return { tone: 'text-rose-700', text: `Plan not yet submitted - it was due ${calendarDateLabel(deadline.due)}` };
  return { tone: 'text-amber-800', text: `Plan due ${calendarDateLabel(deadline.due)} (7 days after intake)` };
});
</script>

<template>
  <section aria-labelledby="plan-monitoring-title" class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 id="plan-monitoring-title" class="ui-section-title text-lg">Monitoring</h2>
        <p class="ui-muted mt-1 max-w-3xl">Status of the Intervention Plan items against their target dates, and the latest updates. To change the plan, open Intervention Plan and Referrals.</p>
      </div>
      <button type="button" class="ui-button min-h-11" @click="emit('openPlan')"><PenLine class="h-4 w-4" aria-hidden="true" />Open the plan</button>
    </header>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="ui-panel p-4"><p class="text-xs font-semibold text-slate-600">Plan items done</p><p class="mt-1 text-2xl font-bold text-slate-900">{{ counts.done }} / {{ rows.length }}</p>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" aria-label="Plan items done"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${percent}%` }"></div></div></div>
      <div class="ui-panel p-4"><p class="text-xs font-semibold text-slate-600">In progress</p><p class="mt-1 text-2xl font-bold text-sky-800">{{ counts.progress }}</p></div>
      <div class="ui-panel p-4"><p class="text-xs font-semibold text-slate-600">Not started</p><p class="mt-1 text-2xl font-bold text-slate-800">{{ counts.open }}</p></div>
      <div class="ui-panel p-4"><p class="text-xs font-semibold text-slate-600">Past the target end</p><p class="mt-1 text-2xl font-bold" :class="counts.overdue ? 'text-rose-700' : 'text-slate-800'">{{ counts.overdue }}</p></div>
    </div>
    <p v-if="deadlineText" class="flex items-center gap-1.5 text-sm font-semibold" :class="deadlineText.tone"><AlarmClock class="h-4 w-4" aria-hidden="true" />{{ deadlineText.text }}</p>

    <section class="ui-panel overflow-hidden p-0" aria-labelledby="plan-monitoring-items">
      <header class="ui-panel-header"><h3 id="plan-monitoring-items" class="ui-section-title">Plan items</h3><span class="text-xs text-slate-600">{{ plan.status === 'final' ? `Finalized version ${plan.versions[0]?.version ?? ''}` : plan.status === 'draft' ? 'Draft' : 'Not started' }}</span></header>
      <ol v-if="rows.length" class="divide-y divide-[var(--color-line)]">
        <li v-for="(row, index) in rows" :key="row.id || index" class="grid gap-3 p-4 md:grid-cols-[minmax(0,1fr)_14rem]" :data-monitor-row="index + 1">
          <div class="flex min-w-0 items-start gap-3">
            <component :is="icon(row.status)" class="mt-0.5 h-5 w-5 shrink-0" :class="row.status === 'done' ? 'text-emerald-600' : row.status === 'in_progress' ? 'text-sky-600' : 'text-slate-400'" aria-hidden="true" />
            <div class="min-w-0">
              <p class="font-semibold text-slate-900">{{ index + 1 }}. {{ row.problem || 'Plan item' }}</p>
              <p v-if="row.objective" class="mt-0.5 text-sm text-slate-700"><b>Objective:</b> {{ row.objective }}</p>
              <p v-if="row.activities" class="mt-0.5 text-sm text-slate-700"><b>Activities:</b> {{ row.activities }}</p>
              <p v-if="row.responsible" class="mt-0.5 text-sm text-slate-600">Responsible: {{ row.responsible }}</p>
              <ul v-if="rowReferrals(row).length" class="mt-2 flex flex-wrap gap-2"><li v-for="item in rowReferrals(row)" :key="item.id" class="flex items-center gap-1.5 rounded-lg bg-slate-50 px-2 py-1 text-xs"><StatusBadge :label="titleCase(item.status)" :tone="referralTone(item.status)" dot />{{ item.referred_to }}</li></ul>
            </div>
          </div>
          <div class="space-y-1.5 text-sm md:text-right">
            <StatusBadge :label="PLAN_ROW_STATUSES[row.status as keyof typeof PLAN_ROW_STATUSES] ?? 'Not started'" :tone="row.status === 'done' ? 'success' : row.status === 'in_progress' ? 'info' : 'neutral'" />
            <p class="flex items-center gap-1 text-xs text-slate-600 md:justify-end"><CalendarRange class="h-3.5 w-3.5" aria-hidden="true" />{{ row.target_start ? calendarDateLabel(row.target_start) : '-' }} to {{ row.target_end ? calendarDateLabel(row.target_end) : '-' }}</p>
            <p><StatusBadge :label="timing(row).label" :tone="timing(row).tone" /></p>
            <p v-if="row.timeframe" class="text-xs text-slate-600">Time frame: {{ row.timeframe }}</p>
          </div>
        </li>
      </ol>
      <p v-else class="p-4 text-sm text-slate-600">No Intervention Plan items yet. Write the plan in Kalinga forms.</p>
    </section>

    <section class="ui-panel overflow-hidden p-0" aria-labelledby="plan-monitoring-updates">
      <header class="ui-panel-header"><h3 id="plan-monitoring-updates" class="ui-section-title">Latest updates</h3><button type="button" class="ui-link min-h-9 text-xs" @click="emit('openNotes')">All progress notes</button></header>
      <ol v-if="updates.length" class="divide-y divide-[var(--color-line)]">
        <li v-for="note in updates" :key="note.id" class="p-4 text-sm"><p class="text-xs font-semibold text-slate-600">{{ formatPh(note.occurred_at ?? note.created_at ?? '', 'dateShort') }} - {{ titleCase(note.note_type ?? 'note') }}</p><p class="mt-1 line-clamp-3 whitespace-pre-wrap break-words text-slate-800">{{ note.body }}</p></li>
      </ol>
      <p v-else class="p-4 text-sm text-slate-600">No progress notes yet.</p>
    </section>
  </section>
</template>
