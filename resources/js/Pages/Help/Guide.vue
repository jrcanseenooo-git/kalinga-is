<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Building2, ChevronDown, FileText, FolderOpen, Search, ShieldCheck, Sparkles, Users, WifiOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { HELP_ARTICLES, searchHelp, type HelpArticle } from '@/data/helpGuides';

const page = usePage();
const permissions = computed(() => new Set<string>((page.props.auth as { permissions?: string[] }).permissions ?? []));
const userName = computed(() => String((page.props.auth as { user?: { name?: string } }).user?.name ?? '').split(' ')[0]);
const articles = computed(() => HELP_ARTICLES.filter(article => !article.permission || permissions.value.has(article.permission)));

const CATEGORY_ICONS: Record<HelpArticle['category'], unknown> = {
  'Start here': BookOpen, 'Case work': FolderOpen, Coordination: Users, 'Field mode': WifiOff, Reports: FileText, 'Privacy & security': ShieldCheck, Settings: Building2,
};
const categories = computed(() => [...new Set(articles.value.map(article => article.category))]);
const activeCategory = ref<HelpArticle['category'] | 'All'>('All');
const query = ref('');
const open = ref<string | null>('first-day');

/** Matching guides for a typed question (word matching, no external AI). */
const matches = computed(() => searchHelp(query.value, articles.value));
const shown = computed(() => query.value.trim() ? matches.value : articles.value.filter(article => activeCategory.value === 'All' || article.category === activeCategory.value));
const suggestions = ['How do I refer a child?', 'Working with no signal', 'Print a case study report', 'Record financial assistance'];
function ask(text: string) { query.value = text; open.value = searchHelp(text, articles.value)[0]?.id ?? null; }
// Opened from Kalinga Assist with a question (?q=...).
const asked = new URLSearchParams(window.location.search).get('q');
if (asked) ask(asked.slice(0, 200));
</script>

<template>
  <Head title="Help & guides" />
  <AuthenticatedLayout>
    <div class="mx-auto max-w-5xl space-y-5">
      <section class="help-hero">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100">Help & guides</p>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">How can we help{{ userName ? `, ${userName}` : '' }}?</h1>
        <p class="mt-1 max-w-2xl text-sm text-brand-50">Step-by-step guides for the screens you use in Kalinga, shown for your role.</p>
        <label class="relative mt-4 block max-w-xl">
          <span class="sr-only">Ask a question or search the guides</span>
          <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
          <input v-model="query" type="search" placeholder="Ask a question, for example: how do I refer a child?" class="h-12 w-full rounded-xl border-0 pl-10 pr-3 text-sm text-slate-900 shadow-sm">
        </label>
        <div class="mt-3 flex flex-wrap gap-2"><button v-for="item in suggestions" :key="item" type="button" class="help-chip" @click="ask(item)">{{ item }}</button></div>
      </section>

      <nav v-if="!query.trim()" class="flex gap-2 overflow-x-auto pb-1" aria-label="Guide topics">
        <button type="button" class="topic" :class="activeCategory === 'All' ? 'active' : ''" :aria-pressed="activeCategory === 'All'" @click="activeCategory = 'All'">All guides</button>
        <button v-for="category in categories" :key="category" type="button" class="topic" :class="activeCategory === category ? 'active' : ''" :aria-pressed="activeCategory === category" @click="activeCategory = category"><component :is="CATEGORY_ICONS[category]" class="h-4 w-4" aria-hidden="true" />{{ category }}</button>
      </nav>
      <p v-else class="flex items-center gap-2 text-sm text-slate-700" role="status"><Sparkles class="h-4 w-4 text-brand-700" aria-hidden="true" />{{ matches.length ? `${matches.length} guide${matches.length === 1 ? '' : 's'} match your question.` : 'No guide matches yet. Try other words, or ask your system administrator.' }}<button type="button" class="ui-link min-h-9 text-xs" @click="query = ''">Clear</button></p>

      <section class="space-y-3" aria-label="Guides">
        <article v-for="article in shown" :key="article.id" class="ui-panel overflow-hidden">
          <button type="button" class="flex w-full items-start gap-3 p-4 text-left sm:p-5" :aria-expanded="open === article.id" :aria-controls="`guide-${article.id}`" @click="open = open === article.id ? null : article.id">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700"><component :is="CATEGORY_ICONS[article.category]" class="h-5 w-5" aria-hidden="true" /></span>
            <span class="min-w-0 flex-1"><span class="block text-xs font-semibold text-brand-700">{{ article.category }}</span><span class="block font-semibold text-slate-900">{{ article.title }}</span><span class="block text-sm text-slate-600">{{ article.summary }}</span></span>
            <ChevronDown class="mt-2 h-4 w-4 shrink-0 text-slate-500 transition-transform" :class="open === article.id ? 'rotate-180' : ''" aria-hidden="true" />
          </button>
          <div v-if="open === article.id" :id="`guide-${article.id}`" class="border-t border-slate-100 px-4 pb-5 pt-4 sm:px-5">
            <ol class="space-y-3">
              <li v-for="(step, index) in article.steps" :key="index" class="flex gap-3 text-sm text-slate-800"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-700 text-xs font-bold text-white">{{ index + 1 }}</span><span class="pt-0.5">{{ step }}</span></li>
            </ol>
            <ul v-if="article.tips?.length" class="mt-4 space-y-2"><li v-for="tip in article.tips" :key="tip" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950"><b>Tip:</b> {{ tip }}</li></ul>
            <Link v-if="article.link" :href="route(article.link.route)" class="ui-button-primary mt-4">{{ article.link.label }}</Link>
          </div>
        </article>
      </section>

      <section class="ui-panel p-5 text-sm text-slate-700">
        <h2 class="ui-section-title">Still stuck?</h2>
        <p class="mt-1">Contact your system administrator for account, access or device problems. For a suspected data breach, tell your Data Protection Officer at once.</p>
      </section>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.help-hero{border-radius:1rem;background:linear-gradient(135deg,#4c1d95,#6d28d9 60%,#7c3aed);padding:1.5rem;color:#fff}
.help-chip{min-height:2.25rem;border-radius:999px;background:rgb(255 255 255/.15);padding:0 .8rem;font-size:.78rem;font-weight:600;color:#fff}.help-chip:hover{background:rgb(255 255 255/.25)}
.topic{display:inline-flex;flex-shrink:0;min-height:2.5rem;align-items:center;gap:.4rem;border:1px solid var(--color-line);border-radius:999px;background:#fff;padding:0 .9rem;font-size:.82rem;font-weight:600;color:#475569;white-space:nowrap}.topic.active{border-color:#6d28d9;background:#f5f3ff;color:#5b21b6}
</style>
