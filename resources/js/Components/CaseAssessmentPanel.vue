<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { registerUnsavedCaseWork, reloadAfterAccessDenial } from '@/Security/browserHistory';
import type { PageProps } from '@/types';
import FormField from '@/Components/UI/FormField.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';

const props=defineProps<{caseId:number; active:boolean}>();
const page=usePage<PageProps>();
const canManage=computed(()=>page.props.auth.permissions.includes('assessments.manage'));
const canRestrict=computed(()=>page.props.auth.permissions.includes('assessments.restricted_view'));
const definitions=[
 {key:'presenting_situation',label:'Presenting situation'}, {key:'family_relationships',label:'Family relationships'},
 {key:'cefmu_context',label:'CEFMU context'}, {key:'economic_education',label:'Economic and education'},
 {key:'health_srh',label:'Health and SRH'}, {key:'psychosocial_environment',label:'Psychosocial environment'},
 {key:'strengths_support',label:'Strengths and support'}, {key:'perspectives',label:'Perspectives'},
 {key:'professional_assessment',label:'Professional assessment'}, {key:'case_formulation',label:'Case formulation'},
 {key:'recommendations',label:'Recommendations'},
] as const;
type SectionKey=typeof definitions[number]['key'];
type Action='for_review'|'reviewed'|'returned_for_revision'|'final';
interface AssessmentSummary {id:number; version:number; status:string; record_type:string; assessment_date:string; author_name:string; author_role?:string; reviewer_name?:string|null; reviewer_role?:string|null; is_current:boolean; previous_assessment_id:number|null; amendment_reason?:string|null; finalized_at?:string|null; returned_at?:string|null; returned_by_name?:string|null; review_comments?:string|null}
interface SectionDraft {section_key:SectionKey; completion_state:string; source_type:string; facts:string; reported_information:string; observations:string; conflicting_information:string; analysis:string; restricted:boolean}
interface SectionRecord extends Partial<Omit<SectionDraft,'section_key'>> {section_key:SectionKey; placeholder?:string}
interface AssessmentDetail {assessment:AssessmentSummary; sections:SectionRecord[]; history:{event:string; comments:string|null; actor_name:string; actor_role:string; created_at:string}[]; allowed_actions:{save_sections:boolean; submit:boolean; review:boolean; return:boolean; finalize:boolean; new_version:boolean}}
const records=ref<AssessmentSummary[]>([]); const selected=ref<AssessmentDetail|null>(null);
const loaded=ref(false); const busy=ref(false); const message=ref(''); const error=ref('');
const panelRoot=ref<HTMLElement|null>(null);
const fieldErrors=ref<Record<string,string>>({}); const errorSummary=ref<HTMLElement|null>(null);
const sectionKey=ref<SectionKey>('presenting_situation'); const draft=ref<SectionDraft|null>(null);
const drafts=ref<Record<string,SectionDraft>>({}); const baselines=new Map<string,string>();
const date=ref(new Date().toISOString().slice(0,10)); const versionType=ref('full_revision'); const reason=ref('');
const comments=ref(''); const pendingAction=ref<Action|null>(null); let desiredRecord:number|undefined;
const textFields=[{key:'facts',label:'Verified facts'},{key:'reported_information',label:'Reported information'},{key:'observations',label:'Observed information'},{key:'conflicting_information',label:'Conflicting information'},{key:'analysis',label:'Professional analysis'}] as const;
const sources=[['child','Child'],['parent_caregiver','Parent or caregiver'],['household_member','Household member'],['school','School'],['health_provider','Health provider'],['barangay_lgu','Barangay or LGU'],['law_enforcement','Law enforcement'],['service_provider','Service provider'],['document_record','Document or record'],['worker_observation','Worker observation'],['other','Other']];
const human=(value:string)=>value.replaceAll('_',' ');
const calendarDate=(value:string)=>{
 const date=new Date(`${value.slice(0,10)}T00:00:00Z`);
 return Number.isNaN(date.getTime())?'Date not recorded':date.toLocaleDateString('en-PH',{timeZone:'UTC',dateStyle:'medium'});
};
const sections=computed(()=>selected.value?.assessment.record_type==='addendum'?definitions.filter(item=>item.key==='professional_assessment'):definitions);
const section=(key:SectionKey)=>selected.value?.sections.find(item=>item.section_key===key);
const cacheKey=(key:SectionKey)=>`${selected.value?.assessment.id}:${key}`;
const isDirty=(key:string)=>JSON.stringify(drafts.value[key])!==baselines.get(key);
const dirtyKeys=computed(()=>Object.keys(drafts.value).filter(isDirty));
const completed=computed(()=>sections.value.filter(item=>section(item.key)?.completion_state==='completed').length);
const currentSection=computed(()=>section(sectionKey.value));
const editable=computed(()=>Boolean(canManage.value&&selected.value?.allowed_actions.save_sections&&!currentSection.value?.placeholder));

