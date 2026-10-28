<script setup lang="ts">
/**
 * Aftercare: the plan written at closure for the child's return to the
 * community, and the follow-up visits after closure (stable, needs support,
 * at risk). A visit that finds the child at risk suggests reopening; the
 * next visit date also shows on the calendar.
 */
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, CalendarClock, CheckCircle2, HeartHandshake, Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/Components/UI/FormField.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { calendarDateLabel, manilaDateTime } from '@/utils/philippineTime';

export type AftercarePlan = { id: number; living_arrangement: string; support_people: string | null; services_to_continue: string | null; risks_to_watch: string | null; responsible_office: string | null;
  follow_up_frequency: string | null; initial_follow_up_at: string | null; follow_up_until: string | null; notes: string | null };
export type AftercareVisit = { id: number; visited_on: string; status: 'stable' | 'needs_support' | 'at_risk'; status_label: string; notes: string; recorded_by: string | null };
export type AftercareData = { plan: AftercarePlan | null; visits: AftercareVisit[]; next_due: string | null; suggest_reopen: boolean; complete: boolean };
const props = defineProps<{ caseId: number; status: string; aftercare: AftercareData; canEdit: boolean }>();
const emit = defineEmits<{ reopen: [] }>();

const FREQUENCIES: Record<string, string> = { monthly: 'Every month', bimonthly: 'Every 2 months', quarterly: 'Every 3 months' };
const STATUSES = [{ value: 'stable', label: 'Stable', hint: 'Safe and doing well' }, { value: 'needs_support', label: 'Needs support', hint: 'Doing fair; some help needed' }, { value: 'at_risk', label: 'At risk', hint: 'Unsafe or back in the union; consider reopening' }] as const;
const today = manilaDateTime().slice(0, 10);
const closed = computed(() => props.status === 'CLOSED');
const editing = ref(!props.aftercare.plan && props.canEdit);
const plan = props.aftercare.plan;
const addMonths = (date: string, months: number) => { const d = new Date(`${date}T00:00:00`); d.setMonth(d.getMonth() + months); return d.toLocaleDateString('en-CA'); };
const form = useForm({
  living_arrangement: plan?.living_arrangement ?? '', support_people: plan?.support_people ?? '', services_to_continue: plan?.services_to_continue ?? '', risks_to_watch: plan?.risks_to_watch ?? '',
  responsible_office: plan?.responsible_office ?? '', follow_up_frequency: plan?.follow_up_frequency ?? 'monthly',
  initial_follow_up_at: plan?.initial_follow_up_at ?? addMonths(today, 1), follow_up_until: plan?.follow_up_until ?? addMonths(today, 6), notes: plan?.notes ?? '',
});
const savePlan = () => form.put(route('cases.aftercare.plan', props.caseId), { preserveScroll: true, onSuccess: () => { editing.value = false; } });
const visit = useForm({ visited_on: today, status: '' as string, notes: '' });
const addingVisit = ref(false);
const saveVisit = () => visit.post(route('cases.aftercare.visits.store', props.caseId), { preserveScroll: true, onSuccess: () => { visit.reset(); addingVisit.value = false; } });
const tone = (status: string) => (status === 'stable' ? 'success' as const : status === 'needs_support' ? 'warning' as const : 'danger' as const);
const nextOverdue = computed(() => Boolean(props.aftercare.next_due && props.aftercare.next_due < today));
</script>

