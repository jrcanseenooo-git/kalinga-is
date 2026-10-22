<script setup lang="ts">
defineProps<{ title: string; description?: string; id?: string }>();
</script>
<template><section class="ui-panel" :aria-labelledby="id"><header v-if="title" class="ui-panel-header"><div><h2 :id="id" class="ui-section-title">{{ title }}</h2><p v-if="description" class="mt-1 text-xs leading-5 text-slate-600">{{ description }}</p></div><slot name="actions" /></header><div class="p-4 sm:p-5"><slot /></div><footer v-if="$slots.footer" class="border-t border-slate-200 px-4 py-3 sm:px-5"><slot name="footer" /></footer></section></template>
