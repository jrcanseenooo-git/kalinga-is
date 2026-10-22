<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { OUTCOME_REQUIRED, REASON_REQUIRED, stepLabel } from '@/utils/referralTracking';

const props = withDefaults(defineProps<{ referralId: number; status: string; allowedStatuses: string[]; compact?: boolean }>(), { compact: false });
const emit = defineEmits<{ recorded: [] }>();
const form = useForm({ status: '', expected_status: props.status, outcome: '', reason: '', remarks: '' });
const needsOutcome = computed(() => OUTCOME_REQUIRED.includes(form.status));
const needsReason = computed(() => REASON_REQUIRED.includes(form.status));
watch(() => props.status, status => { form.reset(); form.expected_status = status; });
function recordStatus() {
    form.transform(data => ({ status: data.status, expected_status: data.expected_status, remarks: data.remarks || null,
        outcome: needsOutcome.value ? data.outcome : null, reason: needsReason.value ? data.reason : null }))
        .patch(route('referrals.transition', props.referralId), { preserveScroll: true, onSuccess: () => emit('recorded') });
}
const errorText = computed(() => { const errors = form.errors as Record<string, string | undefined>; return errors.status || errors.outcome || errors.reason || errors.form || ''; });
const fieldId = (name: string) => `referral-${name}-${props.referralId}`;
</script>

<template>
    <form v-if="allowedStatuses.length" :aria-label="`Update referral ${referralId}`" :class="compact ? '' : 'mt-4 border-t border-slate-200 pt-4'" @submit.prevent="recordStatus">
        <p v-if="!compact" class="text-sm text-slate-600">Record the actual hand-off or response after following the approved referral process.</p>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end" :class="compact ? '' : 'mt-3'">
            <div class="min-w-0 flex-1">
                <label :for="fieldId('status')" class="text-sm font-bold text-slate-800" :class="compact ? 'sr-only' : ''">Next referral status</label>
                <select :id="fieldId('status')" v-model="form.status" required :disabled="form.processing" class="w-full rounded-xl border-slate-300 text-sm" :class="compact ? 'min-h-10' : 'mt-1 min-h-11'">
                    <option value="">{{ compact ? 'Next status' : 'Select recorded status' }}</option>
                    <option v-for="status in allowedStatuses" :key="status" :value="status">{{ stepLabel(status) }}</option>
                </select>
            </div>
            <button type="submit" :disabled="form.processing || !form.status" class="shrink-0 rounded-xl bg-brand-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50" :class="compact ? 'min-h-10' : 'min-h-11'">{{ form.processing ? 'Recording...' : 'Record status' }}</button>
        </div>
        <div v-if="form.status" class="mt-2 space-y-2">
            <div v-if="needsOutcome">
                <label :for="fieldId('outcome')" class="text-xs font-semibold text-slate-700">Outcome <span aria-hidden="true" class="text-rose-700">*</span></label>
                <textarea :id="fieldId('outcome')" v-model="form.outcome" required rows="2" maxlength="5000" class="ui-field mt-1 text-sm" placeholder="What service did the client actually receive?" />
            </div>
            <div v-if="needsReason">
                <label :for="fieldId('reason')" class="text-xs font-semibold text-slate-700">Reason <span aria-hidden="true" class="text-rose-700">*</span></label>
                <textarea :id="fieldId('reason')" v-model="form.reason" required rows="2" maxlength="2000" class="ui-field mt-1 text-sm" :placeholder="form.status === 'UNAVAILABLE' ? 'Why is the service unavailable? This informs the service-gap summary.' : 'Why did the referral not proceed?'" />
            </div>
            <div>
                <label :for="fieldId('remarks')" class="text-xs font-semibold text-slate-700">Remarks (optional)</label>
                <textarea :id="fieldId('remarks')" v-model="form.remarks" rows="2" maxlength="2000" class="ui-field mt-1 text-sm" placeholder="Operational follow-up only, for example who confirmed and when." />
            </div>
        </div>
        <div v-if="errorText" class="mt-2">
            <p role="alert" class="text-sm text-rose-800">{{ errorText }}</p>
            <button v-if="form.errors.status" type="button" class="mt-2 min-h-11 rounded-lg border px-3 text-sm font-bold" @click="router.reload({ only: ['items', 'referralTransitions'] })">Refresh referral records</button>
        </div>
    </form>
</template>
