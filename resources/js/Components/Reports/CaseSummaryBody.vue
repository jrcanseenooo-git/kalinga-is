<script setup lang="ts">
import { computed } from 'vue';
import FamilyTable from '@/Components/Reports/FamilyTable.vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { ReportProps } from '@/types/reports';
import { NOTE_TYPES } from '@/utils/caseOptions';
import { incidenceRows } from '@/data/cefmu';
import { ageOn, classificationText, counted, human, initialsOf, longDate, peso, shortDate } from '@/utils/reportFormat';

/**
 * Case Summary Report (type "summary") and Social Case Study Report (type
 * "study"). Same structure as before; flat styling and formal wording.
 */
const props = defineProps<{ report: ReportProps; reveal: boolean; clientName: string }>();
const r = computed(() => props.report);
const study = computed(() => r.value.type === 'study');
const n = (summaryNo: number, studyNo: number) => study.value ? studyNo : summaryNo;

const client = computed(() => r.value.client);
const clientName = computed(() => props.clientName);
const age = computed(() => ageOn(client.value.birthdate));
const classification = computed(() => classificationText(r.value.caseInfo.classifications ?? []));
const noteLabel = (type: string | null) => NOTE_TYPES.find(item => item.value === type)?.label ?? human(type);

/** Actions taken, oldest first: progress-note actions, referrals and services provided. */
const actions = computed(() => {
  const rows: { at: string; action: string; who: string }[] = [];
  for (const note of r.value.notes ?? []) if (note.at && note.type !== 'referral' && note.type !== 'service_delivery') rows.push({ at: note.at, action: note.action || noteLabel(note.type), who: note.author ?? '' });
  for (const ref of r.value.referrals ?? []) rows.push({ at: ref.referred_at || ref.created_at, action: `Referral for ${(ref.purpose || ref.service_category).toLowerCase()} - ${human(ref.status).toLowerCase()}`, who: ref.referred_to ?? '' });
  for (const svc of r.value.services ?? []) rows.push({ at: svc.date_provided, action: `${svc.service_type}${svc.specific_detail ? `: ${svc.specific_detail}` : ''}${svc.amount ? ` (${peso(svc.amount)})` : ''}`, who: svc.provided_by ?? '' });
  return rows.sort((a, b) => a.at.localeCompare(b.at));
});
const assistanceTotal = computed(() => (r.value.services ?? []).reduce((sum, item) => sum + Number(item.amount || 0), 0));

const background = computed(() => [r.value.caseInfo.cefmu_context, r.value.caseInfo.problem_presented].filter(Boolean).join('\n\n') || 'No background information was recorded at intake.');
const presenting = computed(() => String(r.value.caseInfo.problem_presented || '').split(/\n+/).filter(Boolean));
const findings = computed(() => {
  const lines = (r.value.assessment?.sections ?? []).filter(item => ['professional_assessment', 'case_formulation', 'presenting_situation'].includes(item.key)).map(item => item.text);
  if (!lines.length && r.value.caseInfo.initial_assessment) lines.push(r.value.caseInfo.initial_assessment);
  if (r.value.risk) lines.push(`The safety and risk assessment conducted on ${longDate(r.value.risk.assessed_at)} rated the overall risk as ${human(r.value.risk.overall_risk_level).toLowerCase()}.`);
  return lines.length ? lines : ['No assessment has been finalized as of this report.'];
});
const recommendations = computed(() => {
  const goals: string[] = r.value.plan?.goals?.map((goal: { goal_statement: string }) => goal.goal_statement) ?? [];
  const rec = r.value.assessment?.sections.find(item => item.key === 'recommendations')?.text;
  return [...(rec ? rec.split(/\n+/) : []), ...goals, ...(goals.length || rec ? [] : [r.value.caseInfo.plan_recommendations || 'No intervention plan has been recorded as of this report.'])].filter(Boolean);
});
const statusText = computed(() => {
  const info = r.value.caseInfo;
  const open = (r.value.referrals ?? []).filter(item => ['SENT', 'ACCEPTED', 'IN_PROGRESS'].includes(item.status)).length;
  const stage = info.stage && human(info.stage) !== human(info.status) ? ` at the ${human(info.stage).toLowerCase()} stage` : '';
  const parts = [`As of ${longDate(r.value.generatedAt)}, the case is ${human(info.status).toLowerCase()}${stage}.`];
  parts.push(open ? `There ${open === 1 ? 'is' : 'are'} ${counted(open, 'referral')} awaiting completion.` : 'There are no pending referrals.');
  if (r.value.plan?.review_due_at) parts.push(`The intervention plan is due for review on ${longDate(r.value.plan.review_due_at)}.`);
  return parts.join(' ');
});
const assessmentBlocks = computed(() => (r.value.assessment?.sections ?? []).filter(item => item.key !== 'recommendations'));
</script>

