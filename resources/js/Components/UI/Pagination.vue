<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Laravel paginator links. Phones show Previous / "Page x of y" / Next;
 * larger screens add the page numbers. Disabled ends are announced as such.
 */
const props = defineProps<{ previous?: string | null; next?: string | null; from: number | null; to: number | null; total: number; links?: Array<{ url: string | null; label: string; active: boolean }> }>();
const pages = computed(() => props.links?.slice(1, -1) ?? []);
const current = computed(() => pages.value.find(link => link.active)?.label ?? '1');
const last = computed(() => [...pages.value].reverse().find(link => /^\d+$/.test(link.label))?.label ?? '1');
</script>

<template>
  <nav aria-label="Pagination" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
    <p class="text-xs text-slate-600" aria-live="polite">Showing {{ from ?? 0 }}-{{ to ?? 0 }} of {{ total }}</p>
    <div class="flex items-center gap-2">
      <Link v-if="previous" :href="previous" preserve-scroll class="ui-button min-h-11 px-3" rel="prev"><ChevronLeft class="h-4 w-4" aria-hidden="true" />Previous</Link>
      <span v-else aria-disabled="true" class="ui-button min-h-11 cursor-not-allowed px-3 opacity-50"><ChevronLeft class="h-4 w-4" aria-hidden="true" />Previous</span>
      <span class="px-1 text-sm text-slate-700 sm:hidden">Page {{ current }} of {{ last }}</span>
      <template v-for="link in pages" :key="link.label">
        <Link v-if="link.url" :href="link.url" preserve-scroll class="ui-button min-h-11 min-w-11 justify-center px-3 max-sm:hidden" :class="link.active ? 'border-brand-300 bg-brand-50 text-brand-900' : ''" :aria-current="link.active ? 'page' : undefined" :aria-label="link.active ? `Page ${link.label}, current page` : `Page ${link.label}`">{{ link.label }}</Link>
        <span v-else class="inline-flex min-h-11 items-center px-1 text-sm text-slate-600 max-sm:hidden" aria-hidden="true">{{ link.label }}</span>
      </template>
      <Link v-if="next" :href="next" preserve-scroll class="ui-button min-h-11 px-3" rel="next">Next<ChevronRight class="h-4 w-4" aria-hidden="true" /></Link>
      <span v-else aria-disabled="true" class="ui-button min-h-11 cursor-not-allowed px-3 opacity-50">Next<ChevronRight class="h-4 w-4" aria-hidden="true" /></span>
    </div>
  </nav>
</template>
