<script setup lang="ts">
import KalingaAssist from '@/Components/Assist/KalingaAssist.vue';
import ConfirmDialogHost from '@/Components/UI/ConfirmDialogHost.vue';
import { formatPh } from '@/utils/philippineTime';
import ConnectionStatus from '@/Components/UI/ConnectionStatus.vue';
import { accessCheckPending } from '@/Security/browserHistory';
import Breadcrumbs from '@/Components/UI/Breadcrumbs.vue';
import FeedbackDialog from '@/Components/FeedbackDialog.vue';
import NotificationPanel from '@/Components/UI/NotificationPanel.vue';
import type { PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3'; import AppIcon from '@/Components/AppIcon.vue'; import SubmissionConfirmation from '@/Components/SubmissionConfirmation.vue'; import MidnightSessionGuard from '@/Components/MidnightSessionGuard.vue'; import { Sparkles, Bell, CalendarDays, ChevronDown, CircleHelp, LogOut, Menu, MessageSquareText, Plus, Search, UserRound } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import FlashToast from '@/Components/FlashToast.vue';
import PageSkeleton from '@/Components/UI/PageSkeleton.vue';
interface NavigationLink { label: string; icon: string; route: string; match: string[]; params?: Record<string, string>; badge?: number | null; badgeTone?: 'brand' | 'alert'; }
const desktop = ref(false);
const navigationCollapsed = computed(() => desktop.value && collapsed.value);
const menuButton = ref<HTMLButtonElement | null>(null);
const sidebar = ref<HTMLElement | null>(null);
const userMenuOpen = ref(false); const feedbackOpen = ref(false); const assistOpen = ref(false); const userMenu = ref<HTMLElement | null>(null); const searchTerm = ref('');
const open = ref(false); const collapsed = ref(false); const confirmLogout = ref(false); const notificationsOpen = ref(false); const connectionOnline = ref(navigator.onLine); const page = usePage<PageProps>(); const unreadAlerts = computed(() => Number(page.props.unreadNotifications ?? 0)); const notificationPreview = computed(() => page.props.notificationPreview ?? []);
const permissions = computed(() => new Set<string>(page.props.auth.permissions ?? []));
const can = (permission: string) => permissions.value.has(permission);
const isActive = (link: NavigationLink) => link.match.some((name) => route().current(name));
// The Workboard (dashboard) is everyone's to-do list; CPU members start from their referred cases.
const homeLink = computed(() => page.props.auth.user.role === 'cpu_member'
  ? { label:'My referred cases', icon:'home', route:'mdt.collaboration.index', match:['mdt.collaboration.index','mdt.workspace'] }
  : { label:'Workboard', icon:'home', route:'dashboard', match:['dashboard'] });
const navCounts = computed(() => (page.props as unknown as { navCounts?: { activeCases: number | null; calendarDue: number | null; connectUnread?: number | null; teamUnread?: number | null } | null }).navCounts ?? null);
const groups = computed<{ title: string; links: NavigationLink[] }[]>(() => [
  { title:'', links:[homeLink.value] },
  { title:'Case work', links:[
    // Case manager order (owner, 2026-10-29): Case intake, Cases, Calendar.
    ...(can('cases.create') ? [{ label:'Case intake', icon:'intake', route:'cases.create', match:['cases.create'] }] : []),
    ...(can('cases.create') ? [{ label:'Cases', icon:'cases', route:'cases.index', match:['cases.index','cases.show','cases.plans.workspace','cases.conferences.index','reports.index','identity.review.index'], badge: navCounts.value?.activeCases, badgeTone: 'brand' as const }]
      : can('cases.monitor') ? [{ label:'Cases', icon:'cases', route:'monitoring.cases.index', match:['monitoring.cases.index','monitoring.cases.show'] }] : []),
    ...(can('activities.manage') ? [{ label:'Calendar', icon:'calendar', route:'calendar.index', match:['calendar.index','tasks.index'], badge: navCounts.value?.calendarDue, badgeTone: 'alert' as const }] : []),
  ]},
  { title:'Coordination', links:[
    ...(can('cpu.chat.view') ? [{ label:'Kalinga Connect', icon:'connect', route:'cpu.coordination.index', match:['cpu.coordination.index'], badge: navCounts.value?.connectUnread, badgeTone: 'alert' as const }] : []),
    ...(can('connect.network') || can('cases.monitor') || can('cases.update') ? [{ label:'Project team chat', icon:'connect', route:'connect.network', match:['connect.network'], badge: navCounts.value?.teamUnread, badgeTone: 'alert' as const }] : []),
  ]},
  ...(can('analytics.view') || can('analytics.quarterly') ? [{ title:'Insights', links:[
    { label:'Analytics', icon:'bars', route:'analytics.index', match:['analytics.index','analytics.quarterly'] },
    ...(can('management.dashboard.view') ? [{ label:'Management overview', icon:'home', route:'management.dashboard', match:['management.dashboard','management.section'] }] : []),
  ]}] : []),
  { title: can('admin.manage') ? 'Settings' : 'Management', links:[
    ...(can('admin.manage') ? [{ label:'Administration', icon:'settings', route:'admin.overview', match:['admin.overview','admin.index','admin.lgus.index','admin.audits','admin.forms','admin.options','admin.feedback'] }] : [
      ...(can('users.approve') ? [{ label:'Users & approvals', icon:'settings', route:'admin.index', match:['admin.index'] }] : []),
      ...(can('lgu.registry.view') ? [{ label:'LGU registry', icon:'building', route:'admin.lgus.index', match:['admin.lgus.index'] }] : []),
      ...(can('options.manage') ? [{ label:'Form options', icon:'settings', route:'admin.options', match:['admin.options'] }] : []),
      ...(can('feedback.review') ? [{ label:'User feedback', icon:'bell', route:'admin.feedback', match:['admin.feedback'] }] : []),
    ]),
    ...(can('lgu.profile.view') ? [{ label:'Office & signatories', icon:'building', route:'lgu-profile.show', match:['lgu-profile.show'] }] : []),
  ]},
].filter(group => group.links.length));
const mobileLinks = computed(()=>[homeLink.value,...groups.value.flatMap(group=>group.links).filter(link=>['cases.index','monitoring.cases.index','cases.create','calendar.index','cpu.coordination.index','analytics.index'].includes(link.route))].slice(0,5));
const offlinePending = ref(0);
function readOfflinePending(){ try { offlinePending.value = Number(localStorage.getItem('kalinga_offline_pending') ?? 0) || 0; } catch { offlinePending.value = 0; } }
const initials = computed(() => String(page.props.auth.user.name).split(' ').map((part:string) => part[0]).slice(0,2).join(''));
const ROLE_LABELS: Record<string, string> = { system_admin:'System Administrator', project_manager:'Project Manager', fo_user:'Field Office', lgu_head:'LGU Head', social_worker:'Case Manager', cpu_member:'CPU Member' };
const roleLabel = computed(() => ROLE_LABELS[String(page.props.auth.user.role)] ?? String(page.props.auth.user.role).replaceAll('_',' '));
function submitSearch(){ const term=searchTerm.value.trim(); router.get(route(can('cases.create') ? 'cases.index' : 'monitoring.cases.index'), term?{search:term}:{}); }
function onDocumentClick(event: MouseEvent){ if(userMenuOpen.value && userMenu.value && !userMenu.value.contains(event.target as Node)) userMenuOpen.value=false; }
const today = computed(() => formatPh(new Date(), 'weekdayDate'));
const pageTitle = computed(() => {
  if (route().current('dashboard')) return 'Workboard';
  if (route().current('reports.index')) return 'Cases · Intake screening';
  if (route().current('cases.create')) return 'Cases · New case';
  if (route().current('cases.index')) return 'Cases';
  if (route().current('cases.show')) return 'Cases · Case detail';
  if (route().current('cases.plans.workspace')) return 'Cases - Case plan';
  if (route().current('cases.conferences.index')) return 'Cases - Case conferences';
  if (route().current('identity.review.index')) return 'Cases · Identity review';
  
  if (route().current('calendar.index')) return 'Calendar';
  if (route().current('lgu-profile.show')) return 'Settings · Office profile';
  if (route().current('analytics.index')) return 'Analytics · Reports';
  if (route().current('analytics.quarterly')) return 'Analytics · Quarterly monitoring';
  if (route().current('monitoring.cases.index')) return 'Cases · Monitoring';
  if (route().current('monitoring.cases.show')) return 'Cases · Case monitoring';
  if (route().current('management.dashboard')) return 'Overview';
  if (route().current('management.section')) return 'National overview · Management';
  if (route().current('cpu.coordination.index')) return 'Kalinga Connect · CPU group chat';
  if (route().current('mdt.collaboration.index')) return 'MDT Coordination · CPU referral pathway';
  if (route().current('mdt.workspace')) return 'MDT Coordination · Permitted case workspace';
  if (route().current('notifications.index')) return 'Alerts';
  if (route().current('help.guide')) return 'Help & guides';
  if (route().current('offline.workspace')) return 'Offline workspace';
  if (route().current('admin.overview')) return 'Administration';
  if (route().current('admin.lgus.index')) return 'Administration · LGU registry';
  if (route().current('admin.index')) return 'Administration · Users';
  if (route().current('admin.audits')) return 'Administration · Audit logs';
  if (route().current('admin.options')) return 'Administration - Form options';
  if (route().current('admin.feedback')) return 'Administration - Feedback';
  return 'Kalinga IS';
});
function toggleSidebar(){ if(!desktop.value){ open.value=!open.value; return; } collapsed.value=!collapsed.value; try { localStorage.setItem('kalinga_sidebar_collapsed',String(collapsed.value)); } catch { /* Optional layout preference may be blocked by the browser. */ } }
function closeNavigation() { open.value = false; }
function updateViewport() { desktop.value = window.innerWidth >= 1024; if (desktop.value) open.value = false; }
function updateConnection() { connectionOnline.value = navigator.onLine; }
function onNavigationKey(event: KeyboardEvent) {
  if (event.key === 'Escape' && userMenuOpen.value) { userMenuOpen.value = false; return; }
  if (!open.value) return;
  if (event.key === 'Escape') { event.preventDefault(); closeNavigation(); return; }
  if (event.key !== 'Tab') return;
  const controls = sidebar.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])');
  if (!controls?.length) return;
  const first = controls[0]; const last = controls[controls.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
watch(open, async (value) => {
  document.body.style.overflow = value ? 'hidden' : '';
  await nextTick();
  if (value) sidebar.value?.querySelector<HTMLElement>('button, a[href]')?.focus();
  else if (!desktop.value) menuButton.value?.focus();
});
function openOfflineWorkspace(event: MouseEvent) { if (!connectionOnline.value) { event.preventDefault(); window.location.assign(route('offline.workspace')); } }
function markAllAlertsRead(){ router.patch(route('notifications.read-all'), {}, { preserveScroll:true, onSuccess:()=>{ notificationsOpen.value=false; } }); }
function logout(){ confirmLogout.value = true; }
// A full login-page navigation obtains the regenerated CSRF token after the
// server invalidates this session.  Keeping the old Inertia page would leave
// a stale token available for an immediate sign-in attempt.
function completeLogout(){ confirmLogout.value = false; router.post(route('logout'), {}, { onSuccess: () => window.location.assign(route('login')) }); }
onMounted(()=>{ readOfflinePending(); addEventListener('storage', readOfflinePending); addEventListener('kalinga:offline-queue', readOfflinePending); try { collapsed.value=localStorage.getItem('kalinga_sidebar_collapsed')==='true'; } catch { /* Use the expanded default. */ } updateViewport(); addEventListener('resize', updateViewport); addEventListener('online', updateConnection); addEventListener('offline', updateConnection); document.addEventListener('keydown', onNavigationKey); document.addEventListener('click', onDocumentClick); });
onUnmounted(()=>{ removeEventListener('storage', readOfflinePending); removeEventListener('kalinga:offline-queue', readOfflinePending); removeEventListener('resize', updateViewport); removeEventListener('online', updateConnection); removeEventListener('offline', updateConnection); document.removeEventListener('keydown', onNavigationKey); document.removeEventListener('click', onDocumentClick); document.body.style.overflow = ''; });
</script>
<template>
 <div v-if="accessCheckPending" role="status" class="grid min-h-screen place-items-center bg-slate-50 p-6"><p class="text-sm font-semibold text-slate-800">Checking current workspace access...</p></div><div v-else class="min-h-screen bg-[var(--color-canvas)] text-slate-800">
  <a href="#main-content" class="skip-link">Skip to main content</a>
  <header :inert="open" class="workspace-header" :class="navigationCollapsed?'shell-collapsed':''">
   <div class="flex min-w-0 flex-1 items-center gap-3">
    <button ref="menuButton" type="button" aria-controls="workspace-navigation" :aria-expanded="desktop?!navigationCollapsed:open" class="ui-button h-11 w-11 shrink-0 border-0 bg-transparent px-0 hover:bg-slate-100" :aria-label="desktop?(navigationCollapsed?'Expand navigation':'Collapse navigation'):(open?'Close navigation':'Open navigation')" @click="toggleSidebar"><Menu class="h-5 w-5"/></button>
    <form v-if="can('cases.view') || can('cases.monitor')" role="search" data-skip-generic-confirm="true" class="relative hidden w-full max-w-md min-[768px]:block" @submit.prevent="submitSearch"><label for="workspace-search" class="sr-only">Search authorized cases</label><Search aria-hidden="true" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500"/><input id="workspace-search" v-model="searchTerm" type="search" autocomplete="off" :placeholder="can('cases.create') ? 'Search case number or client name...' : 'Search case number or alias...'" class="h-11 w-full rounded-xl border-transparent bg-[#f3f2f8] pl-10 pr-3 text-sm text-slate-800 placeholder:text-slate-500 focus:bg-white"/></form>
    <div :class="can('cases.view')?'min-[768px]:sr-only':''"><Breadcrumbs :items="[{label:pageTitle}]" /></div>
   </div>
   <div class="flex shrink-0 items-center gap-1 sm:gap-2">
    <time v-if="!route().current('dashboard')" class="hidden items-center gap-2 rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-xs font-medium text-slate-700 xl:inline-flex"><CalendarDays aria-hidden="true" class="h-4 w-4 text-brand-700"/>{{today}}</time>
    <ConnectionStatus :online="connectionOnline" class="px-2"/>
    <button type="button" class="ui-button h-11 gap-1.5 border-violet-200 bg-violet-50 px-2.5 text-violet-800 hover:bg-violet-100 sm:px-3" aria-label="Open Kalinga Assist" @click="assistOpen=true"><Sparkles aria-hidden="true" class="h-5 w-5"/><span class="hidden sm:inline">Assist</span></button><button type="button" class="ui-button relative h-11 w-11 border-0 bg-transparent px-0 hover:bg-slate-100" aria-label="Open notifications" aria-controls="notification-panel" :aria-expanded="notificationsOpen" @click="notificationsOpen=!notificationsOpen"><Bell class="h-5 w-5"/><span v-if="unreadAlerts" class="absolute right-1 top-1 grid min-h-4 min-w-4 place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold text-white">{{unreadAlerts>9?'9+':unreadAlerts}}</span></button>
    <div ref="userMenu" class="relative">
     <button type="button" class="flex min-h-11 items-center gap-2 rounded-xl px-1.5 hover:bg-slate-100 sm:px-2" aria-haspopup="menu" :aria-expanded="userMenuOpen" aria-controls="user-menu" @click.stop="userMenuOpen=!userMenuOpen"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-800">{{initials}}</span><span class="hidden min-w-0 text-left min-[1024px]:block"><span class="block max-w-40 truncate text-sm font-semibold text-slate-900">{{page.props.auth.user.name}}</span><span class="block max-w-40 truncate text-xs text-slate-600">{{roleLabel}}</span></span><ChevronDown aria-hidden="true" class="hidden h-4 w-4 text-slate-500 min-[1024px]:block"/></button>
     <div v-if="userMenuOpen" id="user-menu" role="menu" class="absolute right-0 top-12 z-50 w-64 overflow-hidden rounded-xl border border-[var(--color-line)] bg-white py-1 shadow-lg"><div class="border-b border-slate-100 px-4 py-3 min-[1024px]:hidden"><p class="truncate text-sm font-semibold text-slate-900">{{page.props.auth.user.name}}</p><p class="truncate text-xs text-slate-600">{{roleLabel}}</p></div><button type="button" role="menuitem" class="flex min-h-11 w-full items-center gap-3 px-4 text-left text-sm text-slate-700 hover:bg-slate-50" @click="userMenuOpen=false; feedbackOpen=true"><MessageSquareText aria-hidden="true" class="h-4 w-4"/>Send feedback</button><Link role="menuitem" :href="route('profile.edit')" class="flex min-h-11 items-center gap-3 px-4 text-sm text-slate-700 hover:bg-slate-50" @click="userMenuOpen=false"><UserRound aria-hidden="true" class="h-4 w-4"/>Profile and security</Link><Link role="menuitem" :href="route('help.guide')" class="flex min-h-11 items-center gap-3 px-4 text-sm text-slate-700 hover:bg-slate-50" @click="userMenuOpen=false"><CircleHelp aria-hidden="true" class="h-4 w-4"/>Help and guides</Link><button role="menuitem" type="button" class="flex min-h-11 w-full items-center gap-3 border-t border-slate-100 px-4 text-left text-sm text-rose-700 hover:bg-rose-50" @click="userMenuOpen=false;logout()"><LogOut aria-hidden="true" class="h-4 w-4"/>Sign out</button></div>
    </div>
    <Link v-if="can('cases.create')" :href="route('cases.create')" aria-label="Create new case" class="ui-button ui-button-primary ml-1"><Plus class="h-4 w-4"/><span class="hidden sm:inline">New case</span></Link>
   </div>
  </header>
  <FeedbackDialog v-if="feedbackOpen" @close="feedbackOpen=false" />
  <NotificationPanel v-if="notificationsOpen" :items="notificationPreview" :unread="unreadAlerts" @close="notificationsOpen=false" @read-all="markAllAlertsRead" />
  <aside id="workspace-navigation" ref="sidebar" :inert="!desktop&&!open" :role="!desktop&&open?'dialog':undefined" :aria-modal="!desktop&&open?true:undefined" aria-label="Workspace navigation" class="workspace-sidebar" :class="{'sidebar-open':open,'sidebar-collapsed':navigationCollapsed}">
   <button v-if="open" type="button" class="ui-button m-3 justify-start lg:hidden" @click="closeNavigation">Close navigation</button>
   <Link :href="route(homeLink.route)" @click="closeNavigation" class="flex h-[76px] shrink-0 items-center gap-3 px-5" :class="navigationCollapsed?'justify-center px-2':''"><img src="/kalinga-logo-purple.png" alt="Kalinga" :class="navigationCollapsed?'w-14':'w-24'" class="h-auto max-h-12 object-contain"/><span v-if="!navigationCollapsed" class="text-[11px] font-semibold leading-4 text-slate-600">CEFMU Case Management<br/>Information System</span></Link>
   <nav aria-label="Workspace sections" class="sidebar-scroll min-h-0 flex-1 overflow-y-auto px-3 py-4"><section v-for="(group,index) in groups" :key="index" class="mb-4"><p v-if="group.title&&!navigationCollapsed" class="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{group.title}}</p><div class="space-y-0.5"><Link v-for="link in group.links" :key="link.route" :href="route(link.route,link.params||{})" :aria-current="isActive(link)?'page':undefined" :title="navigationCollapsed?link.label:undefined" class="nav-item relative flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm" :class="[navigationCollapsed?'justify-center px-0':'',isActive(link)?'nav-active bg-brand-50 font-semibold text-brand-800':'font-medium text-slate-600 hover:bg-[#f5f4fa] hover:text-slate-900']" @click="closeNavigation()"><AppIcon :name="link.icon" class="h-[18px] w-[18px] shrink-0"/><span v-if="!navigationCollapsed" class="min-w-0 flex-1 truncate">{{link.label}}</span><span v-if="link.badge" aria-hidden="true" class="nav-badge" :class="[link.badgeTone==='alert'?'bg-rose-600 text-white':'bg-brand-100 text-brand-800',navigationCollapsed?'absolute right-1 top-1':'']" :title="link.badgeTone==='alert'?`${link.badge} due today or overdue`:`${link.badge} active cases`">{{link.badge>99?'99+':link.badge}}</span></Link></div></section></nav>
   <div v-if="can('offline.use')" class="shrink-0 px-3 pb-2"><a :href="route('offline.workspace')" class="field-card flex items-center gap-3 rounded-xl border px-3 py-2.5 text-xs" :class="[connectionOnline?'border-emerald-200 bg-emerald-50/60':'border-amber-300 bg-amber-50', navigationCollapsed?'justify-center px-0':'']" :aria-label="`Field mode: ${connectionOnline?'online':'offline'}${offlinePending?`, ${offlinePending} waiting to sync`:''}`" :title="navigationCollapsed?'Field mode':undefined" @click="closeNavigation();openOfflineWorkspace($event)"><span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white"><AppIcon name="offline" class="h-4 w-4" :class="connectionOnline?'text-emerald-700':'text-amber-700'"/><span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white" :class="connectionOnline?'bg-emerald-500':'bg-amber-500'"/></span><span v-if="!navigationCollapsed" class="min-w-0 flex-1"><span class="block font-semibold text-slate-900">Field mode</span><span class="block leading-snug text-slate-600">{{ offlinePending ? `${offlinePending} waiting to sync` : connectionOnline ? 'Online - ready for no-signal areas' : 'Offline - intake still works' }}</span></span></a><Link :href="route('help.guide')" class="mt-1 flex min-h-10 items-center gap-3 rounded-xl px-3 text-sm font-medium text-slate-600 hover:bg-[#f5f4fa]" :class="[navigationCollapsed?'justify-center px-0':'', route().current('help.guide')?'bg-brand-50 text-brand-800':'']" :aria-current="route().current('help.guide')?'page':undefined" :title="navigationCollapsed?'Help & guides':undefined" @click="closeNavigation()"><AppIcon name="help" class="h-[18px] w-[18px] shrink-0"/><span v-if="!navigationCollapsed">Help & guides</span></Link></div>
   <div v-else class="shrink-0 px-3 pb-2"><Link :href="route('help.guide')" class="flex min-h-10 items-center gap-3 rounded-xl px-3 text-sm font-medium text-slate-600 hover:bg-[#f5f4fa]" :class="navigationCollapsed?'justify-center px-0':''" :title="navigationCollapsed?'Help & guides':undefined" @click="closeNavigation()"><AppIcon name="help" class="h-[18px] w-[18px] shrink-0"/><span v-if="!navigationCollapsed">Help & guides</span></Link></div>
   <div class="flex shrink-0 items-center gap-2 border-t border-[var(--color-line)] px-3 py-3" :class="navigationCollapsed?'flex-col':''"><div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-800">{{initials}}</div><div v-if="!navigationCollapsed" class="min-w-0 flex-1"><p class="truncate text-xs font-semibold text-slate-900">{{page.props.auth.user.name}}</p><p class="mt-0.5 truncate text-[11px] text-slate-600">{{roleLabel}}</p></div><button type="button" class="ui-button h-11 shrink-0 border-0 bg-transparent px-2 hover:bg-slate-100" aria-label="Log out" @click="closeNavigation();logout()"><LogOut class="h-4 w-4"/></button></div>
  </aside>
  <div v-if="open" class="fixed inset-0 z-30 bg-slate-950/35 lg:hidden" @click="closeNavigation" />
  <main id="main-content" tabindex="-1" :inert="open" class="workspace-main relative" :class="navigationCollapsed?'shell-collapsed':''"><slot/><PageSkeleton/></main><FlashToast/>
  <nav :inert="open" class="workspace-mobile-nav" aria-label="Primary navigation"><Link v-for="link in mobileLinks" :key="link.route" :href="route(link.route)" :aria-current="isActive(link)?'page':undefined" class="flex min-h-12 min-w-0 flex-1 flex-col items-center justify-center gap-1 px-1 py-2 text-[10px] font-medium" :class="isActive(link)?'text-brand-900':'text-slate-600'"><AppIcon :name="link.icon" class="h-5 w-5"/><span class="text-center leading-tight">{{link.label}}</span></Link></nav>
  <SubmissionConfirmation/><ConfirmDialogHost/><KalingaAssist :show="assistOpen" @close="assistOpen=false"/><MidnightSessionGuard/>
  <Modal aria-label="Sign out of Kalinga" :show="confirmLogout" max-width="md" @close="confirmLogout=false"><section class="p-5"><h2 class="text-lg font-semibold text-slate-900">Sign out of Kalinga?</h2><p class="mt-2 text-sm leading-6 text-slate-600">You will need to sign in again to access your authorized workspace.</p><div class="mt-5 flex flex-wrap justify-end gap-2"><button type="button" class="ui-button" @click="confirmLogout=false">Stay signed in</button><button type="button" class="ui-button border-rose-800 bg-rose-800 text-white hover:bg-rose-900" @click="completeLogout">Sign out</button></div></section></Modal>
 </div>
</template>
<style scoped>
.workspace-header{position:fixed;inset:0 0 auto;z-index:30;display:flex;height:68px;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--color-line);background:#fff;padding:0 16px}
.workspace-sidebar{position:fixed;inset:0 auto 0 0;z-index:40;display:flex;width:248px;flex-direction:column;border-right:1px solid var(--color-line);background:white;transform:translateX(-100%);transition:transform .18s ease,width .18s ease}.sidebar-open{transform:translateX(0)}
.workspace-main{min-width:0;padding:84px 16px calc(88px + env(safe-area-inset-bottom))}.workspace-mobile-nav{position:fixed;inset:auto 0 0;z-index:30;display:flex;justify-content:space-around;border-top:1px solid #e2e8f0;background:#fff;padding-bottom:env(safe-area-inset-bottom)}
.skip-link{position:fixed;left:16px;top:-100px;z-index:80;border:2px solid #5a3a95;border-radius:4px;background:#fff;padding:12px 16px;color:#2d1a5e;font-weight:600}.skip-link:focus{top:8px}.sidebar-scroll{scrollbar-width:thin}
@media(min-width:768px){.workspace-main{padding:88px 24px 96px}}
/* Printing (PDF reports): only the page content, never the app frame. */
@media print{.workspace-header,.workspace-sidebar,.workspace-mobile-nav,.skip-link{display:none!important}.min-h-screen{background:#fff!important}.workspace-main,.workspace-main.shell-collapsed{margin:0!important;padding:0!important}}
@media(min-width:1024px){.workspace-sidebar{transform:none;width:248px}.sidebar-collapsed{width:96px}.workspace-header{padding-left:264px;padding-right:24px}.workspace-header.shell-collapsed{padding-left:112px}.workspace-main{margin-left:248px;padding:92px 28px 40px}.workspace-main.shell-collapsed{margin-left:96px}.workspace-mobile-nav{display:none}}
@media(min-width:1440px){.workspace-main{padding:92px 32px 40px}}
</style>
