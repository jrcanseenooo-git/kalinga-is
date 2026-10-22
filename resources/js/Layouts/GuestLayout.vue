<script setup lang="ts">
import SubmissionConfirmation from '@/Components/SubmissionConfirmation.vue';
import { Link } from '@inertiajs/vue3';

withDefaults(defineProps<{ fitViewport?: boolean }>(), { fitViewport: false });
</script>

<template>
    <main :class="fitViewport ? 'h-[100dvh] overflow-hidden overscroll-none' : 'min-h-[100dvh]'" class="bg-white">
        <div :class="fitViewport ? 'h-[100dvh]' : 'min-h-[100dvh]'" class="w-full overflow-hidden bg-white lg:grid lg:min-h-[100dvh] lg:grid-cols-[480px_minmax(0,1fr)]">
            <section :class="fitViewport ? 'h-[100dvh]' : 'min-h-[100dvh]'" class="relative flex flex-col bg-white lg:min-h-0 lg:justify-center lg:px-12">
                <div class="relative h-44 overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600 px-6 pt-6 sm:h-60 sm:px-12 sm:pt-9 lg:hidden">
                    <div class="absolute inset-0 opacity-[.12]" style="background-image:linear-gradient(rgba(255,255,255,.5) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.5) 1px,transparent 1px);background-size:32px 32px" />
                    <div class="absolute -right-12 -top-16 h-48 w-48 rounded-full border-[18px] border-white/20" />
                    <div class="absolute -bottom-20 -left-14 h-40 w-40 rounded-full bg-sky-300/20 blur-2xl" />
                    <Link :href="route('login')" class="relative inline-flex flex-col items-start" aria-label="Kalinga CEFMU Registry login"><img src="/kalinga-logo-white.png" alt="Kalinga" class="h-12 w-auto sm:h-16" /><span class="mt-1.5 text-[10px] font-bold uppercase tracking-[.18em] text-brand-100 sm:mt-2">CEFMU Registry</span></Link>
                </div>
                <div class="relative z-10 mx-auto w-full max-w-sm px-5 pb-5 pt-5 sm:px-10 sm:pb-12 sm:pt-8 lg:mx-0 lg:max-w-none lg:px-0 lg:py-0">
                    <div class="-mx-4 -mt-14 w-[calc(100%+2rem)] rounded-[1.75rem] border border-slate-100 bg-white p-6 shadow-[0_18px_40px_rgba(48,28,95,.14)] sm:mx-0 sm:-mt-20 sm:w-full sm:p-8 lg:mt-0 lg:rounded-none lg:border-0 lg:p-0 lg:shadow-none"><slot /></div>
                </div>
            </section>
            <aside class="relative hidden min-h-0 overflow-hidden bg-gradient-to-br from-brand-900 via-brand-900 to-brand-800 p-12 text-white lg:flex lg:flex-col lg:justify-end">
                <div class="absolute inset-0 opacity-[.07]" style="background-image:linear-gradient(rgba(255,255,255,.42) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.42) 1px,transparent 1px);background-size:48px 48px" />
                <div class="absolute -right-24 -top-32 h-[31rem] w-[31rem] rounded-full border-[48px] border-brand-400/20" />
                <div class="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-brand-500/30 blur-3xl" />
                <div class="absolute bottom-0 left-0 h-80 w-80 rounded-full bg-brand-800/40 blur-3xl" />
                <div class="absolute left-1/2 top-1/2 h-64 w-64 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand-400/15 blur-2xl" />
                <div class="relative max-w-md space-y-5"><img src="/kalinga-logo-white.png" alt="Kalinga" class="h-16 w-auto" /><h1 class="text-5xl font-extrabold leading-[1.06] tracking-tight text-white">Protecting children,<br><span class="text-sky-300">one case at a time.</span></h1><p class="max-w-sm text-base leading-relaxed text-brand-200">Track and manage CEFMU cases across the Kalinga program area with real-time insights.</p><Link :href="route('public.dashboard')" class="inline-flex items-center rounded-xl border border-white/20 bg-black/20 px-6 py-3 text-base font-medium text-white backdrop-blur-sm transition hover:bg-black/30">View Public Dashboard</Link></div>
            </aside>
        </div>
        <SubmissionConfirmation />
    </main>
</template>
