<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { PARTICIPATION_DECISIONS } from '@/utils/caseOptions';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { registerUnsavedCaseWork, reloadAfterAccessDenial } from '@/Security/browserHistory';
import type { PageProps } from '@/types';
import FormField from '@/Components/UI/FormField.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';

const props=defineProps<{caseId:number;active:boolean}>();
const permissions=usePage<PageProps>();
const canManage=computed(()=>permissions.props.auth.permissions.includes('participation.manage'));
type Value=string|boolean|null;
type SectionKey='participation_context'|'child_views'|'communication_accommodations'|'decision_under_consideration'|'how_views_were_considered'|'best_interest_considerations'|'feedback_to_child'|'safeguards_follow_up';
type Action='for_review'|'reviewed'|'returned_for_revision'|'final';
interface Field {key:string;label:string;kind:'text'|'textarea'|'date'|'boolean'|'alignment';limit?:number;hint?:string}
interface Section {key:SectionKey;label:string;fields:Field[]}
const sections:Section[]=[
 {key:'participation_context',label:'Participation context',fields:[
  {key:'developmental_considerations',label:'Developmental considerations',kind:'textarea'},
  {key:'preferred_language',label:'Preferred language',kind:'text',limit:120},
  {key:'location_environment',label:'Location and environment',kind:'text',limit:255},
  {key:'privacy_reasonably_provided',label:'Reasonable privacy provided',kind:'boolean'},
  {key:'comfortable_to_continue',label:'Child appeared comfortable to continue',kind:'boolean'},
 ]},
 {key:'child_views',label:'Child views and statements',fields:[
  {key:'child_views',label:"Child's expressed views, wishes or concerns",kind:'textarea'},
  {key:'direct_quote',label:'Direct quotation',kind:'textarea',hint:'Record a direct quote only when necessary and appropriate.'},
  {key:'worker_observation',label:'Worker observation',kind:'textarea'},
  {key:'reported_information',label:'Information reported by another person',kind:'textarea'},
 ]},
 {key:'communication_accommodations',label:'Communication and accommodations',fields:[
  {key:'communication_method',label:'Communication method',kind:'text',limit:255},
  {key:'accessibility_accommodation',label:'Disability or accessibility accommodation',kind:'textarea'},
  {key:'interpreter_or_support',label:'Interpreter or communication support',kind:'text',limit:255},
  {key:'trusted_adult_or_support_person',label:'Trusted adult or support person',kind:'text',limit:255},
 ]},
 {key:'decision_under_consideration',label:'Decision under consideration',fields:[
  {key:'decision_title',label:'Decision under consideration',kind:'text',limit:255},
  {key:'child_preference',label:"Child's expressed preference",kind:'textarea'},
 ]},
 {key:'how_views_were_considered',label:'How views were considered',fields:[
  {key:'factors_considered',label:'Factors considered',kind:'textarea'},
  {key:'view_influence',label:"How the child's view influenced the decision",kind:'textarea'},
  {key:'decision_alignment',label:'Decision alignment with expressed preference',kind:'alignment'},
  {key:'professional_consideration',label:'Professional consideration and interpretation',kind:'textarea'},
 ]},
 {key:'best_interest_considerations',label:'Best-interest considerations',fields:[
  {key:'best_interest_rationale',label:'Professional and best-interest rationale',kind:'textarea',hint:'Record professional reasoning. No automated score or decision is calculated.'},
  {key:'safeguards_mitigating_actions',label:'Safeguards or mitigating actions',kind:'textarea'},
 ]},
 {key:'feedback_to_child',label:'Feedback to the child',fields:[
  {key:'outcome_explained_to_child',label:'Outcome explained back to the child',kind:'boolean'},
  {key:'feedback_date',label:'Feedback date',kind:'date'},
  {key:'feedback_by',label:'Feedback provided by',kind:'text',limit:255},
  {key:'feedback_method_language',label:'Feedback method or language',kind:'text',limit:255},
  {key:'child_response_questions',label:"Child's response or questions",kind:'textarea'},
  {key:'follow_up_needed',label:'Further clarification or follow-up needed',kind:'textarea'},
 ]},
 {key:'safeguards_follow_up',label:'Safeguards and follow-up',fields:[
  {key:'sharing_preference',label:"Child's sharing concern or preference",kind:'textarea',hint:'A stated preference does not change system access permissions.'},
  {key:'sharing_professional_consideration',label:'Professional consideration of sharing',kind:'textarea'},
  {key:'necessary_disclosure_basis',label:'Basis for necessary disclosure',kind:'textarea'},
 ]},
];
interface ParticipationRecord {id:number;version:number;record_type:string;status:string;participation_date:string;is_current:boolean;previous_record_id:number|null;decision_title?:string;occasion_type?:string;participation_status?:string;non_participation_reason?:string|null;amendment_reason?:string|null;review_comments?:string|null;returned_at?:string|null;finalized_at?:string|null;[key:string]:unknown}
interface Detail {record:ParticipationRecord;restricted:boolean;placeholder:string|null;history:{event:string;comments?:string|null;actor_name?:string;actor_role?:string;created_at:string}[];allowed_actions:{save_sections:boolean;submit:boolean;review:boolean;return:boolean;finalize:boolean;new_version:boolean}}
const occasions=[['initial_case_planning','Initial case planning'],['safety_planning','Safety planning'],['referral_service','Referral or service decision'],['placement_living','Placement or living arrangement'],['education','Education decision'],['case_conference','Case conference'],['transfer','Transfer'],['reintegration','Reintegration'],['closure','Closure'],['other','Other significant decision']];
const statuses=[['participated','Participated'],['partially_participated','Partially participated'],['child_declined','Child declined'],['deferred','Deferred'],['not_appropriate_unsafe','Not appropriate or unsafe at this time'],['child_unable_to_participate','Child unable to participate'],['not_yet_conducted','Not yet conducted']];
const human=(value:string)=>value.replaceAll('_',' ');
const title=(item:ParticipationRecord)=>item.decision_title||'Restricted participation record';
const dateLabel=(value:string)=>{const date=new Date(`${value.slice(0,10)}T00:00:00Z`);return Number.isNaN(date.getTime())?'Date not recorded':date.toLocaleDateString('en-PH',{timeZone:'UTC',dateStyle:'medium'});};
const records=ref<ParticipationRecord[]>([]);const selected=ref<Detail|null>(null);const loaded=ref(false);const busy=ref(false);
const panelRoot=ref<HTMLElement|null>(null);const errorSummary=ref<HTMLElement|null>(null);const error=ref('');const message=ref('');const errors=ref<Record<string,string>>({});
const sectionNav=ref<HTMLElement|null>(null);const editor=ref<HTMLElement|null>(null);
const sectionKey=ref<SectionKey>('participation_context');const drafts=ref<Record<string,Record<string,Value>>>({});const baselines=new Map<string,string>();const draft=ref<Record<string,Value>|null>(null);
const section=computed(()=>sections.find(item=>item.key===sectionKey.value)!);
const keyFor=(key:SectionKey)=>`${selected.value?.record.id}:${key}`;
const dirty=(key:string)=>JSON.stringify(drafts.value[key])!==baselines.get(key);
const dirtyKeys=computed(()=>Object.keys(drafts.value).filter(dirty));
const editable=computed(()=>Boolean(selected.value&&!selected.value.restricted&&selected.value.allowed_actions.save_sections&&canManage.value));
const textValue=(key:string)=>typeof draft.value?.[key]==='string'?draft.value[key] as string:'';
function updateText(key:string,event:Event){if(draft.value&&(event.target instanceof HTMLInputElement||event.target instanceof HTMLTextAreaElement))draft.value[key]=event.target.value;}
const showCreate=ref(false);const correction=ref(false);const create=ref({occasion_type:'initial_case_planning',decision_title:'',participation_date:new Date().toISOString().slice(0,10),participation_status:'participated',non_participation_reason:'',record_type:'full_revision',amendment_reason:''});
const creationBaseline=ref(JSON.stringify(create.value));const correctionSourceId=ref<number|null>(null);
const creationDirty=computed(()=>showCreate.value&&JSON.stringify(create.value)!==creationBaseline.value);
const needsReason=computed(()=>!['participated','partially_participated'].includes(create.value.participation_status));
const comments=ref('');const pendingAction=ref<Action|null>(null);let desiredId:number|undefined;

