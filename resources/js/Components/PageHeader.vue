<script setup lang="ts">
withDefaults(defineProps<{ title:string; description?:string; eyebrow?:string }>(),{});
</script>
<template><header class="flex flex-col justify-between gap-4 pb-1 sm:flex-row sm:items-end"><div class="min-w-0"><p v-if="eyebrow" class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand-700">{{ eyebrow }}</p><h1 class="text-2xl font-bold tracking-tight text-[var(--color-ink)] sm:text-[28px]">{{ title }}</h1><p v-if="description" class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ description }}</p></div><div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2"><slot name="actions" /></div></header></template>
