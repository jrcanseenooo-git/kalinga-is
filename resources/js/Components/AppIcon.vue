<script setup lang="ts">
import {
    Building2,
    Bell,
    CalendarDays,
    CalendarPlus,
    ChartColumn,
    ChartNoAxesCombined,
    CircleHelp,
    ClipboardCheck,
    ClipboardPlus,
    Clock3,
    CloudOff,
    FileChartColumn,
    FileText,
    FolderOpen,
    Globe2,
    House,
    ListTodo,
    MessagesSquare,
    NotebookPen,
    Send,
    Settings2,
    ShieldCheck,
    TriangleAlert,
    Users,
    Waypoints,
} from '@lucide/vue';
import { computed } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps<{ name: string }>();
const icons = {
    home: House,
    cases: FolderOpen,
    report: FileText,
    reports: FileChartColumn,
    intake: ClipboardPlus,
    referral: Send,
    connect: MessagesSquare,
    pathway: Waypoints,
    task: ListTodo,
    calendar: CalendarDays,
    schedule: CalendarPlus,
    chart: ChartNoAxesCombined,
    bars: ChartColumn,
    globe: Globe2,
    bell: Bell,
    settings: Settings2,
    help: CircleHelp,
    building: Building2,
    offline: CloudOff,
    assessment: ClipboardCheck,
    note: NotebookPen,
    clock: Clock3,
    alert: TriangleAlert,
    users: Users,
    shield: ShieldCheck,
};
const icon = computed(() => icons[props.name as keyof typeof icons] ?? CircleHelp);
</script>

<template><component :is="icon" v-bind="$attrs" stroke-width="1.9" aria-hidden="true" /></template>
