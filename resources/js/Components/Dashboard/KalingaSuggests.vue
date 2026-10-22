<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { ref } from 'vue';

type Suggestion = { kind: string; tone: 'urgent' | 'action' | 'info'; title: string; text: string; case_id: number; case_number: string; section: string };
defineProps<{ items: Suggestion[] }>();
const showAll = ref(false);
</script>

<template>
  <section class="ui-panel overflow-hidden" aria-labelledby="kalinga-suggests-title">
    <header class="ui-panel-header bg-gradient-to-r from-brand-50 to-white">
      <div><h2 id="kalinga-suggests-title" class="ui-section-title flex items-center gap-2"><Sparkles class="h-4 w-4 text-brand-700" aria-hidden="true" />Kalinga suggests</h2><p class="text-xs text-slate-600">Next actions found in your cases by simple rules: overdue referrals, safety reviews, missing assessments and notes.</p></div>
    </header>
    <ul v-if="items.length" class="divide-y divide-slate-100">
      <li v-for="(item, index) in (showAll ? items : items.slice(0, 4))" :key="index">
        <Link :href="`${route('cases.show', item.case_id)}?section=${item.section}`" class="flex items-start gap-3 px-4 py-3 hover:bg-[#faf9fd] sm:px-5">
          <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="item.tone === 'urgent' ? 'bg-rose-500' : item.tone === 'action' ? 'bg-amber-500' : 'bg-sky-500'" aria-hidden="true" />
          <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-slate-900">{{ item.title }}<span class="sr-only"> ({{ item.tone === 'urgent' ? 'urgent' : item.tone === 'action' ? 'to do' : 'reminder' }})</span></span><span class="block text-xs text-slate-600">{{ item.text }}</span></span>
          <span class="shrink-0 rounded-md bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-800">{{ item.case_number }}</span>
        </Link>
      </li>
    </ul>
    <p v-else class="px-5 py-4 text-sm text-slate-600">Nothing urgent. Your cases are up to date.</p>
    <button v-if="items.length > 4" type="button" class="ui-link mx-5 mb-3" @click="showAll = !showAll">{{ showAll ? 'Show fewer' : `Show all ${items.length}` }}</button>
  </section>
</template>
