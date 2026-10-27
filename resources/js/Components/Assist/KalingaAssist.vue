<script setup lang="ts">
/**
 * Kalinga Assist: a helper that opens from any page. It shows what needs the
 * user's attention (the same rules as the dashboard), tips for the page in
 * view, and answers questions from the Help guides. Everything is rule-based
 * and explainable: no external AI, and nothing typed here leaves Kalinga.
 */
import Modal from '@/Components/Modal.vue';
import { HELP_ARTICLES, searchHelp } from '@/data/helpGuides';
import { tipsFor } from '@/data/assistTips';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, BookOpen, Lightbulb, Search, Sparkles } from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ show: boolean }>();
const emit = defineEmits<{ close: [] }>();
const page = usePage();
const permissions = computed(() => new Set<string>((page.props.auth as { permissions?: string[] }).permissions ?? []));
const articles = computed(() => HELP_ARTICLES.filter(article => !article.permission || permissions.value.has(article.permission)));
const tips = computed(() => tipsFor(page.component));
const guides = computed(() => tips.value.guides.map(id => articles.value.find(article => article.id === id)).filter(Boolean) as typeof HELP_ARTICLES);

type Item = { tone: 'urgent' | 'action' | 'info'; title: string; text: string; href: string };
const items = ref<Item[]>([]);
const total = ref(0);
const state = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
async function load() {
    state.value = 'loading';
    try {
        const { data } = await axios.get<{ items: Item[]; total: number }>(route('assist.index'));
        items.value = data.items; total.value = data.total; state.value = 'ready';
    } catch { state.value = 'error'; }
}
watch(() => props.show, open => { if (open) void load(); });

const question = ref('');
const answers = computed(() => searchHelp(question.value, articles.value).slice(0, 3));
const go = (href: string) => { emit('close'); router.visit(href); };
const toneClass: Record<Item['tone'], string> = { urgent: 'border-rose-300 bg-rose-50', action: 'border-amber-300 bg-amber-50', info: 'border-slate-200 bg-white' };
</script>

<template>
    <Modal :show="show" max-width="lg" aria-labelledby="assist-title" @close="emit('close')">
        <section class="p-5 sm:p-6">
            <header class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-violet-100 text-violet-700" aria-hidden="true"><Sparkles class="h-6 w-6" /></span>
                <div class="min-w-0 flex-1">
                    <h2 id="assist-title" class="text-lg font-semibold text-slate-900">Kalinga Assist</h2>
                    <p class="text-sm text-slate-600">What needs you, tips for this page, and answers from the guides. Rule-based; nothing you type leaves Kalinga.</p>
                </div>
            </header>

            <form class="mt-5" role="search" @submit.prevent>
                <label for="assist-question" class="text-sm font-semibold text-slate-800">Ask a question</label>
                <div class="relative mt-1"><Search class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                    <input id="assist-question" v-model="question" type="search" class="ui-field min-h-12 pl-10 text-base" placeholder="For example: how do I refer a child?" autocomplete="off"></div>
            </form>
            <div v-if="question.trim()" class="mt-3 space-y-2" aria-live="polite">
                <p v-if="!answers.length" class="text-sm text-slate-600">No guide matches yet. Try other words, or open Help & guides.</p>
                <article v-for="article in answers" :key="article.id" class="rounded-xl border border-violet-200 bg-violet-50/60 p-3">
                    <h3 class="font-semibold text-violet-950">{{ article.title }}</h3>
                    <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-slate-800"><li v-for="(step, index) in article.steps.slice(0, 3)" :key="index">{{ step }}</li></ol>
                    <Link :href="route('help.guide', { q: question })" class="ui-link" @click="emit('close')">Read the full guide <ArrowRight class="h-4 w-4" aria-hidden="true" /></Link>
                </article>
            </div>

            <section class="mt-6" aria-labelledby="assist-now-title">
                <h3 id="assist-now-title" class="flex items-center gap-2 font-semibold text-slate-900"><AlertTriangle class="h-4 w-4 text-amber-700" aria-hidden="true" />Needs your attention</h3>
                <p v-if="state === 'loading'" class="mt-2 text-sm text-slate-600">Checking...</p>
                <p v-else-if="state === 'error'" class="mt-2 text-sm text-rose-700">Could not check right now. Your dashboard has the same list.</p>
                <p v-else-if="state === 'ready' && !items.length" class="mt-2 text-sm text-slate-600">All caught up - nothing needs you right now.</p>
                <ul v-else class="mt-2 space-y-2">
                    <li v-for="(item, index) in items" :key="index"><button type="button" class="flex w-full items-start gap-2 rounded-xl border p-3 text-left hover:shadow-sm" :class="toneClass[item.tone]" @click="go(item.href)">
                        <span class="min-w-0 flex-1"><span class="block font-semibold text-slate-900">{{ item.title }}</span><span class="block text-sm text-slate-700">{{ item.text }}</span></span><ArrowRight class="mt-1 h-4 w-4 shrink-0 text-slate-500" aria-hidden="true" />
                    </button></li>
                </ul>
                <p v-if="total > items.length" class="mt-2 text-sm text-slate-600">{{ total - items.length }} more on your <Link :href="route('dashboard')" class="ui-link" @click="emit('close')">dashboard</Link>.</p>
            </section>

            <section class="mt-6" aria-labelledby="assist-tips-title">
                <h3 id="assist-tips-title" class="flex items-center gap-2 font-semibold text-slate-900"><Lightbulb class="h-4 w-4 text-violet-700" aria-hidden="true" />Tips: {{ tips.title }}</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-800"><li v-for="tip in tips.tips" :key="tip">{{ tip }}</li></ul>
                <div v-if="guides.length" class="mt-3 flex flex-wrap gap-2">
                    <Link v-for="article in guides" :key="article.id" :href="route('help.guide', { q: article.title })" class="inline-flex min-h-10 items-center gap-1.5 rounded-full border border-slate-300 px-3 text-sm font-medium text-slate-800 hover:border-brand-400" @click="emit('close')"><BookOpen class="h-4 w-4" aria-hidden="true" />{{ article.title }}</Link>
                </div>
            </section>
            <div class="mt-6 flex justify-end"><button type="button" class="ui-button min-h-12 px-5" @click="emit('close')">Close</button></div>
        </section>
    </Modal>
</template>
