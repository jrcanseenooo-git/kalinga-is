<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { SAFETY_RESPONSIBLE } from '@/utils/caseOptions';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { registerUnsavedCaseWork } from '@/Security/browserHistory';

export interface RiskHistoryItem {
    assessment: { id:number; assessor?:string; assessment_type:string; assessed_at:string; overall_risk_level:string; status:string; required_action:string|null; responsible_person:string|null; target_date:string|null; next_reassessment_at:string|null; professional_rationale:string|null; safety_plan_status:string; immediate_protection_required:boolean };
    factors: { id:number; domain:string; finding_level:string; is_protective_factor:boolean; notes?:string|null }[];
}
const props=defineProps<{caseId:number; canAssess:boolean; currentRisk:string|null; history:RiskHistoryItem[]; nextReview?:string|null; reviewOverdue?:boolean}>();
const form=useForm({assessment_type:'initial',assessed_at:new Date().toISOString().slice(0,16),overall_risk_level:'',immediate_protection_required:false,safety_plan_status:'not_required',required_action:'',responsible_person:'',target_date:'',next_reassessment_at:'',professional_rationale:'',risk_domains:[] as string[],protective_domains:[] as string[]});
const acknowledgedForm=ref(JSON.stringify(form.data()));
const removeUnsaved=registerUnsavedCaseWork(()=>props.canAssess&&JSON.stringify(form.data())!==acknowledgedForm.value);
onBeforeUnmount(removeUnsaved);
const riskDomains=['Immediate danger','Current safety of child','Continuing risk of harm','Person-of-concern access','Coercion, threat, or intimidation','Household safety','Caregiver protective capacity','Health-related vulnerability','Pregnancy or parenting vulnerability','School disruption','Displacement or runaway risk','Economic dependency','Community or environment risk'];
const protectiveDomains=['Supportive caregiver','Trusted or safe adult','Extended family support','School support','Community support','Service availability','Child strengths'];
const saved=ref(''); const errorSummary=ref<HTMLElement|null>(null);
const errors=computed(()=>Object.values(form.errors).filter(Boolean));
const pendingFinal=ref(false);
function save(status:'draft'|'final') {
 if(form.processing)return;
 pendingFinal.value=false; saved.value='';
 form.transform(data=>({...data,status,factors:[...data.risk_domains.map(domain=>({domain,finding_level:'concern',is_protective_factor:false})),...data.protective_domains.map(domain=>({domain,finding_level:'no_concern',is_protective_factor:true}))]}))
 .post(route('cases.risk-assessments.store',props.caseId),{
  preserveScroll:true,
  onSuccess:()=>{saved.value=status==='final'?'Safety and risk assessment finalized.':'Safety and risk assessment draft saved.';form.reset('required_action','responsible_person','target_date','next_reassessment_at','professional_rationale','risk_domains','protective_domains');acknowledgedForm.value=JSON.stringify(form.data());},
  onError:async()=>{await nextTick();errorSummary.value?.focus();},
 });
}
const orderedHistory=computed(()=>[...props.history].sort((a,b)=>b.assessment.assessed_at.localeCompare(a.assessment.assessed_at)||b.assessment.id-a.assessment.id));
const human=(value:string)=>value.replaceAll('_',' ');
</script>

