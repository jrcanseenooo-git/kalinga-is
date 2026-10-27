<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CaseSummaryBody from '@/Components/Reports/CaseSummaryBody.vue';
import KalingaFormBody from '@/Components/Reports/KalingaFormBody.vue';
import KalingaPlanBody from '@/Components/Reports/KalingaPlanBody.vue';
import IntakeSheetBody from '@/Components/Reports/IntakeSheetBody.vue';
import InterventionPlanBody from '@/Components/Reports/InterventionPlanBody.vue';
import ProgressNotesBody from '@/Components/Reports/ProgressNotesBody.vue';
import ReportSheet from '@/Components/Reports/ReportSheet.vue';
import type { ReportProps } from '@/types/reports';
import { clientDisplayName, type NameMode } from '@/utils/clientName';

/** Printable case reports; the body depends on the report type. */
const props = defineProps<ReportProps>();
const nameMode = ref<NameMode>('masked');
const reveal = computed(() => nameMode.value === 'full');
const clientName = computed(() => clientDisplayName(props.client, nameMode.value));
// A Kalinga form the case manager wrote is printed as written; otherwise the report is assembled from the record.
const written = computed(() => Boolean(props.form?.hasContent));
const body = computed(() => ({ intake: IntakeSheetBody, notes: ProgressNotesBody, plan: written.value ? KalingaPlanBody : InterventionPlanBody,
  summary: written.value ? KalingaFormBody : CaseSummaryBody, study: written.value ? KalingaFormBody : CaseSummaryBody })[props.type]);
const subtitle = computed(() => ({ intake: 'General Intake Sheet', notes: 'Case management progress notes', plan: 'Intervention plan and contract', summary: '', study: '' })[props.type] || undefined);

// Progress notes: optional period covered.
const from = ref(props.period?.from ?? '');
const to = ref(props.period?.to ?? '');
const applyPeriod = () => router.get(route('cases.reports.show', [props.caseId, 'notes']), { ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}) }, { preserveState: false });
</script>

<template>
  <Head :title="title" />
  <ReportSheet v-model:name-mode="nameMode" :has-alias="Boolean(client.alias)" :title="title" :subtitle="subtitle" :report-no="reportNo" :generated-at="generatedAt" :case-id="caseId" :office="office" :prepared-by="preparedBy">
    <template v-if="type === 'notes'" #tools>
      <form class="flex flex-wrap items-end gap-2" data-skip-generic-confirm="true" aria-label="Period covered" @submit.prevent="applyPeriod">
        <label class="text-xs font-semibold text-slate-600">From<input v-model="from" type="date" class="mt-0.5 block min-h-11 rounded-md border-slate-300 text-sm"></label>
        <label class="text-xs font-semibold text-slate-600">To<input v-model="to" type="date" :min="from || undefined" class="mt-0.5 block min-h-11 rounded-md border-slate-300 text-sm"></label>
        <button type="submit" class="rpt-btn">Apply period</button>
      </form>
    </template>
    <component :is="body" :report="props" :reveal="reveal" :client-name="clientName" />
  </ReportSheet>
</template>
