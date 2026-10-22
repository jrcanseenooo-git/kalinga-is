<script setup lang="ts">
import { formatPh, manilaHour } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GeographicHeatmap from '@/Components/GeographicHeatmap.vue';
import BarangayWorkloadPanel from '@/Components/BarangayWorkloadPanel.vue';
import MetricCard from '@/Components/MetricCard.vue';
import CaseActionQueue from '@/Components/CaseActionQueue.vue';
import KalingaSuggests from '@/Components/Dashboard/KalingaSuggests.vue';
import Accordion from '@/Components/UI/Accordion.vue';
import Panel from '@/Components/UI/Panel.vue';
import ChartCard from '@/Components/UI/ChartCard.vue';
import ContentState from '@/Components/UI/ContentState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { CalendarDays, MapPin } from '@lucide/vue';
import type { CaseActionRow } from '@/types/casework';
import type { PageProps } from '@/types';

type CountRow={label:string;total:number};
const props=defineProps<{
 suggestions?:Array<{kind:string;tone:'urgent'|'action'|'info';title:string;text:string;case_id:number;case_number:string;section:string}>;actionQueue?:CaseActionRow[];asOf?:string;intakeTrend?:Array<CountRow & {month:string}>;caseStatuses?:CountRow[];
 upcomingActivities?:Array<{id:number;case_id:number|null;case_number:string|null;title:string;due_at:string}>;
 metrics:Record<string,number>;attention:Record<string,number>;areas:Array<{area:string;total:number}>;
 regionHeatmap:Array<{area:string;total:number}>;provinceHeatmap?:Array<{region:string;province:string;total:number}>;
 barangayWorkload?:Array<{barangay:string;total:number;active:number}>;
 geographicScope?:{level:string;label:string;psgcCode:string|null;boundaryStatus:string};
 recentCases:Array<{id:number;first_name:string;last_name:string;case_number:string;status:string}>;
 selectedRegion:string;lguWorkload?:Record<string,number>|null;
 charts:{classification:CountRow[];sex:CountRow[];age:CountRow[];ageSummary:{youngest:number|null;oldest:number|null;recorded:number};services:CountRow[]};
}>();
const page=usePage<PageProps>();
const showAllUpcoming=ref(false);
const visibleUpcoming=computed(()=>showAllUpcoming.value?(props.upcomingActivities??[]):(props.upcomingActivities??[]).slice(0,3));
const can=(permission:string)=>page.props.auth.permissions.includes(permission);
const greeting=computed(()=>{const hour=manilaHour();return hour<12?'Good morning':hour<18?'Good afternoon':'Good evening';});
const displayStatus=(status:string)=>status.replaceAll('_',' ').toLowerCase().replace(/\b\w/g,letter=>letter.toUpperCase());
const filterRegion=(region:string)=>router.get(route('dashboard'),region?{region}:{},{preserveScroll:true,preserveState:true,replace:true});
const dayOf=(value:string)=>formatPh(value,'day');
const monthOf=(value:string)=>formatPh(value,'monthShort').toUpperCase();
const timeOf=(value:string)=>formatPh(value,'time');
const todayLong=formatPh(new Date(),'weekdayLong');
const date=(value:string)=>formatPh(value,'dateTimeShort');
const trendPeriod=computed(()=>props.intakeTrend?.length?`${props.intakeTrend[0].month} to ${props.intakeTrend[props.intakeTrend.length-1].month}. Recorded opening dates; Philippine time.`:'No intake series available.');
const statusRows=computed(()=>(props.caseStatuses??[]).map(row=>({label:displayStatus(row.label),total:Number(row.total)})));
const quickActions=computed(()=>[
 {label:'New case intake',hint:'Start CEFMU intake',icon:'intake',href:'cases.create',permission:'cases.create',tone:'bg-brand-50 text-brand-800 border-brand-100'},
 {label:'My cases',hint:'Open the case registry',icon:'cases',href:'cases.index',permission:'cases.view',tone:'bg-sky-50 text-sky-900 border-sky-100'},
 {label:'Schedule follow-up',hint:'Tasks and calendar',icon:'schedule',href:'calendar.index',permission:'activities.manage',tone:'bg-amber-50 text-amber-900 border-amber-100'},
 {label:'Analytics',hint:'Caseload figures',icon:'bars',href:'analytics.index',permission:'analytics.view',tone:'bg-emerald-50 text-emerald-900 border-emerald-100'},
 {label:'Offline workspace',hint:'Field mode for GIDA',icon:'offline',href:'offline.workspace',permission:'offline.use',tone:'bg-slate-50 text-slate-800 border-slate-200'},
].filter(item=>can(item.permission)));
const operational=[{key:'screeningOverdue',label:'Screening overdue',href:'reports.index'},{key:'validationOverdue',label:'Validation overdue',href:'reports.index'},{key:'firstContactOverdue',label:'First contact overdue',href:'cases.index'},{key:'planOverdue',label:'Case plan overdue',href:'cases.index'},{key:'referralResponseOverdue',label:'Provider response overdue',href:'cases.index'},{key:'referralCompletionOverdue',label:'Referral completion overdue',href:'cases.index'},{key:'staleCases',label:'No update in 30 days',href:'cases.index'}];
const visibleOperational=computed(()=>operational.filter(item=>Number(props.attention[item.key]??0)>0));
</script>
<template>
 <Head title="Dashboard" />
 <AuthenticatedLayout>
  <div id="worker-dashboard" class="mx-auto max-w-[1680px] space-y-5">
   <header class="flex flex-col gap-4 min-[1024px]:flex-row min-[1024px]:items-center min-[1024px]:justify-between">
    <div class="min-w-0"><h1 class="text-xs font-semibold uppercase tracking-wider text-brand-700">Dashboard</h1><p class="mt-1 text-2xl font-bold tracking-tight text-[var(--color-ink)] sm:text-[30px]">{{greeting}}, {{page.props.auth.user.name.split(' ')[0]}}!</p><p class="mt-1 text-sm text-slate-600">Here is an overview of your cases and next actions today.</p></div>
    <div class="flex flex-wrap gap-3">
     <div class="flex min-h-14 items-center gap-3 rounded-xl border border-[var(--color-line)] bg-white px-4 py-2 shadow-[var(--shadow-card)]"><CalendarDays aria-hidden="true" class="h-5 w-5 text-brand-700"/><div><p class="text-[11px] text-slate-500">Today</p><p class="text-sm font-semibold text-slate-900">{{todayLong}}</p></div></div>
     <div class="flex min-h-14 items-center gap-3 rounded-xl border border-[var(--color-line)] bg-white px-4 py-2 shadow-[var(--shadow-card)]"><MapPin aria-hidden="true" class="h-5 w-5 text-brand-700"/><div><p class="text-[11px] text-slate-500">Assigned office</p><p class="text-sm font-semibold text-slate-900">{{geographicScope?.label || 'Authorized workspace'}}</p><p v-if="selectedRegion" class="text-[11px] text-slate-500">Region filter: {{selectedRegion}}</p></div></div>
    </div>
   </header>
   <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Casework priorities">
    <MetricCard label="Active cases" :value="metrics.activeCases??0" :href="route('cases.index',{active:1,region:selectedRegion||undefined})" definition="Open cases under your management." icon="cases" />
    <MetricCard label="Due within 24 hours" :value="attention.dueSoon??0" :href="route('calendar.index')" definition="Case tasks due in the next 24 hours." icon="clock" tone="warning" />
    <MetricCard label="Overdue actions" :value="attention.overdueActions??0" :href="route('calendar.index')" definition="Tasks past their due date." icon="alert" tone="danger" />
    <MetricCard label="Follow-up cases" :value="attention.followUps??0" :href="route('cases.index',{status:'FOR_FOLLOW_UP',region:selectedRegion||undefined})" definition="Cases marked for follow-up." icon="users" tone="info" />
   </section>
   <div class="grid items-start gap-5 min-[768px]:grid-cols-[minmax(0,1.6fr)_minmax(240px,1fr)] min-[1280px]:grid-cols-[minmax(0,1.8fr)_minmax(290px,1fr)]">
    <KalingaSuggests v-if="suggestions" :items="suggestions" />
    <CaseActionQueue :rows="actionQueue??[]" :as-of="asOf" :compact="Boolean(suggestions)" />
    <div class="space-y-5">
     <Panel id="upcoming-activities-title" title="Upcoming schedule" description="Your next dated tasks (Philippine time).">
      <template #actions><Link :href="route('calendar.index')" class="ui-link">View calendar</Link></template>
      <ul v-if="upcomingActivities?.length" class="space-y-2"><li v-for="item in visibleUpcoming" :key="item.id"><Link :href="item.case_id?route('cases.show',item.case_id):route('calendar.index')" class="group flex min-h-11 items-center gap-3 rounded-lg p-1.5 hover:bg-[#faf9fd]"><time :datetime="item.due_at" class="ui-date-badge"><span class="text-lg font-bold">{{dayOf(item.due_at)}}</span><span class="mt-1 text-[10px] font-semibold">{{monthOf(item.due_at)}}</span></time><div class="min-w-0 flex-1"><p class="break-words text-sm font-semibold text-slate-900 group-hover:text-brand-800">{{item.title}}</p><p class="mt-0.5 text-xs text-slate-600">{{item.case_number || 'Personal work task'}}</p></div><span class="flex shrink-0 items-center gap-1 text-xs text-slate-600"><AppIcon name="clock" class="h-3.5 w-3.5"/>{{timeOf(item.due_at)}}</span></Link></li></ul>
      <button v-if="(upcomingActivities?.length??0)>3" type="button" class="ui-link mt-2" :aria-expanded="showAllUpcoming" @click="showAllUpcoming=!showAllUpcoming">{{showAllUpcoming?'Show next 3 tasks':`Show all ${upcomingActivities?.length} scheduled tasks`}}</button>
      <ContentState v-if="!upcomingActivities?.length" title="No upcoming dated tasks" description="Open your calendar to review your work or record a schedule." />
     </Panel>

    </div>
   </div>
   <section aria-label="Supporting caseload analytics" class="grid gap-5 min-[1280px]:grid-cols-[1.2fr_1.2fr_.8fr]">
    <ChartCard id="intake-trend-title" title="Case intake trend" :description="trendPeriod" :rows="intakeTrend??[]" kind="trend" />
    <ChartCard id="status-distribution-title" title="Case status distribution" description="Current recorded statuses across your authorized cases." :rows="statusRows" kind="distribution" />
     <Panel class="order-first xl:order-none" id="quick-actions-title" title="Quick actions"><nav aria-label="Dashboard quick actions" class="grid grid-cols-2 gap-2 min-[1280px]:grid-cols-1"><Link v-for="item in quickActions" :key="item.href" :href="route(item.href)" :aria-label="item.label" class="flex min-h-[56px] min-w-0 items-center gap-3 rounded-xl border px-3 py-2 transition hover:shadow-md" :class="item.tone"><AppIcon :name="item.icon" class="h-5 w-5 shrink-0"/><span class="min-w-0"><span class="block text-sm font-semibold leading-5">{{item.label}}</span><span class="block text-[11px] leading-4 opacity-80">{{item.hint}}</span></span></Link></nav></Panel>
   </section>
   <Accordion v-if="visibleOperational.length" title="More operational deadlines"><div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3"><Link v-for="item in visibleOperational" :key="item.key" :href="route(item.href)" class="flex min-h-11 items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700"><span>{{item.label}}</span><strong class="text-rose-800">{{attention[item.key]}}</strong></Link></div><p class="mt-3 text-xs text-slate-600">Existing operational checks; no new deadlines are inferred by this view.</p></Accordion>
   <Accordion title="Caseload breakdown and geographic workload">
    <section v-if="lguWorkload" class="my-4 rounded-md border border-slate-200 p-4"><h2 class="ui-section-title">LGU operational workload</h2><p class="mt-1 text-xs text-slate-600">Aggregate only - case records assigned to other workers remain unavailable.</p><dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5"><div v-for="(value,key) in lguWorkload" :key="key"><dt class="text-xs capitalize text-slate-600">{{String(key).replaceAll('_',' ')}}</dt><dd class="mt-1 text-xl font-semibold">{{value}}</dd></div></dl></section>
    <BarangayWorkloadPanel v-if="geographicScope?.level==='barangay'" :municipality="geographicScope.label" :boundary-status="geographicScope.boundaryStatus" :rows="barangayWorkload??[]" />
    <GeographicHeatmap v-else :rows="regionHeatmap" :province-rows="provinceHeatmap??[]" :selected-region="selectedRegion" @region-change="filterRegion" />
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><section v-for="chart in [{title:'By recorded classification',rows:charts.classification},{title:'By recorded sex',rows:charts.sex},{title:'By recorded age',rows:charts.age},{title:'Services provided',rows:charts.services}]" :key="chart.title" class="rounded-md border border-slate-200 p-3"><h2 class="text-sm font-semibold">{{chart.title}}</h2><dl class="mt-2 space-y-2 text-xs"><div v-for="row in chart.rows" :key="row.label" class="flex justify-between gap-3"><dt>{{row.label}}</dt><dd class="font-semibold">{{row.total}}</dd></div></dl><p v-if="!chart.rows.length" class="mt-2 text-xs text-slate-600">No data recorded.</p></section></div>
    <p v-if="charts.ageSummary.recorded" class="mt-3 text-xs text-slate-600">Valid recorded ages: {{charts.ageSummary.recorded}}. Youngest: {{charts.ageSummary.youngest}}. Oldest: {{charts.ageSummary.oldest}}.</p>
    <section class="mt-4 grid gap-4 sm:grid-cols-2"><div><h2 class="ui-section-title">Cases by area</h2><dl class="mt-3 space-y-2 text-sm"><div v-for="area in areas" :key="area.area" class="flex justify-between"><dt>{{area.area}}</dt><dd>{{area.total}}</dd></div></dl><Link :href="route('analytics.index')" class="ui-link">View authorized analytics</Link></div><div><h2 class="ui-section-title">My assigned case activity</h2><ul class="mt-2"><li v-for="item in recentCases.slice(0,4)" :key="item.id"><Link :href="route('cases.show',item.id)" class="ui-link">{{item.first_name}} {{item.last_name}} - {{item.case_number}}</Link></li></ul><Link :href="route('cases.index')" class="ui-link">View my cases</Link></div></section>
   </Accordion>
  </div>
 </AuthenticatedLayout>
</template>