<template>
  <div class="rpt-grid2">
    <ReportSection :num="1" title="Case information">
      <table class="rpt-kv"><tbody>
        <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
        <tr><th>Date of intake</th><td>{{ longDate(r.caseInfo.intake_at) }}</td></tr>
        <tr><th>Case category</th><td>CEFMU{{ classification ? ` - ${classification}` : '' }}</td></tr>
        <tr><th>Marriage or union</th><td>{{ incidenceRows(r.caseInfo)[0][1] }}</td></tr>
        <tr><th>Married or cohabiting</th><td>{{ incidenceRows(r.caseInfo)[1][1] }}</td></tr>
        <tr><th>Case status</th><td>{{ human(r.caseInfo.status) }}<span v-if="r.caseInfo.stage && human(r.caseInfo.stage) !== human(r.caseInfo.status)"> ({{ human(r.caseInfo.stage).toLowerCase() }} stage)</span></td></tr>
        <tr><th>Source of referral</th><td>{{ r.caseInfo.referred_by || human(r.caseInfo.mode_of_admission) }}</td></tr>
        <tr><th>Referral date</th><td>{{ longDate(r.caseInfo.referral_date) }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="2" :title="study ? 'Identifying information' : 'Client information'">
      <table class="rpt-kv"><tbody>
        <tr><th>Name</th><td>{{ clientName }}</td></tr>
        <tr><th>Age / sex</th><td>{{ age !== null ? `${age} years old` : 'Not recorded' }} / {{ client.sex || 'Not recorded' }}</td></tr>
        <tr v-if="study"><th>Date of birth</th><td>{{ longDate(client.birthdate) }}</td></tr>
        <tr><th>Address</th><td>{{ client.address || 'Not recorded' }}</td></tr>
        <tr><th>Civil status</th><td>{{ client.civil_status || (age !== null && age < 18 ? 'Not applicable (minor)' : 'Not recorded') }}</td></tr>
        <tr v-if="study"><th>Religion</th><td>{{ client.religion || 'Not recorded' }}</td></tr>
        <tr v-if="study"><th>Indigenous group</th><td>{{ client.ip_category || 'Not applicable' }}</td></tr>
        <tr><th>Education</th><td>{{ client.education || human(r.caseInfo.education_status) }}</td></tr>
        <tr><th>Parent / guardian</th><td>{{ client.caregiver ? `${reveal ? client.caregiver : initialsOf(client.caregiver)}${client.caregiver_relationship ? ` (${client.caregiver_relationship})` : ''}` : 'Not recorded' }}</td></tr>
      </tbody></table>
    </ReportSection>
  </div>

  <ReportSection v-if="study" :num="3" title="Family composition"><FamilyTable :family="r.family" :reveal="reveal" /></ReportSection>

  <ReportSection :num="n(3, 4)" :title="study ? 'Background of the case' : 'Case background'">
    <div class="rpt-text" contenteditable="true" spellcheck="true">{{ background }}</div>
  </ReportSection>

  <ReportSection :num="n(4, 5)" :title="study ? 'Problem presented' : 'Presenting issue / concern'">
    <ul class="rpt-list" contenteditable="true"><li v-for="(line, index) in (presenting.length ? presenting : ['No problem was recorded at intake.'])" :key="index">{{ line }}</li></ul>
  </ReportSection>

  <ReportSection v-if="study && assessmentBlocks.length" :num="6" title="Assessment">
    <div v-for="block in assessmentBlocks" :key="block.key" class="rpt-sub"><h3>{{ block.label }}</h3><p class="rpt-text" contenteditable="true">{{ block.text }}</p></div>
  </ReportSection>

  <ReportSection :num="n(5, 7)" :title="study ? 'Interventions and services provided' : 'Actions taken'">
    <table class="rpt-table"><thead><tr><th class="rpt-w-date">Date</th><th>Action taken</th><th>Persons / agencies involved</th></tr></thead>
      <tbody><tr v-for="(row, index) in actions" :key="index"><td>{{ shortDate(row.at) }}</td><td>{{ row.action }}</td><td>{{ row.who || '-' }}</td></tr><tr v-if="!actions.length"><td colspan="3" class="rpt-empty">No actions recorded as of this report.</td></tr></tbody></table>
    <p v-if="r.services?.length" class="rpt-total">Services provided: {{ r.services.length }} | Total assistance: <strong>{{ peso(assistanceTotal) }}</strong></p>
  </ReportSection>

  <div class="rpt-grid3">
    <ReportSection v-if="!study" :num="6" title="Assessment / findings"><ul class="rpt-list" contenteditable="true"><li v-for="(line, index) in findings" :key="index">{{ line }}</li></ul></ReportSection>
    <ReportSection :num="n(7, 8)" :title="study ? 'Recommendations' : 'Intervention plan / recommendations'" :span="study"><ul class="rpt-list" contenteditable="true"><li v-for="(line, index) in recommendations" :key="index">{{ line }}</li></ul></ReportSection>
    <div class="rpt-stack">
      <ReportSection :num="n(8, 9)" title="Current status"><p class="rpt-text rpt-small" contenteditable="true">{{ statusText }}</p></ReportSection>
      <ReportSection :num="n(9, 10)" :title="r.notedBy ? 'Prepared / noted by' : 'Prepared by'">
        <ReportSignatures :prepared-by="r.preparedBy" :noted-by="r.notedBy" :office-short="r.office.short" />
      </ReportSection>
    </div>
  </div>
</template>
