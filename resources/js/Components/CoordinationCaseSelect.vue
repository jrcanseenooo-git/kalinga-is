<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface CaseReference {id:number;case_number:string;status:string}
interface ReferencePage {cases:CaseReference[];selected_case:CaseReference|null;has_more:boolean}
const props=withDefaults(defineProps<{id:string;circleId:number;modelValue:string;required?:boolean;disabled?:boolean;error?:string;allowKnownReference?:boolean}>(),{required:false,disabled:false,allowKnownReference:false});
const emit=defineEmits<{'update:modelValue':[value:string];accessDenied:[]}>();
const cases=ref<CaseReference[]>([]),selected=ref<CaseReference|null>(null),term=ref(''),busy=ref(false),failure=ref(''),more=ref(false);
const field=ref<HTMLSelectElement|null>(null);
const options=computed(()=>selected.value&&!cases.value.some(item=>item.id===selected.value?.id)?[selected.value,...cases.value]:cases.value);
const available=computed(()=>options.value.some(item=>String(item.id)===props.modelValue));
let token=0;
function validate(){field.value?.setCustomValidity(props.required&&props.modelValue&&!available.value?'Choose a currently authorized case reference.':'');}
async function load(){
    if(props.disabled)return;
    const request=++token;busy.value=true;failure.value='';
    try{
        const {data}=await axios.post<ReferencePage>(route('cpu.coordination.cases.index',props.circleId),{q:term.value.trim()||null,selected_id:props.modelValue?Number(props.modelValue):null});
        if(request!==token)return;
        if(!Array.isArray(data.cases)||typeof data.has_more!=='boolean')throw Error('References unavailable');
        cases.value=data.cases;selected.value=data.selected_case;more.value=data.has_more;
    }catch(cause){
        if(request!==token)return;
        cases.value=[];selected.value=null;
        if(axios.isAxiosError(cause)&&[401,403,404,419].includes(cause.response?.status??0)){emit('accessDenied');return;}
        failure.value=axios.isAxiosError(cause)&&cause.response?.status===422?'Use at least two characters and no more than 100 in the case-reference search.':'Case references could not be refreshed. Check your connection and try Find references again.';
    }finally{if(request===token){busy.value=false;validate();}}
}
function choose(event:Event){const value=(event.target as HTMLSelectElement).value;selected.value=options.value.find(item=>String(item.id)===value)??null;emit('update:modelValue',value);}
watch(()=>props.circleId,()=>{token++;cases.value=[];selected.value=null;term.value='';void load();});
watch(()=>props.disabled,disabled=>{if(!disabled&&!busy.value&&!cases.value.length&&!failure.value)void load();});
watch(()=>props.modelValue,value=>{if(!value)selected.value=null;else if(!props.allowKnownReference&&!options.value.some(item=>String(item.id)===value))void load();validate();});
watch(available,validate);onMounted(()=>void load());onBeforeUnmount(()=>token++);
</script>

<template>
    <div role="group" aria-label="Case reference selection" :aria-busy="busy" class="min-w-0 space-y-2">
        <label :for="id" class="block text-sm font-medium text-slate-800">{{required?'Authorized case reference':'Case reference (optional)'}}<span v-if="required" aria-hidden="true"> *</span></label>
        <select :id="id" ref="field" :value="modelValue" :required="required" :disabled="disabled" :aria-label="required?'Authorized case reference':'Case reference (optional)'" :aria-describedby="id+'-hint'" :aria-invalid="Boolean(error)||Boolean(required&&modelValue&&!available&&!busy)" class="ui-field" @change="choose"><option value="">{{busy?'Checking authorized references...':required?'Select an authorized case':'No case tag'}}</option><option v-if="modelValue&&!available" :value="modelValue">{{busy?'Checking existing reference...':'Existing tag - authorization not confirmed'}}</option><option v-for="item in options" :key="item.id" :value="String(item.id)">{{item.case_number}} - {{item.status.toLowerCase().replaceAll('_',' ')}}</option></select>
        <p v-if="selected&&String(selected.id)===modelValue" class="break-words text-xs text-slate-700">{{selected.case_number}} - {{selected.status.toLowerCase().replaceAll('_',' ')}}</p>
        <p :id="id+'-hint'" class="text-xs leading-5 text-slate-600">Only case references within your existing case authority and this group are listed. A tag does not grant access.</p>
        <details class="rounded border border-slate-200 bg-white px-3"><summary class="min-h-11 cursor-pointer py-3 text-xs font-semibold text-brand-900">Find or refresh case references</summary><div class="space-y-2 pb-3"><label :for="id+'-search'" class="block text-xs font-semibold text-slate-700">Search by case number</label><div class="flex flex-wrap gap-2"><input :id="id+'-search'" v-model="term" type="search" minlength="2" maxlength="100" class="ui-field min-w-0 flex-1" :disabled="disabled||busy" placeholder="Case reference" @keydown.enter.prevent="load"><button type="button" class="ui-button" :disabled="disabled||busy" @click="load">Find references</button></div><p v-if="more" class="text-xs leading-5 text-slate-600">More references match. Refine the case number to find the intended record.</p></div></details>
        <p v-if="failure||error" role="alert" class="text-xs leading-5 text-rose-800">{{failure||error}}</p>
        <details v-if="allowKnownReference&&!required" class="rounded border border-slate-200 bg-white px-3"><summary class="min-h-11 cursor-pointer py-3 text-xs font-semibold text-slate-700">Use a known operational reference</summary><label :for="id+'-known'" class="block text-xs font-semibold text-slate-700">Known case reference ID<input :id="id+'-known'" :value="modelValue" :disabled="disabled" inputmode="numeric" pattern="[0-9]*" class="ui-field mt-2" @input="emit('update:modelValue',($event.target as HTMLInputElement).value)"></label><p class="py-3 text-xs leading-5 text-slate-600">Retains the existing known-reference tagging workflow. Use an existing ID for this LGU; tagging never grants case access or authorizes formal capture.</p></details>
    </div>
</template>
