<script setup lang="ts">
/** User feedback inbox: read suggestions and problem reports, set a status and reply (the sender is notified). */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Star } from '@lucide/vue';
import { ref } from 'vue';
import { formatPh } from '@/utils/philippineTime';

type Item = { id: number; category: string; subject: string; message: string; page: string | null; rating: number | null; status: string; response: string | null; responded_at: string | null; created_at: string; sender: string; sender_role: string };
const props = defineProps<{ items: { data: Item[]; links: any[]; prev_page_url: string | null; next_page_url: string | null; from: number | null; to: number | null; total: number }; filters: { status: string; category: string }; categories: Record<string, string>; statuses: Record<string, string>; counts: Record<string, number>; averageRating: number }>();
const open = ref<number | null>(null);
const reply = useForm({ status: 'in_review', response: '' });
const start = (item: Item) => { open.value = open.value === item.id ? null : item.id; reply.status = item.status === 'new' ? 'in_review' : item.status; reply.response = item.response ?? ''; };
const send = (item: Item) => reply.patch(route('admin.feedback.respond', item.id), { preserveScroll: true, onSuccess: () => { open.value = null; } });
const filter = (changes: Partial<typeof props.filters>) => router.get(route('admin.feedback'), Object.fromEntries(Object.entries({ ...props.filters, ...changes }).filter(([, v]) => v)), { preserveState: true });
const tone: Record<string, string> = { new: 'bg-rose-100 text-rose-900', in_review: 'bg-amber-100 text-amber-900', planned: 'bg-sky-100 text-sky-900', resolved: 'bg-emerald-100 text-emerald-900', closed: 'bg-slate-200 text-slate-700' };
</script>

<template>
  <Head title="User feedback" />
  <AuthenticatedLayout>
    <section class="mx-auto max-w-5xl space-y-5">
      <SectionNav section="admin" />
      <header class="ui-panel p-5 sm:p-7">
        <p class="text-sm font-bold text-brand-700">Administration</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">User feedback</h1>
        <p class="mt-2 text-sm text-slate-600">Suggestions and problem reports sent from the "Send feedback" button. Replies are sent to the user as a notification.<span v-if="averageRating"> Average rating: <b>{{ averageRating }} / 5</b>.</span></p>
      </header>
      <div class="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
        <button type="button" class="ui-button min-h-10 px-3 text-sm" :aria-pressed="!filters.status" @click="filter({ status: '' })">All</button>
        <button v-for="(label, key) in statuses" :key="key" type="button" class="ui-button min-h-10 px-3 text-sm" :class="filters.status === key ? 'ring-2 ring-brand-300' : ''" :aria-pressed="filters.status === key" @click="filter({ status: key })">{{ label }} ({{ counts[key] ?? 0 }})</button>
        <label class="sr-only" for="feedback-category">Kind</label>
        <select id="feedback-category" class="ui-field min-h-10 w-auto" :value="filters.category" @change="filter({ category: ($event.target as HTMLSelectElement).value })"><option value="">All kinds</option><option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option></select>
      </div>
      <ul class="space-y-3">
        <li v-for="item in items.data" :key="item.id" class="ui-panel p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0"><p class="text-xs text-slate-600">{{ categories[item.category] ?? item.category }} - {{ item.sender }}, {{ formatPh(item.created_at, 'dateTimeShort') }}<span v-if="item.page"> - from {{ item.page }}</span></p><h2 class="mt-1 text-sm font-semibold text-slate-900">{{ item.subject }}</h2></div>
            <div class="flex items-center gap-2"><span v-if="item.rating" class="inline-flex items-center gap-0.5 text-xs text-amber-700"><Star class="h-3.5 w-3.5" aria-hidden="true" />{{ item.rating }}/5</span><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="tone[item.status]">{{ statuses[item.status] }}</span></div>
          </div>
          <p class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ item.message }}</p>
          <p v-if="item.response" class="mt-2 rounded-lg bg-brand-50 p-2 text-sm text-brand-950"><b>Reply:</b> {{ item.response }}</p>
          <button type="button" class="ui-button mt-2 min-h-10 px-3 text-xs" :aria-expanded="open === item.id" @click="start(item)">{{ item.response ? 'Update' : 'Reply' }}</button>
          <form v-if="open === item.id" class="mt-2 grid gap-2 sm:grid-cols-[12rem_minmax(0,1fr)_auto] sm:items-end" data-skip-generic-confirm="true" @submit.prevent="send(item)">
            <label class="text-xs font-medium">Status<select v-model="reply.status" class="ui-field mt-1"><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option></select></label>
            <label class="text-xs font-medium">Reply to the user (optional)<textarea v-model="reply.response" rows="2" maxlength="3000" class="ui-field mt-1"></textarea></label>
            <button type="submit" class="ui-button-primary min-h-11" :disabled="reply.processing">Save</button>
          </form>
        </li>
        <li v-if="!items.data.length" class="ui-panel p-6 text-center text-sm text-slate-600">No feedback here.</li>
      </ul>
      <Pagination :previous="items.prev_page_url" :next="items.next_page_url" :from="items.from" :to="items.to" :total="items.total" :links="items.links" />
    </section>
  </AuthenticatedLayout>
</template>
