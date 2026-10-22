<script setup lang="ts">
import ReportPreview from '@/Components/UI/ReportPreview.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

interface ReportData {
    report_status: 'draft';
    template_version: string;
    approval_notice: string;
    prepared_by: string;
    source: { case_id: number; updated_at: string };
    case: Record<string, string | null>;
    client: Record<string, string | null>;
    plan: { version: number; status: string; overall_objective: string | null; review_due_at: string | null } | null;
    referrals: Array<{ service_category: string; status: string; referred_at: string | null; completed_at: string | null }>;
    aftercare: { status: string; initial_follow_up_at: string | null; created_at: string } | null;
    template_notice: string;
    generated_at: string;
}

const props = defineProps<{ report: ReportData; caseId: number }>();

const formatKey = (key: string) => key.replaceAll('_', ' ').replace(/\b\w/g, (l: string) => l.toUpperCase());
</script>

<template>
    <Head title="Social case management report" />
    <AuthenticatedLayout>
        <ReportPreview title="Social Case Management Report" :generated-at="report.generated_at" :notice="report.approval_notice"><template #actions><a :href="route('cases.social-report.download',caseId)" class="ui-button ui-button-primary">Download draft CSV</a></template>
            <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{{ report.template_notice }}</p>
            <dl class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-600">Prepared by</dt><dd class="break-words font-semibold">{{ report.prepared_by }}</dd></div>
                <div><dt class="text-slate-600">Template</dt><dd class="font-semibold">{{ report.template_version }}</dd></div>
                <div><dt class="text-slate-600">Source case record</dt><dd class="font-semibold">{{ report.source.case_id }}</dd></div>
                <div><dt class="text-slate-600">Source last updated</dt><dd class="break-words font-semibold">{{ report.source.updated_at }}</dd></div>
            </dl>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="font-extrabold">Case</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div v-for="(value, key) in report.case" :key="key" class="flex justify-between gap-4">
                            <dt class="text-slate-500">{{ formatKey(String(key)) }}</dt>
                            <dd class="text-right font-semibold">{{ value || 'Not recorded' }}</dd>
                        </div>
                    </dl>
                </section>
                <section class="rounded-2xl border bg-white p-5">
                    <h2 class="font-extrabold">Client and locality</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div v-for="(value, key) in report.client" :key="key" class="flex justify-between gap-4">
                            <dt class="text-slate-500">{{ formatKey(String(key)) }}</dt>
                            <dd class="text-right font-semibold">{{ value || 'Not recorded' }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section v-if="report.plan" class="mt-5 rounded-2xl border bg-white p-5">
                <h2 class="font-extrabold">Current plan</h2>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Version</dt><dd class="font-semibold">{{ report.plan.version }}</dd></div>
                    <div><dt class="text-slate-500">Status</dt><dd class="font-semibold">{{ report.plan.status }}</dd></div>
                    <div v-if="report.plan.overall_objective" class="sm:col-span-2"><dt class="text-slate-500">Objective</dt><dd class="font-semibold">{{ report.plan.overall_objective }}</dd></div>
                    <div v-if="report.plan.review_due_at"><dt class="text-slate-500">Review due</dt><dd class="font-semibold">{{ report.plan.review_due_at }}</dd></div>
                </dl>
            </section>

            <section class="mt-5 rounded-2xl border bg-white p-5">
                <h2 class="font-extrabold">Referrals</h2>
                <div v-if="report.referrals.length" class="mt-3 overflow-x-auto" tabindex="0" role="region" aria-label="Report referrals table">
                    <table class="min-w-full text-left text-sm">
                        <thead><tr class="border-b"><th class="p-2">Service</th><th class="p-2">Status</th><th class="p-2">Referred</th><th class="p-2">Completed</th></tr></thead>
                        <tbody>
                            <tr v-for="(item, idx) in report.referrals" :key="idx" class="border-b last:border-0">
                                <td class="p-2">{{ item.service_category }}</td>
                                <td class="p-2">{{ item.status }}</td>
                                <td class="p-2">{{ item.referred_at || '-' }}</td>
                                <td class="p-2">{{ item.completed_at || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-3 text-sm text-slate-500">No referrals recorded.</p>
            </section>

            <section v-if="report.aftercare" class="mt-5 rounded-2xl border bg-white p-5">
                <h2 class="font-extrabold">Aftercare</h2>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Status</dt><dd class="font-semibold">{{ report.aftercare.status }}</dd></div>
                    <div v-if="report.aftercare.initial_follow_up_at"><dt class="text-slate-500">Follow-up</dt><dd class="font-semibold">{{ report.aftercare.initial_follow_up_at }}</dd></div>
                </dl>
            </section>
        </ReportPreview>
    </AuthenticatedLayout>
</template>
