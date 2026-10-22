<script setup lang="ts">
defineProps<{online:boolean}>();
</script>
<template><span aria-live="polite" class="inline-flex items-center gap-1.5 text-xs font-medium" :class="online?'text-emerald-800':'text-amber-900'"><span aria-hidden="true" class="h-2 w-2 rounded-full bg-current"/>{{online?'Online':'Offline'}}</span></template>
