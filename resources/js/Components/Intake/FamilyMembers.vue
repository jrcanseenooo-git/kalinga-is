<script setup lang="ts">
/** Family composition: one card per person, with an address that can follow the child's. */
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import AddressFields from '@/Components/Intake/AddressFields.vue';
import { emptyFamilyMember, type FamilyMember } from '@/Casework/intake';
import { CIVIL_STATUSES, EDUCATION_LEVELS, OCCUPATIONS, RELATIONSHIPS } from '@/utils/caseOptions';
import { ageFromBirthdate } from '@/utils/philippineTime';
import { ChevronDown, Plus, Trash2, UserRound } from '@lucide/vue';
import { nextTick, ref } from 'vue';

const members = defineModel<FamilyMember[]>({ required: true });
defineProps<{ childAddress: string }>();
const open = ref<number | null>(null);
const todayIso = new Date().toISOString().slice(0, 10);

async function add() {
    members.value.push(emptyFamilyMember());
    open.value = members.value.length - 1;
    await nextTick();
    document.getElementById(`family-${open.value}-name`)?.focus();
}
function remove(index: number) {
    members.value.splice(index, 1);
    open.value = null;
}
const summary = (member: FamilyMember) => {
    const age = member.birthdate ? ageFromBirthdate(member.birthdate) : null;
    return [member.relationship_type, age !== null && age >= 0 ? `${age} yrs` : '', member.occupation].filter(Boolean).join(' · ') || 'Details not yet entered';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4" aria-labelledby="family-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 id="family-title" class="text-sm font-bold text-slate-900">Family composition</h3>
                <p class="text-xs text-slate-600">Household members and relatives involved in the child's care. Shown in the Case Summary and the Social Case Study Report.</p>
            </div>
            <button type="button" class="ui-button min-h-11 px-3" @click="add"><Plus class="h-4 w-4" aria-hidden="true" />Add family member</button>
        </div>

        <ul v-if="members.length" class="mt-3 space-y-2">
            <li v-for="(member, index) in members" :key="index" class="rounded-xl border border-slate-200 bg-white">
                <button type="button" class="flex min-h-12 w-full items-center gap-3 px-3 py-2 text-left" :aria-expanded="open === index" :aria-controls="`family-${index}`" @click="open = open === index ? null : index">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700"><UserRound class="h-4 w-4" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-900">{{ member.name || `Family member ${index + 1}` }}</span><span class="block truncate text-xs text-slate-600">{{ summary(member) }}</span></span>
                    <ChevronDown class="h-4 w-4 shrink-0 text-slate-500 transition" :class="open === index ? 'rotate-180' : ''" aria-hidden="true" />
                </button>
                <div v-show="open === index" :id="`family-${index}`" class="space-y-3 border-t border-slate-100 p-3">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="text-sm font-medium" :for="`family-${index}-name`">Name
                            <input :id="`family-${index}-name`" v-model="member.name" maxlength="160" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-medium">Relationship to the child<OptionSelect v-model="member.relationship_type" :options="RELATIONSHIPS" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                        <label class="text-sm font-medium">Sex
                            <select v-model="member.sex" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"><option value="">Not recorded</option><option>Female</option><option>Male</option></select>
                        </label>
                        <label class="text-sm font-medium">Date of birth<input v-model="member.birthdate" type="date" :max="todayIso" class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"></label>
                        <label class="text-sm font-medium">Civil status<OptionSelect v-model="member.civil_status" :options="CIVIL_STATUSES" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                        <label class="text-sm font-medium">Education<OptionSelect v-model="member.education" :options="EDUCATION_LEVELS" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                        <label class="text-sm font-medium sm:col-span-2 lg:col-span-1">Occupation<OptionSelect v-model="member.occupation" :options="OCCUPATIONS" select-class="mt-1 block min-h-11 w-full rounded-xl border-slate-300" /></label>
                    </div>
                    <label class="flex min-h-11 items-start gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                        <input v-model="member.same_address" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-700">
                        <span><span class="font-semibold">Same address as the child</span><span v-if="member.same_address" class="block text-xs text-slate-600">{{ childAddress || 'The child\'s present address' }}</span></span>
                    </label>
                    <AddressFields v-if="!member.same_address" v-model:street="member.house_no_street" v-model:region="member.region" v-model:province="member.province"
                        v-model:municipality="member.municipality" v-model:barangay="member.barangay" :id-prefix="`family-${index}-`" />
                    <button type="button" class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-rose-700" @click="remove(index)"><Trash2 class="h-4 w-4" aria-hidden="true" />Remove {{ member.name || 'this person' }}</button>
                </div>
            </li>
        </ul>
        <p v-else class="mt-3 text-sm text-slate-600">No family members added yet.</p>
    </section>
</template>
