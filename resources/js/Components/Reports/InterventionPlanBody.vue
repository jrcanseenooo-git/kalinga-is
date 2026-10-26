<script setup lang="ts">
import { computed } from 'vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { PlanGoal, ReportProps } from '@/types/reports';
import { DOMAIN_LABELS, human, longDate, shortDate } from '@/utils/reportFormat';

/**
 * Intervention Plan (intervention plan and contract): the current plan, or the
 * latest version when none is approved yet, with each goal's interventions,
 * responsible persons and timeframe. Goals in health domains print the area
 * only. The conforme lines are signed by hand.
 */
const props = defineProps<{ report: ReportProps; reveal: boolean; clientName: string }>();
const r = computed(() => props.report);
const plan = computed(() => r.value.plan as null | { version: number; date: string | null; status: string; current: boolean; objective: string | null; summary: string | null; review_due_at: string | null; author: string | null; author_position: string | null; goals: PlanGoal[]; review: { review_date: string; overall_progress: string | null; recommendation: string | null; next_review_at: string | null } | null });
const name = computed(() => props.clientName);
const statusLabel = computed(() => !plan.value ? '' : plan.value.current ? `${human(plan.value.status)} (current plan)` : `${human(plan.value.status)} - not yet approved`);
const timeframe = (start: string | null, end: string | null) => start && end ? `${shortDate(start)} - ${shortDate(end)}` : end ? `By ${shortDate(end)}` : start ? `From ${shortDate(start)}` : '-';
const preparer = computed(() => plan.value?.author ? { name: plan.value.author, position: plan.value.author_position } : r.value.preparedBy);
</script>

<template>
  <div class="rpt-grid2">
    <ReportSection :num="1" title="Case information">
      <table class="rpt-kv"><tbody>
        <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
        <tr><th>Client</th><td>{{ name }}</td></tr>
        <tr><th>Case status</th><td>{{ human(r.caseInfo.status) }}</td></tr>
        <tr><th>Current risk level</th><td>{{ human(r.caseInfo.current_risk_level || r.caseInfo.initial_risk_level) }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="2" title="Plan information">
      <table v-if="plan" class="rpt-kv"><tbody>
        <tr><th>Plan version</th><td>Version {{ plan.version }}</td></tr>
        <tr><th>Date of plan</th><td>{{ longDate(plan.date) }}</td></tr>
        <tr><th>Status</th><td>{{ statusLabel }}</td></tr>
        <tr><th>Review due</th><td>{{ longDate(plan.review_due_at) }}</td></tr>
      </tbody></table>
      <p v-else class="rpt-empty">No intervention plan has been prepared for this case.</p>
    </ReportSection>
  </div>

  <template v-if="plan">
    <ReportSection :num="3" title="Overall objective"><p class="rpt-text" contenteditable="true">{{ plan.objective || plan.summary || 'Not recorded' }}</p></ReportSection>

    <ReportSection :num="4" title="Goals and interventions">
      <div class="rpt-wrap" tabindex="0" role="region" aria-label="Goals and interventions table">
        <table class="rpt-table">
          <thead><tr><th>No.</th><th>Area</th><th>Need / problem</th><th>Goal</th><th>Interventions / activities</th><th>Responsible</th><th>Timeframe</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="(goal, index) in plan.goals" :key="index">
              <td>{{ index + 1 }}</td>
              <td>{{ DOMAIN_LABELS[goal.domain] ?? human(goal.domain) }}</td>
              <template v-if="goal.sensitive"><td colspan="4" class="rpt-withheld">Health information. Details are kept in the case record and are not printed.</td></template>
              <template v-else>
                <td><span class="rpt-text" contenteditable="true">{{ goal.need_problem }}</span></td>
                <td><span class="rpt-text" contenteditable="true">{{ goal.goal_statement }}</span></td>
                <td><ul v-if="goal.interventions.length" class="rpt-list"><li v-for="(item, i) in goal.interventions" :key="i">{{ item.activity || item.service_category }}</li></ul><span v-else class="rpt-muted">To be identified</span></td>
                <td>{{ [...new Set(goal.interventions.map(item => item.responsible).filter(Boolean))].join(', ') || '-' }}</td>
              </template>
              <td>{{ timeframe(goal.interventions[0]?.start_date ?? null, goal.target_date) }}</td>
              <td>{{ human(goal.status) }}</td>
            </tr>
            <tr v-if="!plan.goals.length"><td colspan="8" class="rpt-empty">No goals have been recorded in this plan version.</td></tr>
          </tbody>
        </table>
      </div>
    </ReportSection>

    <ReportSection v-if="plan.review" :num="5" title="Latest plan review">
      <table class="rpt-kv"><tbody>
        <tr><th>Date of review</th><td>{{ longDate(plan.review.review_date) }}</td></tr>
        <tr><th>Overall progress</th><td>{{ human(plan.review.overall_progress) }}</td></tr>
        <tr><th>Recommendation</th><td><span class="rpt-text" contenteditable="true">{{ plan.review.recommendation || 'Not recorded' }}</span></td></tr>
        <tr><th>Next review</th><td>{{ longDate(plan.review.next_review_at) }}</td></tr>
      </tbody></table>
    </ReportSection>
  </template>

  <ReportSection :num="plan?.review ? 6 : plan ? 5 : 3" title="Conforme and signatures">
    <p class="rpt-small">We have discussed this intervention plan together and agree to carry out our respective tasks.</p>
    <div class="mt-2"><ReportSignatures :prepared-by="preparer" :noted-by="r.notedBy" :office-short="r.office.short" :extra="[{ label: 'Conforme:', role: 'Child (signature over printed name)' }, { label: 'Conforme:', role: 'Parent / guardian (signature over printed name)' }]" /></div>
  </ReportSection>
</template>
