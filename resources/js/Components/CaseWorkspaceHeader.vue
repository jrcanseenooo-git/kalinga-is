<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, FileText } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { priorityTone, riskTone, statusTone, titleCase } from '@/utils/caseDisplay';
import type { CaseWorkspaceSummary } from '@/types/casework';

const props = defineProps<{
    caseRecord: CaseWorkspaceSummary;
    age: number | null;
    activityDays: number | null;
    canGenerateReport: boolean;
}>();

const clientName = computed(() => [props.caseRecord.first_name, props.caseRecord.last_name].filter(Boolean).join(' ') || 'Client name not recorded');
const initials = computed(() => [props.caseRecord.first_name, props.caseRecord.last_name].filter(Boolean).map(part => String(part)[0]).join('').toUpperCase() || '?');
/** Printable reports (App\\Services\\CaseReports::TYPES), in the order of the Kalinga forms. */
const REPORTS = [
    { type: 'intake', label: 'Kalinga Intake Sheet' },
    { type: 'summary', label: 'Case Summary Report' },
    { type: 'study', label: 'Social Case Study Report' },
    { type: 'plan', label: 'Intervention Plan' },
    { type: 'notes', label: 'Progress Notes' },
];
const menuOpen = ref(false);
const menu = ref<HTMLElement | null>(null);
const closeOutside = (event: Event) => { if (menu.value && !menu.value.contains(event.target as Node)) menuOpen.value = false; };
const closeOnEscape = (event: KeyboardEvent) => { if (event.key === 'Escape' && menuOpen.value) { menuOpen.value = false; menu.value?.querySelector('button')?.focus(); } };
onMounted(() => { document.addEventListener('click', closeOutside); document.addEventListener('keydown', closeOnEscape); });
onBeforeUnmount(() => { document.removeEventListener('click', closeOutside); document.removeEventListener('keydown', closeOnEscape); });
const lastActivity = computed(() => props.activityDays === null ? 'Not recorded' : props.activityDays === 0 ? 'Today' : props.activityDays === 1 ? 'Yesterday' : `${props.activityDays} days ago`);
</script>

<template>
    <section class="ui-panel p-4 sm:p-5" aria-label="Case folder summary">
        <Link :href="route('cases.index')" class="ui-link min-h-9 text-xs"><ArrowLeft aria-hidden="true" class="h-3.5 w-3.5" />Back to case registry</Link>
        <div class="mt-1 flex min-w-0 flex-col gap-4 min-[1024px]:flex-row min-[1024px]:items-start min-[1024px]:justify-between">
            <div class="flex min-w-0 items-start gap-4">
                <div aria-hidden="true" class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-brand-100 text-xl font-bold text-brand-800">{{ initials }}</div>
                <div class="min-w-0">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <h1 class="break-words text-2xl font-bold tracking-tight text-[var(--color-ink)] [overflow-wrap:anywhere]">{{ caseRecord.case_number }}</h1>
                        <StatusBadge :label="titleCase(caseRecord.status)" :tone="statusTone(caseRecord.status)" dot />
                        <StatusBadge :label="caseRecord.priority ? `${titleCase(caseRecord.priority)} priority` : 'Priority not recorded'" :tone="priorityTone(caseRecord.priority)" />
                        <StatusBadge v-if="caseRecord.current_risk_level" :label="`${titleCase(caseRecord.current_risk_level)} risk`" :tone="riskTone(caseRecord.current_risk_level)" />
                    </div>
                    <p class="mt-1 break-words text-base font-semibold text-slate-800">{{ clientName }}<span v-if="age !== null" class="font-normal text-slate-600"> - {{ age }} years old</span></p>
                    <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-600">
                        <div class="flex gap-1"><dt>Classification:</dt><dd class="font-semibold text-slate-800">{{ caseRecord.category || 'Not recorded' }}</dd></div>
                        <div class="flex gap-1"><dt>Case worker:</dt><dd class="font-semibold text-slate-800">{{ caseRecord.worker_name || 'Unassigned' }}</dd></div>
                        <div class="flex gap-1"><dt>Last activity:</dt><dd class="font-semibold text-slate-800">{{ lastActivity }}</dd></div>
                    </dl>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <!-- Plan & continuity is hidden for now (owner, 2026-10-28); planning is the Intervention Plan in Kalinga forms. -->
                <div v-if="canGenerateReport" ref="menu" class="relative">
                    <button type="button" class="ui-button-primary" aria-haspopup="true" :aria-expanded="menuOpen" aria-controls="case-report-menu" @click="menuOpen = !menuOpen"><FileText class="h-4 w-4" aria-hidden="true" />Reports<ChevronDown class="h-4 w-4 transition" :class="menuOpen ? 'rotate-180' : ''" aria-hidden="true" /></button>
                    <ul v-show="menuOpen" id="case-report-menu" class="absolute right-0 z-30 mt-1 w-64 overflow-hidden rounded-xl border border-[var(--color-line)] bg-white py-1 shadow-lg" aria-label="Print a case report">
                        <li v-for="report in REPORTS" :key="report.type"><Link :href="route('cases.reports.show', [caseRecord.id, report.type])" class="flex min-h-11 items-center px-4 text-sm text-slate-800 hover:bg-brand-50 focus-visible:bg-brand-50 focus-visible:outline-none">{{ report.label }}</Link></li>
                    </ul>
                </div>
            </div>
        </div>
        <div v-if="$slots.actions" class="mt-5 grid grid-cols-2 gap-2 border-t border-[var(--color-line)] pt-4 sm:grid-cols-3 xl:grid-cols-6"><slot name="actions" /></div>
    </section>
</template>
