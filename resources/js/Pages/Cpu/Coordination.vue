<script setup lang="ts">
import { formatPh, manilaDayKey, parseStored } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import CoordinationCaseSelect from '@/Components/CoordinationCaseSelect.vue';
import { useConnectWorkspace } from '@/composables/useConnectWorkspace';
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { ChevronUp, FileText, Flag, Hash, Lock, MessagesSquare, PenLine, RefreshCw, Reply, Search, SendHorizontal, ShieldCheck, SlidersHorizontal, TriangleAlert, Users, X } from '@lucide/vue';
import type { CoordinationCircle, CoordinationMessage, CoordinationProps } from '@/types/connect';

const props = defineProps<CoordinationProps>();
// All send, search, history, draft, capture and uncertainty behavior lives in the
// shared workspace composable; this page only lays it out as a group chat.
const workspace = useConnectWorkspace(props);
const { activeCircle, currentCircle, items, circleMembers, pagination, unread, loading, sending, capturing, busy, refreshRequired, uncertainSend, uncertainCapture,
  searchTerm, appliedSearch, requestError, notice, errorSummary, captureErrorSummary, composer, capture, selectedMessage, canPost, canCapture, dirty } = workspace;

const showMembers = ref(false);
const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);
const previews = ref<Record<number, CoordinationCircle>>(Object.fromEntries(props.circles.map(circle => [circle.id, { ...circle }])));
const circleList = computed(() => props.circles.map(circle => previews.value[circle.id] ?? circle));
const memberCount = computed(() => circleMembers.value.length || currentCircle.value?.member_count || 0);
const composerLocked = computed(() => sending.value || refreshRequired.value || uncertainSend.value);
const replyTarget = computed(() => items.value.find(message => message.id === composer.parent_id) ?? null);
const mentionedMembers = computed(() => circleMembers.value.filter(member => composer.mention_user_ids.includes(member.id)));
const optionsSet = computed(() => Boolean(composer.case_id || composer.is_urgent || composer.follow_up || composer.mention_user_ids.length));
if (props.circles[0]) previews.value[props.circles[0].id] = { ...previews.value[props.circles[0].id], unread: 0 };

// Presentation helpers only.
const avatarTones = ['bg-violet-100 text-violet-800', 'bg-sky-100 text-sky-800', 'bg-emerald-100 text-emerald-800', 'bg-amber-100 text-amber-800', 'bg-rose-100 text-rose-800', 'bg-teal-100 text-teal-800'];
const tone = (name: string) => avatarTones[[...name].reduce((sum, letter) => sum + letter.charCodeAt(0), 0) % avatarTones.length];
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]!.toUpperCase()).join('') || '?';
const roleLabel = (role: string) => String(role ?? '').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
const excerpt = (text: string, size = 90) => { const flat = text.replace(/\s+/g, ' ').trim(); return flat.length > size ? `${flat.slice(0, size)}...` : flat; };
const clock = (value: string) => formatPh(value, 'time');
const dayKey = (value: string) => manilaDayKey(value);
function dayLabel(value: string) {
  const today = new Date(); const yesterday = new Date(today.getTime() - 86400000);
  if (dayKey(value) === dayKey(today.toISOString())) return 'Today';
  if (dayKey(value) === dayKey(yesterday.toISOString())) return 'Yesterday';
  return formatPh(value, 'weekdayShortDate');
}
const listTime = (value?: string | null) => !value ? '' : dayKey(value) === dayKey(new Date().toISOString()) ? clock(value) : formatPh(value, 'dateShort');

// Consecutive messages from one author within five minutes share a header, like a group chat.
const rows = computed(() => items.value.map((message, index) => {
  const previous = items.value[index - 1];
  const newDay = !previous || dayKey(previous.created_at) !== dayKey(message.created_at);
  const grouped = !newDay && previous?.author === message.author && Boolean(previous?.is_own) === Boolean(message.is_own)
    && (parseStored(message.created_at)?.getTime() ?? 0) - (parseStored(previous!.created_at)?.getTime() ?? 0) < 5 * 60 * 1000;
  const parent = message.parent_id ? items.value.find(item => item.id === message.parent_id) ?? null : null;
  return { message, newDay, grouped, parent };
}));

