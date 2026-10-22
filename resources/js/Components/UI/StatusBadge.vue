<script setup lang="ts">
import { computed } from 'vue';
const props=withDefaults(defineProps<{ label: string; tone?: 'neutral'|'brand'|'danger'|'warning'|'success'|'info'; dot?: boolean }>(),{tone:'neutral',dot:false});
// Rounded tinted pills, as in the Kalinga mockups. Text colours keep at least 4.5:1 contrast on their tint.
const tones={neutral:'bg-slate-100 text-slate-700',brand:'bg-brand-50 text-brand-800',danger:'bg-rose-50 text-rose-700',warning:'bg-amber-50 text-amber-800',success:'bg-emerald-50 text-emerald-700',info:'bg-sky-50 text-sky-800'};
const classes=computed(()=>tones[props.tone]);
</script>
<template><span class="ui-pill" :class="classes"><span v-if="dot" aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-current" />{{ label }}</span></template>
