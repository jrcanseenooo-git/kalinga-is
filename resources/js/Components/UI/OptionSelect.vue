<script setup lang="ts">
/**
 * Dropdown with an optional "Other (specify)" escape.
 * A stored value that is not in the list (older free-text records) opens in
 * "Other" with its text kept, so nothing is lost when a form is edited.
 */
import { computed, ref, watch } from 'vue';
import type { Option } from '@/utils/caseOptions';

const props = withDefaults(defineProps<{
  modelValue?: string | null;
  options: Option[];
  id?: string;
  placeholder?: string;
  allowOther?: boolean;
  otherLabel?: string;
  required?: boolean;
  disabled?: boolean;
  invalid?: boolean;
  selectClass?: string;
}>(), { modelValue: '', placeholder: 'Select', allowOther: true, otherLabel: 'Other (specify)', required: false, disabled: false, invalid: false, selectClass: 'ui-field' });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const OTHER = '__other__';
const known = (value?: string | null) => !!value && props.options.some(option => option.value === value);
const choice = ref(props.modelValue ? (known(props.modelValue) ? props.modelValue : (props.allowOther ? OTHER : props.modelValue)) : '');
const otherText = ref(props.modelValue && !known(props.modelValue) ? props.modelValue : '');

watch(() => props.modelValue, value => {
  if ((value ?? '') === (choice.value === OTHER ? otherText.value : choice.value)) return;
  if (!value) { choice.value = ''; otherText.value = ''; }
  else if (known(value)) choice.value = value;
  else if (props.allowOther) { choice.value = OTHER; otherText.value = value; }
});

function select(value: string) {
  choice.value = value;
  emit('update:modelValue', value === OTHER ? otherText.value : value);
}
function type(value: string) { otherText.value = value; emit('update:modelValue', value); }
const showOther = computed(() => props.allowOther && choice.value === OTHER);
const otherId = computed(() => props.id ? `${props.id}-other` : undefined);
</script>

<template>
  <div class="min-w-0 space-y-2">
    <select :id="id" :value="choice" :required="required" :disabled="disabled" :aria-invalid="invalid || undefined" :class="selectClass" @change="select(($event.target as HTMLSelectElement).value)">
      <option value="">{{ placeholder }}</option>
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
      <option v-if="allowOther" :value="OTHER">{{ otherLabel }}</option>
    </select>
    <input v-if="showOther" :id="otherId" :value="otherText" :required="required" :disabled="disabled" maxlength="255" type="text" class="ui-field" aria-label="Specify other" placeholder="Please specify" @input="type(($event.target as HTMLInputElement).value)">
  </div>
</template>
