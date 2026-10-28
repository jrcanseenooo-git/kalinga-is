<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/UI/FormField.vue';
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { EVENT_TYPES, VISIT_LOCATIONS, eventTone, eventTypeLabel } from '@/utils/caseOptions';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertTriangle, CalendarDays, Check, History, UserPlus, Users, X, CalendarPlus, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock3, FolderOpen, List, MapPin, Plus, Sparkles, XCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { formatPh, manilaDayKey, parseStored, storedUtcToManilaInput } from '@/utils/philippineTime';

type Person = { id: number; name: string; role?: string; response?: string };
type HistoryItem = { event: string; from_due_at: string | null; to_due_at: string | null; reason: string | null; created_at: string; actor: string };
/** shared = invited by someone else (read only; answer the invitation). team = a case manager's activity shown to the supervisor (read only). */
type Task = { id: number; title: string; event_type: string | null; due_at: string | null; location: string | null; details: string | null; status: string; case_id: number | null; case_number?: string | null;
  shared?: boolean; team?: boolean; organizer?: string; my_response?: string; participants?: Person[]; history?: HistoryItem[] };
type CaseEvent = { key: string; type: string; title: string; at: string; case_id: number; case_number: string; source: string };
type Entry = { key: string; title: string; type: string; at: string; caseId: number | null; caseNumber: string | null; location: string | null; details: string | null; status: string; source: string | null; task: Task | null };

const props = defineProps<{ tasks: Task[]; caseEvents: CaseEvent[]; availableCases: { id: number; case_number: string }[] }>();

const dayKeyOf = (value: string | Date) => manilaDayKey(value);
const atMs = (value: string) => parseStored(value)?.getTime() ?? 0;
const todayKey = dayKeyOf(new Date());
const [ty, tm] = todayKey.split('-').map(Number);
const cursor = ref(new Date(ty, tm - 1, 1));
const view = ref<'month' | 'agenda'>('month');
/** The day whose activities show in the side panel (below the grid on phones), as in Google Calendar. */
const selectedKey = ref(todayKey);
const legendOpen = ref(false);

