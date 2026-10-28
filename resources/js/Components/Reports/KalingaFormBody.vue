<script setup lang="ts">
import { computed } from 'vue';
import FamilyTable from '@/Components/Reports/FamilyTable.vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { ReportProps } from '@/types/reports';
import { incidenceRows } from '@/data/cefmu';
import { reportStatusText } from '@/utils/reportActions';
import { ageOn, classificationText, human, initialsOf, longDate } from '@/utils/reportFormat';

/**
 * Case Summary Report and Social Case Study Report as written by the case
 * manager (Kalinga forms): the narrative sections in paragraphs, with
 * identifying information, family composition and the plan matrix taken
 * from the record. The Case Summary Report follows the owner's sequence:
 * presenting problem, brief case history, assessment, recommendations.
 */
const props = defineProps<{ report: ReportProps; reveal: boolean; clientName: string }>();
const r = computed(() => props.report);
const client = computed(() => r.value.client);
const age = computed(() => ageOn(client.value.birthdate));
const sections = computed(() => r.value.form?.sections ?? []);
const text = (key: string) => r.value.form?.content.sections?.[key] ?? '';
const paragraphs = (key: string) => text(key).split(/\n\s*\n/).map((item) => item.trim()).filter(Boolean);
const status = computed(() => reportStatusText(r.value));
const columns = computed(() => Object.entries(r.value.form?.planColumns ?? {}));
const matrix = computed(() => (r.value.planMatrix ?? []).filter((row) => Object.values(row).some((value) => String(value).trim())));
</script>

<template>
  <p v-if="r.form?.status !== 'final'" class="rpt-small rpt-muted">Draft - this form has not been finalized.</p>
  <p v-else-if="r.form?.version" class="rpt-small rpt-muted">Finalized version {{ r.form.version }}.</p>
  <template v-for="(section, index) in sections" :key="section.key">
    <ReportSection :num="index + 1" :title="section.title.replace(/^[IVX]+\.\s*/, '')">
      <table v-if="section.key === 'identifying'" class="rpt-kv"><tbody>
        <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
        <tr><th>Name</th><td>{{ clientName }}</td></tr>
        <tr><th>Age / sex</th><td>{{ age !== null ? `${age} years old` : 'Not recorded' }} / {{ client.sex || 'Not recorded' }}</td></tr>
        <tr><th>Date of birth</th><td>{{ longDate(client.birthdate) }}</td></tr>
        <tr><th>Address</th><td>{{ client.address || 'Not recorded' }}</td></tr>
        <tr><th>Religion / indigenous group</th><td>{{ client.religion || 'Not recorded' }} / {{ client.ip_category || 'Not applicable' }}</td></tr>
        <tr><th>Education</th><td>{{ client.education || human(r.caseInfo.education_status) }}</td></tr>
        <tr><th>Primary caregiver / custody</th><td>{{ client.caregiver ? `${reveal ? client.caregiver : initialsOf(client.caregiver)}${client.caregiver_relationship ? ` (${client.caregiver_relationship})` : ''}` : 'Not recorded' }}</td></tr>
        <tr><th>Date of intake</th><td>{{ longDate(r.caseInfo.intake_at) }}</td></tr>
        <tr><th>Source of referral</th><td>{{ r.caseInfo.referred_by || human(r.caseInfo.mode_of_admission) }}</td></tr>
      </tbody></table>
      <table v-else-if="section.key === 'incidence'" class="rpt-kv"><tbody>
        <tr><th>Classification</th><td>CEFMU{{ classificationText(r.caseInfo.classifications ?? []) ? ` - ${classificationText(r.caseInfo.classifications ?? [])}` : '' }}</td></tr>
        <tr v-for="[label, value] in incidenceRows(r.caseInfo)" :key="label"><th>{{ label }}</th><td>{{ value }}</td></tr>
      </tbody></table>
      <FamilyTable v-else-if="section.key === 'family'" :family="r.family" :reveal="reveal" />
      <div v-else-if="section.key === 'plan'" class="rpt-wrap" tabindex="0" role="region" aria-label="Intervention plan">
        <table class="rpt-table"><thead><tr><th v-for="[key, label] in columns" :key="key">{{ label }}</th></tr></thead>
          <tbody><tr v-for="(row, i) in matrix" :key="i"><td v-for="[key] in columns" :key="key" class="whitespace-pre-wrap">{{ row[key] }}</td></tr><tr v-if="!matrix.length"><td :colspan="columns.length" class="rpt-empty">No intervention plan has been written yet.</td></tr></tbody></table>
      </div>
      <p v-else-if="section.key === 'status'" class="rpt-text" contenteditable="true">{{ status }}</p>
      <template v-else>
        <p v-for="(para, i) in paragraphs(section.key)" :key="i" class="rpt-text rpt-para" contenteditable="true">{{ para }}</p>
        <p v-if="!paragraphs(section.key).length" class="rpt-empty">Not written.</p>
        <p v-if="section.key === 'assessment' && r.kavat" class="rpt-small">KaVAT ({{ longDate(r.kavat.administered_at) }}): empowerment {{ r.kavat.empowerment_rate }}%, safety {{ r.kavat.safety_rate }}%, final protection score <strong>{{ r.kavat.final_score }}</strong> - {{ r.kavat.priority_label }}.</p>
      </template>
    </ReportSection>
  </template>
  <ReportSection :num="sections.length + 1" :title="r.notedBy ? 'Prepared / noted by' : 'Prepared by'">
    <ReportSignatures :prepared-by="r.preparedBy" :noted-by="r.notedBy" :office-short="r.office.short" />
  </ReportSection>
</template>
