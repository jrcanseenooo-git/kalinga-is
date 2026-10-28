<script setup lang="ts">
import { refreshNavBadges } from '@/utils/navBadges';
import { askConfirm } from '@/utils/confirmDialog';
/**
 * Kalinga Connect - project team: Project Managers find case managers, LGU
 * Heads and Field Office users in the regions they cover, message them
 * directly and run group chats (for example "Region VIII"). Everyone added
 * can read and reply. Coordination only: no names or case details.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, MessageSquarePlus, Search, Send, UserPlus, Users, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatPh } from '@/utils/philippineTime';

type Person = { id: number; name: string; role: string; place?: string | null; region?: string | null };
type Thread = { id: number; kind: 'direct' | 'group'; title: string; members: Person[]; unread: number; last_message_at: string | null; can_manage: boolean };
type Message = { id: number; body: string; is_urgent: boolean; created_at: string; sender: string; sender_role: string; mine: boolean };
const props = defineProps<{ threads: Thread[]; canStart: boolean; regions: string[] }>();

const activeId = ref<number | null>(Number(new URLSearchParams(window.location.search).get('thread')) || props.threads[0]?.id || null);
const active = computed(() => props.threads.find(thread => thread.id === activeId.value) ?? null);
const messages = ref<Message[]>([]);
const body = ref('');
const urgent = ref(false);
const sending = ref(false);
const error = ref('');
const list = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setInterval> | null = null;

async function load(reset = false) {
  if (!activeId.value) return;
  const after = !reset && messages.value.length ? messages.value[messages.value.length - 1].id : undefined;
  try {
    const { data } = await axios.get(route('connect.network.messages', activeId.value), { params: after ? { after } : {} });
    messages.value = reset ? data.messages : [...messages.value, ...data.messages];
    if (reset || data.messages.length) refreshNavBadges();
    if (data.messages.length) { await nextTick(); list.value?.scrollTo({ top: list.value.scrollHeight }); }
  } catch { error.value = 'Messages could not be loaded. Check your connection.'; }
}
watch(activeId, () => { messages.value = []; error.value = ''; void load(true); }, { immediate: true });
onMounted(() => { timer = setInterval(() => { if (document.visibilityState === 'visible') void load(); }, 15000); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

async function send() {
  if (!activeId.value || !body.value.trim()) return;
  sending.value = true; error.value = '';
  try { await axios.post(route('connect.network.send', activeId.value), { body: body.value.trim(), is_urgent: urgent.value }); body.value = ''; urgent.value = false; await load(); }
  catch { error.value = 'Not sent. Your message is still in the box; try again.'; }
  finally { sending.value = false; }
}

// Find people and start a conversation or a group.
const finder = ref<'none' | 'direct' | 'group' | 'add'>('none');
const query = ref('');
const region = ref('');
const role = ref('');
const people = ref<Person[]>([]);
const picked = ref<Person[]>([]);
const groupTitle = ref('');
let searchTimer: ReturnType<typeof setTimeout> | null = null;
async function search() {
  try { people.value = (await axios.get(route('connect.network.people'), { params: { q: query.value || undefined, region: region.value || undefined, role: role.value || undefined } })).data.people; }
  catch { people.value = []; }
}
watch([query, region, role], () => { if (searchTimer) clearTimeout(searchTimer); searchTimer = setTimeout(search, 250); });
function openFinder(kind: typeof finder.value) { finder.value = kind; picked.value = []; groupTitle.value = kind === 'group' && region.value ? region.value : ''; void search(); }
const togglePick = (person: Person) => { picked.value = picked.value.some(p => p.id === person.id) ? picked.value.filter(p => p.id !== person.id) : [...picked.value, person]; };
const pickAll = () => { const ids = new Set(picked.value.map(p => p.id)); picked.value = [...picked.value, ...people.value.filter(p => !ids.has(p.id))]; };
const finderError = ref('');
async function startDirect(person: Person) {
  const { data } = await axios.post(route('connect.network.direct'), { user_id: person.id });
  finder.value = 'none'; router.reload({ only: ['threads'], onSuccess: () => { activeId.value = data.thread_id; } });
}
async function createGroup() {
  finderError.value = '';
  try {
    if (finder.value === 'add' && activeId.value) await axios.post(route('connect.network.members', activeId.value), { members: picked.value.map(p => p.id) });
    else { const { data } = await axios.post(route('connect.network.groups'), { title: groupTitle.value, members: picked.value.map(p => p.id) }); activeId.value = data.thread_id; }
    finder.value = 'none'; router.reload({ only: ['threads'] });
  } catch (e: any) { finderError.value = Object.values(e?.response?.data?.errors ?? {}).flat().join(' ') || 'Could not save. Try again.'; }
}
async function leave() {
  if (!active.value || !(await askConfirm({ title: `Leave "${active.value.title}"?`, message: 'You will stop receiving its messages.', confirmLabel: 'Leave', tone: 'danger' }))) return;
  await axios.post(route('connect.network.leave', active.value.id)); activeId.value = null; router.reload({ only: ['threads'] });
}
</script>

<template>
  <Head title="Project team chat" />
  <AuthenticatedLayout>
    <section class="mx-auto max-w-6xl space-y-4">
      <header class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-sm font-bold text-brand-700">Kalinga Connect</p><h1 class="text-2xl font-extrabold text-slate-900">Project team chat</h1><p class="mt-1 max-w-2xl text-sm text-slate-600">Conversations with the Kalinga project team across regions. For coordination only: do not write children's names or case details. <Link :href="route('cpu.coordination.index')" class="ui-link">LGU group chat</Link></p></div>
        <div v-if="canStart" class="flex flex-wrap gap-2"><button type="button" class="ui-button" @click="openFinder('direct')"><MessageSquarePlus class="h-4 w-4" aria-hidden="true" />Message someone</button><button type="button" class="ui-button-primary" @click="openFinder('group')"><Users class="h-4 w-4" aria-hidden="true" />New group</button></div>
      </header>

      <section v-if="finder !== 'none'" class="ui-panel space-y-3 p-4" aria-label="Find people">
        <div class="flex items-center justify-between"><h2 class="ui-section-title">{{ finder === 'direct' ? 'Message someone' : finder === 'add' ? 'Add people' : 'New group chat' }}</h2><button type="button" class="grid h-10 w-10 place-items-center rounded-lg hover:bg-slate-100" aria-label="Close" @click="finder = 'none'"><X class="h-4 w-4" /></button></div>
        <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_12rem_12rem]">
          <label class="relative"><span class="sr-only">Name or LGU</span><Search class="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-500" aria-hidden="true" /><input v-model="query" type="search" placeholder="Name or LGU" class="ui-field pl-9"></label>
          <label><span class="sr-only">Region</span><select v-model="region" class="ui-field"><option value="">All my regions</option><option v-for="item in regions" :key="item" :value="item">{{ item }}</option></select></label>
          <label><span class="sr-only">Role</span><select v-model="role" class="ui-field"><option value="">Everyone</option><option value="social_worker">Case managers</option><option value="lgu_head">LGU Heads</option><option value="fo_user">Field Office</option><option value="project_manager">Project Managers</option></select></label>
        </div>
        <label v-if="finder === 'group'" class="block text-sm font-medium">Group name<input v-model="groupTitle" maxlength="120" placeholder="For example: Region VIII case managers" class="ui-field mt-1"></label>
        <div v-if="finder !== 'direct'" class="flex flex-wrap items-center gap-2 text-xs"><button type="button" class="ui-button min-h-9 px-3 text-xs" :disabled="!people.length" @click="pickAll">Add all {{ people.length }} shown</button><span>{{ picked.length }} chosen</span><span v-for="person in picked" :key="person.id" class="inline-flex items-center gap-1 rounded-full bg-brand-50 py-0.5 pl-2 pr-1 text-brand-900">{{ person.name }}<button type="button" class="grid h-6 w-6 place-items-center" :aria-label="`Remove ${person.name}`" @click="togglePick(person)"><X class="h-3 w-3" /></button></span></div>
        <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
          <li v-for="person in people" :key="person.id"><button type="button" class="flex min-h-12 w-full items-center gap-3 px-3 text-left text-sm hover:bg-brand-50" :aria-pressed="finder !== 'direct' ? picked.some(p => p.id === person.id) : undefined" @click="finder === 'direct' ? startDirect(person) : togglePick(person)">
            <span class="min-w-0 flex-1"><span class="block font-medium text-slate-900">{{ person.name }}</span><span class="block text-xs text-slate-600">{{ person.role }}<template v-if="person.place"> - {{ person.place }}</template></span></span>
            <span v-if="finder !== 'direct' && picked.some(p => p.id === person.id)" class="text-xs font-semibold text-brand-800">Chosen</span>
          </button></li>
          <li v-if="!people.length" class="p-4 text-sm text-slate-600">No one found.</li>
        </ul>
        <p v-if="finderError" role="alert" class="text-sm text-rose-700">{{ finderError }}</p>
        <button v-if="finder !== 'direct'" type="button" class="ui-button-primary" :disabled="!picked.length || (finder === 'group' && groupTitle.trim().length < 3)" @click="createGroup">{{ finder === 'add' ? 'Add to the group' : 'Create group' }}</button>
      </section>

      <div class="grid min-h-[32rem] gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <aside class="ui-panel overflow-hidden p-0" :class="active ? 'max-lg:hidden' : ''" aria-label="Conversations">
          <ul class="divide-y divide-slate-100">
            <li v-for="thread in threads" :key="thread.id"><button type="button" class="flex min-h-14 w-full items-center gap-3 px-4 text-left hover:bg-brand-50" :class="activeId === thread.id ? 'bg-brand-50' : ''" :aria-current="activeId === thread.id ? 'true' : undefined" @click="activeId = thread.id">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-800"><Users v-if="thread.kind === 'group'" class="h-4 w-4" aria-hidden="true" /><template v-else>{{ thread.title.split(' ').map(p => p[0]).slice(0, 2).join('') }}</template></span>
              <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-900">{{ thread.title }}</span><span class="block truncate text-xs text-slate-600">{{ thread.kind === 'group' ? `${thread.members.length + 1} people` : thread.members[0]?.role }}</span></span>
              <span v-if="thread.unread" class="rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold text-white">{{ thread.unread }}</span>
            </button></li>
            <li v-if="!threads.length" class="p-5 text-sm text-slate-600">{{ canStart ? 'No conversations yet. Message someone or start a group.' : 'No conversations yet. A Project Manager can add you to one.' }}</li>
          </ul>
        </aside>
        <section v-if="active" class="ui-panel flex min-h-[32rem] flex-col overflow-hidden p-0" :aria-label="`Conversation: ${active.title}`">
          <header class="flex flex-wrap items-center gap-2 border-b border-[var(--color-line)] px-4 py-3">
            <button type="button" class="grid h-10 w-10 place-items-center rounded-lg hover:bg-slate-100 lg:hidden" aria-label="Back to conversations" @click="activeId = null"><ArrowLeft class="h-4 w-4" /></button>
            <div class="min-w-0 flex-1"><h2 class="truncate text-base font-semibold text-slate-900">{{ active.title }}</h2><p class="truncate text-xs text-slate-600">{{ active.members.map(m => m.name).join(', ') }}</p></div>
            <button v-if="active.kind === 'group' && active.can_manage && canStart" type="button" class="ui-button min-h-10 px-3 text-xs" @click="openFinder('add')"><UserPlus class="h-4 w-4" aria-hidden="true" />Add people</button>
            <button v-if="active.kind === 'group'" type="button" class="ui-button min-h-10 px-3 text-xs" @click="leave">Leave</button>
          </header>
          <ol ref="list" class="flex-1 space-y-3 overflow-y-auto bg-slate-50/60 p-4" aria-live="polite">
            <li v-for="message in messages" :key="message.id" class="flex" :class="message.mine ? 'justify-end' : 'justify-start'">
              <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm" :class="[message.mine ? 'bg-brand-700 text-white' : 'bg-white text-slate-900', message.is_urgent ? 'ring-2 ring-rose-400' : '']">
                <p v-if="!message.mine" class="text-xs font-semibold" :class="message.mine ? '' : 'text-brand-800'">{{ message.sender }} <span class="font-normal text-slate-600">{{ message.sender_role }}</span></p>
                <p v-if="message.is_urgent" class="text-[11px] font-bold uppercase" :class="message.mine ? 'text-rose-100' : 'text-rose-700'">Urgent</p>
                <p class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                <p class="mt-1 text-right text-[11px]" :class="message.mine ? 'text-brand-100' : 'text-slate-600'">{{ formatPh(message.created_at, 'dateTimeShort') }}</p>
              </div>
            </li>
            <li v-if="!messages.length" class="py-10 text-center text-sm text-slate-600">No messages yet.</li>
          </ol>
          <form class="space-y-2 border-t border-[var(--color-line)] p-3" data-skip-generic-confirm="true" @submit.prevent="send">
            <label class="sr-only" for="network-message">Message</label>
            <textarea id="network-message" v-model="body" rows="2" maxlength="4000" placeholder="Write a message (no names or case details)" class="ui-field" @keydown.enter.exact.prevent="send"></textarea>
            <div class="flex items-center justify-between gap-2"><label class="flex min-h-10 items-center gap-2 text-xs"><input v-model="urgent" type="checkbox" class="rounded border-slate-300">Urgent</label><p v-if="error" role="alert" class="flex-1 text-xs text-rose-700">{{ error }}</p><button type="submit" class="ui-button-primary min-h-10" :disabled="sending || !body.trim()"><Send class="h-4 w-4" aria-hidden="true" />Send</button></div>
          </form>
        </section>
        <section v-else class="ui-panel grid place-items-center p-8 text-center text-sm text-slate-600 max-lg:hidden">Choose a conversation.</section>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
