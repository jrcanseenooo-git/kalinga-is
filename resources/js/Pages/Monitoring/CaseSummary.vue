<script setup lang="ts">
/** Read-only case summary for monitoring roles, with remarks and closure approval. */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import RemarksThread from '@/Components/RemarksThread.vue';
import { classificationLabels, incidenceRows, type IncidenceRecord } from '@/data/cefmu';
import { riskTone, statusTone, titleCase } from '@/utils/caseDisplay';
import { formatPh } from '@/utils/philippineTime';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, ShieldCheck, Undo2 } from '@lucide/vue';
import { computed } from 'vue';
import type { Remark } from '@/types/remarks';

type CaseRecord = IncidenceRecord & { id: number; case_number: string; name: string; age: number | null; sex: string | null; lgu: string | null; province: string | null; region: string | null;
    case_manager: string | null; status: string; stage: string | null; priority: string; initial_risk_level: string | null; current_risk_level: string | null; classifications: string[];
    opened_at: string | null; last_activity_at: string | null; closed_at: string | null; next_review_at: string | null };
const props = defineProps<{
    caseRecord: CaseRecord;
    progress: { notes: number; lastNoteAt: string | null; referralsOpen: number; referralsTotal: number; visits: number };
    activity: Array<{ from_status: string | null; to_status: string; created_at: string }>;
    remarks: Remark[];
    closure: { summary: string | null; ready: boolean; canApprove: boolean } | null;
    kavat?: { final_score: number; priority: string; administered_at: string; occasion: string } | null;
    forms?: Array<{ type: string; title: string; status: string; updated_at: string | null }>;
    grants?: { total: number; count: number };
    canRemark: boolean; roleLabel: string; canOpenRecord?: boolean;
}>();

const c = computed(() => props.caseRecord);
const risk = computed(() => c.value.current_risk_level || c.value.initial_risk_level);
const facts = computed(() => [
    ['Client', `${c.value.name}${c.value.age !== null ? `, ${c.value.age} years old` : ''}${c.value.sex ? `, ${c.value.sex}` : ''}`],
    ['LGU', [c.value.lgu, c.value.province, c.value.region].filter(Boolean).join(', ') || '-'],
    ['Case manager', c.value.case_manager || 'Not assigned'],
    ['Opened', formatPh(c.value.opened_at, 'date', '-')],
    ['Last activity', formatPh(c.value.last_activity_at, 'date', '-')],
    ['Next review', formatPh(c.value.next_review_at, 'date', 'Not set')],
]);
const remark = useForm({ body: '' });
const sendRemark = () => remark.post(route('monitoring.cases.remarks.store', c.value.id), { preserveScroll: true, onSuccess: () => remark.reset() });
const approve = useForm({ note: '' });
const giveBack = useForm({ reason: '' });
const doApprove = () => approve.post(route('monitoring.cases.closure.approve', c.value.id), { preserveScroll: true });
const doReturn = () => giveBack.post(route('monitoring.cases.closure.return', c.value.id), { preserveScroll: true });
</script>

