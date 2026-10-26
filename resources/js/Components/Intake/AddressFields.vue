<script setup lang="ts">
/** House number / street plus PSGC region, province, municipality and barangay for one address. */
import LocationSelects from '@/Components/LocationSelects.vue';

const street = defineModel<string>('street', { default: '' });
const region = defineModel<string>('region', { default: '' });
const province = defineModel<string>('province', { default: '' });
const municipality = defineModel<string>('municipality', { default: '' });
const barangay = defineModel<string>('barangay', { default: '' });
defineProps<{ idPrefix: string; lockArea?: boolean; streetLabel?: string }>();
</script>

<template>
    <div class="space-y-3">
        <label class="block text-sm font-medium" :for="`${idPrefix}house_no_street`">{{ streetLabel ?? 'House no. / street / sitio' }}
            <input :id="`${idPrefix}house_no_street`" v-model="street" maxlength="255" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" placeholder="e.g., 12 Rizal St., Purok 3">
        </label>
        <LocationSelects v-model:region="region" v-model:province="province" v-model:municipality="municipality" v-model:barangay="barangay" :lock-area="lockArea" :id-prefix="idPrefix" />
    </div>
</template>