<template>
 <div class="space-y-6">
  <header class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-900">Safety and risk assessment</h2><p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">Record professional judgment, protective strengths and protection actions. This assessment does not calculate or predict risk.</p></div><StatusBadge :label="currentRisk ? `Current finalized risk: ${currentRisk}` : 'No finalized risk recorded'" :tone="['high','critical'].includes(currentRisk??'')?'danger':'neutral'"/></header>
  <p v-if="saved" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{saved}}</p>
  <p v-if="nextReview" class="rounded-md border p-3 text-sm" :class="reviewOverdue?'border-rose-200 bg-rose-50 text-rose-900':'border-amber-200 bg-amber-50 text-amber-900'">{{reviewOverdue?'Reassessment overdue - review required.':'Reassessment scheduled.'}} {{nextReview}} UTC</p>
  <form autocomplete="off" v-if="canAssess" data-skip-generic-confirm="true" @submit.prevent="save('draft')" class="space-y-6">
   <div v-if="errors.length" ref="errorSummary" tabindex="-1" role="alert" class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><h3 class="font-semibold">Review this assessment</h3><ul class="mt-2 list-inside list-disc"><li v-for="error in errors" :key="error">{{error}}</li></ul><p class="mt-2">Your entered assessment remains in this form.</p></div>
   <fieldset :disabled="form.processing" class="min-w-0 space-y-5"><legend class="text-base font-semibold text-slate-900">Assessment record</legend>
    <div class="grid gap-4 sm:grid-cols-2">
     <FormField id="risk-type" label="Assessment type" :error="form.errors.assessment_type"><select id="risk-type" v-model="form.assessment_type" class="ui-field"><option value="initial">Initial assessment</option><option value="reassessment">Reassessment</option><option value="emergency">Emergency assessment</option><option value="pre_closure">Pre-closure assessment</option><option value="other">Other</option></select></FormField>
     <FormField id="risk-assessed" label="Assessment date and time" :error="form.errors.assessed_at" hint="Existing system time convention (UTC)." required><input id="risk-assessed" v-model="form.assessed_at" type="datetime-local" required class="ui-field" aria-describedby="risk-assessed-help"></FormField>
     <FormField id="risk-level" label="Overall current risk" :error="form.errors.overall_risk_level" required><select id="risk-level" v-model="form.overall_risk_level" required class="ui-field"><option value="">Choose the assessed risk level</option><option value="low">Low</option><option value="moderate">Moderate</option><option value="high">High</option><option value="critical">Critical</option></select></FormField>
     <FormField id="risk-plan" label="Safety plan status" :error="form.errors.safety_plan_status"><select id="risk-plan" v-model="form.safety_plan_status" class="ui-field"><option value="not_required">Safety plan not required</option><option value="required">Safety plan required</option><option value="current">Current safety plan in place</option></select></FormField>
    </div>
    <label class="flex min-h-11 items-center gap-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-900"><input v-model="form.immediate_protection_required" type="checkbox" class="rounded border-slate-400">Immediate protection is required</label>
    <div class="grid items-start gap-5 lg:grid-cols-2">
     <fieldset class="min-w-0 rounded-md border border-slate-200 p-4"><legend class="px-1 font-semibold text-slate-900">Risk domains to review</legend><p class="mb-3 text-sm text-slate-600">Select domains with recorded concerns.</p><label v-for="domain in riskDomains" :key="domain" class="flex min-h-11 items-center gap-3 border-b border-slate-100 py-2 text-sm text-slate-800"><input v-model="form.risk_domains" :value="domain" type="checkbox" class="rounded border-slate-400">{{domain}}</label></fieldset>
     <div class="space-y-5"><fieldset class="min-w-0 rounded-md border border-emerald-200 p-4"><legend class="px-1 font-semibold text-slate-900">Strengths and protective factors</legend><label v-for="domain in protectiveDomains" :key="domain" class="flex min-h-11 items-center gap-3 border-b border-slate-100 py-2 text-sm text-slate-800"><input v-model="form.protective_domains" :value="domain" type="checkbox" class="rounded border-slate-400">{{domain}}</label></fieldset>
      <FormField id="risk-rationale" label="Professional rationale" :error="form.errors.professional_rationale" hint="Record key concerns and protective factors considered."><textarea id="risk-rationale" v-model="form.professional_rationale" rows="5" maxlength="5000" class="ui-field"/></FormField>
     </div>
    </div>
    <fieldset class="min-w-0 space-y-4 rounded-md border border-slate-200 p-4"><legend class="px-1 font-semibold text-slate-900">Protection actions and review</legend>
     <FormField id="risk-action" label="Required protection action" :error="form.errors.required_action" hint="For high or critical risk, record immediate protection needs or an action."><textarea id="risk-action" v-model="form.required_action" rows="3" maxlength="5000" class="ui-field" aria-describedby="risk-action-help"/></FormField>
     <div class="grid gap-4 sm:grid-cols-2"><FormField id="risk-responsible" label="Responsible person or office" :error="form.errors.responsible_person"><OptionSelect id="risk-responsible" v-model="form.responsible_person" :options="SAFETY_RESPONSIBLE" /></FormField><FormField id="risk-target" label="Action target date" :error="form.errors.target_date"><input id="risk-target" v-model="form.target_date" type="date" class="ui-field"></FormField><FormField id="risk-review" label="Next reassessment date and time" :error="form.errors.next_reassessment_at" hint="Existing system time convention (UTC)."><input id="risk-review" v-model="form.next_reassessment_at" type="datetime-local" class="ui-field" aria-describedby="risk-review-help"></FormField></div>
    </fieldset>
    <div class="flex flex-wrap gap-3"><button class="ui-button" :disabled="form.processing">Save draft</button><button type="button" class="ui-button-primary" :disabled="form.processing" @click="pendingFinal=true">Review and finalize</button></div>
    <div v-if="pendingFinal" class="rounded-md border border-brand-200 bg-brand-50 p-4"><h3 class="font-semibold text-brand-900">Finalize this professional assessment?</h3><p class="mt-1 text-sm text-brand-900">Finalization adds a permanent history record. Current risk follows the latest finalized assessment date. A draft does not change current risk.</p><div class="mt-3 flex flex-wrap gap-3"><button type="button" class="ui-button" @click="pendingFinal=false">Keep editing</button><button type="button" class="ui-button-primary" @click="save('final')">Finalize assessment</button></div></div>
   </fieldset>
  </form>
  <section aria-labelledby="risk-history-title" class="space-y-3"><h3 id="risk-history-title" class="text-base font-semibold text-slate-900">Assessment history</h3><p class="text-sm text-slate-600">Most recent assessment date first. Drafts are separate from finalized risk.</p>
   <article v-for="item in orderedHistory" :key="item.assessment.id" class="rounded-md border border-slate-200 p-4 text-sm"><div class="flex flex-wrap items-start justify-between gap-2"><div><h4 class="font-semibold capitalize text-slate-900">{{human(item.assessment.assessment_type)}} - {{item.assessment.overall_risk_level}} risk</h4><p class="mt-1 text-slate-600">{{item.assessment.assessed_at}} UTC - assessed by {{item.assessment.assessor || 'Recorded assessor'}}</p></div><StatusBadge :label="item.assessment.status==='final'?'Finalized':'Draft'" :tone="item.assessment.status==='final'?'success':'neutral'"/></div>
    <p v-if="item.assessment.required_action" class="mt-3 whitespace-pre-wrap break-words text-slate-800">Action: {{item.assessment.required_action}}<span v-if="item.assessment.responsible_person"> - {{item.assessment.responsible_person}}</span><span v-if="item.assessment.target_date"> - target {{item.assessment.target_date}}</span></p>
    <details class="mt-3"><summary class="min-h-11 cursor-pointer py-3 font-semibold text-brand-800">Review recorded findings and rationale</summary><p class="mt-2 capitalize">Safety plan: {{human(item.assessment.safety_plan_status)}}. Immediate protection: {{item.assessment.immediate_protection_required?'required':'not recorded as required'}}.</p><p v-if="item.assessment.next_reassessment_at" class="mt-2">Next reassessment: {{item.assessment.next_reassessment_at}} UTC</p><ul class="mt-3 space-y-2"><li v-for="factor in item.factors" :key="factor.id" class="break-words"><span class="font-semibold">{{factor.is_protective_factor?'Protective factor':'Risk domain'}}:</span> {{factor.domain}} - {{human(factor.finding_level)}}<p v-if="factor.notes" class="mt-1 whitespace-pre-wrap">{{factor.notes}}</p></li></ul><p v-if="item.assessment.professional_rationale" class="mt-3 whitespace-pre-wrap break-words">Professional rationale: {{item.assessment.professional_rationale}}</p></details>
   </article><p v-if="!history.length" class="rounded-md border border-dashed border-slate-300 p-5 text-sm text-slate-600">No dedicated safety and risk assessment has been recorded.</p>
  </section>
 </div>
</template>