<template>
    <Head :title="caseRecord.case_number" />
    <AuthenticatedLayout>
        <section class="mx-auto w-full max-w-6xl space-y-4">
            <Link :href="route('monitoring.cases.index')" class="ui-link inline-flex items-center gap-1 text-sm"><ArrowLeft class="h-4 w-4" aria-hidden="true" />Back to cases</Link>
            <header class="ui-panel flex flex-wrap items-start justify-between gap-3 p-5">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-700">Case monitoring</p>
                    <h1 class="mt-1 text-2xl font-extrabold text-slate-900">{{ caseRecord.case_number }}</h1>
                    <p class="mt-1 font-mono text-sm text-slate-700">{{ caseRecord.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <StatusBadge :label="titleCase(caseRecord.status)" :tone="statusTone(caseRecord.status)" dot />
                    <StatusBadge v-if="risk" :label="`${titleCase(risk)} risk`" :tone="riskTone(risk)" />
                    <StatusBadge :label="`${titleCase(caseRecord.priority)} priority`" tone="neutral" />
                </div>
                <p class="flex w-full items-center gap-1.5 text-xs text-slate-600"><ShieldCheck class="h-4 w-4 text-emerald-600" aria-hidden="true" />Read-only. Names, addresses, contacts and narratives stay with the case manager.</p>
                <Link v-if="canOpenRecord" :href="route('cases.show', caseRecord.id)" class="ui-button min-h-11 px-3 text-sm">Open the full case record (read-only, as LGU Head)</Link>
            </header>

            <section v-if="closure" class="rounded-2xl border border-amber-300 bg-amber-50 p-5" aria-labelledby="closure-title">
                <h2 id="closure-title" class="font-bold text-amber-900">Waiting for closure review</h2>
                <p v-if="closure.summary" class="mt-2 whitespace-pre-wrap rounded-xl bg-white p-3 text-sm leading-6 text-slate-800">{{ closure.summary }}</p>
                <p v-else class="mt-2 text-sm text-amber-900">The case manager has not written the closure record yet.</p>
                <div v-if="closure.canApprove && closure.ready" class="mt-4 grid gap-4 lg:grid-cols-2">
                    <form class="space-y-2" data-skip-generic-confirm="true" @submit.prevent="doApprove">
                        <label class="block text-sm font-semibold text-slate-800" for="approve-note">Note to the case manager (optional)<textarea id="approve-note" v-model="approve.note" rows="2" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal"></textarea></label>
                        <button type="submit" class="ui-button-primary min-h-11 px-4" :disabled="approve.processing"><CheckCircle2 class="h-4 w-4" aria-hidden="true" />Approve and close the case</button>
                    </form>
                    <form class="space-y-2" data-skip-generic-confirm="true" @submit.prevent="doReturn">
                        <label class="block text-sm font-semibold text-slate-800" for="return-reason">Reason for returning <span class="text-rose-700">*</span><textarea id="return-reason" v-model="giveBack.reason" rows="2" maxlength="2000" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal" :aria-invalid="Boolean(giveBack.errors.reason)"></textarea></label>
                        <small v-if="giveBack.errors.reason" class="text-xs text-rose-700">{{ giveBack.errors.reason }}</small>
                        <button type="submit" class="ui-button min-h-11 px-4" :disabled="giveBack.processing"><Undo2 class="h-4 w-4" aria-hidden="true" />Return to the case manager</button>
                    </form>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <div class="space-y-4">
                    <section class="ui-panel p-5" aria-labelledby="facts-title">
                        <h2 id="facts-title" class="font-bold text-slate-900">Case at a glance</h2>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div v-for="[label, value] in facts" :key="label"><dt class="text-xs font-semibold text-slate-600">{{ label }}</dt><dd class="text-slate-900">{{ value }}</dd></div>
                        </dl>
                    </section>
                    <section class="ui-panel p-5" aria-labelledby="cefmu-title">
                        <h2 id="cefmu-title" class="font-bold text-slate-900">CEFMU incidence</h2>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs font-semibold text-slate-600">Classification</dt><dd class="text-slate-900">{{ classificationLabels(caseRecord.classifications).join(', ') || 'Not recorded' }}</dd></div>
                            <div v-for="[label, value] in incidenceRows(caseRecord).filter(([l]) => l !== `Partner's age (current)`)" :key="label"><dt class="text-xs font-semibold text-slate-600">{{ label }}</dt><dd class="text-slate-900">{{ value }}</dd></div>
                        </dl>
                    </section>
                    <section class="ui-panel p-5" aria-labelledby="progress-title">
                        <h2 id="progress-title" class="font-bold text-slate-900">Case progress</h2>
                        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xl font-extrabold text-slate-900">{{ progress.notes }}</p><p class="text-xs text-slate-600">Progress notes</p></div>
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xl font-extrabold text-slate-900">{{ progress.referralsOpen }}<span class="text-sm font-semibold text-slate-500"> / {{ progress.referralsTotal }}</span></p><p class="text-xs text-slate-600">Open referrals</p></div>
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xl font-extrabold text-slate-900">{{ progress.visits }}</p><p class="text-xs text-slate-600">Monitoring visits</p></div>
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-sm font-bold text-slate-900">{{ formatPh(progress.lastNoteAt, 'dateShort', 'None yet') }}</p><p class="text-xs text-slate-600">Last progress note</p></div>
                        </div>
                        <div v-if="kavat" class="mt-3 rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm">
                            <b>KaVAT ({{ titleCase(kavat.occasion) }}, {{ formatPh(kavat.administered_at, 'date') }}):</b> final protection score {{ kavat.final_score }} - {{ kavat.priority }}
                        </div>
                        <p v-if="grants?.count" class="mt-3 text-sm text-slate-700">Grants released: <b>{{ new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(grants.total) }}</b> ({{ grants.count }})</p>
                        <ul v-if="forms?.length" class="mt-3 grid gap-2 sm:grid-cols-3">
                            <li v-for="form in forms" :key="form.type" class="rounded-xl border border-slate-200 p-3 text-sm"><p class="font-semibold text-slate-900">{{ form.title }}</p><p class="text-xs text-slate-600">{{ form.status }}<template v-if="form.updated_at"> · {{ formatPh(form.updated_at, 'dateShort') }}</template></p></li>
                        </ul>
                    </section>
                    <section class="ui-panel p-5" aria-labelledby="history-title">
                        <h2 id="history-title" class="font-bold text-slate-900">Status history</h2>
                        <ol class="mt-3 space-y-2 text-sm">
                            <li v-for="(item, index) in activity" :key="index" class="flex justify-between gap-3 border-b border-slate-100 pb-2"><span>{{ item.from_status ? `${titleCase(item.from_status)} → ` : '' }}<b>{{ titleCase(item.to_status) }}</b></span><time class="text-xs text-slate-600">{{ formatPh(item.created_at, 'dateTimeShort') }}</time></li>
                            <li v-if="!activity.length" class="text-slate-600">No status changes yet.</li>
                        </ol>
                    </section>
                </div>

                <aside class="space-y-4" aria-label="Remarks">
                    <section class="ui-panel p-5" aria-labelledby="remark-title">
                        <h2 id="remark-title" class="font-bold text-slate-900">Remarks to the case manager</h2>
                        <form v-if="canRemark" class="mt-3 space-y-2" data-skip-generic-confirm="true" @submit.prevent="sendRemark">
                            <label class="sr-only" for="remark-body">Remark</label>
                            <textarea id="remark-body" v-model="remark.body" rows="3" maxlength="2000" required class="block w-full rounded-xl border-slate-300 text-sm" placeholder="Guidance, a question or a follow-up for the case manager" :aria-invalid="Boolean(remark.errors.body)"></textarea>
                            <small v-if="remark.errors.body" class="text-xs text-rose-700">{{ remark.errors.body }}</small>
                            <button type="submit" class="ui-button-primary min-h-11 w-full" :disabled="remark.processing || remark.body.trim().length < 2">Send remark as {{ roleLabel }}</button>
                        </form>
                        <RemarksThread class="mt-4" :remarks="remarks" />
                    </section>
                </aside>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
