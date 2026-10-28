<script setup lang="ts">
import { askConfirm } from '@/utils/confirmDialog';
/**
 * Kalinga Intake (online). Answers are saved automatically as a "saved
 * intake" once the privacy notice step is done, so the intake can be paused
 * and resumed. Creating the case folder opens the new case.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import IntakeForm from '@/Components/Intake/IntakeForm.vue';
import { DEFAULT_INTAKE_OPTIONS, INTAKE_STEPS, clampStep, restoreIntake, intakePayload, intakeStepForField, type AssignedLocation, type IntakeData, type IntakeOptions } from '@/Casework/intake';
import { formatPh } from '@/utils/philippineTime';
import type { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ClipboardList, CloudOff, FilePlus2, History, PauseCircle, ShieldCheck, Trash2, UserRound } from '@lucide/vue';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type SavedIntake = { id: string; label: string | null; step: number; updated_at: string; expires_at: string };
const props = defineProps<{
    assignedLocation?: AssignedLocation;
    intakeOptions?: IntakeOptions;
    draft?: { id: string; step: number; payload: Partial<IntakeData> } | null;
    savedIntakes?: SavedIntake[];
    keepDays?: number;
}>();
const page = usePage<PageProps>();
const canUseOffline = computed(() => page.props.auth.permissions.includes('offline.use'));
const options = computed<IntakeOptions>(() => props.intakeOptions?.riskFactors?.length ? props.intakeOptions : DEFAULT_INTAKE_OPTIONS);

const form = useForm(restoreIntake(props.draft?.payload, props.assignedLocation));
const step = ref(clampStep(props.draft?.step ?? 1));
const started = ref(Boolean(props.draft));
const confirming = ref(false);
const draftId = ref<string | null>(props.draft?.id ?? null);
const intakeForm = ref<InstanceType<typeof IntakeForm> | null>(null);
let focusErrorsAfterClose = false;
function afterDialogClosed() {
    if (!focusErrorsAfterClose) return;
    focusErrorsAfterClose = false;
    void intakeForm.value?.focusSummary();
}

// Saving to "Saved intakes"
const saveState = ref<'idle' | 'saving' | 'saved' | 'offline' | 'error'>(props.draft ? 'saved' : 'idle');
const savedAt = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let lastSaved = props.draft ? JSON.stringify(form.data()) : '';

async function saveDraft(): Promise<boolean> {
    if (timer) { clearTimeout(timer); timer = null; }
    if (!form.privacy_notice_acknowledged || form.processing) return false;
    const snapshot = JSON.stringify(form.data());
    if (snapshot === lastSaved && draftId.value) return true;
    if (!navigator.onLine) { saveState.value = 'offline'; return false; }
    draftId.value ??= crypto.randomUUID();
    saveState.value = 'saving';
    try {
        const response = await axios.put(route('intake.drafts.update', draftId.value), { step: step.value, payload: JSON.parse(snapshot) });
        lastSaved = snapshot;
        savedAt.value = response.data.saved_at;
        saveState.value = 'saved';
        return true;
    } catch {
        saveState.value = navigator.onLine ? 'error' : 'offline';
        return false;
    }
}
function scheduleSave() {
    if (!started.value || !form.privacy_notice_acknowledged) return;
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => { void saveDraft(); }, 2500);
}
watch(() => form.data(), scheduleSave, { deep: true });
watch(step, () => { void saveDraft(); });

async function pause() {
    if (await saveDraft()) router.visit(route('cases.create'));
}
const statusText = computed(() => ({
    idle: form.privacy_notice_acknowledged ? 'Changes save automatically.' : 'Saving starts after the privacy notice step.',
    saving: 'Saving...',
    saved: savedAt.value ? `Saved ${formatPh(savedAt.value, 'time')}` : 'Saved',
    offline: 'No connection: not saved yet. Keep this page open.',
    error: 'Not saved. It will retry when you continue.',
}[saveState.value]));

function leaveGuard(event: BeforeUnloadEvent) {
    if (started.value && form.isDirty && JSON.stringify(form.data()) !== lastSaved && !form.processing) { event.preventDefault(); event.returnValue = ''; }
}
onMounted(() => window.addEventListener('beforeunload', leaveGuard));
onBeforeUnmount(() => { window.removeEventListener('beforeunload', leaveGuard); if (timer) clearTimeout(timer); });

const serverErrors = computed(() => form.errors as Record<string, string>);
function create() {
    if (form.processing) return;
    form.transform(data => ({ ...intakePayload(data as IntakeData), draft_id: draftId.value }))
        .post(route('cases.store'), {
            preserveScroll: true,
            onStart: () => { if (timer) clearTimeout(timer); },
            onError: errors => { focusErrorsAfterClose = true; confirming.value = false; step.value = intakeStepForField(Object.keys(errors)[0] ?? ''); },
        });
}

const startSteps = [
    { icon: ShieldCheck, title: 'Privacy notice', text: 'Explain the notice; photograph the signed consent / assent form.' },
    { icon: UserRound, title: 'Child & contact', text: 'Name, birth date, address, child contact, caregiver and family.' },
    { icon: ClipboardList, title: 'Case details', text: 'CEFMU classification and incidence, schooling, referral source.' },
    { icon: AlertTriangle, title: 'Safety triage', text: 'Risks observed; Kalinga suggests the initial risk level.' },
];
const readyList = ['The signed consent / assent form, to photograph', "The child's birth date and present address", "The caregiver's name and contact number"];
/** The saved intake changed within the last day, offered first. */
const recent = computed(() => {
    const first = props.savedIntakes?.[0];
    return first && Date.now() - new Date(first.updated_at).getTime() < 24 * 60 * 60 * 1000 ? first : null;
});
const named = (item: SavedIntake) => Boolean(item.label && item.label !== 'Not recorded');
const draftTitle = (item: SavedIntake) => named(item) ? `Intake for ${item.label}` : 'Intake - child\'s name not entered yet';
const daysLeft = (item: SavedIntake) => Math.max(0, Math.ceil((new Date(item.expires_at).getTime() - Date.now()) / 86_400_000));
const stepName = (value: number) => INTAKE_STEPS[Math.max(0, Math.min(INTAKE_STEPS.length - 1, value - 1))];
const discard = async (item: SavedIntake) => {
    if (!(await askConfirm({ title: 'Discard this saved intake?', message: `${item.label && item.label !== 'Not recorded' ? item.label + ': the' : 'The'} answers are deleted.`, confirmLabel: 'Discard', tone: 'danger' }))) return;
    router.delete(route('intake.drafts.destroy', item.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Case intake" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-5xl pb-8">
            <PageHeader title="Kalinga Intake" eyebrow="Case intake" description="Open a protected CEFMU case in four steps. You can pause and continue later." />

            <template v-if="!started">
                <!-- Smart: offer the intake worked on most recently. -->
                <section v-if="recent" class="mt-5 flex flex-wrap items-center gap-4 rounded-2xl border border-violet-200 bg-violet-50 p-4 sm:p-5" aria-labelledby="resume-title">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-white text-violet-700" aria-hidden="true"><History class="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 id="resume-title" class="font-bold text-violet-950">Pick up where you left off</h2>
                        <p class="text-sm text-violet-900">{{ draftTitle(recent) }} - step {{ recent.step }} of 4 ({{ stepName(recent.step) }}), saved {{ formatPh(recent.updated_at, 'dateTimeShort') }}.</p>
                    </div>
                    <Link :href="route('cases.create', { draft: recent.id })" class="ui-button-primary min-h-12 px-5 text-base">Continue this intake</Link>
                </section>

                <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="start-title">
                    <div class="grid gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                        <div class="min-w-0">
                            <h2 id="start-title" class="text-xl font-bold text-slate-900">Start a new intake</h2>
                            <p class="mt-1 text-[15px] leading-6 text-slate-600">Four short steps. Answers save as you go after step 1, so you can stop if the child is not ready.</p>
                        </div>
                        <button type="button" class="ui-button-primary min-h-14 px-6 text-base" @click="started = true"><FilePlus2 class="h-5 w-5" aria-hidden="true" />Start new intake</button>
                    </div>
                    <ol class="grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4" aria-label="Intake steps">
                        <li v-for="(item, index) in startSteps" :key="item.title" class="flex items-start gap-3 bg-white p-4">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true"><component :is="item.icon" class="h-5 w-5" /></span>
                            <span class="min-w-0"><span class="block text-xs font-bold uppercase tracking-wide text-brand-700">Step {{ index + 1 }}</span><span class="block font-semibold text-slate-900">{{ item.title }}</span><span class="block text-sm leading-5 text-slate-600">{{ item.text }}</span></span>
                        </li>
                    </ol>
                    <div class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-7">
                        <p class="text-sm font-semibold text-slate-800">Have these ready</p>
                        <ul class="mt-2 grid gap-2 text-sm text-slate-700 sm:grid-cols-3">
                            <li v-for="item in readyList" :key="item" class="flex items-start gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />{{ item }}</li>
                        </ul>
                    </div>
                </section>

                <section class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="saved-title">
                    <header class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h2 id="saved-title" class="font-bold text-slate-900">Saved intakes <span class="text-sm font-semibold text-slate-500">({{ savedIntakes?.length ?? 0 }})</span></h2>
                            <p class="text-sm text-slate-600">Only you can see these. Each is deleted after {{ keepDays ?? 30 }} days without changes.</p>
                        </div>
                    </header>
                    <ul v-if="savedIntakes?.length" class="divide-y divide-slate-100">
                        <li v-for="item in savedIntakes" :key="item.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-semibold text-slate-900">{{ draftTitle(item) }}</p>
                                    <span v-if="daysLeft(item) <= 7" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">Deleted in {{ daysLeft(item) }} {{ daysLeft(item) === 1 ? 'day' : 'days' }}</span>
                                </div>
                                <p class="text-sm text-slate-600">Step {{ item.step }} of 4 - {{ stepName(item.step) }} - saved {{ formatPh(item.updated_at, 'dateTimeShort') }}</p>
                                <div class="mt-2 h-2 max-w-xs overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="item.step" aria-valuemin="1" aria-valuemax="4" :aria-label="`Stopped at step ${item.step} of 4`"><div class="h-full rounded-full bg-brand-600" :style="{ width: `${(item.step / 4) * 100}%` }"></div></div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link :href="route('cases.create', { draft: item.id })" class="ui-button-primary min-h-12 px-5">Continue</Link>
                                <button type="button" class="ui-button min-h-12 px-4 text-rose-700" :aria-label="`Discard saved intake ${named(item) ? item.label : 'without a name'}`" @click="discard(item)"><Trash2 class="h-4 w-4" aria-hidden="true" />Discard</button>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-6 text-sm text-slate-600">No saved intakes. When you pause an intake, it appears here.</p>
                </section>

                <p v-if="canUseOffline" class="mt-4 flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950"><CloudOff class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" /><span>No signal where you are going? Use <a :href="route('offline.workspace')" class="font-semibold underline">field mode</a>: the same intake, saved encrypted on your device and sent when you are back online.</span></p>
            </template>

            <section v-else class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <IntakeForm ref="intakeForm" v-model:step="step" :data="form" :options="options" :server-errors="serverErrors" :assigned-location="assignedLocation" :busy="form.processing"
                    submit-label="Create case folder" @continue="form.clearErrors()" @submit="confirming = true">
                    <template #status>
                        <div class="flex min-w-0 flex-wrap items-center justify-center gap-x-3 gap-y-1 text-center">
                            <span class="text-xs text-slate-600" aria-live="polite">{{ statusText }}</span>
                            <button v-if="form.privacy_notice_acknowledged" type="button" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-brand-700" :disabled="form.processing" @click="pause"><PauseCircle class="h-4 w-4" aria-hidden="true" />Save and continue later</button>
                        </div>
                    </template>
                </IntakeForm>
            </section>

            <Modal :show="confirming" aria-labelledby="intake-confirm-title" :closeable="!form.processing" max-width="lg" @close="confirming = false" @closed="afterDialogClosed">
                <section class="p-6">
                    <h2 id="intake-confirm-title" class="text-lg font-bold text-slate-900">Create the case folder?</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">The case is assigned to you and opens next, where you continue with the Kalinga forms. The saved intake is then deleted.</p>
                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" class="ui-button min-h-11 px-4" :disabled="form.processing" @click="confirming = false">Review again</button>
                        <button type="button" class="ui-button-primary min-h-11 px-4" :disabled="form.processing" @click="create">{{ form.processing ? 'Creating...' : 'Create case folder' }}</button>
                    </div>
                </section>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>
