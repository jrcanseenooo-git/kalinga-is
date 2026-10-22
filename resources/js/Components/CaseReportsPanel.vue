<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, CircleDashed, CircleDot, Printer } from '@lucide/vue';
import { computed } from 'vue';
import { human, longDate } from '@/utils/reportFormat';

/**
 * Case reports checklist (adapted from the SIPAG eSCMS household list): each
 * case-management document, whether its content is complete in the record,
 * where to complete it, and the printable report.
 */
const props = defineProps<{
  caseId: number; canPrint: boolean;
  intakeAt: string | null; noteCount: number; lastNoteAt: string | null;
  plan: { version: number; status: string; review_due_at?: string | null; goal_count: number } | null | undefined;
  assessmentStatus: string | null;
}>();
type Section = 'people' | 'monitoring' | 'care' | 'assessment' | 'overview';
const emit = defineEmits<{ open: [section: Section] }>();

type Row = { key: string; label: string; state: 'complete' | 'progress' | 'missing'; detail: string; section: Section; report: string };
const rows = computed<Row[]>(() => [
  { key: 'intake', label: 'Kalinga Intake Sheet', state: props.intakeAt ? 'complete' : 'progress', detail: props.intakeAt ? `Intake completed ${longDate(props.intakeAt)}` : 'Intake details still being completed', section: 'people', report: 'intake' },
  { key: 'notes', label: 'Progress Notes', state: props.noteCount ? 'complete' : 'missing', detail: props.noteCount ? `${props.noteCount} note${props.noteCount === 1 ? '' : 's'}, latest ${longDate(props.lastNoteAt)}` : 'No progress note yet', section: 'monitoring', report: 'notes' },
  { key: 'plan', label: 'Intervention Plan', state: !props.plan ? 'missing' : props.plan.status === 'active' ? 'complete' : 'progress', detail: props.plan ? `Version ${props.plan.version}, ${human(props.plan.status).toLowerCase()}, ${props.plan.goal_count} goal${props.plan.goal_count === 1 ? '' : 's'}` : 'No plan prepared yet', section: 'care', report: 'plan' },
  { key: 'study', label: 'Social Case Study Report', state: props.assessmentStatus === 'final' ? 'complete' : props.assessmentStatus ? 'progress' : 'missing', detail: props.assessmentStatus === 'final' ? 'Assessment finalized; report ready' : props.assessmentStatus ? 'Finalize the comprehensive assessment first' : 'Needs the comprehensive assessment', section: 'assessment', report: 'study' },
  { key: 'summary', label: 'Case Summary Report', state: 'complete', detail: 'Available at any stage of the case', section: 'overview', report: 'summary' },
]);
const done = computed(() => rows.value.filter(row => row.state === 'complete').length);
const icon = { complete: CheckCircle2, progress: CircleDot, missing: CircleDashed };
const stateLabel = { complete: 'Complete', progress: 'In progress', missing: 'Not started' };
</script>

<template>
  <section class="ui-panel overflow-hidden" aria-labelledby="case-reports-title">
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--color-line)] px-4 py-3 sm:px-5">
      <div><h2 id="case-reports-title" class="ui-section-title">Case reports</h2><p class="text-xs text-slate-600">Documents for the case folder, the Central Office and the CPU.</p></div>
      <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-800">{{ done }} of {{ rows.length }} complete</span>
    </header>
    <ul class="divide-y divide-[var(--color-line)]">
      <li v-for="row in rows" :key="row.key" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5">
        <component :is="icon[row.state]" class="h-5 w-5 shrink-0" :class="row.state === 'complete' ? 'text-emerald-600' : row.state === 'progress' ? 'text-amber-600' : 'text-slate-500'" aria-hidden="true" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-slate-900">{{ row.label }} <span class="sr-only">- {{ stateLabel[row.state] }}</span></p>
          <p class="text-xs text-slate-600">{{ row.detail }}</p>
        </div>
        <div class="flex shrink-0 gap-2">
          <button v-if="row.section !== 'overview' && row.state !== 'complete'" type="button" class="ui-button min-h-10 px-3 text-xs" @click="emit('open', row.section)">Complete<span class="sr-only"> {{ row.label }}</span></button>
          <Link v-if="canPrint" :href="route('cases.reports.show', [caseId, row.report])" class="ui-button min-h-10 px-3 text-xs" :aria-label="`Print ${row.label}`"><Printer class="h-3.5 w-3.5" aria-hidden="true" />Print</Link>
        </div>
      </li>
    </ul>
  </section>
</template>
