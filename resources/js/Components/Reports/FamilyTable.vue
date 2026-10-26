<script setup lang="ts">
import type { FamilyMember } from '@/types/reports';
import { ageOn, capitalize, initialsOf } from '@/utils/reportFormat';

defineProps<{ family: FamilyMember[]; reveal: boolean }>();
</script>

<template>
  <div class="rpt-wrap" tabindex="0" role="region" aria-label="Family composition table">
    <table class="rpt-table">
      <thead><tr><th>Name</th><th>Age</th><th>Civil status</th><th>Relationship</th><th>Education</th><th>Occupation</th><th>Address</th></tr></thead>
      <tbody>
        <tr v-for="(member, index) in family" :key="index"><td>{{ reveal ? member.name : initialsOf(member.name) }}</td><td>{{ ageOn(member.birthdate) ?? '-' }}</td><td>{{ member.civil_status || '-' }}</td><td>{{ capitalize(member.relationship_type) }}</td><td>{{ member.education || '-' }}</td><td>{{ member.occupation || '-' }}</td><td>{{ [reveal ? member.street : null, member.area].filter(Boolean).join(', ') || '-' }}</td></tr>
        <tr v-if="!family.length"><td colspan="7" class="rpt-empty">No family members recorded.</td></tr>
      </tbody>
    </table>
  </div>
</template>