async function scrollToBottom() { await nextTick(); if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; }
// Keep the conversation list preview in step with what this page has actually read back.
watch(items, list => {
  const id = activeCircle.value; const last = list[list.length - 1];
  if (id && last && !appliedSearch.value) previews.value[id] = { ...previews.value[id]!, last_author: last.author, last_excerpt: excerpt(last.body, 80), last_at: last.created_at, unread: 0 };
  if (!appliedSearch.value) void scrollToBottom();
}, { immediate: true });

async function openCircle(id: number) { showMembers.value = false; await workspace.chooseCircle(id); }
function onSelectCircle(event: Event) { void openCircle(Number((event.target as HTMLSelectElement).value)); }
async function loadEarlier() {
  const previousHeight = scroller.value?.scrollHeight ?? 0;
  await workspace.loadEarlier(); await nextTick();
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight - previousHeight;
}
function writeMessage() { input.value?.scrollIntoView({ block: 'nearest' }); input.value?.focus(); }
function onComposerKey(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); (event.target as HTMLTextAreaElement).form?.requestSubmit(); }
}
function toggleMention(id: number) {
  composer.mention_user_ids = composer.mention_user_ids.includes(id) ? composer.mention_user_ids.filter(item => item !== id) : [...composer.mention_user_ids, id];
}
const replyTo = (message: CoordinationMessage) => workspace.reply(message);
</script>

