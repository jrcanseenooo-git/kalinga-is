<script setup lang="ts">
import FormField from '@/Components/UI/FormField.vue';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import type { PlanningData, PlanningField, PlanningValue } from '@/types/planning';
const props=withDefaults(defineProps<{id:string;title:string;description?:string;modelValue:PlanningData;fields:PlanningField[];busy:boolean;submitLabel:string;errors?:Record<string,string>}>(),{errors:()=>({})});
const emit=defineEmits<{submit:[];'update:modelValue':[PlanningData]}>();
function change(key:string,value:PlanningValue){if(!props.busy)emit('update:modelValue',{...props.modelValue,[key]:value});}
function text(key:string){const value=props.modelValue[key];return typeof value==='string'||typeof value==='number'?value:'';}
function input(key:string,event:Event){if(event.target instanceof HTMLInputElement||event.target instanceof HTMLTextAreaElement)change(key,event.target.value);}
function select(field:PlanningField,event:Event){if(event.target instanceof HTMLSelectElement)change(field.key,field.options?.[event.target.selectedIndex]?.value??'');}
</script>
<template>
 <form :data-planning-form="id" autocomplete="off" class="ui-panel scroll-mt-24 p-4 sm:p-5" @submit.prevent="!busy&&emit('submit')" :aria-labelledby="`${id}-title`" :aria-busy="busy">
  <h2 :id="`${id}-title`" class="ui-section-title">{{title}}</h2><p v-if="description" class="ui-muted mt-1">{{description}}</p>
  <fieldset :disabled="busy" class="mt-4 grid min-w-0 gap-4 sm:grid-cols-2"><legend class="sr-only">{{title}} fields</legend>
   <div v-for="field in fields" :key="field.key" :class="field.wide?'sm:col-span-2':''" class="min-w-0">
    <label v-if="field.kind==='checkbox'" class="flex min-h-11 items-center gap-3 text-sm text-slate-800"><input :id="`${id}-${field.key}`" type="checkbox" class="h-5 w-5 rounded border-slate-400" :checked="Boolean(modelValue[field.key])" @change="change(field.key,($event.target as HTMLInputElement).checked)">{{field.label}}</label>
    <FormField v-else :id="`${id}-${field.key}`" :label="field.label" :required="field.required" :error="errors[field.key]" :hint="field.hint" v-slot="{describedBy,invalid}">
     <textarea v-if="field.kind==='textarea'" :id="`${id}-${field.key}`" :value="text(field.key)" @input="input(field.key,$event)" rows="3" :required="field.required" :maxlength="field.max??5000" :placeholder="field.label" :aria-invalid="invalid||undefined" :aria-describedby="describedBy" class="ui-field"/>
     <OptionSelect v-else-if="field.kind==='choice'" :id="`${id}-${field.key}`" :model-value="String(modelValue[field.key]??'')" :options="field.choices??[]" :required="field.required" :invalid="invalid" @update:model-value="change(field.key,$event)"/>
     <select v-else-if="field.kind==='select'" :id="`${id}-${field.key}`" :value="modelValue[field.key]??''" @change="select(field,$event)" :required="field.required" :aria-invalid="invalid||undefined" :aria-describedby="describedBy" class="ui-field"><option v-for="(option,index) in field.options" :key="index" :value="option.value??''">{{option.label}}</option></select>
     <input v-else :id="`${id}-${field.key}`" :type="field.kind??'text'" :value="text(field.key)" @input="input(field.key,$event)" :required="field.required" :maxlength="field.max??255" :min="field.kind==='number'?1:undefined" :placeholder="field.label" :aria-invalid="invalid||undefined" :aria-describedby="describedBy" class="ui-field">
    </FormField>
   </div>
   <div class="sm:col-span-2"><button type="submit" class="ui-button-primary">{{submitLabel}}</button></div>
  </fieldset>
 </form>
</template>
