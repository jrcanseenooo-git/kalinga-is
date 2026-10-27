<script setup lang="ts">
/**
 * Family composition: one card per person, with an address that can follow
 * the child's. "Add family member" never stacks empty cards: if a card is
 * still blank it is opened instead, and blank cards are left out when the
 * intake is saved. Kalinga also offers to add the primary caregiver named
 * above in one tap.
 */
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import AddressFields from '@/Components/Intake/AddressFields.vue';
import { emptyFamilyMember, isEmptyFamilyMember, tidyName, type FamilyMember } from '@/Casework/intake';
import { CIVIL_STATUSES, EDUCATION_LEVELS, OCCUPATIONS, RELATIONSHIPS } from '@/utils/caseOptions';
import { ageFromBirthdate, manilaToday } from '@/utils/philippineTime';
import { ChevronDown, Plus, Sparkles, Trash2, UserRound } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';

const members = defineModel<FamilyMember[]>({ required: true });
const props = defineProps<{ childAddress: string; caregiver?: { name: string; relationship: string }; errors?: Record<string, string>; reveal?: number | null }>();
const open = ref<number | null>(null);
const notice = ref('');
const todayIso = manilaToday();

async function openCard(index: number) {
    open.value = index;
    await nextTick();
    document.getElementById(`family-${index}-name`)?.focus();
}
async function add() {
    const blank = members.value.findIndex(isEmptyFamilyMember);
    if (blank !== -1) {
        notice.value = `Family member ${blank + 1} is still empty - fill it in first.`;
        await openCard(blank);
        return;
    }
    notice.value = '';
    members.value.push(emptyFamilyMember());
    await openCard(members.value.length - 1);
}
function remove(index: number) {
    members.value.splice(index, 1);
    open.value = null;
    notice.value = '';
}
// Closing a card that is still blank removes it, so no empty entry is left behind.
function toggle(index: number) {
    if (open.value === index) {
        open.value = null;
        if (isEmptyFamilyMember(members.value[index])) remove(index);
        return;
    }
    if (open.value !== null && isEmptyFamilyMember(members.value[open.value])) {
        const closing = open.value;
        members.value.splice(closing, 1);
        open.value = index > closing ? index - 1 : index;
        return;
    }
    open.value = index;
}
watch(() => props.reveal, index => { if (index !== null && index !== undefined && members.value[index]) open.value = index; });

const caregiverSuggestion = computed(() => {
    const name = props.caregiver?.name.trim();
    if (!name) return null;
    const known = members.value.some(member => member.name.trim().toLowerCase() === name.toLowerCase());
    return known ? null : { name, relationship: props.caregiver?.relationship ?? '' };
});
async function addCaregiver() {
    const suggestion = caregiverSuggestion.value;
    if (!suggestion) return;
    const blank = members.value.findIndex(isEmptyFamilyMember);
    const member = { ...emptyFamilyMember(), name: suggestion.name, relationship_type: suggestion.relationship };
    if (blank !== -1) members.value.splice(blank, 1, member); else members.value.push(member);
    notice.value = `${suggestion.name} was added. Add the date of birth and other details if known.`;
    await openCard(blank !== -1 ? blank : members.value.length - 1);
}

const summary = (member: FamilyMember) => {
    const age = member.birthdate ? ageFromBirthdate(member.birthdate) : null;
    return [member.relationship_type, age !== null && age >= 0 ? `${age} yrs` : '', member.occupation].filter(Boolean).join(' - ') || 'Details not yet entered';
};
const hasError = (index: number) => Object.keys(props.errors ?? {}).some(key => key.startsWith(`family_members.${index}.`));
</script>

