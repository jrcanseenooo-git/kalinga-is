<script setup lang="ts" generic="T">
defineProps<{ rows:T[]; columns:Array<{key:string;label:string}>; rowKey:(row:T)=>string|number; label:string; stacked?:boolean }>();
</script>
<template><div><table v-if="!stacked" class="ui-table hidden xl:table" :aria-label="label"><thead><tr><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th></tr></thead><tbody><tr v-for="row in rows" :key="rowKey(row)"><slot name="row" :row="row" /></tr></tbody></table><ul class="divide-y divide-slate-100" :class="stacked ? '' : 'xl:hidden'" :aria-label="label"><li v-for="row in rows" :key="rowKey(row)" class="p-4"><slot name="card" :row="row" /></li></ul></div></template>
