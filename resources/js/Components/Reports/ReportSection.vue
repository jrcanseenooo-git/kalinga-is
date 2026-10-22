<script setup lang="ts">
/** One numbered report section with a flat heading bar. */
defineProps<{ num: number | string; title: string; span?: boolean }>();
</script>

<template>
  <section class="rpt-sec" :class="span ? 'rpt-span2' : ''">
    <h2><span class="rpt-num">{{ num }}</span>{{ title }}</h2>
    <div class="rpt-body"><slot /></div>
  </section>
</template>
