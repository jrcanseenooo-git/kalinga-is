<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import LocationSelects from '@/Components/LocationSelects.vue'

defineProps<{
  status: Array<{ status: string; total: number }>
  areas: Array<{ area: string; total: number }>
  generatedAt: string
}>()

const report = useForm({
  source: '',
  source_other: '',
  reporter_name: '',
  reporter_contact: '',
  narrative: '',
  subject_first: '',
  subject_last: '',
  subject_birthdate: '',
  subject_sex: '',
  region: '',
  province: '',
  municipality: '',
  barangay: '',
  priority: 'normal',
})

const successMessage = computed(() => (usePage().props.flash as { success?: string } | undefined)?.success)

const submit = () => report.post(route('public.reports.store'), {
  preserveScroll: true,
  onSuccess: () => report.reset(),
})
</script>

<template>
  <Head title="Public dashboard" />

  <main class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <Link :href="route('public.dashboard')" class="font-semibold tracking-tight text-violet-800">Kalinga CEFMU</Link>
        <Link :href="route('login')" class="text-sm font-semibold text-brand-700 hover:text-brand-900">Staff sign in</Link>
      </div>
    </header>

    <section class="bg-gradient-to-br from-violet-950 to-violet-700 text-white">
      <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-violet-200">CEFMU public desk</p>
        <h1 class="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-5xl">Report a concern safely.</h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-violet-100 sm:text-lg">Share only what is needed to help the appropriate LGU screen a possible child, early, or forced marriage concern.</p>
      </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_19rem] lg:py-12">
      <section class="ui-panel p-5 sm:p-7">
        <div class="mb-6 rounded-xl border border-violet-100 bg-violet-50 p-4 text-sm leading-6 text-slate-700">
          <h2 class="font-semibold text-violet-950">Before you submit</h2>
          <p class="mt-1">Do not include detailed abuse disclosures, passwords, financial information, or photos here. The report will be routed confidentially to the LGU you select for screening. If there is immediate danger, contact local emergency services or a trusted protection authority.</p>
        </div>

        <div v-if="successMessage" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800" role="status">
          {{ successMessage }}
        </div>

        <form class="space-y-7" @submit.prevent="submit">
          <fieldset>
            <legend class="text-lg font-semibold">Your report</legend>
            <p class="mt-1 text-sm text-slate-600">You may leave your name and contact details blank if it is not safe to share them.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <label class="field-label">How did you learn about this? <span aria-hidden="true">*</span>
                <select v-model="report.source" class="form-select" :class="{ 'border-rose-500': report.errors.source }" required>
                  <option value="" disabled>Select source</option>
                  <option>Self-report</option><option>Family member</option><option>Community member</option><option>School</option><option>Health facility</option><option>Local government office</option><option>Other</option>
                </select>
                <span v-if="report.errors.source" class="field-error">{{ report.errors.source }}</span>
              </label>
              <label v-if="report.source === 'Other'" class="field-label">Please describe the source
                <input v-model.trim="report.source_other" class="form-input" maxlength="255" placeholder="e.g., community organization" />
              </label>
              <label class="field-label">Your name <span class="font-normal text-slate-500">(optional)</span>
                <input v-model.trim="report.reporter_name" class="form-input" maxlength="255" autocomplete="name" placeholder="e.g., Ana Santos" />
              </label>
              <label class="field-label">Mobile number <span class="font-normal text-slate-500">(optional)</span>
                <input v-model.trim="report.reporter_contact" class="form-input" inputmode="tel" autocomplete="tel" maxlength="13" placeholder="09XXXXXXXXX" />
                <span v-if="report.errors.reporter_contact" class="field-error">{{ report.errors.reporter_contact }}</span>
              </label>
            </div>
          </fieldset>

          <fieldset>
            <legend class="text-lg font-semibold">What happened?</legend>
            <label class="field-label mt-4">Brief concern or observation <span aria-hidden="true">*</span>
              <textarea v-model="report.narrative" class="form-textarea" :class="{ 'border-rose-500': report.errors.narrative }" rows="5" maxlength="5000" required placeholder="Briefly describe what you observed or were told, and when. Do not include detailed disclosures."></textarea>
              <span v-if="report.errors.narrative" class="field-error">{{ report.errors.narrative }}</span>
            </label>
            <label class="field-label mt-4">How urgent does the concern seem? <span aria-hidden="true">*</span>
              <select v-model="report.priority" class="form-select" required>
                <option value="normal">Needs routine screening</option>
                <option value="high">May need prompt attention</option>
                <option value="urgent">Immediate safety concern</option>
              </select>
            </label>
          </fieldset>

          <fieldset>
            <legend class="text-lg font-semibold">Child or adolescent <span class="font-normal text-slate-500">(if known)</span></legend>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <label class="field-label">First name<input v-model.trim="report.subject_first" class="form-input" maxlength="80" placeholder="e.g., Maria" /></label>
              <label class="field-label">Last name<input v-model.trim="report.subject_last" class="form-input" maxlength="80" placeholder="e.g., Dela Cruz" /></label>
              <label class="field-label">Date of birth<input v-model="report.subject_birthdate" class="form-input" type="date" max="9999-12-31" /></label>
              <label class="field-label">Sex
                <select v-model="report.subject_sex" class="form-select"><option value="">Not known</option><option>Female</option><option>Male</option></select>
              </label>
            </div>
          </fieldset>

          <fieldset>
            <legend class="text-lg font-semibold">Where should this report be endorsed? <span aria-hidden="true">*</span></legend>
            <p class="mt-1 text-sm text-slate-600">Select the LGU municipality or city responsible for screening.</p>
            <div class="mt-4">
              <LocationSelects v-model:region="report.region" v-model:province="report.province" v-model:municipality="report.municipality" v-model:barangay="report.barangay" />
              <span v-if="report.errors.municipality" class="field-error">{{ report.errors.municipality }}</span>
            </div>
          </fieldset>

          <button type="submit" class="w-full rounded-xl bg-brand-700 px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-60" :disabled="report.processing">
            {{ report.processing ? 'Submitting securely…' : 'Submit confidential report' }}
          </button>
        </form>
      </section>

      <aside class="space-y-5">
        <section class="ui-panel p-5">
          <h2 class="font-semibold">Community overview</h2>
          <p class="mt-1 text-sm text-slate-600">Aggregated counts only. No case or person details are published.</p>
          <dl class="mt-4 space-y-3 text-sm">
            <div v-for="item in status" :key="item.status" class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0"><dt class="text-slate-600">{{ item.status.replaceAll('_', ' ') }}</dt><dd class="font-semibold">{{ item.total }}</dd></div>
          </dl>
        </section>
        <section class="ui-panel p-5">
          <h2 class="font-semibold">Reports by area</h2>
          <ul class="mt-4 space-y-3 text-sm">
            <li v-for="area in areas" :key="area.area" class="flex items-center justify-between gap-3"><span class="text-slate-600">{{ area.area || 'Not recorded' }}</span><span class="font-semibold">{{ area.total }}</span></li>
          </ul>
          <p class="mt-4 text-xs text-slate-500">Updated {{ generatedAt }}</p>
        </section>
      </aside>
    </div>
  </main>
</template>

<style scoped>
@reference "../../css/app.css";
.field-label { @apply block text-sm font-semibold text-slate-800; }
.form-input, .form-select, .form-textarea { @apply mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base font-normal text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-violet-600 focus:ring-2 focus:ring-violet-100; }
.form-select { @apply pr-10; }
.form-textarea { @apply min-h-32 resize-y leading-6; }
.field-error { @apply mt-1 block text-xs font-medium text-rose-600; }
</style>
