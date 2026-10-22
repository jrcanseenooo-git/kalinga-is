<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { RefreshCw } from '@lucide/vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { priorityTone, progressTone, shortDate, titleCase } from '@/utils/caseDisplay';
import type { PlanGoal, PlanIntervention } from '@/types/planning';

const props = defineProps<{
  caseId: number;
  summary: { version: number; status: string; review_due_at?: string | null; goal_count: number; open_intervention_count: number } | null;
  goals: PlanGoal[];
  interventions: PlanIntervention[];
  planStatus: 'idle' | 'loading' | 'ready' | 'denied' | 'failed';
}>();
const emit = defineEmits<{ reloadPlan: [] }>();
const reviewOverdue = computed(() => Boolean(props.summary?.review_due_at && new Date(props.summary.review_due_at) < new Date()));
const serviceCount = (goal: PlanGoal) => props.interventions.filter(item => item.case_plan_goal_id === goal.id).length;
</script>

<template>
  <div class="space-y-5">
    <section class="ui-panel overflow-hidden p-0" aria-labelledby="case-plan-title">
      <header class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
        <div>
          <h2 id="case-plan-title" class="ui-section-title">Case plan</h2>
          <p class="ui-muted mt-0.5">Goals, interventions and plan reviews for this case.</p>
        </div>
        <Link :href="route('cases.plans.workspace', caseId)" class="ui-button-primary">{{ summary ? 'Open case plan' : 'Create case plan' }}</Link>
      </header>

      <dl v-if="summary" class="grid grid-cols-2 gap-px bg-[var(--color-line)] lg:grid-cols-4">
        <div class="bg-white px-5 py-3"><dt class="text-xs text-slate-500">Plan</dt><dd class="mt-1 flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-800">Version {{ summary.version }} <StatusBadge :label="titleCase(summary.status)" :tone="progressTone(summary.status)" /></dd></div>
        <div class="bg-white px-5 py-3"><dt class="text-xs text-slate-500">Goals</dt><dd class="mt-1 text-xl font-bold text-brand-900">{{ summary.goal_count }}</dd></div>
        <div class="bg-white px-5 py-3"><dt class="text-xs text-slate-500">Open interventions</dt><dd class="mt-1 text-xl font-bold text-brand-900">{{ summary.open_intervention_count }}</dd></div>
        <div class="bg-white px-5 py-3"><dt class="text-xs text-slate-500">Next plan review</dt><dd class="mt-1 text-sm font-semibold" :class="reviewOverdue ? 'text-rose-700' : 'text-slate-800'">{{ summary.review_due_at ? shortDate(summary.review_due_at) : 'Not set' }}<span v-if="reviewOverdue"> - overdue</span></dd></div>
      </dl>
      <p v-else class="m-5 rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-600">No case plan yet. Create one after the assessment is finalized.</p>
    </section>

    <section v-if="summary" class="ui-panel overflow-hidden p-0" aria-labelledby="case-goals-title">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
        <h3 id="case-goals-title" class="ui-section-title">Case plan goals and objectives</h3>
        <button v-if="planStatus === 'failed'" type="button" class="ui-button min-h-10 gap-1.5 text-sm" @click="emit('reloadPlan')"><RefreshCw class="h-4 w-4" aria-hidden="true" />Retry</button>
      </header>
      <p v-if="planStatus === 'loading' || planStatus === 'idle'" role="status" class="px-5 py-6 text-sm text-slate-600">Loading goals...</p>
      <p v-else-if="planStatus === 'denied'" class="px-5 py-6 text-sm text-slate-600">Goal details are available in the case plan workspace for authorized staff.</p>
      <p v-else-if="planStatus === 'failed'" role="alert" class="px-5 py-6 text-sm text-rose-800">Goals could not be loaded. Check your connection and retry.</p>
      <template v-else>
        <table v-if="goals.length" class="ui-table ui-table-stack">
          <thead><tr><th scope="col">No.</th><th scope="col">Domain</th><th scope="col">Goal / objective</th><th scope="col">Target date</th><th scope="col">Priority</th><th scope="col">Services</th><th scope="col">Status</th></tr></thead>
          <tbody>
            <tr v-for="(goal, index) in goals" :key="goal.id">
              <td data-label="No." class="font-semibold text-brand-800">G{{ index + 1 }}</td>
              <td data-label="Domain" class="whitespace-nowrap">{{ titleCase(goal.domain) }}</td>
              <td data-label="Goal" class="max-w-md"><span class="line-clamp-2 break-words font-medium text-slate-800" :title="goal.goal_statement">{{ goal.goal_statement }}</span></td>
              <td data-label="Target date" class="whitespace-nowrap">{{ shortDate(goal.target_date) || '-' }}</td>
              <td data-label="Priority"><StatusBadge :label="titleCase(goal.priority)" :tone="priorityTone(goal.priority)" /></td>
              <td data-label="Services">{{ serviceCount(goal) }}</td>
              <td data-label="Status"><StatusBadge :label="titleCase(goal.status)" :tone="progressTone(goal.status)" dot /></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!goals.length" class="px-5 py-6 text-sm text-slate-600">No goals recorded in the current plan yet. Add goals in the case plan.</p>
      </template>
    </section>
  </div>
</template>