<template>
  <Head title="Kalinga Connect" />
  <AuthenticatedLayout>
    <section class="connect-shell ui-panel relative flex flex-col overflow-hidden p-0 lg:grid lg:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)_260px]">
      <!-- Groups -->
      <div class="flex shrink-0 flex-col border-b border-[var(--color-line)] lg:min-h-0 lg:border-b-0 lg:border-r">
        <div class="flex items-center justify-between gap-3 px-4 pt-3 lg:px-5 lg:pt-5">
          <div class="min-w-0">
            <h1 class="sr-only text-lg font-bold text-[var(--color-ink)] lg:not-sr-only">Kalinga Connect</h1>
            <p class="hidden text-xs text-slate-600 lg:block">CPU coordination groups</p>
          </div>
          <span v-if="unread" class="ui-pill bg-amber-100 text-amber-900">{{ unread }} unread</span>
        </div>
        <div class="px-3 py-3 lg:px-4 xl:hidden">
          <label for="coordination-circle" class="sr-only">Coordination circle</label>
          <select id="coordination-circle" :value="activeCircle ?? ''" aria-label="Coordination circle" :disabled="busy || uncertainSend || circleList.length < 2" class="ui-field min-h-10 text-sm" @change="onSelectCircle">
            <option v-for="circle in circleList" :key="circle.id" :value="circle.id">{{ circle.name }} - {{ circle.lgu_name }}</option>
          </select>
        </div>
        <nav aria-label="Coordination groups" class="hidden min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3 pb-4 lg:flex">
          <button v-for="circle in circleList" :key="circle.id" type="button" :aria-current="circle.id === activeCircle ? 'true' : undefined" :disabled="busy || uncertainSend"
            class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition disabled:cursor-not-allowed" :class="circle.id === activeCircle ? 'bg-brand-50 ring-1 ring-brand-200' : 'hover:bg-slate-50'" @click="openCircle(circle.id)">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold" :class="tone(circle.name)" aria-hidden="true">{{ initials(circle.name) }}</span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2"><span class="truncate text-sm font-semibold text-[var(--color-ink)]">{{ circle.name }}</span><span v-if="circle.last_at" class="shrink-0 text-[11px] text-slate-600">{{ listTime(circle.last_at) }}</span></span>
              <span class="flex items-center justify-between gap-2"><span class="truncate text-xs text-slate-600"><span class="font-medium text-slate-600">{{ circle.lgu_name }}</span> - {{ circle.last_excerpt ? `${circle.last_author}: ${circle.last_excerpt}` : 'No messages yet' }}</span><span v-if="circle.unread" class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-brand-700 px-1.5 text-[10px] font-bold text-white">{{ circle.unread }}<span class="sr-only"> unread</span></span></span>
            </span>
          </button>
          <p v-if="!circleList.length" class="px-2 py-6 text-sm text-slate-600">You are not an active member of any coordination group.</p>
        </nav>
        <p class="mx-4 mb-4 hidden items-start gap-2 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600 lg:flex"><ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-emerald-700" aria-hidden="true" />Group membership does not authorize case access. Use the MDT referral pathway for case-specific work.</p>
      </div>

      <!-- Conversation -->
      <div class="relative flex min-h-0 min-w-0 flex-1 flex-col">
        <header class="flex flex-wrap items-center gap-2 border-b border-[var(--color-line)] px-4 py-3 lg:px-5">
          <span v-if="currentCircle" class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold" :class="tone(currentCircle.name)" aria-hidden="true">{{ initials(currentCircle.name) }}</span>
          <div class="min-w-0 flex-1">
            <h2 class="truncate text-base font-semibold text-[var(--color-ink)]">{{ currentCircle ? currentCircle.name : 'No group selected' }}</h2>
            <p class="truncate text-xs text-slate-600">{{ currentCircle?.lgu_name }}<template v-if="memberCount"> - {{ memberCount }} eligible {{ memberCount === 1 ? 'member' : 'members' }}</template></p>
          </div>
          <form role="search" data-skip-generic-confirm="true" class="order-last flex w-full items-center sm:order-none sm:w-52 xl:w-60" @submit.prevent="workspace.search()">
            <div class="relative min-w-0 flex-1">
              <input v-model="searchTerm" type="search" aria-label="Search authorized coordination messages" placeholder="Search messages" maxlength="100" :disabled="busy || refreshRequired" class="h-10 w-full rounded-xl border-transparent bg-[#f3f2f8] pl-3.5 pr-11 text-sm focus:bg-white" />
              <button type="submit" aria-label="Search" :disabled="busy || refreshRequired" class="absolute right-0.5 top-1/2 grid h-9 w-10 -translate-y-1/2 place-items-center rounded-lg text-slate-600 hover:bg-white hover:text-brand-700"><Search class="h-4 w-4" aria-hidden="true" /></button>
            </div>
          </form>
          <button type="button" class="ui-button h-10 min-h-10 w-10 px-0" aria-label="Refresh conversation" :disabled="busy" @click="workspace.loadCircle()"><RefreshCw class="h-4 w-4" :class="loading ? 'animate-spin' : ''" aria-hidden="true" /></button>
          <button type="button" class="ui-button h-10 min-h-10 w-10 px-0 sm:hidden" aria-label="Write message" @click="writeMessage"><PenLine class="h-4 w-4" aria-hidden="true" /></button>
          <button type="button" class="ui-button h-10 min-h-10 w-10 px-0 xl:hidden" aria-label="Members" :aria-expanded="showMembers" aria-controls="circle-members" @click="showMembers = !showMembers"><Users class="h-4 w-4" aria-hidden="true" /></button>
        </header>

        <div v-if="appliedSearch" class="flex items-center justify-between gap-3 border-b border-[var(--color-line)] bg-brand-50/60 px-5 py-2 text-xs text-brand-900">
          <span>Search results for "{{ appliedSearch }}"</span>
          <button type="button" class="font-semibold underline-offset-2 hover:underline" :disabled="busy" @click="workspace.loadCircle()">Back to conversation</button>
        </div>
        <div v-if="requestError && !selectedMessage" ref="errorSummary" role="alert" tabindex="-1" class="mx-4 mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">{{ requestError }}</div>
        <p role="status" class="sr-only">{{ notice }}</p>
        <p v-if="notice && notice !== 'Message sent.'" class="mx-4 mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ notice }}</p>

        <div ref="scroller" class="chat-scroll min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-5" role="region" tabindex="0" aria-label="Coordination messages" :aria-busy="loading">
          <div v-if="pagination.has_more" class="mb-3 flex justify-center">
            <button :disabled="busy || refreshRequired" type="button" class="ui-button min-h-9 gap-1.5 rounded-full px-4 text-xs" @click="loadEarlier"><ChevronUp class="h-4 w-4" aria-hidden="true" />Load earlier {{ appliedSearch ? 'results' : 'messages' }}</button>
          </div>
          <template v-for="row in rows" :key="row.message.id">
            <div v-if="row.newDay" class="my-4 flex items-center gap-3 text-[11px] font-semibold text-slate-600" aria-hidden="true"><span class="h-px flex-1 bg-[var(--color-line)]" />{{ dayLabel(row.message.created_at) }}<span class="h-px flex-1 bg-[var(--color-line)]" /></div>
            <article class="group flex gap-2.5" :class="[row.message.is_own ? 'flex-row-reverse' : '', row.grouped ? 'mt-1' : 'mt-4']" :aria-label="`${row.message.is_own ? 'You' : row.message.author}, ${clock(row.message.created_at)}`">
              <span v-if="!row.message.is_own" class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold" :class="[tone(row.message.author), row.grouped ? 'invisible' : '']" aria-hidden="true">{{ initials(row.message.author) }}</span>
              <div class="flex min-w-0 max-w-[85%] flex-col sm:max-w-[70%]" :class="row.message.is_own ? 'items-end' : 'items-start'">
                <p v-if="!row.grouped" class="mb-1 flex flex-wrap items-baseline gap-x-2 px-1 text-xs">
                  <span class="font-semibold" :class="row.message.is_own ? 'text-slate-700' : 'text-brand-800'">{{ row.message.is_own ? 'You' : row.message.author }}</span>
                  <span v-if="!row.message.is_own" class="text-slate-600">{{ roleLabel(row.message.author_role) }}</span>
                  <time :datetime="row.message.created_at" class="text-slate-600">{{ clock(row.message.created_at) }}</time>
                </p>
                <div class="min-w-0 max-w-full rounded-2xl px-3.5 py-2.5 text-sm leading-6" :class="row.message.is_own ? 'rounded-tr-md bg-brand-700 text-white' : 'rounded-tl-md border border-[var(--color-line)] bg-white text-slate-800'">
                  <div v-if="row.message.parent_id" class="mb-1.5 rounded-lg border-l-2 px-2.5 py-1 text-xs" :class="row.message.is_own ? 'border-white/70 bg-brand-800 text-white' : 'border-brand-400 bg-brand-50 text-slate-600'">
                    <span class="font-semibold">{{ row.parent ? `Reply to ${row.parent.is_own ? 'you' : row.parent.author}` : 'Thread reply' }}</span>
                    <span v-if="row.parent" class="block truncate">{{ excerpt(row.parent.body, 70) }}</span>
                  </div>
                  <p class="whitespace-pre-wrap break-words">{{ row.message.body }}</p>
                  <p v-if="row.message.mentions?.length" class="mt-1 text-xs font-semibold" :class="row.message.is_own ? 'text-white' : 'text-brand-800'"><span v-for="mention in row.message.mentions" :key="mention.id" class="mr-1.5">@{{ mention.name }}</span></p>
                  <div v-if="row.message.is_urgent || row.message.follow_up || row.message.case_reference" class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span v-if="row.message.is_urgent" class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800"><TriangleAlert class="h-3 w-3" aria-hidden="true" />Urgent</span>
                    <span v-if="row.message.follow_up" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900"><Flag class="h-3 w-3" aria-hidden="true" />Follow-up</span>
                    <span v-if="row.message.case_reference" class="inline-flex flex-wrap items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="row.message.is_own ? 'bg-brand-900 text-white' : 'bg-brand-50 text-brand-800'">
                      <Hash class="h-3 w-3" aria-hidden="true" />Case: {{ row.message.case_reference }}
                      <a v-if="row.message.can_open_case && row.message.case_id" :href="route('cases.show', row.message.case_id)" class="ml-1 underline underline-offset-2">Open</a>
                      <span v-else class="ml-1 inline-flex items-center gap-0.5 font-normal opacity-80"><Lock class="h-3 w-3" aria-hidden="true" />Requires separate authorization</span>
                    </span>
                  </div>
                </div>
                <div v-if="canPost || canCapture" class="msg-actions mt-1 flex gap-1" :class="row.message.is_own ? 'flex-row-reverse' : ''">
                  <button v-if="canPost" type="button" :disabled="busy || refreshRequired || uncertainSend" class="inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 disabled:opacity-50 lg:min-h-7" @click="replyTo(row.message)"><Reply class="h-3.5 w-3.5" aria-hidden="true" />Reply</button>
                  <button v-if="canCapture" type="button" :disabled="busy || uncertainCapture" class="inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 disabled:opacity-50 lg:min-h-7" @click="workspace.captureMessage(row.message)"><FileText class="h-3.5 w-3.5" aria-hidden="true" />Record as note</button>
                </div>
              </div>
            </article>
          </template>
          <div v-if="!items.length && !loading" class="grid h-full place-items-center py-10 text-center">
            <div>
              <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-brand-50 text-brand-700"><MessagesSquare class="h-7 w-7" aria-hidden="true" /></span>
              <p class="mt-3 text-sm font-semibold text-slate-800">{{ appliedSearch ? 'No messages match your search.' : 'No authorized coordination messages.' }}</p>
              <p v-if="!appliedSearch && canPost" class="mt-1 text-xs text-slate-600">Start the conversation with an operational update.</p>
            </div>
          </div>
        </div>

        <!-- Composer: the generic submit confirmation stays on, as verified for this workflow. -->
        <form v-if="canPost" aria-label="Send coordination message" class="relative border-t border-[var(--color-line)] bg-white px-3 py-3 sm:px-5" @submit.prevent="workspace.post()">
          <div v-if="composer.parent_id" class="mb-2 flex items-center justify-between gap-3 rounded-xl border-l-2 border-brand-500 bg-brand-50 px-3 py-2 text-xs text-slate-700">
            <span class="min-w-0"><span class="font-semibold">Replying to a message in this group</span><span v-if="replyTarget" class="block truncate text-slate-600">{{ replyTarget.is_own ? 'You' : replyTarget.author }}: {{ excerpt(replyTarget.body, 90) }}</span></span>
            <button type="button" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg hover:bg-white" aria-label="Cancel reply" :disabled="composerLocked" @click="composer.parent_id = null"><X class="h-4 w-4" aria-hidden="true" /></button>
          </div>
          <div v-if="uncertainSend || (dirty && refreshRequired)" class="mb-2 flex flex-wrap items-center gap-2 rounded-xl border border-amber-300 bg-kalinga-warm px-3 py-2 text-xs text-amber-950">
            <span class="min-w-0 flex-1">{{ uncertainSend ? 'Refresh and review the conversation before deciding whether this message still needs sending.' : 'Unsent text remains in this tab. Refresh the conversation before sending.' }}</span>
            <button v-if="uncertainSend && !refreshRequired" type="button" class="ui-button min-h-9 text-xs" @click="workspace.resumeSend()">Review complete - allow send</button>
            <button type="button" class="ui-button min-h-9 text-xs" :disabled="busy" @click="workspace.discardMessage()">Discard unfinished message</button>
          </div>
          <details class="composer-options mb-2" :open="optionsSet || undefined">
            <summary class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-brand-800 hover:bg-brand-50"><SlidersHorizontal class="h-3.5 w-3.5" aria-hidden="true" />Case reference, urgency &amp; mentions</summary>
            <div class="mt-2 grid gap-3 rounded-xl border border-[var(--color-line)] bg-slate-50/60 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
              <CoordinationCaseSelect v-if="activeCircle" id="coordination-case" v-model="composer.case_id" :circle-id="activeCircle" :disabled="composerLocked" :error="composer.errors.case_id" allow-known-reference @access-denied="workspace.reauthorize()" />
              <div class="space-y-3">
                <div class="flex flex-wrap gap-2">
                  <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-[var(--color-line)] bg-white px-3 text-sm"><input v-model="composer.is_urgent" type="checkbox" :disabled="composerLocked" class="rounded border-slate-300 text-rose-600" /><TriangleAlert class="h-4 w-4 text-rose-600" aria-hidden="true" />Urgent</label>
                  <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-[var(--color-line)] bg-white px-3 text-sm"><input v-model="composer.follow_up" type="checkbox" :disabled="composerLocked" class="rounded border-slate-300 text-amber-600" /><Flag class="h-4 w-4 text-amber-600" aria-hidden="true" />Follow-up</label>
                </div>
                <fieldset :disabled="composerLocked">
                  <legend class="text-xs font-semibold text-slate-600">Mention members</legend>
                  <div class="mt-1.5 flex max-h-32 flex-wrap gap-1.5 overflow-y-auto">
                    <label v-for="member in circleMembers" :key="member.id" class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 text-xs" :class="composer.mention_user_ids.includes(member.id) ? 'border-sky-300 bg-sky-50 text-sky-900' : 'border-[var(--color-line)] bg-white text-slate-700'">
                      <input type="checkbox" class="sr-only" :checked="composer.mention_user_ids.includes(member.id)" @change="toggleMention(member.id)" />@{{ member.name }}
                    </label>
                  </div>
                </fieldset>
              </div>
            </div>
          </details>
          <div class="flex items-end gap-2">
            <label for="coordination-body" class="sr-only">New message</label>
            <textarea id="coordination-body" ref="input" v-model="composer.body" aria-label="New message" required rows="1" maxlength="3000" :disabled="composerLocked"
              class="composer-input min-w-0 flex-1 resize-none rounded-2xl border-transparent bg-[#f3f2f8] px-4 py-2.5 text-sm leading-6 focus:bg-white disabled:opacity-60"
              placeholder="Type a message..." :aria-invalid="Boolean(composer.errors.body) || undefined" @keydown="onComposerKey" />
            <button type="submit" :disabled="composerLocked || busy || !canPost" aria-label="Send message" class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-700 text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-50"><SendHorizontal class="h-5 w-5" aria-hidden="true" /></button>
          </div>
          <p v-if="composer.errors.body" class="mt-2 text-sm text-rose-700">{{ composer.errors.body }}</p>
          <p class="mt-1.5 hidden text-[11px] text-slate-600 sm:block">Enter to send, Shift + Enter for a new line. Operational follow-up only - do not put sensitive narratives here.</p>
        </form>
      </div>

      <!-- Members -->
      <aside id="circle-members" class="members-panel border-[var(--color-line)] bg-white xl:static xl:block xl:border-l" :class="showMembers ? 'is-open' : ''" aria-label="Circle members">
        <div class="flex items-center justify-between gap-2 border-b border-[var(--color-line)] px-4 py-3.5">
          <h2 class="text-sm font-semibold text-[var(--color-ink)]">Circle members <span class="font-normal text-slate-600">({{ circleMembers.length }})</span></h2>
          <button type="button" class="grid h-9 w-9 place-items-center rounded-lg hover:bg-slate-100 xl:hidden" aria-label="Close members" @click="showMembers = false"><X class="h-4 w-4" aria-hidden="true" /></button>
        </div>
        <ul class="space-y-1 overflow-y-auto p-3">
          <li v-for="member in circleMembers" :key="member.id" class="flex items-center gap-3 rounded-lg px-2 py-2">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold" :class="tone(member.name)" aria-hidden="true">{{ initials(member.name) }}</span>
            <div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ member.name }}</p><p class="truncate text-[11px] text-slate-600">{{ roleLabel(member.membership_role) }} - {{ roleLabel(member.role) }}</p></div>
          </li>
          <li v-if="!circleMembers.length" class="px-2 py-4 text-sm text-slate-600">No active members loaded.</li>
        </ul>
        <p class="mx-3 mb-3 rounded-xl bg-sky-50 p-3 text-xs leading-5 text-sky-950">A reviewed professional summary can be recorded through your current case authority. The original chat is retained separately.</p>
      </aside>

      <!-- Reviewed capture -->
      <Modal :show="Boolean(selectedMessage)" max-width="xl" :closeable="!capturing" @close="workspace.closeCapture()">
        <form aria-labelledby="capture-title" class="w-full p-5" @submit.prevent="workspace.saveCapture()">
          <h2 id="capture-title" class="text-lg font-bold text-slate-900">Record coordination message</h2>
          <p class="mt-1 text-sm text-slate-600">Review and write a professional summary. The original chat thread is not copied into the formal record.</p>
          <blockquote v-if="selectedMessage" class="mt-3 rounded-xl border-l-2 border-brand-400 bg-slate-50 px-3 py-2 text-sm text-slate-600"><span class="block text-xs font-semibold text-slate-700">{{ selectedMessage.author }} - {{ clock(selectedMessage.created_at) }}</span>{{ excerpt(selectedMessage.body, 220) }}</blockquote>
          <div v-if="requestError" ref="captureErrorSummary" role="alert" tabindex="-1" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ requestError }}</div>
          <fieldset :disabled="capturing || uncertainCapture" class="mt-4 space-y-3">
            <legend class="sr-only">Capture fields</legend>
            <CoordinationCaseSelect v-if="activeCircle" id="capture-case" v-model="capture.case_id" :circle-id="activeCircle" required :error="capture.errors.case_id" @access-denied="workspace.reauthorize()" />
            <label class="block"><span class="text-xs font-semibold text-slate-600">Record type</span>
              <select v-model="capture.record_type" aria-label="Record type" class="ui-field mt-1"><option value="progress_note">Progress Note</option><option value="mdt_note">MDT Note - requires active case grant</option></select></label>
            <label class="block"><span class="text-xs font-semibold text-slate-600">Professional summary</span>
              <textarea v-model="capture.professional_summary" aria-label="Professional summary" required rows="5" maxlength="5000" class="ui-field mt-1" /></label>
            <p v-if="capture.errors.professional_summary" class="text-sm text-rose-700">{{ capture.errors.professional_summary }}</p>
            <label class="flex items-center gap-2 text-sm"><input v-model="capture.confirmed" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" /><span class="text-slate-700">I reviewed this summary and confirm formal record creation.</span></label>
          </fieldset>
          <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="ui-button" :disabled="capturing" @click="workspace.closeCapture()">Cancel</button>
            <button type="submit" :disabled="capturing || uncertainCapture" class="ui-button ui-button-primary disabled:opacity-50">{{ capturing ? 'Saving...' : 'Confirm record creation' }}</button>
          </div>
        </form>
      </Modal>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
/* The conversation scrolls inside the card, like a chat app. */
.connect-shell { height: calc(100dvh - 172px); min-height: 560px; }
@media (min-width: 768px) { .connect-shell { height: calc(100dvh - 184px); } }
@media (min-width: 1024px) { .connect-shell { height: calc(100dvh - 132px); grid-template-rows: minmax(0, 1fr); } }
.chat-scroll { background: linear-gradient(180deg, #fbfaff 0%, #f7f6fb 100%); scrollbar-width: thin; }
.composer-input { max-height: 9rem; field-sizing: content; }
.composer-options > summary { list-style: none; }
.composer-options > summary::-webkit-details-marker { display: none; }
@media (min-width: 1024px) { .msg-actions { opacity: 0; transition: opacity .15s; } .group:hover .msg-actions, .group:focus-within .msg-actions { opacity: 1; } }
.members-panel { display: none; }
.members-panel.is-open { position: absolute; inset: 0 0 0 auto; z-index: 20; display: block; width: min(300px, 90%); box-shadow: -12px 0 32px rgba(31, 26, 61, .12); border-left: 1px solid var(--color-line); }
@media (min-width: 1280px) { .members-panel, .members-panel.is-open { position: static; display: block; width: auto; box-shadow: none; } }
</style>
