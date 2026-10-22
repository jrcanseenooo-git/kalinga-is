<script setup lang="ts">
import { formatPh, parseStored } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  items: {
    data: Array<{ id: number; title: string; body: string; action_url?: string; read_at?: string | null; created_at: string }>;
    current_page: number;
    last_page: number;
    next_page_url?: string | null;
    prev_page_url?: string | null;
  };
}>();

const unreadCount = computed(() => props.items.data.filter(i => !i.read_at).length);

function markAllRead() {
  router.post(route('notifications.readAll'), {}, { preserveScroll: true });
}

const timeAgo = (d: string) => {
  const diff = Date.now() - (parseStored(d)?.getTime() ?? Date.now());
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  if (days < 7) return `${days}d ago`;
  return formatPh(d, 'dateShort');
};
</script>

<template>
  <Head title="Notifications" />
  <AuthenticatedLayout>
    <section class="w-full max-w-3xl">
      <header class="flex flex-col gap-4 ui-panel p-5 sm:flex-row sm:items-end sm:justify-between sm:p-7">
        <div>
          <p class="text-sm font-bold text-brand-700">My work</p>
          <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Notifications</h1>
          <p v-if="unreadCount" class="mt-2 text-sm leading-6 text-slate-600">{{ unreadCount }} unread</p>
        </div>
        <button
          v-if="items.data.length"
          type="button"
          class="shrink-0 rounded-xl border border-brand-200 px-4 py-2.5 text-sm font-bold text-brand-700 transition hover:bg-brand-50"
          @click="markAllRead"
        >
          Mark all read
        </button>
      </header>

      <!-- List -->
      <div class="mt-5 space-y-1.5">
        <Link
          v-for="item in items.data" :key="item.id"
          :href="item.action_url || '#'"
          class="group flex items-start gap-3.5 rounded-xl bg-white px-4 py-3.5 shadow-sm ring-1 transition"
          :class="!item.read_at ? 'ring-brand-200/60 bg-brand-50/30' : 'ring-slate-200/60 hover:ring-brand-200'"
        >
          <div class="mt-1 shrink-0">
            <span v-if="!item.read_at" class="block h-2.5 w-2.5 rounded-full bg-brand-600" />
            <span v-else class="block h-2.5 w-2.5 rounded-full bg-slate-200" />
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
              <p class="text-sm font-bold text-slate-900">{{ item.title }}</p>
              <time class="shrink-0 text-[11px] text-slate-500">{{ timeAgo(item.created_at) }}</time>
            </div>
            <p class="mt-0.5 text-sm leading-relaxed text-slate-600">{{ item.body }}</p>
          </div>
        </Link>

        <!-- Empty state -->
        <div v-if="!items.data.length" class="flex flex-col items-center rounded-2xl bg-white py-16 shadow-sm ring-1 ring-slate-200/60">
          <div class="grid h-14 w-14 place-items-center rounded-2xl bg-brand-50">
            <span class="text-xl text-brand-600">✓</span>
          </div>
          <p class="mt-3 text-sm font-bold text-slate-600">All caught up</p>
          <p class="mt-1 text-xs text-slate-500">No notifications right now.</p>
        </div>
      </div>

      <!-- Pagination -->
      <nav v-if="items.last_page > 1" class="mt-4 flex items-center justify-between rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200/60" aria-label="Notification pagination">
        <Link v-if="items.prev_page_url" :href="items.prev_page_url" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-brand-300">Previous</Link>
        <span v-else />
        <span class="text-sm text-slate-500">Page {{ items.current_page }} of {{ items.last_page }}</span>
        <Link v-if="items.next_page_url" :href="items.next_page_url" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-brand-300">Next</Link>
        <span v-else />
      </nav>
    </section>
  </AuthenticatedLayout>
</template>
