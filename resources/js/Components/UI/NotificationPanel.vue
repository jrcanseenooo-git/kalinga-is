<script setup lang="ts">
import { formatPh } from '@/utils/philippineTime';
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import type { PageProps } from '@/types';
import { pushState, turnOffPush, turnOnPush, type PushState } from '@/utils/phoneNotifications';

defineProps<{items:Array<{id:number;title:string;body:string;action_url?:string;read_at?:string|null;created_at:string}>;unread:number}>();
defineEmits<{close:[];readAll:[]}>();
const page = usePage<PageProps>();
const push = ref<PushState | 'loading'>('loading');
const pushError = ref('');
onMounted(async () => { push.value = page.props.pushPublicKey ? await pushState().catch(() => 'unsupported' as const) : 'unsupported'; });
async function toggle() {
  pushError.value = '';
  try { push.value = push.value === 'on' ? await turnOffPush() : await turnOnPush(page.props.pushPublicKey!); }
  catch { pushError.value = 'Could not change phone notifications. Check your connection and try again.'; }
}
const pushText: Record<string, string> = {
  'needs-install': 'On iPhone, first add Kalinga to the Home Screen (Share, then Add to Home Screen), open it from there, then turn notifications on.',
  blocked: 'Notifications are blocked for Kalinga in this browser. Allow them in the browser or phone settings, then try again.',
  off: 'Get alerts on this phone even when Kalinga is closed. Messages sent while you are offline arrive when you reconnect.',
  on: 'Phone notifications are on for this device.',
};
</script>
<template><section id="notification-panel" aria-label="Notifications" class="fixed inset-x-3 top-16 z-50 overflow-hidden rounded-lg border border-slate-300 bg-white shadow-md sm:left-auto sm:right-5 sm:w-96"><header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2"><div><h2 class="text-sm font-semibold">Notifications</h2><p class="text-xs text-slate-600">{{unread}} unread</p></div><button type="button" class="ui-button border-0" @click="$emit('close')">Close</button></header>
<div v-if="push !== 'unsupported' && push !== 'loading'" class="border-b border-slate-200 bg-brand-50/50 px-4 py-3 text-xs text-slate-700"><p class="flex items-start gap-2"><BellRing class="mt-0.5 h-4 w-4 shrink-0 text-brand-700" aria-hidden="true"/>{{ pushText[push] }}</p><button v-if="push === 'off' || push === 'on'" type="button" class="ui-button mt-2 min-h-10 px-3 text-xs" @click="toggle">{{ push === 'on' ? 'Turn off on this device' : 'Turn on phone notifications' }}</button><p v-if="pushError" role="alert" class="mt-1 text-rose-700">{{ pushError }}</p></div>
<button v-if="unread" type="button" class="mx-4 min-h-11 text-xs font-semibold text-brand-800" @click="$emit('readAll')">Mark all as read</button><ul v-if="items.length" class="max-h-80 divide-y divide-slate-200 overflow-y-auto"><li v-for="item in items" :key="item.id"><Link :href="item.action_url || route('notifications.index')" class="block min-h-11 px-4 py-3 hover:bg-slate-50" @click="$emit('close')"><p class="text-sm font-medium">{{item.title}}</p><p class="mt-1 text-xs leading-5 text-slate-600">{{item.body}}</p><time class="mt-1 block text-xs text-slate-600">{{formatPh(item.created_at,'date')}}</time></Link></li></ul><p v-else class="p-5 text-sm text-slate-600">No notifications yet.</p><Link :href="route('notifications.index')" class="ui-link w-full justify-center border-t border-slate-200" @click="$emit('close')">View all notifications</Link></section></template>
