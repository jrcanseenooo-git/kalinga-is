<script setup lang="ts">
import type { FamilyMember } from '@/types/reports';
import { ageOn, capitalize, initialsOf } from '@/utils/reportFormat';

defineProps<{ family: FamilyMember[]; reveal: boolean }>();
</script>

<template>
  <div class="rpt-wrap" tabindex="0" role="region" aria-label="Family composition table">
    <table class="rpt-table">
      <thead><tr><th>Name</th><th>Relationship</th><th>Age</th><th>Sex</th><th>Education</th><th>Occupation</th></tr></thead>
      <tbody>
        <tr v-for="(member, index) in family" :key="index"><td>{{ reveal ? member.name : initialsOf(member.name) }}</td><td>{{ capitalize(member.relationship_type) }}</td><td>{{ ageOn(member.birthdate) ?? '-' }}</td><td>{{ member.sex || '-' }}</td><td>{{ member.education || '-' }}</td><td>{{ member.occupation || '-' }}</td></tr>
        <tr v-if="!family.length"><td colspan="6" class="rpt-empty">No family members recorded.</td></tr>
      </tbody>
    </table>
  </div>
</template>
