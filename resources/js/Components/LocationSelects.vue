<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { locationDatasetUrl, type LocationDataset } from '@/data/locations';

const props = withDefaults(defineProps<{ region: string; province: string; municipality: string; barangay?: string; hideBarangay?: boolean }>(), { barangay: '', hideBarangay: false });
const emit = defineEmits<{
    'update:region': [value: string];
    'update:province': [value: string];
    'update:municipality': [value: string];
    'update:barangay': [value: string];
}>();

const locations = ref<LocationDataset | null>(null);
const datasetError = ref('');
const selectedRegion = computed(() => locations.value?.regions.find((item) => item.name === props.region));
const provinces = computed(() => selectedRegion.value?.provinces ?? []);
const selectedProvince = computed(() => provinces.value.find((item) => item.name === props.province));
const municipalities = computed(() => selectedProvince.value?.municipalities ?? (provinces.value.length === 0 ? selectedRegion.value?.municipalities ?? [] : []));
const selectedMunicipality = computed(() => municipalities.value.find((item) => item.name === props.municipality));
const barangays = computed(() => selectedMunicipality.value?.barangays ?? []);
const needsProvince = computed(() => provinces.value.length > 0);

const setRegion = (value: string) => { emit('update:region', value); emit('update:province', ''); emit('update:municipality', ''); emit('update:barangay', ''); };
const setProvince = (value: string) => { emit('update:province', value); emit('update:municipality', ''); emit('update:barangay', ''); };
const setMunicipality = (value: string) => { emit('update:municipality', value); emit('update:barangay', ''); };
onMounted(async () => {
    try {
        const response = await fetch(locationDatasetUrl, { cache: 'force-cache' });
        if (!response.ok) throw new Error('Location dataset is unavailable.');
        locations.value = await response.json() as LocationDataset;
    } catch {
        datasetError.value = 'Official PSGC locations could not be loaded. Please refresh and try again.';
    }
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-medium">Region
            <select :value="region" :disabled="!locations" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="setRegion(($event.target as HTMLSelectElement).value)">
                <option value="">{{ locations ? 'Select region' : 'Loading PSGC locations…' }}</option><option v-for="item in locations?.regions ?? []" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
        <label v-if="needsProvince" class="text-sm font-medium">Province
            <select :value="province" :disabled="!region" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="setProvince(($event.target as HTMLSelectElement).value)">
                <option value="">Select province</option><option v-for="item in provinces" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
        <label class="text-sm font-medium">Municipality / city
            <select :value="municipality" :disabled="!region || (needsProvince && !province)" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="setMunicipality(($event.target as HTMLSelectElement).value)">
                <option value="">Select municipality / city</option><option v-for="item in municipalities" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
        <label v-if="!hideBarangay" class="text-sm font-medium">Barangay
            <select :value="barangay" :disabled="!municipality" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="emit('update:barangay', ($event.target as HTMLSelectElement).value)">
                <option value="">Select barangay</option><option v-for="item in barangays" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
    </div>
    <p v-if="datasetError" class="text-xs text-rose-600">{{ datasetError }}</p>
</template>