<template>
    <section class="f-card bg-slate-50/60" aria-labelledby="family-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h3 id="family-title" class="font-bold text-slate-900">Family composition</h3>
                <p class="f-hint">Household members and relatives involved in the child's care. Shown in the Case Summary and the Social Case Study Report.</p>
            </div>
            <button type="button" class="ui-button min-h-12 px-4 text-base" @click="add"><Plus class="h-5 w-5" aria-hidden="true" />Add family member</button>
        </div>

        <button v-if="caregiverSuggestion" type="button" class="mt-3 flex min-h-12 w-full items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-left text-sm text-violet-950 hover:bg-violet-100" @click="addCaregiver">
            <Sparkles class="h-5 w-5 shrink-0 text-violet-700" aria-hidden="true" />
            <span>Add <b>{{ caregiverSuggestion.name }}</b>{{ caregiverSuggestion.relationship ? ` (${caregiverSuggestion.relationship})` : '' }}, the primary caregiver, to the family composition</span>
        </button>
        <p v-if="notice" class="mt-3 text-sm text-slate-700" role="status">{{ notice }}</p>

        <ul v-if="members.length" class="mt-3 space-y-2">
            <li v-for="(member, index) in members" :key="index" class="rounded-xl border bg-white" :class="hasError(index) ? 'border-rose-400' : 'border-slate-200'">
                <button type="button" class="flex min-h-14 w-full items-center gap-3 px-3 py-2 text-left" :aria-expanded="open === index" :aria-controls="`family-${index}`" @click="toggle(index)">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700"><UserRound class="h-5 w-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-slate-900">{{ member.name || `Family member ${index + 1}` }}</span><span class="block truncate text-sm text-slate-600">{{ summary(member) }}</span></span>
                    <ChevronDown class="h-5 w-5 shrink-0 text-slate-500 transition" :class="open === index ? 'rotate-180' : ''" aria-hidden="true" />
                </button>
                <div v-show="open === index" :id="`family-${index}`" class="space-y-3 border-t border-slate-100 p-3 sm:p-4">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="font-medium" :for="`family-${index}-name`">Name
                            <input :id="`family-${index}-name`" v-model="member.name" maxlength="160" class="mt-1 block w-full rounded-xl border-slate-300" :aria-invalid="Boolean(errors?.[`family_members.${index}.name`]) || undefined" @blur="member.name = tidyName(member.name)">
                        </label>
                        <label class="font-medium">Relationship to the child<OptionSelect :id="`family-${index}-relationship_type`" v-model="member.relationship_type" :options="RELATIONSHIPS" select-class="mt-1 block w-full rounded-xl border-slate-300" /></label>
                        <label class="font-medium">Sex
                            <select v-model="member.sex" class="mt-1 block w-full rounded-xl border-slate-300"><option value="">Not recorded</option><option>Female</option><option>Male</option></select>
                        </label>
                        <label class="font-medium">Date of birth<input v-model="member.birthdate" type="date" :max="todayIso" class="mt-1 block w-full rounded-xl border-slate-300"></label>
                        <label class="font-medium">Civil status<OptionSelect v-model="member.civil_status" :options="CIVIL_STATUSES" select-class="mt-1 block w-full rounded-xl border-slate-300" /></label>
                        <label class="font-medium">Education<OptionSelect v-model="member.education" :options="EDUCATION_LEVELS" select-class="mt-1 block w-full rounded-xl border-slate-300" /></label>
                        <label class="font-medium sm:col-span-2 lg:col-span-1">Occupation<OptionSelect v-model="member.occupation" :options="OCCUPATIONS" select-class="mt-1 block w-full rounded-xl border-slate-300" /></label>
                    </div>
                    <p v-for="key in ['name', 'relationship_type']" v-show="errors?.[`family_members.${index}.${key}`]" :key="key" class="text-sm text-rose-700">{{ errors?.[`family_members.${index}.${key}`] }}</p>
                    <label class="flex min-h-12 items-start gap-3 rounded-lg bg-slate-50 px-3 py-2">
                        <input v-model="member.same_address" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-700">
                        <span><span class="font-semibold">Same address as the child</span><span v-if="member.same_address" class="block text-sm text-slate-600">{{ childAddress || 'The child\'s present address' }}</span></span>
                    </label>
                    <AddressFields v-if="!member.same_address" v-model:street="member.house_no_street" v-model:region="member.region" v-model:province="member.province"
                        v-model:municipality="member.municipality" v-model:barangay="member.barangay" :id-prefix="`family-${index}-`" />
                    <button type="button" class="inline-flex min-h-12 items-center gap-1.5 font-semibold text-rose-700" @click="remove(index)"><Trash2 class="h-5 w-5" aria-hidden="true" />Remove {{ member.name || 'this person' }}</button>
                </div>
            </li>
        </ul>
        <p v-else class="mt-3 text-slate-600">No family members added yet.</p>
    </section>
</template>
