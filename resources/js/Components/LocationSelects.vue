<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { locationDatasetUrl, type LocationDataset } from '@/data/locations';

/** lockArea: region, province and municipality are fixed (for example the case LGU); only the barangay is chosen. */
const props = withDefaults(defineProps<{ region: string; province: string; municipality: string; barangay?: string; hideBarangay?: boolean; lockArea?: boolean; idPrefix?: string }>(), { barangay: '', hideBarangay: false, lockArea: false, idPrefix: '' });
const fieldId = (name: string) => props.idPrefix ? `${props.idPrefix}${name}` : undefined;
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
    <div v-if="lockArea" class="grid gap-4 sm:grid-cols-2">
        <p class="text-sm font-medium">Municipality / city<span class="mt-1 flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-normal text-slate-700">{{ [municipality, province, region].filter(Boolean).join(', ') || 'Not assigned' }}</span></p>
        <label v-if="!hideBarangay" class="text-sm font-medium">Barangay
            <select v-if="!locations || barangays.length" :id="fieldId('barangay')" :value="barangay" :disabled="!locations || !municipality" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="emit('update:barangay', ($event.target as HTMLSelectElement).value)">
                <option value="">{{ locations ? 'Select barangay' : 'Loading PSGC locations...' }}</option><option v-for="item in barangays" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
            <input v-else :id="fieldId('barangay')" :value="barangay" maxlength="120" class="mt-1 block w-full rounded-xl border-slate-300" placeholder="Type the barangay" @input="emit('update:barangay', ($event.target as HTMLInputElement).value)">
        </label>
    </div>
    <div v-else class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-medium">Region
            <select :id="fieldId('region')" :value="region" :disabled="!locations" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="setRegion(($event.target as HTMLSelectElement).value)">
                <option value="">{{ locations ? 'Select region' : 'Loading PSGC locations...' }}</option><option v-for="item in locations?.regions ?? []" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
        <label v-if="needsProvince" class="text-sm font-medium">Province
            <select :id="fieldId('province')" :value="province" :disabled="!region" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="setProvince(($event.target as HTMLSelectElement).value)">
                <option value="">Select province</option><option v-for="item in provinces" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
        <label class="text-sm font-medium">Municipality / city
            <select :id="fieldId('municipality')" :value="municipality" :disabled="!region || (needsProvince && !province)" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="setMunicipality(($event.target as HTMLSelectElement).value)">
                <option value="">Select municipality / city</option><option v-for="item in municipalities" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
        <label v-if="!hideBarangay" class="text-sm font-medium">Barangay
            <select :id="fieldId('barangay')" :value="barangay" :disabled="!municipality" class="mt-1 block w-full rounded-xl border-slate-300 disabled:bg-slate-100" @change="emit('update:barangay', ($event.target as HTMLSelectElement).value)">
                <option value="">Select barangay</option><option v-for="item in barangays" :key="item.code" :value="item.name">{{ item.name }}</option>
            </select>
        </label>
    </div>
    <p v-if="datasetError" class="text-xs text-rose-600">{{ datasetError }}</p>
</template>
