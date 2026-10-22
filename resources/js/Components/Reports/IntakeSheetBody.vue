<script setup lang="ts">
import { computed } from 'vue';
import FamilyTable from '@/Components/Reports/FamilyTable.vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { ReportProps } from '@/types/reports';
import { ageOn, classificationText, human, initialsOf, longDate, maskContact } from '@/utils/reportFormat';

/**
 * Kalinga Intake Sheet (General Intake Sheet): identifying information,
 * family composition, CEFMU situation, initial safety and risk, the social
 * worker's assessment and the privacy notice and consent record.
 */
const props = defineProps<{ report: ReportProps; reveal: boolean }>();
const r = computed(() => props.report);
const c = computed(() => r.value.client);
const intake = computed(() => r.value.intake!);
const age = computed(() => ageOn(c.value.birthdate, r.value.caseInfo.intake_at || r.value.generatedAt));
const name = computed(() => props.reveal ? [c.value.first_name, c.value.middle_name, c.value.last_name, c.value.suffix].filter(Boolean).join(' ') : initialsOf(c.value.first_name, c.value.middle_name, c.value.last_name));
const text = (value?: string | null, empty = 'Not recorded') => value && String(value).trim() ? value : empty;
</script>

<template>
  <div class="rpt-grid2">
    <ReportSection :num="1" title="Case and intake information">
      <table class="rpt-kv"><tbody>
        <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
        <tr><th>Date of intake</th><td>{{ longDate(r.caseInfo.intake_at) }}</td></tr>
        <tr><th>Mode of admission</th><td>{{ human(r.caseInfo.mode_of_admission) }}</td></tr>
        <tr><th>Referred by</th><td>{{ text(r.caseInfo.referred_by, 'Not applicable') }}</td></tr>
        <tr><th>Referral date</th><td>{{ longDate(r.caseInfo.referral_date) }}</td></tr>
        <tr><th>Case category</th><td>CEFMU{{ classificationText(r.caseInfo.classifications ?? []) ? ` - ${classificationText(r.caseInfo.classifications ?? [])}` : '' }}</td></tr>
        <tr><th>Interviewed by</th><td>{{ r.preparedBy.name }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="2" title="Identifying information of the child">
      <table class="rpt-kv"><tbody>
        <tr><th>Name</th><td>{{ name }}</td></tr>
        <tr><th>Sex</th><td>{{ text(c.sex) }}</td></tr>
        <tr><th>Date of birth / age</th><td>{{ longDate(c.birthdate) }}<template v-if="age !== null"> ({{ age }} years old at intake)</template></td></tr>
        <tr><th>Civil status</th><td>{{ text(c.civil_status, age !== null && age < 18 ? 'Not applicable (minor)' : 'Not recorded') }}</td></tr>
        <tr><th>Religion</th><td>{{ text(c.religion) }}</td></tr>
        <tr><th>Indigenous group</th><td>{{ text(c.ip_category, 'Not applicable') }}</td></tr>
        <tr><th>Highest education</th><td>{{ text(c.education) }}</td></tr>
      </tbody></table>
    </ReportSection>
  </div>

  <div class="rpt-grid2">
    <ReportSection :num="3" title="Address and safe contact">
      <table class="rpt-kv"><tbody>
        <tr><th>Present address</th><td>{{ text(c.address) }}</td></tr>
        <tr><th>Permanent address</th><td>{{ text(c.permanent_address, 'Same as present address') }}</td></tr>
        <tr><th>Contact number</th><td>{{ maskContact(c.contact, reveal) }}</td></tr>
        <tr><th>Safe way to contact</th><td>{{ human(c.safe_contact_method) }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="4" title="Parent / guardian">
      <table class="rpt-kv"><tbody>
        <tr><th>Name</th><td>{{ c.caregiver ? (reveal ? c.caregiver : initialsOf(c.caregiver)) : 'Not recorded' }}</td></tr>
        <tr><th>Relationship to child</th><td>{{ text(c.caregiver_relationship) }}</td></tr>
        <tr><th>Contact number</th><td>{{ maskContact(c.caregiver_contact, reveal) }}</td></tr>
      </tbody></table>
    </ReportSection>
  </div>

  <ReportSection :num="5" title="Family composition"><FamilyTable :family="r.family" :reveal="reveal" /></ReportSection>

  <div class="rpt-grid2">
    <ReportSection :num="6" title="CEFMU situation">
      <table class="rpt-kv"><tbody>
        <tr><th>Classification</th><td>{{ classificationText(r.caseInfo.classifications ?? []) || 'Not recorded' }}</td></tr>
        <tr><th>Form of union</th><td>{{ human(r.caseInfo.union_type) }}</td></tr>
        <tr><th>Partner age / power context</th><td>{{ human(r.caseInfo.partner_age_band) }}</td></tr>
        <tr><th>Schooling at intake</th><td>{{ human(r.caseInfo.education_status) }}</td></tr>
        <tr v-if="intake.health_recorded"><th>Health information</th><td class="rpt-withheld">Recorded in the case record; not printed on this sheet.</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="7" title="Initial safety and risk">
      <table class="rpt-kv"><tbody>
        <tr><th>Initial risk level</th><td>{{ human(r.caseInfo.initial_risk_level) }}</td></tr>
        <tr><th>Immediate safety concern</th><td>{{ intake.immediate_safety_concern ? 'Yes' : 'No' }}</td></tr>
        <tr><th>Risk indicators</th><td>{{ intake.risk_indicators.length ? intake.risk_indicators.map(human).join('; ') : 'None recorded' }}</td></tr>
        <tr><th>Next safety review</th><td>{{ longDate(intake.next_review_at) }}</td></tr>
      </tbody></table>
    </ReportSection>
  </div>

  <ReportSection :num="8" title="Problem presented">
    <p class="rpt-text" contenteditable="true">{{ text(r.caseInfo.problem_presented, 'No problem was recorded at intake.') }}</p>
    <div v-if="r.caseInfo.cefmu_context" class="rpt-sub mt-2"><h3>Circumstances of the union</h3><p class="rpt-text" contenteditable="true">{{ r.caseInfo.cefmu_context }}</p></div>
  </ReportSection>

  <ReportSection :num="9" title="Immediate actions taken"><p class="rpt-text" contenteditable="true">{{ text(r.caseInfo.immediate_actions, 'No immediate action was recorded at intake.') }}</p></ReportSection>

  <div class="rpt-grid2">
    <ReportSection :num="10" title="Social worker's assessment"><p class="rpt-text" contenteditable="true">{{ text(r.caseInfo.initial_assessment, 'No initial assessment was recorded.') }}</p></ReportSection>
    <ReportSection :num="11" title="Recommendations"><p class="rpt-text" contenteditable="true">{{ text(r.caseInfo.plan_recommendations, 'No recommendation was recorded.') }}</p><p v-if="intake.remarks" class="rpt-text rpt-small mt-2"><strong>Remarks:</strong> {{ intake.remarks }}</p></ReportSection>
  </div>

  <div class="rpt-grid2">
    <ReportSection :num="12" title="Privacy notice and consent">
      <table class="rpt-kv"><tbody>
        <tr><th>Privacy notice given</th><td>{{ human(intake.privacy_notice_method) }}<template v-if="intake.privacy_notice_at"> on {{ longDate(intake.privacy_notice_at) }}</template></td></tr>
        <tr><th>Consent status</th><td>{{ human(intake.consent_status) }}</td></tr>
        <tr><th>Basis for data sharing</th><td>{{ human(intake.data_sharing_basis) }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="13" :title="r.notedBy ? 'Prepared / noted by' : 'Prepared by'">
      <ReportSignatures :prepared-by="r.preparedBy" :noted-by="r.notedBy" :office-short="r.office.short" />
    </ReportSection>
  </div>
</template>
