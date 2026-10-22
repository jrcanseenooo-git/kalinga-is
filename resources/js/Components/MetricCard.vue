<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import { ChevronRight } from '@lucide/vue';
withDefaults(defineProps<{label:string;value:number|string;href:string;definition:string;icon?:string;tone?:'brand'|'danger'|'warning'|'info'|'success'}>(),{icon:'cases',tone:'brand'});
// Tinted KPI tile (mockup: icon tile, large figure, coloured label, one-line description).
const tile={brand:'bg-brand-100 text-brand-700',danger:'bg-rose-100 text-rose-700',warning:'bg-amber-100 text-amber-700',info:'bg-sky-100 text-sky-700',success:'bg-emerald-100 text-emerald-700'};
const figure={brand:'text-brand-900',danger:'text-rose-700',warning:'text-amber-800',info:'text-sky-900',success:'text-emerald-800'};
const labelTone={brand:'text-brand-800',danger:'text-rose-700',warning:'text-amber-800',info:'text-sky-800',success:'text-emerald-800'};
</script>
<template><Link :href="href" class="ui-kpi group focus-visible:outline-offset-2" :class="`ui-kpi-${tone}`" :title="definition"><div class="flex items-start gap-3 sm:gap-4"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl sm:h-12 sm:w-12" :class="tile[tone]"><AppIcon :name="icon" class="h-5 w-5 sm:h-6 sm:w-6" /></span><div class="min-w-0"><p class="text-[28px] font-bold leading-none tracking-tight sm:text-[32px]" :class="figure[tone]">{{ typeof value === 'number' ? value.toLocaleString() : value }}</p><p class="mt-2 inline-flex items-center gap-0.5 text-sm font-semibold" :class="labelTone[tone]">{{ label }}<ChevronRight aria-hidden="true" class="h-4 w-4 transition-transform group-hover:translate-x-0.5" /></p></div></div><p class="mt-3 hidden text-xs leading-5 text-slate-600 sm:block">{{ definition }}</p></Link></template>
