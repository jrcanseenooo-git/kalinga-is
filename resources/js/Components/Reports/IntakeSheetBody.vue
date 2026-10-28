<script setup lang="ts">
import { computed } from 'vue';
import FamilyTable from '@/Components/Reports/FamilyTable.vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { ReportProps } from '@/types/reports';
import { ageOn, classificationText, human, initialsOf, longDate, maskContact } from '@/utils/reportFormat';
import { incidenceRows } from '@/data/cefmu';

/**
 * Kalinga Intake Sheet (General Intake Sheet): identifying information,
 * contact details, primary caregiver, family composition, CEFMU situation,
 * initial safety and risk, and the privacy notice and consent / assent record.
 * The narrative (problem, actions, assessment, recommendations) is written in
 * the Case Summary Report, so it is not repeated here (owner, 2026-10-28).
 */
const props = defineProps<{ report: ReportProps; reveal: boolean; clientName: string }>();
const r = computed(() => props.report);
const c = computed(() => r.value.client);
const intake = computed(() => r.value.intake!);
const age = computed(() => ageOn(c.value.birthdate, r.value.caseInfo.intake_at || r.value.generatedAt));
const name = computed(() => props.clientName);
const text = (value?: string | null, empty = 'Not recorded') => value && String(value).trim() ? value : empty;
const channels = computed(() => (c.value.contact_channels ?? []) as { channel: string; handle: string }[]);
const FORM_TYPES: Record<string, string> = { consent: 'Consent form (parent or guardian)', assent: 'Assent form (child)', consent_and_assent: 'Consent and assent forms' };
const consentForm = computed(() => intake.value.consent_form_type ? `${FORM_TYPES[intake.value.consent_form_type] ?? human(intake.value.consent_form_type)}${intake.value.consent_photo ? ' - signed copy on file' : ''}` : (intake.value.consent_photo ? 'Signed copy on file' : 'Not recorded'));
</script>

<template>
  <div class="rpt-grid2">
    <ReportSection :num="1" title="Case and intake information">
      <table class="rpt-kv"><tbody>
        <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
        <tr><th>Date of intake</th><td>{{ longDate(r.caseInfo.intake_at) }}</td></tr>
        <tr><th>Mode of reporting</th><td>{{ human(r.caseInfo.mode_of_admission) }}</td></tr>
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
    <ReportSection :num="3" title="Address and child contact details">
      <table class="rpt-kv"><tbody>
        <tr><th>Present address</th><td>{{ text(c.address) }}</td></tr>
        <tr><th>Permanent address</th><td>{{ text(c.permanent_address || c.address) }}</td></tr>
        <tr><th>Mobile number</th><td>{{ maskContact(c.contact, reveal) }}</td></tr>
        <tr v-for="(item, index) in channels" :key="index"><th>{{ item.channel || 'Other' }}</th><td>{{ reveal ? item.handle : 'Shown on the full-name copy' }}</td></tr>
        <tr><th>Safe way to contact</th><td>{{ human(c.safe_contact_method) }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="4" title="Primary caregiver / custody">
      <table class="rpt-kv"><tbody>
        <tr><th>Name</th><td>{{ c.caregiver ? (reveal ? c.caregiver : initialsOf(c.caregiver)) : 'Not recorded' }}</td></tr>
        <tr><th>Relationship to child</th><td>{{ text(c.caregiver_relationship) }}</td></tr>
        <tr><th>Contact number</th><td>{{ maskContact(c.caregiver_contact, reveal) }}</td></tr>
        <tr><th>Address</th><td>{{ text(c.caregiver_address) }}</td></tr>
      </tbody></table>
    </ReportSection>
  </div>

  <ReportSection :num="5" title="Family composition"><FamilyTable :family="r.family" :reveal="reveal" /></ReportSection>

  <div class="rpt-grid2">
    <ReportSection :num="6" title="CEFMU incidence">
      <table class="rpt-kv"><tbody>
        <tr><th>Classification</th><td>{{ classificationText(r.caseInfo.classifications ?? []) || 'Not recorded' }}</td></tr>
        <tr v-for="[label, value] in incidenceRows(r.caseInfo)" :key="label"><th>{{ label }}</th><td>{{ value }}</td></tr>
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

  <div class="rpt-grid2">
    <ReportSection :num="8" title="Privacy notice and consent / assent">
      <table class="rpt-kv"><tbody>
        <tr><th>Privacy notice given</th><td>{{ human(intake.privacy_notice_method) }}<template v-if="intake.privacy_notice_at"> on {{ longDate(intake.privacy_notice_at) }}</template></td></tr>
        <tr><th>Consent status</th><td>{{ human(intake.consent_status) }}</td></tr>
        <tr><th>Consent / assent form</th><td>{{ consentForm }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="9" :title="r.notedBy ? 'Prepared / noted by' : 'Prepared by'">
      <ReportSignatures :prepared-by="r.preparedBy" :noted-by="r.notedBy" :office-short="r.office.short" />
    </ReportSection>
  </div>
</template>
