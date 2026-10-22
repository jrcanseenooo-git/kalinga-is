<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { SERVICE_CATEGORIES } from '@/utils/caseOptions';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SectionNav from '@/Components/SectionNav.vue';
import LocationSelects from '@/Components/LocationSelects.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Provider = { id: number; name: string; service_type: string; contact_person?: string | null; phone?: string | null; region?: string | null; province?: string | null; municipality?: string | null; barangay?: string | null; active: boolean | 0 | 1 };
const props = defineProps<{ items: Provider[] }>();
const provider = useForm({ name: '', service_type: '', contact_person: '', phone: '', region: '', province: '', municipality: '', barangay: '' });
const createProvider = () => provider.post(route('providers.store'), { preserveScroll: true, onSuccess: () => provider.reset() });
const search = ref('');
const serviceFilter = ref('');
const statusFilter = ref<'all' | 'active' | 'inactive'>('all');
const serviceTypes = computed(() => [...new Set(props.items.map(item => item.service_type).filter(Boolean))].sort());
const filteredItems = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    return props.items.filter(item => (!serviceFilter.value || item.service_type === serviceFilter.value)
        && (statusFilter.value === 'all' || Boolean(item.active) === (statusFilter.value === 'active'))
        && (!query || [item.name, item.contact_person, item.service_type, item.municipality, item.province, item.barangay]
            .some(value => value?.toLocaleLowerCase().includes(query))));
});
const filtersActive = computed(() => Boolean(search.value || serviceFilter.value || statusFilter.value !== 'all'));
function clearFilters() { search.value = ''; serviceFilter.value = ''; statusFilter.value = 'all'; }
</script>

<template>
  <Head title="Service providers" />
  <AuthenticatedLayout>
    <section class="mx-auto w-full max-w-5xl space-y-5">
      <SectionNav section="admin" />
      <PageHeader title="Service providers" eyebrow="Administration" description="Maintain referral partner details for authorized case coordination. Directory contacts and changes are restricted to system administrators." />
      <p class="text-sm text-slate-600">{{ items.filter(item => Boolean(item.active)).length }} active providers / {{ items.length }} recorded</p>
      <form aria-labelledby="provider-create-title" class="rounded-2xl border border-slate-200 bg-white p-5" @submit.prevent="createProvider">
        <h2 id="provider-create-title" class="font-bold text-slate-900">Add service provider</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div><label for="provider-name" class="text-sm font-bold">Organization name</label><input id="provider-name" v-model="provider.name" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border-slate-300 text-sm" /></div>
          <div><label for="provider-service" class="text-sm font-bold">Service type</label><OptionSelect id="provider-service" v-model="provider.service_type" :options="SERVICE_CATEGORIES" required /></div>
          <div><label for="provider-contact" class="text-sm font-bold">Contact person (optional)</label><input id="provider-contact" v-model="provider.contact_person" maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border-slate-300 text-sm" /></div>
          <div><label for="provider-phone" class="text-sm font-bold">Phone (optional)</label><input id="provider-phone" v-model="provider.phone" type="tel" maxlength="80" class="mt-1 min-h-11 w-full rounded-xl border-slate-300 text-sm" /></div>
          <div class="sm:col-span-2"><LocationSelects v-model:region="provider.region" v-model:province="provider.province" v-model:municipality="provider.municipality" v-model:barangay="provider.barangay" /></div>
          <div v-if="Object.keys(provider.errors).length" role="alert" class="text-sm text-rose-800 sm:col-span-2"><p v-for="(error, field) in provider.errors" :key="field">{{ error }}</p></div>
          <div><button type="submit" :disabled="provider.processing" class="min-h-11 rounded-xl bg-brand-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Add provider</button></div>
        </div>
      </form>
      <section v-if="items.length" aria-labelledby="provider-filter-title" class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 id="provider-filter-title" class="font-bold text-slate-900">Find a provider</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div class="min-w-0"><label for="provider-search" class="text-sm font-bold">Search providers</label><input id="provider-search" v-model="search" type="search" class="mt-1 min-h-11 w-full rounded-xl border-slate-300 text-sm" /></div>
          <div class="min-w-0"><label for="provider-type-filter" class="text-sm font-bold">Service filter</label><select id="provider-type-filter" v-model="serviceFilter" class="mt-1 min-h-11 w-full rounded-xl border-slate-300 text-sm"><option value="">All services</option><option v-for="type in serviceTypes" :key="type" :value="type">{{ type }}</option></select></div>
          <div class="min-w-0"><label for="provider-status-filter" class="text-sm font-bold">Provider status</label><select id="provider-status-filter" v-model="statusFilter" class="mt-1 min-h-11 w-full rounded-xl border-slate-300 text-sm"><option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2"><p role="status" class="text-sm text-slate-600">{{ filteredItems.length }} of {{ items.length }} providers shown</p><button v-if="filtersActive" type="button" class="min-h-11 rounded-lg border border-slate-300 px-3 text-sm font-bold" @click="clearFilters">Clear provider filters</button></div>
      </section>
      <div class="grid gap-3 lg:grid-cols-2">
        <article v-for="item in filteredItems" :key="item.id" :aria-labelledby="`provider-title-${item.id}`" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5">
          <div class="flex items-start justify-between gap-3"><h2 :id="`provider-title-${item.id}`" class="min-w-0 break-words font-bold text-slate-900">{{ item.name }}</h2><span class="shrink-0 rounded-full border px-2 py-1 text-xs font-bold" :class="Boolean(item.active) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-100 text-slate-700'">{{ Boolean(item.active) ? 'Active' : 'Inactive' }}</span></div>
          <dl class="mt-3 space-y-2 text-sm text-slate-700">
            <div><dt class="font-semibold">Service</dt><dd class="break-words">{{ item.service_type }}</dd></div>
            <div v-if="item.contact_person"><dt class="font-semibold">Contact person</dt><dd class="break-words">{{ item.contact_person }}</dd></div>
            <div v-if="item.phone"><dt class="font-semibold">Phone</dt><dd class="break-all">{{ item.phone }}</dd></div>
            <div v-if="item.municipality || item.province || item.region || item.barangay"><dt class="font-semibold">Location</dt><dd class="break-words">{{ [item.barangay, item.municipality, item.province, item.region].filter(Boolean).join(', ') }}</dd></div>
          </dl>
        </article>
      </div>
      <p v-if="!filteredItems.length" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">{{ items.length ? 'No providers match your filters.' : 'No service providers have been added yet.' }}</p>
    </section>
  </AuthenticatedLayout>
</template>
