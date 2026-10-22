<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{ codes: string[] }>();

const copied = ref(false);
const copyAll = async () => {
    const text = props.codes.join('\n');
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};

const downloadCodes = () => {
    const content = [
        'Kalinga CEFMU - Recovery Codes',
        'Generated: ' + new Date().toLocaleDateString(),
        '─'.repeat(36),
        '',
        ...props.codes.map((c, i) => `${i + 1}. ${c}`),
        '',
        'Each code can only be used once.',
        'Store this file in a secure location.',
    ].join('\n');
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'kalinga-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
};
</script>

<template>
    <GuestLayout fit-viewport><Head title="Recovery codes" />
        <div><h2 class="text-2xl font-extrabold tracking-tight text-slate-900">Save your recovery codes</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Store these codes in a secure location. Each code can only be used once. If you lose access to your authenticator app, use a recovery code to sign in.</p>
        </div>

        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="grid grid-cols-2 gap-2">
                <code v-for="code in codes" :key="code" class="rounded-lg bg-white px-3 py-2 text-center font-mono text-sm text-slate-800 shadow-sm select-all">{{ code }}</code>
            </div>
        </div>

        <div class="mt-4 flex gap-3">
            <button type="button" @click="copyAll" class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 active:bg-slate-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                {{ copied ? 'Copied!' : 'Copy all codes' }}
            </button>
            <button type="button" @click="downloadCodes" class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 active:bg-slate-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
                Save as file
            </button>
        </div>

        <p class="mt-3 text-xs font-medium text-amber-700">These codes will not be shown again. Copy or save them before continuing.</p>

        <Link :href="route('dashboard')" class="mt-5 flex w-full items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition hover:bg-brand-800">
            I have saved my codes - continue
        </Link>
    </GuestLayout>
</template>
