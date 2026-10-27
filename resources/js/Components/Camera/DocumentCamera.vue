<script setup lang="ts">
/**
 * Full-screen camera for photographing a signed form.
 *
 * Opens the device camera directly (back camera on phones, the webcam on a
 * laptop), shows a frame to line the paper up with, and gives live hints
 * when the picture is too dark. Nothing is recorded or sent: the stream stays
 * on this device and stops as soon as the camera closes. If the camera
 * cannot be opened (permission refused, no camera), the parent falls back to
 * the phone's own camera app or a file.
 */
import { Camera, RefreshCw, X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { LIMITS, measure, toGray } from '@/utils/photoQuality';

const emit = defineEmits<{ captured: [canvas: HTMLCanvasElement]; close: []; failed: [reason: 'denied' | 'unavailable'] }>();
const video = ref<HTMLVideoElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const ready = ref(false);
const hint = ref('Line the whole form up inside the frame.');
const cameras = ref<MediaDeviceInfo[]>([]);
const facing = ref<'environment' | 'user'>('environment');
let stream: MediaStream | null = null;
let timer: number | undefined;
const previousFocus = document.activeElement as HTMLElement | null;

function stop() {
    window.clearInterval(timer);
    stream?.getTracks().forEach(track => track.stop());
    stream = null;
    ready.value = false;
}

async function start() {
    stop();
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: facing.value }, width: { ideal: 1920 }, height: { ideal: 1440 } } });
    } catch (error) {
        const name = (error as DOMException)?.name;
        emit('failed', name === 'NotAllowedError' || name === 'SecurityError' ? 'denied' : 'unavailable');
        return;
    }
    if (!video.value) return stop();
    video.value.srcObject = stream;
    await video.value.play().catch(() => undefined);
    ready.value = true;
    cameras.value = (await navigator.mediaDevices.enumerateDevices().catch(() => [])).filter(device => device.kind === 'videoinput');
    timer = window.setInterval(liveHint, 800);
}

/** A quick light check on a small copy of the live picture. */
function liveHint() {
    const element = video.value;
    if (!element || !element.videoWidth) return;
    const canvas = document.createElement('canvas');
    canvas.width = 96;
    canvas.height = Math.max(1, Math.round(96 * element.videoHeight / element.videoWidth));
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) return;
    context.drawImage(element, 0, 0, canvas.width, canvas.height);
    const values = measure(toGray(context.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height), canvas.width, canvas.height);
    hint.value = values.brightness < LIMITS.dark ? 'Too dark - move to better light.'
        : values.brightness > LIMITS.bright ? 'Too bright - avoid glare on the paper.'
        : 'Good light. Hold still and take the photo.';
}

function capture() {
    const element = video.value;
    if (!element || !element.videoWidth) return;
    const canvas = document.createElement('canvas');
    canvas.width = element.videoWidth;
    canvas.height = element.videoHeight;
    canvas.getContext('2d')?.drawImage(element, 0, 0);
    stop();
    emit('captured', canvas);
}

function switchCamera() {
    facing.value = facing.value === 'environment' ? 'user' : 'environment';
    start();
}

function close() {
    stop();
    emit('close');
}

const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') { event.preventDefault(); close(); } };
onMounted(async () => {
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    await nextTick();
    closeButton.value?.focus();
    start();
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
    stop();
    previousFocus?.focus?.();
});
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[70] flex flex-col bg-black text-white" role="dialog" aria-modal="true" aria-labelledby="document-camera-title">
            <header class="flex items-center justify-between gap-3 px-4 py-3">
                <h2 id="document-camera-title" class="text-base font-semibold">Photograph the signed form</h2>
                <button ref="closeButton" type="button" class="inline-flex min-h-12 min-w-12 items-center justify-center rounded-full bg-white/15 hover:bg-white/25" aria-label="Close the camera" @click="close"><X class="h-6 w-6" aria-hidden="true" /></button>
            </header>
            <div class="relative min-h-0 flex-1 overflow-hidden">
                <video ref="video" class="h-full w-full object-contain" playsinline muted aria-label="Live camera view"></video>
                <!-- Frame guide in A4 proportions. -->
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center p-6" aria-hidden="true">
                    <div class="aspect-[1/1.414] h-full max-h-full max-w-full rounded-xl border-4 border-dashed border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]"></div>
                </div>
                <p v-if="!ready" class="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-sm">Opening the camera...</p>
            </div>
            <footer class="space-y-3 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 text-center">
                <p class="text-base font-medium" aria-live="polite">{{ hint }}</p>
                <div class="flex items-center justify-center gap-6">
                    <button v-if="cameras.length > 1" type="button" class="inline-flex min-h-14 min-w-14 items-center justify-center rounded-full bg-white/15 hover:bg-white/25" aria-label="Switch camera" @click="switchCamera"><RefreshCw class="h-6 w-6" aria-hidden="true" /></button>
                    <button type="button" class="inline-flex h-20 w-20 items-center justify-center rounded-full border-4 border-white bg-white/90 text-brand-800 shadow-lg disabled:opacity-50" :disabled="!ready" aria-label="Take the photo" @click="capture"><Camera class="h-8 w-8" aria-hidden="true" /></button>
                    <span v-if="cameras.length > 1" class="min-w-14" aria-hidden="true"></span>
                </div>
            </footer>
        </div>
    </Teleport>
</template>
