<script setup lang="ts">
import { computed } from 'vue';
import PlanningForm from '@/Components/PlanningForm.vue';
import type { PlanIntervention, PlanningData, PlanningField } from '@/types/planning';
const props=defineProps<{action:PlanIntervention;modelValue:PlanningData;busy:boolean;errors:Record<string,string>}>();
const emit=defineEmits<{submit:[];'update:modelValue':[PlanningData]}>();
const human=(value:string)=>value.replaceAll('_',' ');
const fields=computed<PlanningField[]>(()=>[
 ...(!props.action.operational_record_type?[{key:'progress',label:'Execution progress',kind:'select' as const,options:['not_started','in_progress','completed','on_hold','discontinued'].map(value=>({value,label:human(value)}))}]:[]),
 {key:'outcome_status',label:'Outcome status',kind:'select',options:[{value:'',label:'Not yet recorded'},...['achieved','partially_achieved','not_achieved','unable_to_determine','discontinued','service_unavailable'].map(value=>({value,label:human(value)}))]},
 {key:'actual_outcome',label:'Actual outcome',kind:'textarea',wide:true},
 {key:'barriers',label:'Barriers',kind:'textarea',wide:true},
 {key:'next_action',label:'Next action',kind:'textarea',wide:true},
]);
</script>
<template><PlanningForm :id="`outcome-${action.id}`" :title="`Service outcome - ${action.activity||action.service_category}`" :description="action.operational_record_type?'Record professional outcomes and barriers. Execution status is controlled by the linked operational record.':'Record delivery progress and professional outcomes separately.'" :model-value="modelValue" @update:model-value="emit('update:modelValue',$event)" :fields="fields" :busy="busy" :errors="errors" submit-label="Save service outcome" @submit="emit('submit')"/></template>