function openSection(key:SectionKey,focusEditor=false){
 sectionKey.value=key;errors.value={};
 if(!selected.value||selected.value.restricted){draft.value=null;return;}
 const id=keyFor(key);
 if(!drafts.value[id]){const value:Record<string,Value>={section_key:key};for(const field of section.value.fields){const existing=selected.value.record[field.key];value[field.key]=field.kind==='boolean'?(typeof existing==='boolean'?existing:(existing===1||existing==='1')?true:(existing===0||existing==='0')?false:null):(typeof existing==='string'?existing:'');}drafts.value[id]=value;baselines.set(id,JSON.stringify(value));}
 draft.value=drafts.value[id];
 if(focusEditor)void nextTick(()=>{editor.value?.scrollIntoView({block:'start'});editor.value?.focus({preventScroll:true});});
}
function chooseSection(){sectionNav.value?.scrollIntoView({block:'start'});sectionNav.value?.querySelector<HTMLElement>('[aria-current="step"]')?.focus({preventScroll:true});}
async function fail(cause:unknown){
 if(axios.isAxiosError(cause)&&cause.response?.status===403){reloadAfterAccessDenial();return;}
 errors.value={};if(axios.isAxiosError(cause)&&cause.response?.status===422){const fields=cause.response.data?.errors;if(fields&&typeof fields==='object')for(const [key,value]of Object.entries(fields))if(Array.isArray(value)&&typeof value[0]==='string')errors.value[key]=value[0];}
 error.value=Object.keys(errors.value).length?'Review the fields below. Entered work remains on this page.':'The operation could not be completed. Check your connection and current case access, then retry. Entered work remains on this page.';await nextTick();errorSummary.value?.focus();
}
async function fetchRecords(id?:number){
 const first=!loaded.value;const list=await axios.get<{records:ParticipationRecord[]}>(route('cases.child-participation.index',props.caseId));if(!Array.isArray(list.data.records))throw new Error('Participation response unavailable');records.value=list.data.records;
 const target=desiredId??id??selected.value?.record.id??records.value[0]?.id;desiredId=undefined;
 selected.value=target?(await axios.get<Detail>(route('cases.child-participation.show',[props.caseId,target]))).data:null;loaded.value=true;
 if(selected.value)openSection(sectionKey.value);else showCreate.value=canManage.value;
 if(first&&props.active){await nextTick();panelRoot.value?.scrollIntoView({block:'start'});}
}
async function load(id?:number){if(id)desiredId=id;if(busy.value)return;busy.value=true;error.value='';message.value='';try{await fetchRecords(id);if(id&&props.active){await nextTick();panelRoot.value?.scrollIntoView({block:'start'});}}catch(cause){await fail(cause);}finally{busy.value=false;}}
function beginCreate(revising=false){
 if(creationDirty.value&&!window.confirm('Discard the unsaved new participation record and start this one?'))return;
 correction.value=revising;errors.value={};error.value='';message.value='';
 correctionSourceId.value=revising?selected.value?.record.id??null:null;
 create.value={occasion_type:revising?selected.value?.record.occasion_type||'initial_case_planning':'initial_case_planning',decision_title:revising?selected.value?.record.decision_title||'':'',participation_date:new Date().toISOString().slice(0,10),participation_status:revising?selected.value?.record.participation_status||'participated':'participated',non_participation_reason:revising?selected.value?.record.non_participation_reason||'':'',record_type:'full_revision',amendment_reason:''};creationBaseline.value=JSON.stringify(create.value);showCreate.value=true;
}
function cancelCreate(){if(!creationDirty.value||window.confirm('Discard this unsaved participation record?'))showCreate.value=false;}
async function createRecord(){
 if(busy.value||!canManage.value)return;busy.value=true;error.value='';message.value='';errors.value={};
 try{const acknowledged=await axios.post<{id:number}>(route('cases.child-participation.store',props.caseId),{...create.value,record_type:correction.value?create.value.record_type:'formal_record',previous_record_id:correction.value?correctionSourceId.value:null});if(!Number.isSafeInteger(acknowledged.data.id))throw new Error('Participation acknowledgement unavailable');selected.value=null;desiredId=undefined;loaded.value=false;showCreate.value=false;await fetchRecords(acknowledged.data.id);message.value='Child participation draft created.';}catch(cause){await fail(cause);}finally{busy.value=false;}
}
async function saveSection(){
 if(busy.value||!editable.value||!selected.value||!draft.value)return;const id=selected.value.record.id;const key=keyFor(sectionKey.value);busy.value=true;error.value='';message.value='';errors.value={};
 try{await axios.put(route('cases.child-participation.sections.save',[props.caseId,id]),{...draft.value});delete drafts.value[key];baselines.delete(key);await fetchRecords(id);message.value='Participation section saved.';}catch(cause){await fail(cause);}finally{busy.value=false;}
}
function requestAction(action:Action){if(selected.value&&dirtyKeys.value.some(key=>key.startsWith(`${selected.value!.record.id}:`))){error.value='Save changed sections before changing this participation record status.';nextTick(()=>errorSummary.value?.focus());return;}pendingAction.value=action;}
async function transition(){if(busy.value||!selected.value||!pendingAction.value)return;const id=selected.value.record.id;const status=pendingAction.value;pendingAction.value=null;busy.value=true;error.value='';message.value='';errors.value={};try{await axios.patch(route('cases.child-participation.transition',[props.caseId,id]),{status,review_comments:comments.value||null});comments.value='';await fetchRecords(id);message.value='Participation record status updated.';}catch(cause){await fail(cause);}finally{busy.value=false;}}
const displayed=(field:Field)=>{const value=selected.value?.record[field.key];if(field.kind==='boolean')return value===true||value===1||value==='1'?'Yes':value===false||value===0||value==='0'?'No':'Not recorded';return typeof value==='string'&&value?(field.kind==='alignment'?human(value):value):'Not recorded';};
const removeUnsaved=registerUnsavedCaseWork(()=>dirtyKeys.value.length>0||creationDirty.value||Boolean(comments.value.trim()));
onBeforeUnmount(removeUnsaved);
watch(()=>props.active,visible=>{if(visible&&!loaded.value)void load();},{immediate:true});defineExpose({load});
</script>