<template>
  <section class="ui-panel overflow-hidden p-0" aria-labelledby="aftercare-title">
    <header class="ui-panel-header flex-wrap gap-2 bg-emerald-50/60">
      <div class="flex min-w-0 items-start gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-800"><HeartHandshake class="h-5 w-5" aria-hidden="true" /></span>
        <div class="min-w-0"><h3 id="aftercare-title" class="ui-section-title">Aftercare</h3><p class="text-xs text-slate-600">The plan for the child's return to the community, and the follow-up visits after closure.</p></div>
      </div>
      <button v-if="canEdit && aftercare.plan && !editing" type="button" class="ui-button min-h-11" @click="editing = true"><Pencil class="h-4 w-4" aria-hidden="true" />Update the plan</button>
    </header>

    <div class="space-y-4 p-4">
      <div v-if="aftercare.suggest_reopen" role="alert" class="flex flex-wrap items-start gap-3 rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900">
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1"><b>The last follow-up visit found the child at risk.</b> Consider reopening the case so the child gets protection and services again. Your supervisor was told.</p>
        <button v-if="closed" type="button" class="ui-button min-h-11" @click="emit('reopen')">Go to reopening</button>
      </div>
      <p v-if="aftercare.plan && closed && aftercare.next_due" class="flex items-center gap-2 text-sm font-semibold" :class="nextOverdue ? 'text-rose-700' : 'text-slate-800'"><CalendarClock class="h-4 w-4" aria-hidden="true" />Next follow-up visit: {{ calendarDateLabel(aftercare.next_due) }}{{ nextOverdue ? ' (past due)' : '' }}</p>
      <p v-else-if="aftercare.complete" class="flex items-center gap-2 text-sm font-semibold text-emerald-800"><CheckCircle2 class="h-4 w-4" aria-hidden="true" />The follow-up period has ended.</p>

      <!-- The plan -->
      <form v-if="editing" class="kalinga-form grid gap-3 sm:grid-cols-2" data-skip-generic-confirm="true" @submit.prevent="savePlan">
        <p v-if="!aftercare.plan" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-950 sm:col-span-2">Write the aftercare plan before the case is closed. It guides the follow-up visits after closure.</p>
        <FormField id="aftercare-living" label="Where and with whom the child will live" :error="form.errors.living_arrangement" required class="sm:col-span-2"><input id="aftercare-living" v-model="form.living_arrangement" maxlength="255" required class="ui-field" placeholder="For example: with the mother in Barangay Bulanao"></FormField>
        <FormField id="aftercare-support" label="People who will support the child" :error="form.errors.support_people"><textarea id="aftercare-support" v-model="form.support_people" rows="3" maxlength="3000" class="ui-field"></textarea></FormField>
        <FormField id="aftercare-services" label="Services that continue" :error="form.errors.services_to_continue"><textarea id="aftercare-services" v-model="form.services_to_continue" rows="3" maxlength="3000" class="ui-field" placeholder="For example: ALS classes, counseling"></textarea></FormField>
        <FormField id="aftercare-risks" label="Risks to watch" :error="form.errors.risks_to_watch"><textarea id="aftercare-risks" v-model="form.risks_to_watch" rows="3" maxlength="3000" class="ui-field"></textarea></FormField>
        <FormField id="aftercare-office" label="Office responsible for the follow-up" :error="form.errors.responsible_office"><input id="aftercare-office" v-model="form.responsible_office" maxlength="180" class="ui-field" placeholder="For example: C/MSWDO"></FormField>
        <FormField id="aftercare-frequency" label="Follow-up visits" :error="form.errors.follow_up_frequency" required><select id="aftercare-frequency" v-model="form.follow_up_frequency" class="ui-field"><option v-for="(label, value) in FREQUENCIES" :key="value" :value="value">{{ label }}</option></select></FormField>
        <FormField id="aftercare-first" label="First follow-up visit" :error="form.errors.initial_follow_up_at" required><input id="aftercare-first" v-model="form.initial_follow_up_at" type="date" required class="ui-field"></FormField>
        <FormField id="aftercare-until" label="Follow up until" :error="form.errors.follow_up_until" required><input id="aftercare-until" v-model="form.follow_up_until" type="date" :min="form.initial_follow_up_at" required class="ui-field"></FormField>
        <FormField id="aftercare-notes" label="Other notes (optional)" :error="form.errors.notes" class="sm:col-span-2"><textarea id="aftercare-notes" v-model="form.notes" rows="2" maxlength="5000" class="ui-field"></textarea></FormField>
        <div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" class="ui-button-primary min-h-11" :disabled="form.processing">Save the aftercare plan</button><button v-if="aftercare.plan" type="button" class="ui-button min-h-11" @click="form.reset(); editing = false">Cancel</button></div>
      </form>
      <dl v-else-if="aftercare.plan" class="grid gap-3 text-sm sm:grid-cols-2">
        <div class="rounded-lg bg-slate-50 p-3 sm:col-span-2"><dt class="text-xs font-semibold text-slate-600">Living with</dt><dd class="mt-0.5 text-slate-900">{{ aftercare.plan.living_arrangement }}</dd></div>
        <div v-if="aftercare.plan.support_people" class="rounded-lg bg-slate-50 p-3"><dt class="text-xs font-semibold text-slate-600">Support people</dt><dd class="mt-0.5 whitespace-pre-wrap">{{ aftercare.plan.support_people }}</dd></div>
        <div v-if="aftercare.plan.services_to_continue" class="rounded-lg bg-slate-50 p-3"><dt class="text-xs font-semibold text-slate-600">Services that continue</dt><dd class="mt-0.5 whitespace-pre-wrap">{{ aftercare.plan.services_to_continue }}</dd></div>
        <div v-if="aftercare.plan.risks_to_watch" class="rounded-lg bg-slate-50 p-3"><dt class="text-xs font-semibold text-slate-600">Risks to watch</dt><dd class="mt-0.5 whitespace-pre-wrap">{{ aftercare.plan.risks_to_watch }}</dd></div>
        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs font-semibold text-slate-600">Follow-up</dt><dd class="mt-0.5">{{ FREQUENCIES[aftercare.plan.follow_up_frequency ?? ''] ?? 'Not set' }}, from {{ aftercare.plan.initial_follow_up_at ? calendarDateLabel(aftercare.plan.initial_follow_up_at) : '-' }} until {{ aftercare.plan.follow_up_until ? calendarDateLabel(aftercare.plan.follow_up_until) : '-' }}<span v-if="aftercare.plan.responsible_office" class="block text-xs text-slate-600">By {{ aftercare.plan.responsible_office }}</span></dd></div>
      </dl>
      <p v-else class="text-sm text-slate-600">No aftercare plan yet.</p>

      <!-- Follow-up visits -->
      <section v-if="aftercare.plan" aria-labelledby="aftercare-visits-title" class="space-y-3 border-t border-[var(--color-line)] pt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h4 id="aftercare-visits-title" class="font-semibold text-slate-900">Follow-up visits ({{ aftercare.visits.length }})</h4>
          <button v-if="canEdit && closed && !addingVisit" type="button" class="ui-button min-h-11" @click="addingVisit = true"><Plus class="h-4 w-4" aria-hidden="true" />Record a follow-up visit</button>
        </div>
        <p v-if="!closed" class="text-sm text-slate-600">Follow-up visits are recorded after the case is closed.</p>
        <form v-if="addingVisit" class="kalinga-form space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4" data-skip-generic-confirm="true" @submit.prevent="saveVisit">
          <FormField id="aftercare-visit-date" label="Date of the visit" :error="visit.errors.visited_on" required><input id="aftercare-visit-date" v-model="visit.visited_on" type="date" :max="today" required class="ui-field max-w-xs"></FormField>
          <fieldset><legend class="text-sm font-semibold text-slate-800">How is the child? <span class="text-rose-700" aria-hidden="true">*</span></legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
              <label v-for="option in STATUSES" :key="option.value" class="flex min-h-14 cursor-pointer items-start gap-2 rounded-xl border-2 p-3 text-sm" :class="visit.status === option.value ? (option.value === 'stable' ? 'border-emerald-600 bg-emerald-50' : option.value === 'needs_support' ? 'border-amber-500 bg-amber-50' : 'border-rose-600 bg-rose-50') : 'border-slate-300 bg-white'">
                <input v-model="visit.status" type="radio" name="aftercare-status" :value="option.value" required class="mt-0.5 h-4 w-4 shrink-0"><span><b class="block">{{ option.label }}</b><span class="text-xs text-slate-600">{{ option.hint }}</span></span>
              </label>
            </div>
            <p v-if="visit.errors.status" class="mt-1 text-sm text-rose-700">{{ visit.errors.status }}</p>
          </fieldset>
          <FormField id="aftercare-visit-notes" label="What was seen and discussed" :error="visit.errors.notes" required><textarea id="aftercare-visit-notes" v-model="visit.notes" rows="3" minlength="5" maxlength="5000" required class="ui-field"></textarea></FormField>
          <p v-if="visit.status === 'at_risk'" class="rounded-lg bg-rose-50 p-2 text-xs text-rose-900">Saving "At risk" tells your supervisor and suggests reopening the case.</p>
          <div class="flex flex-wrap gap-2"><button type="submit" class="ui-button-primary min-h-11" :disabled="visit.processing">Save the visit</button><button type="button" class="ui-button min-h-11" @click="visit.reset(); addingVisit = false">Cancel</button></div>
        </form>
        <ol v-if="aftercare.visits.length" class="space-y-2">
          <li v-for="item in aftercare.visits" :key="item.id" class="rounded-xl border border-slate-200 p-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold">{{ calendarDateLabel(item.visited_on) }}</span><StatusBadge :label="item.status_label" :tone="tone(item.status)" dot /></div>
            <p class="mt-1 whitespace-pre-wrap text-slate-700">{{ item.notes }}</p>
            <p v-if="item.recorded_by" class="mt-1 text-xs text-slate-600">By {{ item.recorded_by }}</p>
          </li>
        </ol>
      </section>
    </div>
  </section>
</template>
