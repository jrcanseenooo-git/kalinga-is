<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
const items = [
  ['What is the difference between a report and an intake?', 'A report is screened and validated before it can become a case. Intake / Registration creates a direct case record using the General Intake Sheet.'],
  ['How does offline field work operate?', 'Before travelling, sign in while online, open Offline workspace, create a device-only passphrase, and download your authorized case pack. Notes and monitoring updates are encrypted on the device until you choose Sync after reconnecting.'],
  ['Can documents be uploaded while offline?', 'No. Document uploads require a stable connection so the system can validate, protect, version, and audit the file.'],
  ['What should I do when a device is lost or reassigned?', 'Notify the assigned system administrator immediately. The device must be removed from service and its encrypted offline workspace cleared under the organization’s device-custody procedure.'],
  ['Why are some dashboard figures not shown?', 'Public and geographic aggregates suppress small groups to reduce the risk of identifying a child, family, or community member.'],
];
</script>

<template><Head title="Help & FAQ" /><main class="min-h-screen bg-slate-50 text-slate-800"><header class="border-b bg-brand-900"><div class="mx-auto flex max-w-4xl items-center justify-between px-5 py-4"><Link :href="route('public.dashboard')"><img src="/kalinga-logo-white.png" alt="Kalinga" class="h-10 w-auto" /></Link><Link :href="route('login')" class="rounded-xl border border-white/20 px-4 py-2 text-sm font-bold text-white hover:bg-white/10">Staff sign in</Link></div></header><section class="mx-auto max-w-4xl px-5 py-12"><p class="text-sm font-bold text-brand-700">KALINGA CEFMU REGISTRY</p><h1 class="mt-2 text-4xl font-extrabold text-slate-950">Help & frequently asked questions</h1><p class="mt-4 max-w-2xl text-slate-600">Practical guidance for reports, intake, field work, and safe use of the registry.</p><div class="mt-8 space-y-4"><details v-for="([question, answer]) in items" :key="question" class="ui-panel p-5"><summary class="cursor-pointer list-none font-bold text-slate-900">{{ question }}</summary><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ answer }}</p></details></div></section></main></template>
