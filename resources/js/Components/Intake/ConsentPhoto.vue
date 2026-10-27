<script setup lang="ts">
/**
 * Photo of the signed consent / assent form.
 *
 * "Take photo" opens the device camera inside Kalinga (back camera on phones,
 * webcam on laptops). If the camera cannot be opened, the phone's own camera
 * app is used instead. Every photo is checked on this device for light,
 * blur, faint writing and size, and can be made clearer (black and white,
 * like a scanner). The picture is redrawn as a smaller JPEG before it is
 * kept, which also removes location and camera details; the server checks
 * and redraws it again.
 */
import DocumentCamera from '@/Components/Camera/DocumentCamera.vue';
import { checkCanvas, enhance, ISSUE_TEXT, type PhotoReport } from '@/utils/photoQuality';
import { AlertTriangle, Camera, CheckCircle2, ImageUp, RotateCcw, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const photo = defineModel<string>('photo', { default: '' });
const type = defineModel<string>('type', { default: '' });
const props = withDefaults(defineProps<{ error?: string; typeError?: string; optional?: boolean; idPrefix?: string }>(), { optional: true, idPrefix: 'intake' });

const MAX_SIDE = 1800;
const working = ref(false);
const problem = ref('');
const cameraOpen = ref(false);
const cameraRefused = ref(false);
const report = ref<PhotoReport | null>(null);
const clearer = ref(false);
const cameraInput = ref<HTMLInputElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
let original: HTMLCanvasElement | null = null;

const canUseCamera = typeof navigator !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia) && window.isSecureContext;
const typeId = computed(() => `${props.idPrefix}-consent_form_type`);

function takePhoto() {
    problem.value = '';
    if (canUseCamera && !cameraRefused.value) cameraOpen.value = true;
    else cameraInput.value?.click();
}

function cameraFailed(reason: 'denied' | 'unavailable') {
    cameraOpen.value = false;
    cameraRefused.value = true;
    problem.value = reason === 'denied'
        ? 'Kalinga was not allowed to use the camera. Allow the camera for this site in the browser settings, or use the phone camera app below.'
        : 'No camera could be opened on this device. Use the phone camera app or choose an image.';
}

