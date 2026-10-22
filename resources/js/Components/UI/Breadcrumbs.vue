<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{ items: Array<{ label: string; href?: string }> }>();
</script>
<template><nav aria-label="Breadcrumb" class="min-w-0 text-xs text-slate-600"><ol class="flex flex-wrap items-center gap-2"><li v-for="(item,index) in items" :key="index" class="flex items-center gap-2"><span v-if="index" aria-hidden="true" class="text-slate-500">/</span><Link v-if="item.href" :href="item.href" class="inline-flex min-h-11 items-center hover:text-brand-800 hover:underline">{{ item.label }}</Link><span v-else aria-current="page" class="font-medium text-slate-800">{{ item.label }}</span></li></ol></nav></template>