function openSection(key:SectionKey) {
 sectionKey.value=key;
 if(!selected.value||section(key)?.placeholder){draft.value=null;return;}
 const id=cacheKey(key); const existing=section(key);
 if(!drafts.value[id]){
  const value:SectionDraft={section_key:key,completion_state:existing?.completion_state||'partially_completed',source_type:existing?.source_type||'',facts:existing?.facts||'',reported_information:existing?.reported_information||'',observations:existing?.observations||'',conflicting_information:existing?.conflicting_information||'',analysis:existing?.analysis||'',restricted:Boolean(existing?.restricted)};
  drafts.value[id]=value;baselines.set(id,JSON.stringify(value));
 }
 draft.value=drafts.value[id]; fieldErrors.value={};
}
async function fail(cause:unknown) {
 if(axios.isAxiosError(cause)&&cause.response?.status===403){reloadAfterAccessDenial();return;}
 fieldErrors.value={};
 if(axios.isAxiosError(cause)&&cause.response?.status===422){
  const errors=cause.response.data?.errors;
  if(errors&&typeof errors==='object')for(const [key,value]of Object.entries(errors))if(Array.isArray(value)&&typeof value[0]==='string')fieldErrors.value[key]=value[0];
 }
 error.value=Object.keys(fieldErrors.value).length?'Review the fields below. Your entered work remains in this page.':'The operation could not be completed. Check your connection and current case access, then retry. Your unsaved entries remain in this page.';
 await nextTick();errorSummary.value?.focus();
}
async function fetchRecords(id?:number) {
 const firstLoad=!loaded.value;
 const list=await axios.get<{assessments:AssessmentSummary[]}>(route('cases.comprehensive-assessments.index',props.caseId));
 if(!Array.isArray(list.data.assessments))throw new Error('Assessment response unavailable');
 records.value=list.data.assessments;
 const wanted=desiredRecord??id??selected.value?.assessment.id??records.value[0]?.id;
 desiredRecord=undefined;
 selected.value=wanted?(await axios.get<AssessmentDetail>(route('cases.comprehensive-assessments.show',[props.caseId,wanted]))).data:null;
 if(selected.value){if(!sections.value.some(item=>item.key===sectionKey.value))sectionKey.value=sections.value[0].key;openSection(sectionKey.value);}
 loaded.value=true;
 if(firstLoad&&props.active){await nextTick();panelRoot.value?.scrollIntoView({block:'start'});}
}
async function load(id?:number) {
 if(id)desiredRecord=id;
 if(busy.value)return;
 busy.value=true;error.value='';message.value='';
 try{await fetchRecords(id);}catch(cause){await fail(cause);}finally{busy.value=false;}
}
async function createVersion(revision=false) {
 if(busy.value||!canManage.value)return;
 busy.value=true;error.value='';message.value='';fieldErrors.value={};
 try{
  await axios.post(route('cases.comprehensive-assessments.store',props.caseId),{assessment_date:date.value,record_type:revision?versionType.value:'full_revision',amendment_reason:revision?reason.value:null,previous_assessment_id:revision?selected.value?.assessment.id:null});
  selected.value=null;loaded.value=false;await fetchRecords();message.value='Assessment draft created.';reason.value='';
 }catch(cause){await fail(cause);}finally{busy.value=false;}
}
async function saveSection() {
 if(busy.value||!editable.value||!selected.value||!draft.value)return;
 busy.value=true;error.value='';message.value='';fieldErrors.value={};const id=selected.value.assessment.id;const key=cacheKey(sectionKey.value);
 try{
  await axios.put(route('cases.comprehensive-assessments.sections.save',[props.caseId,id]),{...draft.value});
  delete drafts.value[key];baselines.delete(key);await fetchRecords(id);message.value='Assessment section saved.';
 }catch(cause){await fail(cause);}finally{busy.value=false;}
}
function requestAction(action:Action) {
 if(selected.value&&dirtyKeys.value.some(key=>key.startsWith(`${selected.value!.assessment.id}:`))){error.value='Save your changed sections before changing this assessment status.';nextTick(()=>errorSummary.value?.focus());return;}
 pendingAction.value=action;
}
async function transition() {
 if(busy.value||!selected.value||!pendingAction.value)return;
 const action=pendingAction.value;pendingAction.value=null;busy.value=true;error.value='';message.value='';fieldErrors.value={};const id=selected.value.assessment.id;
 try{await axios.patch(route('cases.comprehensive-assessments.transition',[props.caseId,id]),{status:action,review_comments:comments.value||null});comments.value='';await fetchRecords(id);message.value='Assessment status updated.';}catch(cause){await fail(cause);}finally{busy.value=false;}
}
const removeUnsaved=registerUnsavedCaseWork(()=>dirtyKeys.value.length>0||Boolean(comments.value.trim()||reason.value.trim()));
onBeforeUnmount(removeUnsaved);
watch(()=>props.active,visible=>{if(visible&&!loaded.value)void load();},{immediate:true});
defineExpose({load});
</script>

