<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Placeholder layout shown over the page content while a new page is loading
 * slowly (after 350 ms), so a weak connection never looks frozen. Not shown
 * for quick visits, filter changes that keep the page, partial reloads or
 * form submissions.
 */
const visible = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
const stops: Array<() => void> = [];

onMounted(() => {
    stops.push(router.on('start', event => {
        const visit = event.detail.visit;
        if (visit.method !== 'get' || visit.only.length || visit.preserveState === true) return;
        clearTimeout(timer);
        timer = setTimeout(() => { visible.value = true; }, 350);
    }));
    stops.push(router.on('finish', () => { clearTimeout(timer); visible.value = false; }));
});
onBeforeUnmount(() => { clearTimeout(timer); stops.forEach(stop => stop()); });
</script>

<template>
    <div v-if="visible" class="page-skeleton" role="status" aria-live="polite">
        <span class="sr-only">Loading page...</span>
        <div aria-hidden="true" class="mx-auto max-w-[1440px] space-y-4">
            <div class="sk h-7 w-48" />
            <div class="sk h-4 w-72 max-w-full" />
            <div class="grid gap-3 sm:grid-cols-3"><div class="sk h-24" /><div class="sk h-24" /><div class="sk h-24 max-sm:hidden" /></div>
            <div class="rounded-2xl border border-[var(--color-line)] bg-white p-4">
                <div v-for="row in 6" :key="row" class="flex items-center gap-3 py-2.5"><div class="sk h-9 w-9 rounded-full" /><div class="flex-1 space-y-2"><div class="sk h-3.5 w-1/2" /><div class="sk h-3 w-1/3" /></div><div class="sk h-6 w-20 max-sm:hidden" /></div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.page-skeleton { position: absolute; inset: 0; z-index: 20; overflow: hidden; background: var(--color-canvas, #f7f6fb); padding: 1.25rem 1rem; }
@media (min-width: 640px) { .page-skeleton { padding: 1.5rem; } }
.sk { border-radius: .6rem; background: linear-gradient(90deg, #ece9f5 25%, #f6f4fb 37%, #ece9f5 63%); background-size: 400% 100%; animation: shimmer 1.3s ease infinite; }
@keyframes shimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
@media (prefers-reduced-motion: reduce) { .sk { animation: none; } }
</style>
