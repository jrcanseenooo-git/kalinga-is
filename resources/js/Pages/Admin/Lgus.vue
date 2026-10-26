<script setup lang="ts">
/**
 * LGU registry: every LGU in the implementation with its readiness (case
 * managers, LGU Head, CPU members, office profile, coordination circle) and
 * case counts. Project Managers see and manage their regions.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import { locationDatasetUrl, type LocationDataset } from '@/data/locations';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Building2, CheckCircle2, Plus, Search, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

type Lgu = { id: number; psgc_code: string; name: string; province: string | null; region: string | null; active: boolean; circle_id: number | null;
    case_managers: number; cpu_members: number; lgu_heads: number; cases: number; active_cases: number; has_profile: boolean };
const props = defineProps<{ lgus: Lgu[]; canManage: boolean; scopeLabel: string }>();
const isAdmin = computed(() => ((usePage().props.auth as { permissions?: string[] }).permissions ?? []).includes('admin.manage'));

const search = ref('');
const regionFilter = ref('');
const readiness = ref<'' | 'needs_staff' | 'needs_profile' | 'ready'>('');
const ready = (lgu: Lgu) => lgu.case_managers > 0 && lgu.lgu_heads > 0 && lgu.has_profile && Boolean(lgu.circle_id);
const gaps = (lgu: Lgu) => [lgu.case_managers === 0 ? 'No case manager' : '', lgu.lgu_heads === 0 ? 'No LGU Head' : '', !lgu.has_profile ? 'No office profile' : '', !lgu.circle_id ? 'No coordination circle' : ''].filter(Boolean);
const regions = computed(() => [...new Set(props.lgus.map(lgu => lgu.region).filter(Boolean))] as string[]);
const rows = computed(() => props.lgus.filter(lgu => `${lgu.name} ${lgu.province} ${lgu.region} ${lgu.psgc_code}`.toLowerCase().includes(search.value.toLowerCase()))
    .filter(lgu => !regionFilter.value || lgu.region === regionFilter.value)
    .filter(lgu => !readiness.value || (readiness.value === 'ready' ? ready(lgu) : readiness.value === 'needs_staff' ? lgu.case_managers === 0 || lgu.lgu_heads === 0 : !lgu.has_profile)));
const totals = computed(() => ({
    lgus: props.lgus.length, ready: props.lgus.filter(ready).length,
    activeCases: props.lgus.reduce((sum, lgu) => sum + lgu.active_cases, 0), caseManagers: props.lgus.reduce((sum, lgu) => sum + lgu.case_managers, 0),
}));

// Register
const adding = ref(false);
const data = ref<LocationDataset | null>(null);
const region = ref(''); const province = ref(''); const code = ref('');
const allRegions = computed(() => data.value?.regions ?? []);
const currentRegion = computed(() => allRegions.value.find(item => item.name === region.value) ?? null);
const provinces = computed(() => currentRegion.value?.provinces ?? []);
const cities = computed(() => !currentRegion.value ? [] : province.value ? provinces.value.find(item => item.name === province.value)?.municipalities ?? [] : currentRegion.value.municipalities ?? []);
const selected = computed(() => cities.value.find(item => item.code === code.value) ?? null);
const already = computed(() => props.lgus.some(lgu => lgu.psgc_code === code.value));
const form = useForm({ psgc_code: '', name: '', province: '', region: '' });
const submit = () => {
    if (!selected.value || !currentRegion.value) return;
    Object.assign(form, { psgc_code: selected.value.code, name: selected.value.name, province: province.value, region: currentRegion.value.name });
    form.post(route('admin.lgus.store'), { preserveScroll: true, onSuccess: () => { region.value = ''; province.value = ''; code.value = ''; form.reset(); adding.value = false; } });
};
onMounted(async () => { try { const response = await fetch(locationDatasetUrl, { cache: 'force-cache' }); if (response.ok) data.value = await response.json(); } catch { data.value = null; } });

// Remove
const removing = ref<Lgu | null>(null); const confirmation = ref(''); const removeError = ref(''); const removingBusy = ref(false);
const remove = () => {
    if (!removing.value || confirmation.value !== removing.value.name) { removeError.value = 'Type the exact LGU name to confirm.'; return; }
    removingBusy.value = true;
    router.delete(route('admin.lgus.destroy', removing.value.id), { data: { confirmation: confirmation.value }, preserveScroll: true,
        onError: errors => { removeError.value = String(errors.confirmation || errors.remove || 'This LGU could not be removed.'); }, onFinish: () => { removingBusy.value = false; }, onSuccess: () => { removing.value = null; } });
};
</script>

<template>
    <Head title="LGU registry" />
    <AuthenticatedLayout>
        <section class="mx-auto max-w-7xl space-y-4 pb-8">
            <SectionNav v-if="isAdmin" section="admin" />
            <header class="ui-panel flex flex-wrap items-end justify-between gap-3 p-5 sm:p-6">
                <div><p class="text-sm font-bold text-brand-700">{{ scopeLabel }}</p><h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">LGU registry</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-600">The LGUs in the Kalinga implementation and whether each is ready: case manager, LGU Head, office profile and coordination circle.</p></div>
                <button v-if="canManage" type="button" class="ui-button-primary min-h-11 px-4" :aria-expanded="adding" aria-controls="register-lgu" @click="adding = !adding"><Plus class="h-4 w-4" aria-hidden="true" />Register an LGU</button>
            </header>

            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-2xl font-extrabold">{{ totals.lgus }}</p><p class="text-xs font-semibold text-slate-600">LGUs</p></div>
                <button type="button" class="rounded-2xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 hover:ring-brand-300" @click="readiness = readiness === 'ready' ? '' : 'ready'"><p class="text-2xl font-extrabold">{{ totals.ready }}</p><p class="text-xs font-semibold text-slate-600">Ready</p></button>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-2xl font-extrabold">{{ totals.caseManagers }}</p><p class="text-xs font-semibold text-slate-600">Case managers</p></div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-2xl font-extrabold">{{ totals.activeCases }}</p><p class="text-xs font-semibold text-slate-600">Active cases</p></div>
            </div>

            <form v-if="canManage && adding" id="register-lgu" class="ui-panel grid gap-4 p-5 lg:grid-cols-[1fr_18rem]" data-skip-generic-confirm="true" @submit.prevent="submit">
                <div>
                    <h2 class="font-extrabold">Register an LGU</h2>
                    <p class="mt-1 text-sm text-slate-600">Choose the official PSGC municipality or city. Its CPU coordination circle is created with it.</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <label class="text-sm font-bold">Region<select v-model="region" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 font-normal" @change="province = ''; code = ''"><option value="">Select region</option><option v-for="item in allRegions" :key="item.code" :value="item.name">{{ item.name }}</option></select></label>
                        <label class="text-sm font-bold">Province<select v-model="province" :disabled="!currentRegion || !provinces.length" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 font-normal disabled:bg-slate-100" @change="code = ''"><option value="">{{ provinces.length ? 'Select province' : 'No province level' }}</option><option v-for="item in provinces" :key="item.code" :value="item.name">{{ item.name }}</option></select></label>
                        <label class="text-sm font-bold">Municipality or city<select v-model="code" :disabled="!currentRegion || (provinces.length > 0 && !province)" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 font-normal disabled:bg-slate-100"><option value="">Select</option><option v-for="item in cities" :key="item.code" :value="item.code">{{ item.name }}</option></select></label>
                    </div>
                    <p v-if="form.errors.psgc_code" class="mt-2 text-sm text-rose-700">{{ form.errors.psgc_code }}</p>
                </div>
                <aside class="rounded-xl bg-brand-50 p-4 text-sm">
                    <template v-if="selected"><p class="font-extrabold text-brand-900">{{ selected.name }}</p><p class="text-slate-700">{{ province || 'No province level' }} · {{ currentRegion?.name }}</p><p class="font-mono text-xs text-slate-600">PSGC {{ selected.code }}</p>
                        <p v-if="already" class="mt-2 text-xs font-bold text-amber-800">Already registered.</p></template>
                    <p v-else class="text-slate-600">Choose a location to preview the official record.</p>
                    <button type="submit" :disabled="!selected || already || form.processing" class="ui-button-primary mt-3 min-h-11 w-full">{{ form.processing ? 'Registering...' : 'Register LGU' }}</button>
                </aside>
            </form>

            <section class="ui-panel p-4 sm:p-5">
                <div class="flex flex-wrap gap-2">
                    <label class="relative min-w-0 flex-1"><span class="sr-only">Search LGUs</span><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" /><input v-model.trim="search" type="search" class="min-h-11 w-full rounded-xl border-slate-300 pl-9 text-sm" placeholder="Search LGU, province or PSGC code"></label>
                    <select v-if="regions.length > 1" v-model="regionFilter" aria-label="Region" class="min-h-11 rounded-xl border-slate-300 text-sm"><option value="">All regions</option><option v-for="item in regions" :key="item" :value="item">{{ item }}</option></select>
                    <select v-model="readiness" aria-label="Readiness" class="min-h-11 rounded-xl border-slate-300 text-sm"><option value="">Any readiness</option><option value="ready">Ready</option><option value="needs_staff">Needs staff</option><option value="needs_profile">Needs office profile</option></select>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article v-for="lgu in rows" :key="lgu.id" class="flex flex-col rounded-2xl border border-slate-200 p-4">
                        <div class="flex justify-between gap-3"><div class="min-w-0"><p class="truncate font-extrabold">{{ lgu.name }}</p><p class="text-sm text-slate-600">{{ lgu.province || 'No province level' }}</p><p class="text-xs text-slate-500">{{ lgu.region }}</p></div><Building2 class="h-5 w-5 shrink-0 text-brand-700" aria-hidden="true" /></div>
                        <dl class="mt-3 grid grid-cols-4 gap-1 text-center text-xs">
                            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-600">CM</dt><dd class="text-base font-extrabold">{{ lgu.case_managers }}</dd></div>
                            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-600">Head</dt><dd class="text-base font-extrabold">{{ lgu.lgu_heads }}</dd></div>
                            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-600">CPU</dt><dd class="text-base font-extrabold">{{ lgu.cpu_members }}</dd></div>
                            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-600">Cases</dt><dd class="text-base font-extrabold">{{ lgu.active_cases }}<span class="text-[10px] font-semibold text-slate-500">/{{ lgu.cases }}</span></dd></div>
                        </dl>
                        <p v-if="ready(lgu)" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700"><CheckCircle2 class="h-4 w-4" aria-hidden="true" />Ready</p>
                        <ul v-else class="mt-3 flex flex-wrap gap-1"><li v-for="gap in gaps(lgu)" :key="gap" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-800"><AlertTriangle class="h-3 w-3" aria-hidden="true" />{{ gap }}</li></ul>
                        <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 pt-3"><span class="font-mono text-xs text-slate-500">{{ lgu.psgc_code }}</span>
                            <button v-if="canManage && lgu.cases === 0" type="button" class="min-h-9 rounded-lg border border-rose-200 px-3 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="removing = lgu; confirmation = ''; removeError = ''">Remove</button></div>
                    </article>
                    <p v-if="!rows.length" class="py-8 text-center text-sm text-slate-600 sm:col-span-2 xl:col-span-3">No LGU matches.</p>
                </div>
            </section>

            <div v-if="removing" class="fixed inset-0 z-50 grid place-items-end bg-slate-950/40 p-4 sm:place-items-center" role="dialog" aria-modal="true" aria-labelledby="remove-title">
                <form class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl" data-skip-generic-confirm="true" @submit.prevent="remove">
                    <div class="flex justify-between gap-2"><h2 id="remove-title" class="text-lg font-extrabold text-slate-900">Remove LGU?</h2><button type="button" class="ui-button min-h-9 px-2" aria-label="Cancel" @click="removing = null"><X class="h-4 w-4" aria-hidden="true" /></button></div>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Remove <b>{{ removing.name }}</b> only if it was added by mistake and has no staff, cases, reports or coordination activity.</p>
                    <label class="mt-4 block text-sm font-bold text-slate-700">Type <span class="select-all">{{ removing.name }}</span> to confirm<input v-model="confirmation" class="mt-1.5 block min-h-11 w-full rounded-xl border-slate-300" autocomplete="off"></label>
                    <p v-if="removeError" class="mt-2 text-sm text-rose-700">{{ removeError }}</p>
                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" class="ui-button min-h-11 px-4" @click="removing = null">Cancel</button><button :disabled="removingBusy || confirmation !== removing.name" class="min-h-11 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white disabled:opacity-40">{{ removingBusy ? 'Removing...' : 'Remove LGU' }}</button></div>
                </form>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
