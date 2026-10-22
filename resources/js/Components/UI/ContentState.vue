<script setup lang="ts">
withDefaults(defineProps<{ title: string; description?: string; kind?: 'empty'|'error'|'loading' }>(),{kind:'empty'});
</script>
<template><div class="rounded-md border border-dashed border-slate-300 p-5 text-sm" :role="kind === 'error' ? 'alert' : kind === 'loading' ? 'status' : undefined"><p class="font-semibold text-slate-900">{{ title }}</p><p v-if="description" class="mt-1 leading-6 text-slate-600">{{ description }}</p><div v-if="$slots.default" class="mt-3"><slot /></div></div></template>
