import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { registerUnsavedCaseWork, reloadAfterAccessDenial } from '@/Security/browserHistory';
import type { PlanningData, PlanningResponse } from '@/types/planning';

export function usePlanningWorkspace(caseId:number,currentUserId:number) {
 const data=ref<PlanningResponse>({plans:[],aftercare:[],reintegration:[],transfers:[]});
 const selectedId=ref<number|null>(null);const busy=ref(false);const loaded=ref(false);const refreshRequired=ref(false);
 const message=ref('');const error=ref('');const errors=ref<Record<string,string>>({});const failedForm=ref('');const errorSummary=ref<HTMLElement|null>(null);
 const forms=reactive<Record<string,PlanningData>>({});const baselines=reactive<Record<string,string>>({});
 const selected=computed(()=>data.value.plans.find(entry=>entry.plan.id===selectedId.value)??data.value.plans[0]??null);
 const isAuthor=computed(()=>selected.value?.plan.authored_by===currentUserId);
 const editable=computed(()=>isAuthor.value&&['draft','returned'].includes(selected.value?.plan.status??''));
 const dirtyKeys=computed(()=>Object.keys(forms).filter(key=>JSON.stringify(forms[key])!==baselines[key]));
 const disabled=computed(()=>busy.value||refreshRequired.value||!loaded.value);
 function form(key:string,defaults:PlanningData):PlanningData {
  if(!forms[key]){forms[key]={...defaults};baselines[key]=JSON.stringify(defaults);}return forms[key];
 }
 function reset(key:string,defaults:PlanningData){forms[key]={...defaults};baselines[key]=JSON.stringify(defaults);}
 async function showError(text:string){error.value=text;await nextTick();errorSummary.value?.focus();}
 async function fetchRecords() {
  const response=await axios.get<PlanningResponse>(route('cases.plans.index',caseId));
  if(!Array.isArray(response.data.plans)||!Array.isArray(response.data.transfers))throw new Error('Planning response unavailable');
  data.value=response.data;
  if(!selectedId.value||!data.value.plans.some(entry=>entry.plan.id===selectedId.value))selectedId.value=data.value.plans[0]?.plan.id??null;
  loaded.value=true;refreshRequired.value=false;
 }
 async function load(){
  if(busy.value)return;busy.value=true;error.value='';errors.value={};
  try{await fetchRecords();}catch(cause){if(axios.isAxiosError(cause)&&[403,404].includes(cause.response?.status??0)){reloadAfterAccessDenial();return;}await showError('Records could not be refreshed. Check your connection and retry Refresh. Unsubmitted work remains on this page.');}finally{busy.value=false;}
 }
 interface Operation {method:'post'|'patch';url:string;body:PlanningData;formKey?:string;defaults?:PlanningData;success:string;selectCreated?:boolean}
 async function save(operation:Operation):Promise<boolean>{
  if(disabled.value)return false;
  busy.value=true;error.value='';message.value='';errors.value={};failedForm.value=operation.formKey??'';
  let acknowledged=false;
  try{
   const response=await axios.request<{id?:number}>({method:operation.method,url:operation.url,data:{...operation.body}});
   acknowledged=true;
   if(operation.selectCreated&&Number.isInteger(response.data?.id))selectedId.value=response.data.id!;
   if(operation.formKey&&operation.defaults)reset(operation.formKey,operation.defaults);
   message.value=operation.success;
   await fetchRecords();return true;
  }catch(cause){
   if(axios.isAxiosError(cause)&&[403,404].includes(cause.response?.status??0)){reloadAfterAccessDenial();return false;}
   if(!acknowledged&&axios.isAxiosError(cause)&&cause.response?.status===422){
    const fields=cause.response.data?.errors;
    if(fields&&typeof fields==='object')for(const [key,value] of Object.entries(fields))if(Array.isArray(value)&&typeof value[0]==='string')errors.value[key]=value[0];
    await showError(Object.keys(errors.value).length?'Review the fields below. Your entries remain on this page.':typeof cause.response.data?.message==='string'?cause.response.data.message:'This change is not allowed in the current workflow state. Refresh the record and review it.');
   }else{
    refreshRequired.value=true;
    await showError(acknowledged?'The change was saved, but records could not be refreshed. Use Refresh before another change; do not submit it again.':'The outcome could not be confirmed. Use Refresh and review the records before attempting another change. Unsubmitted entries remain on this page.');
   }
   return false;
  }finally{busy.value=false;}
 }
 function errorsFor(key:string){return failedForm.value===key?errors.value:{};}
 const remove=registerUnsavedCaseWork(()=>busy.value||dirtyKeys.value.length>0);
 onBeforeUnmount(remove);onMounted(load);
 return {data,selectedId,selected,isAuthor,editable,busy,loaded,disabled,refreshRequired,message,error,errors,errorSummary,forms,dirtyKeys,form,load,save,errorsFor};
}