/** Scale the photo down, keep the original for "clearer", and check it. */
function accept(source: HTMLCanvasElement | ImageBitmap) {
    const scale = Math.min(1, MAX_SIDE / Math.max(source.width, source.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(source.width * scale));
    canvas.height = Math.max(1, Math.round(source.height * scale));
    const context = canvas.getContext('2d');
    if (!context) throw new Error('No canvas');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    original = canvas;
    report.value = checkCanvas(canvas);
    // Smart default: faint writing is made clearer automatically.
    clearer.value = report.value.issues.includes('low_contrast');
    render();
    if (!type.value) type.value = 'consent';
}

function render() {
    if (!original) return;
    if (!clearer.value) {
        photo.value = original.toDataURL('image/jpeg', 0.82);
        return;
    }
    const copy = document.createElement('canvas');
    copy.width = original.width;
    copy.height = original.height;
    const context = copy.getContext('2d', { willReadFrequently: true });
    if (!context) return;
    context.drawImage(original, 0, 0);
    const pixels = context.getImageData(0, 0, copy.width, copy.height);
    enhance(pixels.data, copy.width, copy.height);
    context.putImageData(pixels, 0, 0);
    photo.value = copy.toDataURL('image/jpeg', 0.85);
}

function captured(canvas: HTMLCanvasElement) {
    cameraOpen.value = false;
    working.value = true;
    try { accept(canvas); } catch { problem.value = 'The photo could not be prepared. Take it again.'; } finally { working.value = false; }
}

async function picked(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    problem.value = '';
    if (!/^image\/(jpeg|png|webp|heic|heif)$/.test(file.type) || file.size > 15 * 1024 * 1024) {
        problem.value = 'Choose a photo (JPEG, PNG or WebP) under 15 MB.';
        return;
    }
    working.value = true;
    try {
        const bitmap = await createImageBitmap(file);
        accept(bitmap);
        bitmap.close();
    } catch {
        problem.value = 'This photo could not be read. Take it again or choose another image.';
    } finally {
        working.value = false;
    }
}

const remove = () => { photo.value = ''; type.value = ''; report.value = null; original = null; clearer.value = false; };
const retake = () => { remove(); takePhoto(); };
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" :aria-labelledby="`${idPrefix}-consent-photo-title`">
        <h3 :id="`${idPrefix}-consent-photo-title`" class="text-base font-bold text-slate-900">Signed consent / assent form <span v-if="optional" class="font-normal text-slate-600">(optional)</span></h3>
        <p class="mt-1 text-sm leading-6 text-slate-700">Take a clear photo of the signed form. It is kept in the case folder and seen only by the case team. Location and camera details are removed.</p>

        <div v-if="photo" class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
            <img :src="photo" alt="Photo of the signed form" class="max-h-72 w-full rounded-xl border border-slate-200 bg-slate-50 object-contain">
            <div class="min-w-0 space-y-3">
                <div v-if="report" class="rounded-xl border p-3 text-sm" :class="report.good ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-300 bg-amber-50 text-amber-950'" role="status">
                    <p class="flex items-center gap-2 font-semibold"><component :is="report.good ? CheckCircle2 : AlertTriangle" class="h-5 w-5 shrink-0" aria-hidden="true" />{{ report.good ? 'Clear photo - ready to keep.' : 'Check this photo' }}</p>
                    <ul v-if="!report.good" class="mt-1 list-disc space-y-1 pl-6"><li v-for="issue in report.issues" :key="issue">{{ ISSUE_TEXT[issue] }}</li></ul>
                </div>
                <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3 text-sm font-medium">
                    <input v-model="clearer" type="checkbox" class="h-5 w-5 rounded border-slate-400 text-brand-700" @change="render">Make the text clearer (black and white)
                </label>
                <label class="block text-sm font-semibold text-slate-800" :for="typeId">This photo shows
                    <select :id="typeId" v-model="type" class="ui-field mt-1" :aria-invalid="Boolean(typeError) || undefined">
                        <option value="consent">Consent form (parent or guardian)</option>
                        <option value="assent">Assent form (child)</option>
                        <option value="consent_and_assent">Consent and assent forms</option>
                    </select>
                </label>
                <small v-if="typeError" class="block text-sm text-rose-700">{{ typeError }}</small>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="ui-button min-h-11 px-3" @click="retake"><RotateCcw class="h-4 w-4" aria-hidden="true" />Take again</button>
                    <button type="button" class="ui-button min-h-11 px-3 text-rose-700" @click="remove"><Trash2 class="h-4 w-4" aria-hidden="true" />Remove photo</button>
                </div>
            </div>
        </div>

        <div v-else class="mt-4 grid gap-2 sm:flex sm:flex-wrap">
            <button type="button" class="ui-button-primary min-h-12 justify-center px-4 text-base" :disabled="working" @click="takePhoto"><Camera class="h-5 w-5" aria-hidden="true" />Take photo</button>
            <button type="button" class="ui-button min-h-12 justify-center px-4 text-base" :disabled="working" @click="fileInput?.click()"><ImageUp class="h-5 w-5" aria-hidden="true" />Choose image</button>
            <button v-if="cameraRefused" type="button" class="ui-button min-h-12 justify-center px-4 text-base" :disabled="working" @click="cameraInput?.click()"><Camera class="h-5 w-5" aria-hidden="true" />Use the phone camera app</button>
        </div>
        <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true" @change="picked">
        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" aria-hidden="true" @change="picked">
        <p v-if="working" class="mt-2 text-sm text-slate-700" aria-live="polite">Checking the photo...</p>
        <p v-if="problem || error" :id="`${idPrefix}-consent_photo`" class="mt-2 text-sm text-rose-700" role="alert">{{ problem || error }}</p>
        <DocumentCamera v-if="cameraOpen" @captured="captured" @close="cameraOpen = false" @failed="cameraFailed" />
    </section>
</template>
