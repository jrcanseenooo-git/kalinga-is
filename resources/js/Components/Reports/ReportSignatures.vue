<script setup lang="ts">
/**
 * Signature block. "Prepared by" is the case manager; "Noted by" is the office
 * head from the LGU profile. Extra blocks (for example the conforme of the
 * child and parent or guardian) print as blank lines to be signed by hand.
 */
defineProps<{
  preparedBy: { name: string; position: string | null }; officeShort?: string | null;
  notedBy: { name: string; position: string | null; designation: string | null } | null;
  extra?: { label: string; role: string }[];
}>();
</script>

<template>
  <div class="rpt-sign">
    <div><p class="lbl">Prepared by:</p><p class="sig">{{ preparedBy.name }}</p><p class="pos">{{ preparedBy.position || 'Case manager' }}</p><p v-if="officeShort" class="pos">{{ officeShort }}</p></div>
    <div v-for="item in extra ?? []" :key="item.label"><p class="lbl">{{ item.label }}</p><p class="sig">&nbsp;</p><p class="pos">{{ item.role }}</p></div>
    <div v-if="notedBy"><p class="lbl">Noted by:</p><p class="sig">{{ notedBy.name }}</p><p class="pos">{{ notedBy.position }}</p><p v-if="notedBy.designation" class="pos">{{ notedBy.designation }}</p></div>
  </div>
  <p v-if="!notedBy" class="rpt-warn no-print">Add the office head in LGU profile &amp; signatories to print the "Noted by" signature.</p>
</template>
