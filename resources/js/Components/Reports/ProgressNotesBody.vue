<script setup lang="ts">
import { computed } from 'vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { ReportProps } from '@/types/reports';
import { NOTE_TYPES } from '@/utils/caseOptions';
import { counted, dateTime, human, longDate } from '@/utils/reportFormat';

/**
 * Progress Notes report: finalized case-team notes in date order, with any
 * addenda printed under the note they correct. Columns follow the progress
 * note format (purpose, observations and assessment, action or resolution,
 * next steps).
 */
const props = defineProps<{ report: ReportProps; reveal: boolean; clientName: string }>();
const r = computed(() => props.report);
const notes = computed(() => r.value.progressNotes ?? []);
const noteLabel = (type: string | null) => NOTE_TYPES.find(item => item.value === type)?.label ?? human(type);
const name = computed(() => props.clientName);
const period = computed(() => {
  const p = r.value.period;
  if (p?.from && p?.to) return `${longDate(p.from)} to ${longDate(p.to)}`;
  if (p?.from) return `From ${longDate(p.from)}`;
  if (p?.to) return `Up to ${longDate(p.to)}`;
  return notes.value.length ? `${longDate(notes.value[0].at)} to ${longDate(notes.value[notes.value.length - 1].at)}` : 'Entire case period';
});
const byType = computed(() => NOTE_TYPES.map(type => ({ label: type.label, count: notes.value.filter(note => note.type === type.value).length })).filter(item => item.count));
const referralsOpen = computed(() => (r.value.referrals ?? []).filter(item => ['SENT', 'ACCEPTED', 'IN_PROGRESS'].includes(item.status)).length);
const referralsDone = computed(() => (r.value.referrals ?? []).filter(item => item.status === 'COMPLETED').length);
const lastContact = computed(() => notes.value.length ? longDate(notes.value[notes.value.length - 1].at) : 'No contact recorded');
</script>

<template>
  <div class="rpt-grid2">
    <ReportSection :num="1" title="Case information">
      <table class="rpt-kv"><tbody>
        <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
        <tr><th>Client</th><td>{{ name }}</td></tr>
        <tr><th>Case status</th><td>{{ human(r.caseInfo.status) }}</td></tr>
        <tr><th>Case manager</th><td>{{ r.preparedBy.name }}</td></tr>
        <tr><th>Period covered</th><td>{{ period }}</td></tr>
      </tbody></table>
    </ReportSection>
    <ReportSection :num="2" title="Summary of contacts">
      <table class="rpt-kv"><tbody>
        <tr><th>Progress notes</th><td>{{ notes.length }}</td></tr>
        <tr v-for="item in byType" :key="item.label"><th class="font-normal">- {{ item.label }}</th><td>{{ item.count }}</td></tr>
        <tr><th>Latest contact</th><td>{{ lastContact }}</td></tr>
        <tr><th>Referrals</th><td>{{ referralsDone }} completed, {{ referralsOpen }} pending</td></tr>
        <tr><th>Services provided</th><td>{{ r.services?.length ?? 0 }}</td></tr>
      </tbody></table>
    </ReportSection>
  </div>

  <ReportSection :num="3" title="Progress notes">
    <div class="rpt-wrap" tabindex="0" role="region" aria-label="Progress notes table">
      <table class="rpt-table">
        <thead><tr><th class="rpt-w-date">Date and time</th><th>Purpose</th><th>Observations and assessment</th><th>Action / resolution</th><th>Next steps</th><th>Recorded by</th></tr></thead>
        <tbody>
          <template v-for="note in notes" :key="note.id">
            <tr>
              <td>{{ dateTime(note.at) }}</td><td>{{ noteLabel(note.type) }}</td>
              <td><span class="rpt-text" contenteditable="true">{{ note.body }}</span></td>
              <td><span class="rpt-text" contenteditable="true">{{ note.action || '-' }}</span></td>
              <td><span class="rpt-text" contenteditable="true">{{ note.next || '-' }}</span></td>
              <td>{{ note.author || '-' }}<span v-if="note.author_position" class="rpt-muted"><br>{{ note.author_position }}</span></td>
            </tr>
            <tr v-for="(addendum, index) in note.addenda" :key="`${note.id}-a${index}`">
              <td class="rpt-muted">{{ dateTime(addendum.at) }}</td>
              <td colspan="4"><strong>Addendum</strong><span v-if="addendum.reason"> ({{ addendum.reason }})</span>: <span class="rpt-text">{{ addendum.body }}</span></td>
              <td>{{ addendum.author || '-' }}</td>
            </tr>
          </template>
          <tr v-if="!notes.length"><td colspan="6" class="rpt-empty">No progress notes were recorded for this period.</td></tr>
        </tbody>
      </table>
    </div>
    <p class="rpt-small rpt-muted mt-2">This report contains {{ counted(notes.length, 'progress note') }}. Notes of the multi-disciplinary team and restricted records are kept in the case record and are not included.</p>
  </ReportSection>

  <ReportSection :num="4" :title="r.notedBy ? 'Prepared / noted by' : 'Prepared by'">
    <ReportSignatures :prepared-by="r.preparedBy" :noted-by="r.notedBy" :office-short="r.office.short" />
  </ReportSection>
</template>
