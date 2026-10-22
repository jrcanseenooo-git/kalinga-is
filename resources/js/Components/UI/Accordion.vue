<script setup lang="ts">
defineProps<{ title:string }>();
</script>
<template><details class="ui-panel p-4"><summary class="min-h-11 cursor-pointer py-3 text-sm font-semibold text-slate-900">{{title}}</summary><slot/></details></template>
