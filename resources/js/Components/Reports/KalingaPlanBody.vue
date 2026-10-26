<script setup lang="ts">
import { computed } from 'vue';
import ReportSection from '@/Components/Reports/ReportSection.vue';
import ReportSignatures from '@/Components/Reports/ReportSignatures.vue';
import type { ReportProps } from '@/types/reports';
import { human } from '@/utils/reportFormat';

/** Kalinga Intervention Plan as the case manager wrote it: a matrix, then the conforme lines. */
const props = defineProps<{ report: ReportProps; reveal: boolean; clientName: string }>();
const r = computed(() => props.report);
const columns = computed(() => Object.entries(r.value.form?.planColumns ?? {}));
const rows = computed(() => (r.value.form?.content.rows ?? []).filter((row) => Object.values(row).some((value) => String(value).trim())));
</script>

<template>
  <ReportSection :num="1" title="Case information">
    <table class="rpt-kv"><tbody>
      <tr><th>Case number</th><td>{{ r.caseInfo.number }}</td></tr>
      <tr><th>Client</th><td>{{ clientName }}</td></tr>
      <tr><th>Case status</th><td>{{ human(r.caseInfo.status) }}</td></tr>
      <tr><th>Plan</th><td>{{ r.form?.status === 'final' ? `Finalized version ${r.form.version}` : 'Draft - not yet finalized' }}</td></tr>
      <tr v-if="r.kavat"><th>Latest KaVAT</th><td>{{ r.kavat.final_score }} - {{ r.kavat.priority_label }}</td></tr>
    </tbody></table>
  </ReportSection>
  <ReportSection :num="2" title="Intervention plan">
    <div class="rpt-wrap" tabindex="0" role="region" aria-label="Intervention plan table">
      <table class="rpt-table">
        <thead><tr><th>No.</th><th v-for="[key, label] in columns" :key="key">{{ label }}</th></tr></thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="index"><td>{{ index + 1 }}</td><td v-for="[key] in columns" :key="key"><span class="rpt-text" contenteditable="true">{{ row[key] }}</span></td></tr>
          <tr v-if="!rows.length"><td :colspan="columns.length + 1" class="rpt-empty">No rows written yet.</td></tr>
        </tbody>
      </table>
    </div>
  </ReportSection>
  <ReportSection :num="3" title="Conforme and signatures">
    <p class="rpt-small">We have discussed this intervention plan together and agree to carry out our respective tasks.</p>
    <div class="mt-2"><ReportSignatures :prepared-by="r.preparedBy" :noted-by="r.notedBy" :office-short="r.office.short" :extra="[{ label: 'Conforme:', role: 'Child (signature over printed name)' }, { label: 'Conforme:', role: 'Parent / guardian (signature over printed name)' }]" /></div>
  </ReportSection>
</template>
