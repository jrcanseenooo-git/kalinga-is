<script setup lang="ts">
import axios from 'axios';
import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

const IDLE_LIMIT_MS = 5 * 60 * 1000;
const HEARTBEAT_INTERVAL_MS = 60 * 1000;
let observedManilaDate = '';
let midnightLogoutPending = false;
let lastActivityAt = Date.now();
let lastHeartbeatAt = 0;
let timer: number | undefined;
const listeners: Array<[keyof WindowEventMap, EventListener]> = [];

function manilaDate(): string {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts();
    const value = (type: string) => parts.find((part) => part.type === type)?.value ?? '';
    return `${value('year')}-${value('month')}-${value('day')}`;
}

async function heartbeat(): Promise<void> {
    if (Date.now() - lastHeartbeatAt < HEARTBEAT_INTERVAL_MS) return;
    lastHeartbeatAt = Date.now();
    try {
        await axios.post(route('session.activity'), {}, { headers: { Accept: 'application/json' } });
    } catch (error: any) {
        if (error?.response?.status === 401) router.visit(route('login'));
    }
}

function registerActivity(): void {
    lastActivityAt = Date.now();
    void heartbeat();
}

function endSession(): void {
    window.clearInterval(timer);
    router.post(route('logout'));
}

function evaluate(): void {
    const currentDate = manilaDate();
    if (currentDate !== observedManilaDate) {
        observedManilaDate = currentDate;
        midnightLogoutPending = true;
    }
    if (midnightLogoutPending && Date.now() - lastActivityAt >= IDLE_LIMIT_MS) endSession();
}

onMounted(() => {
    observedManilaDate = manilaDate();
    const events: (keyof WindowEventMap)[] = ['pointerdown', 'mousemove', 'keydown', 'touchstart', 'scroll'];
    events.forEach((event) => {
        const listener: EventListener = registerActivity;
        window.addEventListener(event, listener, { passive: true });
        listeners.push([event, listener]);
    });
    document.addEventListener('visibilitychange', evaluate);
    timer = window.setInterval(evaluate, 15 * 1000);
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
    listeners.forEach(([event, listener]) => window.removeEventListener(event, listener));
    document.removeEventListener('visibilitychange', evaluate);
});
</script>

<template><span class="hidden" aria-hidden="true" /></template>
