<script setup lang="ts">
defineProps<{ id: string; label: string; error?: string; hint?: string; required?: boolean }>();
</script>
<template><div class="min-w-0"><label :for="id" class="block text-sm font-medium text-slate-800">{{ label }}<span v-if="required" aria-hidden="true"> *</span></label><div class="mt-1"><slot :invalid="Boolean(error)" :described-by="error || hint ? `${id}-help` : undefined" /></div><p v-if="error || hint" :id="`${id}-help`" class="mt-1 text-xs leading-5" :class="error ? 'text-rose-800' : 'text-slate-600'" :role="error ? 'alert' : undefined">{{ error || hint }}</p></div></template>
