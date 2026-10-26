<script setup lang="ts">
/**
 * Kalinga Intake (online). Answers are saved automatically as a "saved
 * intake" once the privacy notice step is done, so the intake can be paused
 * and resumed. Creating the case folder opens the new case.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import IntakeForm from '@/Components/Intake/IntakeForm.vue';
import { DEFAULT_INTAKE_OPTIONS, INTAKE_STEPS, emptyIntake, intakePayload, intakeStepForField, type AssignedLocation, type IntakeData, type IntakeOptions } from '@/Casework/intake';
import { formatPh } from '@/utils/philippineTime';
import type { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CloudOff, FilePlus2, PauseCircle, Trash2 } from '@lucide/vue';
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

const form = useForm({ ...emptyIntake(props.assignedLocation), ...(props.draft?.payload ?? {}) } as IntakeData);
const step = ref(props.draft?.step ?? 1);
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

const stepName = (value: number) => INTAKE_STEPS[Math.max(0, Math.min(INTAKE_STEPS.length - 1, value - 1))];
const discard = (item: SavedIntake) => {
    if (!window.confirm(`Discard the saved intake ${item.label ?? ''}? The answers will be deleted.`)) return;
    router.delete(route('intake.drafts.destroy', item.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Case intake" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-5xl pb-8">
            <PageHeader title="Kalinga Intake" eyebrow="Case intake" description="Open a protected CEFMU case in four steps. You can pause and continue later." />

            <template v-if="!started">
                <section class="mt-5 flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-slate-900">Start with the privacy notice</h2>
                        <p class="mt-1 text-sm text-slate-600">1 Privacy notice · 2 Child and safe contact · 3 Case details · 4 Safety triage. Answers are saved as you go, so you can stop if the child is not ready.</p>
                    </div>
                    <button type="button" class="ui-button-primary min-h-12 px-5" @click="started = true"><FilePlus2 class="h-4 w-4" aria-hidden="true" />Start new intake</button>
                </section>

                <section class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="saved-title">
                    <header class="border-b border-slate-100 px-5 py-3">
                        <h2 id="saved-title" class="font-bold text-slate-900">Saved intakes <span class="text-sm font-semibold text-slate-500">({{ savedIntakes?.length ?? 0 }})</span></h2>
                        <p class="text-xs text-slate-600">Only you can see these. Each is deleted after {{ keepDays ?? 30 }} days without changes.</p>
                    </header>
                    <ul v-if="savedIntakes?.length" class="divide-y divide-slate-100">
                        <li v-for="item in savedIntakes" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-bold text-slate-900">{{ item.label && item.label !== 'Not recorded' ? item.label : 'Name not yet entered' }}</p>
                                <p class="text-xs text-slate-600">Stopped at step {{ item.step }} of 4 ({{ stepName(item.step) }}) · saved {{ formatPh(item.updated_at, 'dateTimeShort') }} · deleted on {{ formatPh(item.expires_at, 'date') }}</p>
                            </div>
                            <Link :href="route('cases.create', { draft: item.id })" class="ui-button-primary min-h-11 px-4">Continue</Link>
                            <button type="button" class="ui-button min-h-11 px-3 text-rose-700" :aria-label="`Discard saved intake ${item.label ?? ''}`" @click="discard(item)"><Trash2 class="h-4 w-4" aria-hidden="true" />Discard</button>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-6 text-sm text-slate-600">No saved intakes. When you pause an intake, it appears here.</p>
                </section>

                <p v-if="canUseOffline" class="mt-4 flex items-start gap-2 text-sm text-slate-600"><CloudOff class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>No signal where you are going? Use <a :href="route('offline.workspace')" class="ui-link">field mode</a>: the same intake, saved encrypted on your device and sent when you are back online.</span></p>
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
