<script setup lang="ts">
import PageHeader from '@/Components/PageHeader.vue';
defineProps<{title:string;generatedAt:string;notice:string}>();
</script>
<template><section class="mx-auto max-w-5xl"><PageHeader :title="title" eyebrow="Authorized working summary" :description="`Generated ${generatedAt}`"><template #actions><slot name="actions"/></template></PageHeader><p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">{{notice}}</p><slot/></section></template>