<template>
 <div ref="panelRoot" class="scroll-mt-24 space-y-5">
  <header class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-900">Comprehensive assessment</h2><p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">Build a source-attributed assessment across the recorded domains. Safety and risk and child participation retain their separate records.</p></div><button type="button" class="ui-button" :disabled="busy" @click="load()">Load assessments</button></header>
  <p v-if="busy" role="status" class="text-sm text-slate-600">Working on this assessment...</p><p v-if="message" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{message}}</p>
  <div v-if="error" ref="errorSummary" tabindex="-1" role="alert" class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><p class="font-semibold">{{error}}</p><ul v-if="Object.keys(fieldErrors).length" class="mt-2 list-inside list-disc"><li v-for="(value,key) in fieldErrors" :key="key">{{value}}</li></ul></div>
  <p v-if="dirtyKeys.length" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{dirtyKeys.length}} unsaved section(s) retained on this page. Save each changed section online. Reloading or closing can discard unsaved work.</p>
  <nav v-if="records.length" aria-label="Assessment versions" class="flex gap-2 overflow-x-auto pb-2"><button v-for="item in records" :key="item.id" type="button" :disabled="busy" :aria-current="selected?.assessment.id===item.id?'true':undefined" class="min-h-11 shrink-0 rounded-md border p-3 text-left text-sm" :class="selected?.assessment.id===item.id?'border-brand-400 bg-brand-50 text-brand-900':'border-slate-200 text-slate-800'" @click="load(item.id)"><b>Version {{item.version}}</b> - {{human(item.status)}}<span v-if="item.is_current"> - current</span><span class="mt-1 block text-xs">{{item.author_name}} - {{calendarDate(item.assessment_date)}}</span></button></nav>
  <form autocomplete="off" v-if="loaded&&!records.length&&canManage" @submit.prevent="createVersion()" class="rounded-md border border-brand-200 bg-brand-50 p-4"><h3 class="font-semibold text-brand-900">Start comprehensive assessment</h3><p class="mt-1 text-sm text-brand-900">Create an initial full-assessment draft, then save the relevant sections.</p><fieldset :disabled="busy" class="mt-4 flex min-w-0 flex-wrap items-end gap-3"><FormField id="assessment-initial-date" label="Assessment date" :error="fieldErrors.assessment_date" required><input id="assessment-initial-date" v-model="date" type="date" required class="ui-field"></FormField><button class="ui-button-primary">Create initial draft</button></fieldset></form>
  <p v-else-if="loaded&&!records.length" class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-600">No comprehensive assessment has been recorded. Your current permission does not allow creating one.</p>
  <template v-if="selected">
   <section aria-label="Selected assessment record" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold text-slate-900">Version {{selected.assessment.version}} - {{selected.assessment.record_type==='addendum'?'Addendum':'Full assessment revision'}}</h3><StatusBadge :label="human(selected.assessment.status)" :tone="selected.assessment.status==='final'?'success':'neutral'"/></div><p class="mt-2 text-slate-700">Author: {{selected.assessment.author_name}}<span v-if="selected.assessment.author_role"> ({{human(selected.assessment.author_role)}})</span><span v-if="selected.assessment.reviewer_name"> - reviewer: {{selected.assessment.reviewer_name}}<span v-if="selected.assessment.reviewer_role"> ({{human(selected.assessment.reviewer_role)}})</span></span></p><p v-if="selected.assessment.finalized_at" class="mt-1 text-slate-700">Finalized: {{selected.assessment.finalized_at}}</p><p v-if="selected.assessment.previous_assessment_id" class="mt-2 text-slate-700">Based on finalized version {{records.find(item=>item.id===selected!.assessment.previous_assessment_id)?.version||selected.assessment.previous_assessment_id}}. {{selected.assessment.amendment_reason}}</p><div class="mt-3"><p class="font-semibold text-slate-800">{{completed}} / {{sections.length}} sections recorded as completed</p><div class="mt-2 h-2 rounded bg-slate-200" aria-hidden="true"><div class="h-2 rounded bg-brand-600" :style="{width:`${100*completed/sections.length}%`}"/></div><p class="mt-2 text-xs text-slate-600">Recorded section states indicate documentation progress, not assessment quality or safeguarding clearance.</p></div></section>
   <div v-if="selected.assessment.returned_at" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><h3 class="font-semibold">Returned for revision</h3><p class="mt-1">{{selected.assessment.returned_by_name}} - {{selected.assessment.returned_at}}</p><p class="mt-2 whitespace-pre-wrap break-words">{{selected.assessment.review_comments}}</p></div>
   <form autocomplete="off" v-if="selected.allowed_actions.new_version&&canManage" @submit.prevent="createVersion(true)" class="rounded-md border border-brand-200 p-4"><h3 class="font-semibold text-slate-900">Create a revision or addendum</h3><p class="mt-1 text-sm text-slate-600">A full revision copies final sections into a new draft. An addendum supplements the finalized record without replacing it.</p><fieldset :disabled="busy" class="mt-4 grid min-w-0 gap-4 sm:grid-cols-2"><FormField id="assessment-version-type" label="Version type"><select id="assessment-version-type" v-model="versionType" class="ui-field"><option value="full_revision">Full revised assessment</option><option value="addendum">Addendum</option></select></FormField><FormField id="assessment-version-date" label="Assessment date" :error="fieldErrors.assessment_date"><input id="assessment-version-date" v-model="date" type="date" required class="ui-field"></FormField><FormField id="assessment-version-reason" label="Reason for revision or addendum" :error="fieldErrors.amendment_reason" required><textarea id="assessment-version-reason" v-model="reason" required maxlength="2000" rows="3" class="ui-field"/></FormField><div class="self-end"><button class="ui-button-primary">Create draft</button></div></fieldset></form>
   <div class="grid min-w-0 items-start gap-5 lg:grid-cols-[13rem_minmax(0,1fr)]">
    <nav aria-label="Assessment sections" class="flex min-w-0 gap-2 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible"><button v-for="item in sections" :key="item.key" type="button" :disabled="busy" :aria-current="sectionKey===item.key?'step':undefined" class="min-h-11 shrink-0 rounded-md border p-3 text-left text-sm lg:w-full" :class="sectionKey===item.key?'border-brand-400 bg-brand-50 text-brand-900':'border-slate-200 text-slate-800'" @click="openSection(item.key)"><span class="font-semibold">{{item.label}}</span><span class="mt-1 block text-xs capitalize">{{human(section(item.key)?.completion_state||'not_recorded')}}<span v-if="drafts[cacheKey(item.key)]&&isDirty(cacheKey(item.key))"> - unsaved</span></span></button></nav>
    <div class="min-w-0 space-y-4">
     <form autocomplete="off" v-if="editable&&draft" data-skip-generic-confirm="true" @submit.prevent="saveSection" class="rounded-md border border-slate-200 p-4"><fieldset :disabled="busy" class="min-w-0 space-y-4"><legend class="text-base font-semibold text-slate-900">{{definitions.find(item=>item.key===sectionKey)?.label}}</legend><div class="grid gap-4 sm:grid-cols-2"><FormField id="assessment-section-state" label="Section completion state" :error="fieldErrors.completion_state"><select id="assessment-section-state" v-model="draft.completion_state" class="ui-field"><option value="completed">Completed</option><option value="partially_completed">Partially completed</option><option value="deferred">Deferred</option><option value="information_unavailable">Information unavailable</option><option value="not_applicable">Not applicable</option></select></FormField><FormField id="assessment-source" label="Source attribution" :error="fieldErrors.source_type"><select id="assessment-source" v-model="draft.source_type" class="ui-field"><option value="">Source not recorded</option><option v-for="[value,label] in sources" :key="value" :value="value">{{label}}</option></select></FormField></div><label v-if="canRestrict" class="flex min-h-11 items-center gap-3 rounded-md border border-slate-200 p-3 text-sm text-slate-800"><input v-model="draft.restricted" type="checkbox" class="rounded border-slate-400">Restrict section access</label><FormField v-for="field in textFields" :id="`assessment-${field.key}`" :key="field.key" :label="field.label" :error="fieldErrors[field.key]"><textarea :id="`assessment-${field.key}`" v-model="draft[field.key]" :aria-invalid="Boolean(fieldErrors[field.key])" :aria-describedby="fieldErrors[field.key] ? `assessment-${field.key}-help` : undefined" rows="4" maxlength="5000" class="ui-field"/></FormField><button class="ui-button-primary">Save this section</button></fieldset></form>
     <article v-else class="rounded-md border border-slate-200 p-4 text-sm"><h3 class="font-semibold text-slate-900">{{definitions.find(item=>item.key===sectionKey)?.label}}</h3><p v-if="currentSection?.placeholder" class="mt-3 text-slate-600">Restricted section. Detailed information is unavailable under your current permission.</p><dl v-else-if="currentSection" class="mt-4 space-y-4"><div><dt class="font-semibold text-slate-800">Recorded state and source</dt><dd class="mt-1 capitalize text-slate-700">{{human(currentSection.completion_state || 'not_recorded')}} - {{human(currentSection.source_type || 'source_not_recorded')}}</dd></div><div v-for="field in textFields" :key="field.key"><dt class="font-semibold text-slate-800">{{field.label}}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-slate-700">{{currentSection[field.key]||'Not recorded'}}</dd></div></dl><p v-else class="mt-3 text-slate-600">This section has not been recorded.</p></article>
    </div>
   </div>
   <section v-if="selected.assessment.status!=='final'" aria-label="Assessment review workflow" class="rounded-md border border-slate-200 p-4"><h3 class="font-semibold text-slate-900">Independent review</h3><p class="mt-1 text-sm text-slate-600">Save changed sections before submitting. A different authorized reviewer must review and finalize the assessment.</p><FormField id="assessment-review-comment" label="Reviewer comments" :error="fieldErrors.review_comments" hint="Comments are required when returning for revision."><textarea id="assessment-review-comment" v-model="comments" :disabled="busy" rows="3" maxlength="5000" class="ui-field"/></FormField><div class="mt-4 flex flex-wrap gap-3"><button v-if="selected.allowed_actions.submit&&canManage" type="button" :disabled="busy" class="ui-button-primary" @click="requestAction('for_review')">Submit for review</button><button v-if="selected.allowed_actions.review" type="button" :disabled="busy" class="ui-button-primary" @click="requestAction('reviewed')">Mark as reviewed</button><button v-if="selected.allowed_actions.return" type="button" :disabled="busy" class="ui-button" @click="requestAction('returned_for_revision')">Return for revision</button><button v-if="selected.allowed_actions.finalize" type="button" :disabled="busy" class="ui-button-primary" @click="requestAction('final')">Finalize as reviewer</button></div></section>
   <details v-if="selected.history.length" class="rounded-md border border-slate-200 p-4 text-sm"><summary class="min-h-11 cursor-pointer py-3 font-semibold text-slate-900">Workflow history</summary><ol class="mt-3 space-y-4"><li v-for="(event,index) in selected.history" :key="index" class="border-l-2 border-slate-200 pl-3"><p class="font-semibold capitalize">{{human(event.event)}}</p><p class="mt-1 text-slate-600">{{event.actor_name}} ({{human(event.actor_role)}}) - {{event.created_at}}</p><p v-if="event.comments" class="mt-2 whitespace-pre-wrap break-words text-slate-700">{{event.comments}}</p></li></ol></details>
  </template>
  <Modal :show="Boolean(pendingAction)" aria-labelledby="assessment-transition-title" max-width="md" @close="pendingAction=null"><section class="p-5"><h2 id="assessment-transition-title" class="text-lg font-semibold text-slate-900">Confirm assessment status change</h2><p class="mt-2 text-sm text-slate-700">Version {{selected?.assessment.version}} - {{pendingAction?human(pendingAction):''}}. This records an accountable workflow action.</p><div class="mt-5 flex flex-wrap justify-end gap-3"><button type="button" class="ui-button" @click="pendingAction=null">Keep reviewing</button><button type="button" class="ui-button-primary" @click="transition">Confirm status change</button></div></section></Modal>
 </div>
</template>