<template>
 <div ref="panelRoot" class="scroll-mt-24 space-y-5">
  <header class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-900">Child participation</h2><p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">Record how the child was enabled to participate in a specific decision. Participation is not consent, assent, approval or responsibility for the final decision.</p></div><div class="flex flex-wrap gap-2"><button type="button" :disabled="busy" class="ui-button" @click="load()">Load records</button><button v-if="canManage" type="button" :disabled="busy" class="ui-button-primary" @click="beginCreate()">New decision or occasion</button></div></header>
  <p v-if="busy" role="status" class="text-sm text-slate-600">Working on this participation record...</p><p v-if="message" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{message}}</p>
  <div v-if="error" ref="errorSummary" tabindex="-1" role="alert" class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><p class="font-semibold">{{error}}</p><ul v-if="Object.keys(errors).length" class="mt-2 list-inside list-disc"><li v-for="(value,key) in errors" :key="key">{{value}}</li></ul></div>
  <p v-if="dirtyKeys.length" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{dirtyKeys.length}} unsaved section(s) retained on this page. Save each changed section online before reloading or closing.</p>
  <p v-if="creationDirty" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">The new participation record has unsaved entries. Create the draft online to retain them.</p>
  <form v-if="showCreate&&canManage" autocomplete="off" @submit.prevent="createRecord" class="rounded-md border border-brand-200 bg-brand-50 p-4"><h3 class="font-semibold text-brand-900">{{correction?'Correction or addendum for the same occasion':'Start a formal participation record'}}</h3><p class="mt-1 text-sm leading-6 text-brand-900">{{correction?'The finalized source remains unchanged. A different decision needs a separate formal record.':'Use a formal record for a significant decision or occasion. Ordinary contacts belong in case notes or contact history.'}}</p><fieldset :disabled="busy" class="mt-4 grid min-w-0 gap-4 sm:grid-cols-2">
   <FormField id="participation-occasion" label="Activity type" :error="errors.occasion_type"><select id="participation-occasion" v-model="create.occasion_type" :disabled="correction" class="ui-field"><option v-for="[value,label] in occasions" :key="value" :value="value">{{label}}</option></select></FormField>
   <FormField id="participation-title" label="Decision or participation activity" :error="errors.decision_title" required><input v-if="correction" id="participation-title" v-model="create.decision_title" readonly required maxlength="255" class="ui-field"><OptionSelect v-else id="participation-title" v-model="create.decision_title" :options="PARTICIPATION_DECISIONS" required /></FormField>
   <FormField id="participation-date" label="Participation date" :error="errors.participation_date" required><input id="participation-date" v-model="create.participation_date" type="date" required class="ui-field"></FormField>
   <FormField id="participation-status" label="Participation status" :error="errors.participation_status"><select id="participation-status" v-model="create.participation_status" class="ui-field"><option v-for="[value,label] in statuses" :key="value" :value="value">{{label}}</option></select></FormField>
   <FormField v-if="needsReason" id="participation-reason" label="Neutral reason for participation status" :error="errors.non_participation_reason" hint="Do not treat refusal or non-participation negatively." required><textarea id="participation-reason" v-model="create.non_participation_reason" rows="3" required maxlength="5000" class="ui-field"/></FormField>
   <template v-if="correction"><FormField id="participation-version" label="Correction type"><select id="participation-version" v-model="create.record_type" class="ui-field"><option value="full_revision">Full revised record</option><option value="addendum">Addendum</option></select></FormField><FormField id="participation-amendment" label="Reason for correction or addendum" :error="errors.amendment_reason" required><textarea id="participation-amendment" v-model="create.amendment_reason" rows="3" required maxlength="2000" class="ui-field"/></FormField></template>
   <div class="flex flex-wrap items-end gap-3 sm:col-span-2"><button class="ui-button-primary">Create participation draft</button><button type="button" class="ui-button" @click="cancelCreate">Keep existing records</button></div>
  </fieldset></form>
  <p v-if="loaded&&!records.length&&!showCreate" class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-600">No formal participation record has been recorded.</p>
  <nav v-if="records.length" aria-label="Participation records" class="flex min-w-0 gap-2 overflow-x-auto pb-2"><button v-for="item in records" :key="item.id" type="button" :disabled="busy" :aria-current="selected?.record.id===item.id?'true':undefined" class="min-h-11 max-w-full shrink-0 sm:max-w-80 rounded-md border p-3 text-left text-sm" :class="selected?.record.id===item.id?'border-brand-400 bg-brand-50 text-brand-900':'border-slate-200 text-slate-800'" @click="load(item.id)"><span class="block whitespace-normal break-words font-semibold">{{title(item)}}</span><span class="mt-1 block text-xs">{{dateLabel(item.participation_date)}} - version {{item.version}} - {{human(item.status)}}</span></button></nav>
  <template v-if="selected">
   <section aria-label="Selected participation record" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm"><div class="flex flex-wrap items-start justify-between gap-3"><h3 class="min-w-0 break-words font-semibold text-slate-900">{{title(selected.record)}}</h3><StatusBadge :label="human(selected.record.status)" :tone="selected.record.status==='final'?'success':'neutral'"/></div><p class="mt-2 text-slate-700">Version {{selected.record.version}} - {{human(selected.record.record_type)}}<span v-if="selected.record.is_current"> - current for this occasion</span></p><template v-if="!selected.restricted"><p class="mt-2 text-slate-700">{{human(selected.record.occasion_type||'occasion_not_recorded')}} - {{dateLabel(selected.record.participation_date)}}</p><StatusBadge class="mt-3" :label="human(selected.record.participation_status||'status_not_recorded')"/><p v-if="selected.record.non_participation_reason" class="mt-3 whitespace-pre-wrap break-words text-slate-700">Recorded neutral reason: {{selected.record.non_participation_reason}}</p><p v-if="selected.record.previous_record_id" class="mt-3 whitespace-pre-wrap break-words text-slate-700">Correction or addendum attached to source record #{{selected.record.previous_record_id}}. {{selected.record.amendment_reason}}</p><button v-if="selected.allowed_actions.new_version&&canManage" type="button" :disabled="busy" class="ui-button mt-4" @click="beginCreate(true)">Correct or supplement this occasion</button></template><p v-else class="mt-3 text-slate-600">Restricted Child participation record. Detailed information is unavailable under your current permission.</p></section>
   <div v-if="selected.record.returned_at&&!selected.restricted" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><h3 class="font-semibold">Returned for revision</h3><p class="mt-2 whitespace-pre-wrap break-words">{{selected.record.review_comments}}</p></div>
   <div v-if="!selected.restricted" class="grid min-w-0 items-start gap-5 lg:grid-cols-[13rem_minmax(0,1fr)]">
    <nav ref="sectionNav" aria-label="Child participation sections" class="scroll-mt-24 flex min-w-0 gap-2 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible"><button v-for="item in sections" :key="item.key" type="button" :disabled="busy" :aria-current="sectionKey===item.key?'step':undefined" class="min-h-11 shrink-0 rounded-md border p-3 text-left text-sm font-semibold lg:w-full" :class="sectionKey===item.key?'border-brand-400 bg-brand-50 text-brand-900':'border-slate-200 text-slate-800'" @click="openSection(item.key,true)">{{item.label}}<span v-if="drafts[keyFor(item.key)]&&dirty(keyFor(item.key))" class="mt-1 block text-xs font-normal">Unsaved</span></button></nav>
    <form v-if="editable&&draft" ref="editor" tabindex="-1" aria-labelledby="participation-section-heading" autocomplete="off" data-skip-generic-confirm="true" @submit.prevent="saveSection" class="scroll-mt-24 min-w-0 rounded-md border border-slate-200 p-4"><fieldset :disabled="busy" class="min-w-0 space-y-4"><legend id="participation-section-heading" class="text-base font-semibold text-slate-900">{{section.label}}</legend><p class="text-sm leading-6 text-slate-600">Use neutral, minimum-necessary documentation. Adults remain responsible for safeguarding and decisions.</p><FormField v-for="field in section.fields" :id="`participation-field-${field.key}`" :key="field.key" :label="field.label" :error="errors[field.key]" :hint="field.hint"><select v-if="field.kind==='boolean'" :id="`participation-field-${field.key}`" v-model="draft[field.key]" class="ui-field"><option :value="null">Not recorded</option><option :value="true">Yes</option><option :value="false">No</option></select><select v-else-if="field.kind==='alignment'" :id="`participation-field-${field.key}`" v-model="draft[field.key]" class="ui-field"><option value="">Not recorded</option><option value="aligns">Aligns with expressed preference</option><option value="partially_aligns">Partially aligns</option><option value="does_not_align">Does not align</option><option value="not_yet_decided">Not yet decided</option></select><textarea v-else-if="field.kind==='textarea'" :id="`participation-field-${field.key}`" :value="textValue(field.key)" @input="updateText(field.key,$event)" rows="4" :maxlength="field.limit||5000" :aria-invalid="Boolean(errors[field.key])" :aria-describedby="errors[field.key]||field.hint ? `participation-field-${field.key}-help` : undefined" class="ui-field"/><input v-else :id="`participation-field-${field.key}`" :value="textValue(field.key)" @input="updateText(field.key,$event)" :type="field.kind==='date'?'date':'text'" :maxlength="field.limit||5000" :aria-invalid="Boolean(errors[field.key])" :aria-describedby="errors[field.key] ? `participation-field-${field.key}-help` : undefined" class="ui-field"></FormField><div class="flex flex-wrap gap-3"><button class="ui-button-primary">Save participation section</button><button type="button" class="ui-button" @click="chooseSection">Choose another section</button></div></fieldset></form>
    <article v-else ref="editor" tabindex="-1" aria-labelledby="participation-section-heading" class="scroll-mt-24 min-w-0 rounded-md border border-slate-200 p-4 text-sm"><h3 id="participation-section-heading" class="font-semibold text-slate-900">{{section.label}}</h3><dl class="mt-4 space-y-4"><div v-for="field in section.fields" :key="field.key"><dt class="font-semibold text-slate-800">{{field.label}}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-slate-700">{{displayed(field)}}</dd></div></dl></article>
   </div>
   <section v-if="selected.record.status!=='final'&&!selected.restricted" aria-label="Participation review workflow" class="rounded-md border border-slate-200 p-4"><h3 class="font-semibold text-slate-900">Independent review</h3><p class="mt-1 text-sm leading-6 text-slate-600">Save changed sections before submitting. A different authorized reviewer reviews and finalizes the formal record.</p><FormField id="participation-review-comments" label="Reviewer comments" :error="errors.review_comments" hint="Comments are required when returning for revision."><textarea id="participation-review-comments" v-model="comments" :disabled="busy" rows="3" maxlength="5000" class="ui-field"/></FormField><div class="mt-4 flex flex-wrap gap-3"><button v-if="selected.allowed_actions.submit" type="button" :disabled="busy" class="ui-button-primary" @click="requestAction('for_review')">Submit for review</button><button v-if="selected.allowed_actions.review" type="button" :disabled="busy" class="ui-button-primary" @click="requestAction('reviewed')">Mark as reviewed</button><button v-if="selected.allowed_actions.return" type="button" :disabled="busy" class="ui-button" @click="requestAction('returned_for_revision')">Return for revision</button><button v-if="selected.allowed_actions.finalize" type="button" :disabled="busy" class="ui-button-primary" @click="requestAction('final')">Finalize as reviewer</button></div></section>
   <details v-if="selected.history.length" class="rounded-md border border-slate-200 p-4 text-sm"><summary class="min-h-11 cursor-pointer py-3 font-semibold text-slate-900">Participation workflow history</summary><ol class="mt-3 space-y-4"><li v-for="(event,index) in selected.history" :key="index" class="border-l-2 border-slate-200 pl-3"><p class="font-semibold capitalize">{{human(event.event)}}</p><p class="mt-1 text-slate-600">{{event.created_at}}<span v-if="event.actor_name"> - {{event.actor_name}}<span v-if="event.actor_role"> ({{human(event.actor_role)}})</span></span></p><p v-if="event.comments" class="mt-2 whitespace-pre-wrap break-words text-slate-700">{{event.comments}}</p></li></ol></details>
  </template>
  <Modal :show="Boolean(pendingAction)" aria-labelledby="participation-transition-title" max-width="md" @close="pendingAction=null"><section class="p-5"><h2 id="participation-transition-title" class="text-lg font-semibold text-slate-900">Confirm participation status change</h2><p class="mt-2 text-sm text-slate-700">Version {{selected?.record.version}} - {{pendingAction?human(pendingAction):''}}. This records an accountable workflow action.</p><div class="mt-5 flex flex-wrap justify-end gap-3"><button type="button" class="ui-button" @click="pendingAction=null">Keep reviewing</button><button type="button" class="ui-button-primary" @click="transition">Confirm status change</button></div></section></Modal>
 </div>
</template>