/** Supervisor: show or hide the case managers' activities. */
const hasTeam = computed(() => props.tasks.some(task => task.team));
const showTeam = ref(true);
const entries = computed<Entry[]>(() => [
  ...props.tasks.filter(task => task.due_at && (showTeam.value || !task.team)).map(task => ({ key: `t${task.id}`, title: task.title, type: task.event_type || 'other', at: task.due_at as string, caseId: task.case_id, caseNumber: task.case_number ?? null, location: task.location, details: task.details, status: task.status, source: null, task })),
  ...props.caseEvents.map(event => ({ key: event.key, title: event.title, type: event.type, at: event.at, caseId: event.case_id, caseNumber: event.case_number, location: null, details: null, status: 'open', source: event.source, task: null })),
].sort((a, b) => atMs(a.at) - atMs(b.at)));
const byDay = computed(() => {
  const map = new Map<string, Entry[]>();
  for (const entry of entries.value) { const key = dayKeyOf(entry.at); map.set(key, [...(map.get(key) ?? []), entry]); }
  return map;
});
const keyOf = (day: Date) => `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
const days = computed(() => {
  const year = cursor.value.getFullYear(), month = cursor.value.getMonth();
  const cells: (Date | null)[] = Array.from({ length: new Date(year, month, 1).getDay() }, () => null);
  for (let d = 1; d <= new Date(year, month + 1, 0).getDate(); d++) cells.push(new Date(year, month, d));
  while (cells.length % 7) cells.push(null);
  return cells;
});
const monthName = computed(() => new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(cursor.value));
const monthShort = computed(() => new Intl.DateTimeFormat('en-PH', { month: 'short', year: 'numeric' }).format(cursor.value));
const move = (step: number) => {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + step, 1);
  const prefix = keyOf(cursor.value).slice(0, 7);
  selectedKey.value = prefix === todayKey.slice(0, 7) ? todayKey : keyOf(cursor.value);
};
const goToday = () => { cursor.value = new Date(ty, tm - 1, 1); selectedKey.value = todayKey; };
const dayEntries = (key: string) => byDay.value.get(key) ?? [];
const selectedEntries = computed(() => dayEntries(selectedKey.value));
const dayLabel = (day: Date) => { const count = dayEntries(keyOf(day)).length; return `${longDate(keyOf(day))}${count ? `, ${count} ${count === 1 ? 'activity' : 'activities'}` : ', nothing scheduled'}`; };
const time = (value: string) => formatPh(value, 'time');
const longDate = (key: string) => formatPh(key, 'weekdayLong');
const isOverdue = (entry: Entry) => entry.status === 'open' && entry.task !== null && atMs(entry.at) < Date.now();
const done = (entry: Entry) => entry.status !== 'open';

/** Agenda: the month being viewed, from today onwards when it is the current month. */
const agenda = computed(() => {
  const prefix = keyOf(cursor.value).slice(0, 7);
  const isCurrent = prefix === todayKey.slice(0, 7);
  return [...byDay.value.entries()].filter(([key]) => key.startsWith(prefix) && (!isCurrent || key >= todayKey)).sort(([a], [b]) => a.localeCompare(b));
});
const upcoming = computed(() => entries.value.filter(entry => !done(entry) && atMs(entry.at) >= Date.now()).slice(0, 5));
const overdue = computed(() => entries.value.filter(entry => isOverdue(entry) && !entry.task?.team));

// Add / edit
const formOpen = ref(false);
const editing = ref<Task | null>(null);
const form = useForm({ title: '', event_type: 'home_visit', case_id: '' as number | '', due_at: '', location: '', details: '', participants: [] as number[], reason: '' });
// Invitees: chosen by name from people working in your area.
const invitees = ref<Person[]>([]);
const inviteQuery = ref('');
const inviteResults = ref<Person[]>([]);
let inviteTimer: ReturnType<typeof setTimeout> | null = null;
watch(inviteQuery, value => {
  if (inviteTimer) clearTimeout(inviteTimer);
  if (value.trim().length < 2) { inviteResults.value = []; return; }
  inviteTimer = setTimeout(async () => {
    try { inviteResults.value = (await axios.get(route('calendar.invitees'), { params: { q: value.trim() } })).data.users.filter((user: Person) => !invitees.value.some(item => item.id === user.id)); }
    catch { inviteResults.value = []; }
  }, 250);
});
const invite = (person: Person) => { invitees.value.push(person); inviteQuery.value = ''; inviteResults.value = []; };
const uninvite = (id: number) => { invitees.value = invitees.value.filter(person => person.id !== id); };
/** Moving an activity that already had a date needs a reason (shared with the people concerned). */
const moved = computed(() => Boolean(editing.value?.due_at) && form.due_at !== manilaInput(editing.value!.due_at as string));
const responseLabel: Record<string, string> = { pending: 'Invited', accepted: 'Going', declined: 'Not going' };
const historyLabel: Record<string, string> = { created: 'Created', rescheduled: 'Rescheduled', cancelled: 'Cancelled', completed: 'Marked done', invited: 'Invited', accepted: 'Accepted', declined: 'Declined' };
const titleTouched = ref(false);
const caseNumber = (id: number | '') => props.availableCases.find(item => item.id === id)?.case_number;
/** Suggests a title from the type and case until the worker types their own. */
const suggestedTitle = computed(() => [eventTypeLabel(form.event_type), caseNumber(form.case_id)].filter(Boolean).join(' - '));
watch(suggestedTitle, value => { if (!titleTouched.value) form.title = value; });
const manilaInput = (stored: string) => storedUtcToManilaInput(stored);
function openAdd(dayKey = todayKey) {
  editing.value = null; titleTouched.value = false; form.clearErrors();
  form.defaults({ title: '', event_type: 'home_visit', case_id: '', due_at: `${dayKey}T09:00`, location: '', details: '', participants: [], reason: '' }); form.reset();
  invitees.value = []; inviteQuery.value = '';
  form.title = suggestedTitle.value; detail.value = null; formOpen.value = true;
}
function openEdit(task: Task) {
  editing.value = task; titleTouched.value = true; form.clearErrors();
  form.defaults({ title: task.title, event_type: task.event_type || 'other', case_id: task.case_id ?? '', due_at: task.due_at ? manilaInput(task.due_at) : '', location: task.location ?? '', details: task.details ?? '', participants: [], reason: '' }); form.reset();
  invitees.value = (task.participants ?? []).filter(person => person.response !== 'removed').map(person => ({ ...person })); inviteQuery.value = '';
  detail.value = null; formOpen.value = true;
}
/** Gentle clash check: another activity within an hour of the chosen time. */
const clash = computed(() => {
  if (!form.due_at) return null;
  const at = new Date(`${form.due_at}:00+08:00`).getTime();
  return entries.value.find(entry => entry.status === 'open' && entry.task?.id !== editing.value?.id && Math.abs(atMs(entry.at) - at) < 3600000) ?? null;
});
function save() {
  const options = { preserveScroll: true, onSuccess: () => { formOpen.value = false; } };
  form.transform(data => ({ ...data, case_id: data.case_id || null, participants: invitees.value.map(person => person.id), reason: moved.value ? data.reason : '' }));
  if (editing.value) form.patch(route('tasks.update', editing.value.id), options); else form.post(route('tasks.store'), options);
}

// Details
const detail = ref<Entry | null>(null);
function transition(task: Task, status: 'completed' | 'cancelled', reason = '') {
  router.patch(route('tasks.transition', task.id), { status, reason }, { preserveScroll: true, onSuccess: () => { detail.value = null; cancelling.value = false; cancelReason.value = ''; }, onError: errors => { cancelError.value = errors.reason ?? ''; } });
}
// Cancelling needs a reason; it is shared with the people concerned and kept in the history.
const cancelling = ref(false);
const cancelReason = ref('');
const cancelError = ref('');
watch(detail, () => { cancelling.value = false; cancelReason.value = ''; cancelError.value = ''; });
const respond = (task: Task, response: 'accepted' | 'declined') => router.post(route('tasks.respond', task.id), { response }, { preserveScroll: true, onSuccess: () => { detail.value = null; } });
const toneClass = (type: string) => `tone-${eventTone(type)}`;
</script>

<template>
  <Head title="Calendar" />
  <AuthenticatedLayout>
    <section class="space-y-3 sm:space-y-4">
      <header class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold tracking-tight text-[var(--color-ink)] sm:text-2xl">Calendar</h1>
        <button type="button" class="ui-button-primary min-h-11" @click="openAdd(selectedKey)"><CalendarPlus class="h-4 w-4" aria-hidden="true"/>Add activity</button>
      </header>
      <label v-if="hasTeam" class="flex min-h-11 w-fit cursor-pointer items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 text-sm font-medium text-sky-950">
        <input v-model="showTeam" type="checkbox" class="h-5 w-5 rounded border-sky-400 text-brand-700">Show my case managers' activities
      </label>

      <button v-if="overdue.length" type="button" class="flex w-full items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-left text-sm font-medium text-rose-800" @click="overdue[0] && (detail = overdue[0])">
        <AlertTriangle class="h-4 w-4 shrink-0" aria-hidden="true"/><span class="min-w-0 flex-1">{{ overdue.length }} {{ overdue.length === 1 ? 'activity is' : 'activities are' }} past due. Mark done or reschedule.</span><span class="shrink-0 text-xs font-semibold underline">Review</span>
      </button>

      <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <section class="min-w-0 overflow-hidden rounded-2xl border border-[var(--color-line)] bg-white shadow-[var(--shadow-card)]" aria-label="Calendar">
          <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-2.5 sm:p-3">
            <div class="nav-group"><button type="button" class="nav-btn" aria-label="Previous month" @click="move(-1)"><ChevronLeft class="h-4 w-4"/></button><button type="button" class="nav-btn" aria-label="Next month" @click="move(1)"><ChevronRight class="h-4 w-4"/></button></div>
            <button type="button" class="today-btn" @click="goToday">Today</button>
            <h2 class="ml-1 min-w-0 flex-1 truncate text-base font-semibold text-slate-900 sm:text-lg" aria-live="polite"><span class="sm:hidden" aria-hidden="true">{{ monthShort }}</span><span class="max-sm:sr-only">{{ monthName }}</span></h2>
            <div class="view-toggle" role="group" aria-label="Calendar view">
              <button type="button" :aria-pressed="view === 'month'" @click="view = 'month'"><CalendarDays class="h-4 w-4" aria-hidden="true"/><span class="max-sm:sr-only">Month</span></button>
              <button type="button" :aria-pressed="view === 'agenda'" @click="view = 'agenda'"><List class="h-4 w-4" aria-hidden="true"/><span class="max-sm:sr-only">Agenda</span></button>
            </div>
          </div>

          <template v-if="view === 'month'">
            <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-600"><span v-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d" class="py-2"><span class="sm:hidden">{{ d.charAt(0) }}</span><span class="max-sm:hidden">{{ d }}</span></span></div>
            <div class="grid grid-cols-7">
              <div v-for="(day, index) in days" :key="index" class="day" :class="{ blank: !day, today: day && keyOf(day) === todayKey, selected: day && keyOf(day) === selectedKey }">
                <template v-if="day">
                  <button type="button" class="day-hit" :aria-label="dayLabel(day)" :aria-pressed="keyOf(day) === selectedKey" @click="selectedKey = keyOf(day)" @dblclick="openAdd(keyOf(day))"><span class="day-num">{{ day.getDate() }}</span></button>
                  <button type="button" class="day-add" :aria-label="`Add activity on ${longDate(keyOf(day))}`" @click="openAdd(keyOf(day))"><Plus class="h-3.5 w-3.5" aria-hidden="true"/></button>
                  <div class="day-list">
                    <button v-for="entry in dayEntries(keyOf(day)).slice(0, 3)" :key="entry.key" type="button" class="chip" :class="[toneClass(entry.type), { auto: !entry.task, past: done(entry), late: isOverdue(entry) }]" :title="`${time(entry.at)} ${entry.title}`" @click.stop="detail = entry"><span class="chip-time">{{ time(entry.at) }}</span>{{ entry.title }}</button>
                    <span v-if="dayEntries(keyOf(day)).length > 3" class="more">+{{ dayEntries(keyOf(day)).length - 3 }}</span>
                  </div>
                </template>
              </div>
            </div>
          </template>

          <div v-else class="divide-y divide-slate-100">
            <section v-for="[key, list] in agenda" :key="key" class="grid min-w-0 gap-2 p-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:p-4">
              <div class="flex items-start justify-between gap-2 sm:block"><h3 class="text-sm font-semibold text-slate-900" :class="key === todayKey ? 'text-brand-800' : ''">{{ longDate(key) }}</h3><button type="button" class="ui-link min-h-9 text-xs" @click="openAdd(key)">Add</button></div>
              <ul class="min-w-0 space-y-2">
                <li v-for="entry in list" :key="entry.key"><button type="button" class="agenda-item" :class="[toneClass(entry.type), { auto: !entry.task, past: done(entry), late: isOverdue(entry) }]" @click="detail = entry"><span class="font-semibold">{{ time(entry.at) }}</span><span class="min-w-0 flex-1 truncate">{{ entry.title }}</span><span v-if="entry.caseNumber" class="case-tag">{{ entry.caseNumber }}</span></button></li>
              </ul>
            </section>
            <p v-if="!agenda.length" class="p-8 text-center text-sm text-slate-600">Nothing scheduled for the rest of {{ monthName }}.</p>
          </div>

          <div class="border-t border-slate-100 px-3 py-2 text-xs text-slate-600">
            <button type="button" class="inline-flex min-h-9 items-center gap-1 font-semibold text-slate-700" :aria-expanded="legendOpen" aria-controls="calendar-legend" @click="legendOpen = !legendOpen">Colour key<ChevronDown class="h-3.5 w-3.5 transition" :class="legendOpen ? 'rotate-180' : ''" aria-hidden="true"/></button>
            <ul v-show="legendOpen" id="calendar-legend" class="flex flex-wrap gap-x-4 gap-y-2 pb-2 pt-1" aria-label="Legend">
              <li v-for="type in EVENT_TYPES" :key="type.value" class="flex items-center gap-1.5"><span class="dot" :class="toneClass(type.value)"/>{{ type.label }}</li>
              <li class="flex items-center gap-1.5"><span class="dot dot-auto"/>From case records (change them in the case)</li>
            </ul>
          </div>
        </section>

        <aside class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
          <section class="ui-panel p-4 sm:col-span-2 xl:col-span-1" aria-labelledby="day-title" aria-live="polite">
            <div class="flex items-start justify-between gap-2">
              <div><p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ selectedKey === todayKey ? 'Today' : 'Selected day' }}</p><h2 id="day-title" class="text-base font-semibold text-slate-900">{{ longDate(selectedKey) }}</h2></div>
              <button type="button" class="ui-button min-h-9 shrink-0 px-3 text-xs" @click="openAdd(selectedKey)"><Plus class="h-3.5 w-3.5" aria-hidden="true"/>Add</button>
            </div>
            <ul v-if="selectedEntries.length" class="mt-3 space-y-2">
              <li v-for="entry in selectedEntries" :key="entry.key"><button type="button" class="agenda-item" :class="[toneClass(entry.type), { auto: !entry.task, past: done(entry), late: isOverdue(entry) }]" @click="detail = entry"><span class="w-16 shrink-0 font-semibold">{{ time(entry.at) }}</span><span class="min-w-0 flex-1"><span class="block truncate font-medium text-slate-900">{{ entry.title }}</span><span class="block truncate text-xs text-slate-600">{{ eventTypeLabel(entry.type) }}<template v-if="entry.location"> - {{ entry.location }}</template></span></span></button></li>
            </ul>
            <p v-else class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">Nothing scheduled. Tap <strong>Add</strong> to plan a visit, conference or deadline.</p>
          </section>
          <section v-if="overdue.length" class="ui-panel p-4" aria-labelledby="attention-title">
            <h2 id="attention-title" class="ui-section-title flex items-center gap-2"><Sparkles class="h-4 w-4 text-brand-700" aria-hidden="true"/>Needs attention</h2>
            <ul class="mt-2 divide-y divide-slate-100"><li v-for="entry in overdue" :key="entry.key" class="py-2.5"><span class="block truncate text-sm font-medium text-slate-900">{{ entry.title }}</span><span class="block text-xs text-rose-700">Was due {{ longDate(dayKeyOf(entry.at)) }}</span><span class="mt-1.5 flex gap-2"><button type="button" class="ui-button h-9 min-h-9 px-3 text-xs" @click="entry.task && transition(entry.task, 'completed')">Mark done</button><button type="button" class="ui-link min-h-9 text-xs" @click="entry.task && openEdit(entry.task)">Reschedule</button></span></li></ul>
          </section>
          <section class="ui-panel p-4" aria-labelledby="upcoming-title">
            <h2 id="upcoming-title" class="ui-section-title flex items-center gap-2"><Clock3 class="h-4 w-4 text-brand-700" aria-hidden="true"/>Coming up</h2>
            <ul v-if="upcoming.length" class="mt-2 divide-y divide-slate-100"><li v-for="entry in upcoming" :key="entry.key"><button type="button" class="flex w-full items-center gap-3 py-2.5 text-left" @click="detail = entry"><span class="dot" :class="[toneClass(entry.type), { 'dot-auto': !entry.task }]"/><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-900">{{ entry.title }}</span><span class="block text-xs text-slate-600">{{ longDate(dayKeyOf(entry.at)) }}, {{ time(entry.at) }}</span></span></button></li></ul>
            <p v-else class="mt-2 text-sm text-slate-600">Nothing scheduled yet.</p>
          </section>
        </aside>
      </div>
    </section>

    <Modal :show="!!detail" max-width="md" aria-label="Activity details" @close="detail = null">
      <section v-if="detail" class="p-5">
        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-600"><span class="dot" :class="[toneClass(detail.type), { 'dot-auto': !detail.task }]"/>{{ eventTypeLabel(detail.type) }}<span v-if="detail.source" class="normal-case tracking-normal text-slate-500">· {{ detail.source }}</span></p>
        <h2 class="mt-2 text-lg font-semibold text-slate-900">{{ detail.title }}</h2>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex gap-2"><dt class="sr-only">When</dt><Clock3 class="mt-0.5 h-4 w-4 text-slate-500" aria-hidden="true"/><dd>{{ longDate(dayKeyOf(detail.at)) }}, {{ time(detail.at) }}<span v-if="isOverdue(detail)" class="ml-2 rounded bg-rose-50 px-1.5 py-0.5 text-xs font-semibold text-rose-700">Overdue</span><span v-else-if="done(detail)" class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600">{{ detail.status === 'completed' ? 'Done' : 'Cancelled' }}</span></dd></div>
          <div v-if="detail.location" class="flex gap-2"><dt class="sr-only">Where</dt><MapPin class="mt-0.5 h-4 w-4 text-slate-500" aria-hidden="true"/><dd>{{ detail.location }}</dd></div>
          <div v-if="detail.caseNumber" class="flex gap-2"><dt class="sr-only">Case</dt><FolderOpen class="mt-0.5 h-4 w-4 text-slate-500" aria-hidden="true"/><dd><Link v-if="detail.caseId" :href="route('cases.show', detail.caseId)" class="font-semibold text-brand-700 hover:underline">{{ detail.caseNumber }}</Link></dd></div>
        </dl>
        <p v-if="detail.details" class="mt-4 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{{ detail.details }}</p>
        <p v-if="detail.task?.team" class="mt-4 flex items-center gap-2 rounded-lg bg-sky-50 p-3 text-sm text-sky-950"><Users class="h-4 w-4 shrink-0" aria-hidden="true"/>Activity of {{ detail.task.organizer }} (case manager). You see it as the supervisor; only they can change it.</p>
        <p v-if="detail.task?.shared" class="mt-4 flex items-center gap-2 rounded-lg bg-brand-50 p-3 text-sm text-brand-900"><Users class="h-4 w-4 shrink-0" aria-hidden="true"/>Invited by {{ detail.task.organizer }}. Your answer: <b>{{ responseLabel[detail.task.my_response ?? 'pending'] }}</b></p>
        <div v-if="detail.task?.participants?.length" class="mt-4"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Invited</h3><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="person in detail.task.participants" :key="person.id" class="rounded-full border border-slate-200 px-2.5 py-1 text-xs">{{ person.name }} <span class="text-slate-600">- {{ responseLabel[person.response ?? 'pending'] ?? person.response }}</span></li></ul></div>
        <details v-if="detail.task?.history?.length" class="mt-4 text-sm"><summary class="flex min-h-9 cursor-pointer items-center gap-1.5 text-xs font-semibold text-slate-700"><History class="h-4 w-4" aria-hidden="true"/>History ({{ detail.task.history.length }})</summary>
          <ol class="mt-2 space-y-1.5 border-l-2 border-slate-200 pl-3 text-xs text-slate-700"><li v-for="(item, index) in detail.task.history" :key="index"><b>{{ historyLabel[item.event] ?? item.event }}</b> by {{ item.actor }}, {{ formatPh(item.created_at, 'dateTimeShort') }}<template v-if="item.event === 'rescheduled'"> - from {{ item.from_due_at ? formatPh(item.from_due_at, 'dateTimeShort') : 'no date' }} to {{ item.to_due_at ? formatPh(item.to_due_at, 'dateTimeShort') : 'no date' }}</template><template v-if="item.event === 'invited'"> - {{ item.reason }}</template><span v-else-if="item.reason" class="block text-slate-600">Reason: {{ item.reason }}</span></li></ol></details>
        <form v-if="cancelling && detail.task" class="mt-4 space-y-2 rounded-lg border border-rose-200 bg-rose-50 p-3" data-skip-generic-confirm="true" @submit.prevent="transition(detail.task, 'cancelled', cancelReason)">
          <FormField id="cancel-reason" label="Reason for cancelling" :error="cancelError" hint="Shared with the invited people and the supervisor, Field Office and Project Manager." required><textarea id="cancel-reason" v-model="cancelReason" rows="2" minlength="5" maxlength="500" required class="ui-field"></textarea></FormField>
          <div class="flex justify-end gap-2"><button type="button" class="ui-button" @click="cancelling = false">Keep activity</button><button type="submit" class="ui-button text-rose-700" :disabled="cancelReason.trim().length < 5">Cancel activity</button></div>
        </form>
        <p v-if="!detail.task" class="mt-4 text-xs text-slate-600">This date comes from the case record. Change it in the case itself.</p>
        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <template v-if="detail.task?.shared && detail.status === 'open'">
            <button type="button" class="ui-button" :disabled="detail.task.my_response === 'declined'" @click="respond(detail.task, 'declined')"><X class="h-4 w-4" aria-hidden="true"/>Not going</button>
            <button type="button" class="ui-button-primary" :disabled="detail.task.my_response === 'accepted'" @click="respond(detail.task, 'accepted')"><Check class="h-4 w-4" aria-hidden="true"/>Going</button>
          </template>
          <template v-else-if="detail.task && !detail.task.team && detail.status === 'open' && !cancelling">
            <button type="button" class="ui-button text-rose-700" @click="cancelling = true"><XCircle class="h-4 w-4" aria-hidden="true"/>Cancel activity</button>
            <button type="button" class="ui-button" @click="openEdit(detail.task)">Edit</button>
            <button type="button" class="ui-button-primary" @click="transition(detail.task, 'completed')"><CheckCircle2 class="h-4 w-4" aria-hidden="true"/>Mark done</button>
          </template>
          <Link v-else-if="detail.caseId" :href="route('cases.show', detail.caseId)" class="ui-button-primary">Open case</Link>
          <button v-else type="button" class="ui-button" @click="detail = null">Close</button>
        </div>
      </section>
    </Modal>

    <Modal :show="formOpen" max-width="lg" :aria-label="editing ? 'Edit activity' : 'Add activity'" @close="formOpen = false">
      <form class="space-y-4 p-5" data-skip-generic-confirm="true" @submit.prevent="save">
        <h2 class="text-lg font-semibold text-slate-900">{{ editing ? 'Edit activity' : 'Add activity' }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField id="event-type" label="Activity type" :error="form.errors.event_type" required><select id="event-type" v-model="form.event_type" class="ui-field"><option v-for="type in EVENT_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option></select></FormField>
          <FormField id="event-case" label="Case (optional)" :error="form.errors.case_id"><select id="event-case" v-model="form.case_id" class="ui-field"><option value="">Not linked to a case</option><option v-for="item in availableCases" :key="item.id" :value="item.id">{{ item.case_number }}</option></select></FormField>
          <FormField id="event-title" label="Title" :error="form.errors.title" hint="Filled in from the type and case; change it if you like." required class="sm:col-span-2"><input id="event-title" v-model="form.title" required maxlength="255" class="ui-field" @input="titleTouched = true"></FormField>
          <FormField id="event-when" label="Date and time (Philippine time)" :error="form.errors.due_at" required><input id="event-when" v-model="form.due_at" type="datetime-local" required class="ui-field"></FormField>
          <p v-if="clash" class="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-950 sm:col-span-2">You already have "{{ clash.title }}" at {{ time(clash.at) }} that day. Check you can make both.</p>
          <FormField id="event-where" label="Place" :error="form.errors.location"><OptionSelect id="event-where" v-model="form.location" :options="VISIT_LOCATIONS" placeholder="Not set" /></FormField>
          <FormField v-if="moved" id="event-reason" label="Reason for moving this activity" :error="form.errors.reason" hint="Shared with the invited people and the supervisor, Field Office and Project Manager, and kept in the history." required class="sm:col-span-2"><textarea id="event-reason" v-model="form.reason" rows="2" minlength="5" maxlength="500" required class="ui-field"></textarea></FormField>
          <div class="sm:col-span-2">
            <label for="event-invite" class="flex items-center gap-1.5 text-sm font-medium text-slate-800"><UserPlus class="h-4 w-4" aria-hidden="true"/>Invite people (optional)</label>
            <ul v-if="invitees.length" class="mt-2 flex flex-wrap gap-1.5" aria-label="Invited people"><li v-for="person in invitees" :key="person.id" class="inline-flex items-center gap-1 rounded-full bg-brand-50 py-1 pl-3 pr-1 text-xs text-brand-900">{{ person.name }}<button type="button" class="grid h-7 w-7 place-items-center rounded-full hover:bg-white" :aria-label="`Remove ${person.name}`" @click="uninvite(person.id)"><X class="h-3.5 w-3.5" aria-hidden="true"/></button></li></ul>
            <input id="event-invite" v-model="inviteQuery" type="search" autocomplete="off" placeholder="Type a name" class="ui-field mt-2" aria-describedby="event-invite-help" :aria-expanded="inviteResults.length > 0" aria-controls="event-invite-results">
            <p id="event-invite-help" class="mt-1 text-xs text-slate-600">People who work in your area. Inviting someone does not give them access to the case.</p>
            <p v-if="form.errors.participants" class="mt-1 text-xs text-rose-800" role="alert">{{ form.errors.participants }}</p>
            <ul v-if="inviteResults.length" id="event-invite-results" class="mt-1 max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200"><li v-for="person in inviteResults" :key="person.id"><button type="button" class="flex min-h-11 w-full items-center justify-between gap-2 px-3 text-left text-sm hover:bg-brand-50" @click="invite(person)"><span>{{ person.name }}</span><span class="text-xs text-slate-600">{{ person.role }}</span></button></li></ul>
          </div>
          <FormField id="event-details" label="Notes (optional)" :error="form.errors.details" hint="Logistics only, for example who to bring. Write case findings in the progress notes." class="sm:col-span-2"><textarea id="event-details" v-model="form.details" rows="3" maxlength="2000" class="ui-field"/></FormField>
        </div>
        <div class="flex justify-end gap-2"><button type="button" class="ui-button" @click="formOpen = false">Cancel</button><button type="submit" class="ui-button-primary" :disabled="form.processing">{{ form.processing ? 'Saving...' : editing ? 'Save changes' : 'Add to calendar' }}</button></div>
      </form>
    </Modal>
  </AuthenticatedLayout>
</template>

<style scoped>
.nav-group{display:flex;overflow:hidden;border:1px solid #ddd6fe;border-radius:.7rem}.nav-btn{display:grid;height:2.5rem;width:2.5rem;place-items:center;color:#6d28d9}.nav-btn+.nav-btn{border-left:1px solid #ddd6fe}.nav-btn:hover{background:#f5f3ff}.today-btn{display:inline-flex;height:2.5rem;align-items:center;border:1px solid #ddd6fe;border-radius:.7rem;padding:0 .8rem;font-size:.8rem;font-weight:700;color:#6d28d9}.today-btn:hover{background:#f5f3ff}
.view-toggle{display:inline-flex;border:1px solid #e2e8f0;border-radius:.7rem;padding:.2rem;background:#f8fafc}.view-toggle button{display:inline-flex;min-height:2.25rem;min-width:2.25rem;justify-content:center;align-items:center;gap:.35rem;border-radius:.5rem;padding:0 .7rem;font-size:.8rem;font-weight:600;color:#475569}.view-toggle button[aria-pressed=true]{background:#fff;color:#5b21b6;box-shadow:0 1px 2px rgb(15 23 42/.08)}
.day{position:relative;min-height:7.25rem;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:.3rem}.day:nth-child(7n){border-right:0}.day.blank{background:#fafafa}
.day-hit{position:absolute;inset:0;display:flex;align-items:flex-start;justify-content:flex-start;padding:.3rem;border-radius:0}.day-hit:hover{background:#faf8ff}.day-hit:focus-visible{outline:2px solid #7c3aed;outline-offset:-2px}
.day.selected .day-hit{background:#f5f3ff;box-shadow:inset 0 0 0 2px #c4b5fd}
.day-num{display:inline-grid;height:1.8rem;min-width:1.8rem;place-items:center;border-radius:999px;font-size:.85rem;font-weight:600;color:#334155}.day.today .day-num{background:#6d28d9;color:#fff}
.day-add{position:absolute;right:.3rem;top:.3rem;z-index:2;display:grid;height:1.6rem;width:1.6rem;place-items:center;border-radius:999px;color:#6d28d9;background:#fff;border:1px solid #ddd6fe;opacity:0}.day:hover .day-add,.day-add:focus-visible,.day.selected .day-add{opacity:1}
.day-list{position:relative;z-index:1;margin-top:2rem;display:grid;gap:.2rem;pointer-events:none}.chip{pointer-events:auto}
.chip{display:block;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border-radius:.35rem;border-left:3px solid currentColor;padding:.15rem .35rem;text-align:left;font-size:.72rem;font-weight:600;line-height:1.25}.chip-time{margin-right:.25rem;opacity:.75}.more{font-size:.68rem;font-weight:700;color:#5b21b6;padding:0 .3rem}
.chip.auto,.agenda-item.auto{border-left-style:dashed;background:#fff!important}.chip.past,.agenda-item.past{opacity:.5;text-decoration:line-through}.chip.late,.agenda-item.late{box-shadow:inset 0 0 0 1px #fda4af}
.agenda-item{display:flex;width:100%;min-height:2.75rem;align-items:center;gap:.6rem;border-radius:.6rem;border-left:4px solid currentColor;padding:.4rem .75rem;text-align:left;font-size:.85rem}.case-tag{flex-shrink:0;border-radius:.4rem;background:rgb(255 255 255/.7);padding:.1rem .4rem;font-size:.7rem;font-weight:600;color:#334155}
.dot{display:inline-block;height:.6rem;width:.6rem;flex-shrink:0;border-radius:999px;background:currentColor}.dot-auto{background:#fff;border:2px dashed #7c3aed;color:#7c3aed}
.tone-blue{color:#1d4ed8;background:#eff6ff}.tone-teal{color:#0f766e;background:#f0fdfa}.tone-amber{color:#b45309;background:#fffbeb}.tone-rose{color:#be123c;background:#fff1f2}.tone-red{color:#b91c1c;background:#fef2f2}.tone-slate{color:#334155;background:#f1f5f9}.tone-violet{color:#6d28d9;background:#f5f3ff}.tone-green{color:#15803d;background:#f0fdf4}.tone-purple{color:#7e22ce;background:#faf5ff}.tone-gray{color:#475569;background:#f8fafc}
.dot.tone-blue,.dot.tone-teal,.dot.tone-amber,.dot.tone-rose,.dot.tone-red,.dot.tone-slate,.dot.tone-violet,.dot.tone-green,.dot.tone-purple,.dot.tone-gray{background:currentColor}
/* Phones: Google Calendar style - short coloured title bars in each date; tap a date to list its activities below. */
@media(max-width:640px){
  .case-tag{display:none}
  .day{min-height:4.9rem;padding:.15rem}.day-hit{justify-content:center;padding:.2rem}.day-num{height:1.6rem;min-width:1.6rem;font-size:.78rem}
  .day-add{display:none}
  .day-list{margin-top:1.85rem;gap:.15rem}.chip{pointer-events:none;border-left:0;border-radius:.2rem;padding:.05rem .2rem;font-size:.6rem;line-height:1.2}.chip-time{display:none}
  .chip.auto{border:1px dashed currentColor}
  .more{font-size:.6rem;text-align:center;padding:0}
}
</style>
