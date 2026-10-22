<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { referralTone } from '@/utils/caseDisplay';
import { philippineTimestamp } from '@/utils/philippineTime';
import { REFERRAL_END_STATES, REFERRAL_STEPS, followUp, history, stepLabel, stepReached, type ReferralTargets, type TrackedReferral } from '@/utils/referralTracking';

const props = defineProps<{ referral: TrackedReferral; targets: ReferralTargets }>();
const reached = computed(() => stepReached(props.referral));
const stopped = computed(() => REFERRAL_END_STATES.includes(props.referral.status));
const due = computed(() => followUp(props.referral, props.targets));
const events = computed(() => [...history(props.referral)].reverse());
const derived = computed(() => !props.referral.events?.length);
</script>

<template>
  <div class="space-y-5">
    <!-- Lifecycle -->
    <div>
      <ol class="flex items-start" aria-label="Referral progress">
        <li v-for="(step, index) in REFERRAL_STEPS" :key="step" class="relative flex flex-1 flex-col items-center text-center" :aria-current="referral.status === step ? 'step' : undefined">
          <span v-if="index > 0" aria-hidden="true" class="absolute right-1/2 top-3 h-0.5 w-full" :class="index <= reached && !(stopped && index > reached) ? 'bg-brand-600' : 'bg-slate-200'" />
          <span class="relative z-10 grid h-6 w-6 place-items-center rounded-full border-2 text-[10px] font-bold"
            :class="index < reached || (index === reached && step === 'COMPLETED') ? 'border-brand-600 bg-brand-600 text-white' : index === reached ? (stopped ? 'border-slate-400 bg-white text-slate-600' : 'border-brand-600 bg-white text-brand-700') : 'border-slate-300 bg-white text-slate-500'">{{ index + 1 }}</span>
          <span class="mt-1 text-[11px] leading-tight" :class="index === reached ? 'font-semibold text-slate-900' : 'text-slate-600'">{{ stepLabel(step) }}</span>
        </li>
      </ol>
      <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
        <StatusBadge :label="stepLabel(referral.status)" :tone="referralTone(referral.status)" dot />
        <span v-if="stopped" class="text-slate-600">Stopped after {{ stepLabel(REFERRAL_STEPS[reached]) }}</span>
        <StatusBadge v-if="due" :label="due.label" :tone="due.tone" />
      </div>
    </div>

    <div v-if="referral.outcome || referral.status_reason" class="rounded-xl border p-3 text-sm" :class="referral.outcome ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950'">
      <p class="text-xs font-semibold">{{ referral.outcome ? 'Recorded outcome' : 'Recorded reason' }}</p>
      <p class="mt-1 whitespace-pre-wrap break-words">{{ referral.outcome || referral.status_reason }}</p>
    </div>

    <!-- History -->
    <div>
      <h4 class="text-xs font-semibold text-slate-600">Status history</h4>
      <ol class="referral-history mt-2 space-y-3">
        <li v-for="(event, index) in events" :key="index" class="relative pl-6">
          <span aria-hidden="true" class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full ring-4" :class="index === 0 ? 'bg-brand-600 ring-brand-100' : 'bg-slate-300 ring-slate-100'" />
          <p class="text-sm font-medium text-slate-900">{{ event.from_status ? `${stepLabel(event.from_status)} to ${stepLabel(event.to_status)}` : stepLabel(event.to_status) }}</p>
          <p class="text-xs text-slate-600">{{ event.created_at ? philippineTimestamp(event.created_at) : 'Date not recorded' }}<template v-if="event.actor"> - {{ event.actor }}</template></p>
          <p v-if="event.outcome" class="mt-1 whitespace-pre-wrap break-words text-sm text-emerald-900"><span class="font-semibold">Outcome:</span> {{ event.outcome }}</p>
          <p v-if="event.reason" class="mt-1 whitespace-pre-wrap break-words text-sm text-amber-900"><span class="font-semibold">Reason:</span> {{ event.reason }}</p>
          <p v-if="event.remarks" class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-700">{{ event.remarks }}</p>
        </li>
      </ol>
      <p v-if="derived" class="mt-2 text-xs text-slate-600">Recorded before status history was kept: shown from the stored referral dates.</p>
    </div>
  </div>
</template>

<style scoped>
.referral-history { position: relative; }
.referral-history::before { content: ''; position: absolute; left: 4px; top: .5rem; bottom: .5rem; width: 2px; background: var(--color-line); }
</style>
