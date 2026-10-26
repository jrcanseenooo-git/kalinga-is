<script setup lang="ts">
import CpuAssignmentWorkspace from '@/Components/Mdt/CpuAssignmentWorkspace.vue';
defineProps<{ caseId: number; assignmentId: number }>();
</script>

<template>
  <CpuAssignmentWorkspace :case-id="caseId" :assignment-id="assignmentId" />
</template>
