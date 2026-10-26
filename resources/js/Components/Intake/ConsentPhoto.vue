<script setup lang="ts">
/**
 * Photo of the signed consent / assent form: take it with the camera or pick
 * an image. The picture is redrawn on this device as a smaller JPEG before it
 * is kept, which also removes location and camera details. The server checks
 * and redraws it again.
 */
import { Camera, ImageUp, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

const photo = defineModel<string>('photo', { default: '' });
const type = defineModel<string>('type', { default: '' });
defineProps<{ error?: string; typeError?: string }>();

const MAX_SIDE = 1600;
const working = ref(false);
const problem = ref('');
const cameraInput = ref<HTMLInputElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

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
        const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('No canvas');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        photo.value = canvas.toDataURL('image/jpeg', 0.82);
        if (!type.value) type.value = 'consent';
    } catch {
        problem.value = 'This photo could not be read. Take it again or choose another image.';
    } finally {
        working.value = false;
    }
}
const remove = () => { photo.value = ''; type.value = ''; };
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-4" aria-labelledby="consent-photo-title">
        <h3 id="consent-photo-title" class="text-sm font-bold text-slate-900">Signed consent / assent form <span class="font-normal text-slate-500">(optional)</span></h3>
        <p class="mt-1 text-xs leading-5 text-slate-600">Take a clear photo of the signed form. It is attached to the case folder and seen only by the case team. Location and camera details are removed.</p>

        <div v-if="photo" class="mt-3 flex flex-wrap items-start gap-3">
            <img :src="photo" alt="Photo of the signed form" class="h-32 w-auto max-w-full rounded-lg border border-slate-200 object-contain">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <label class="text-xs font-bold text-slate-700" for="intake-consent_form_type">This photo shows
                    <select id="intake-consent_form_type" v-model="type" class="mt-1 block min-h-11 w-full rounded-lg border-slate-300 text-sm font-normal" :aria-invalid="Boolean(typeError)">
                        <option value="consent">Consent form (parent or guardian)</option>
                        <option value="assent">Assent form (child)</option>
                        <option value="consent_and_assent">Consent and assent forms</option>
                    </select>
                </label>
                <small v-if="typeError" class="text-xs text-rose-700">{{ typeError }}</small>
                <button type="button" class="ui-button min-h-11 self-start px-3 text-rose-700" @click="remove"><Trash2 class="h-4 w-4" aria-hidden="true" />Remove photo</button>
            </div>
        </div>

        <div v-else class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="ui-button min-h-11 px-3" :disabled="working" @click="cameraInput?.click()"><Camera class="h-4 w-4" aria-hidden="true" />Take photo</button>
            <button type="button" class="ui-button min-h-11 px-3" :disabled="working" @click="fileInput?.click()"><ImageUp class="h-4 w-4" aria-hidden="true" />Choose image</button>
            <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true" @change="picked">
            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" aria-hidden="true" @change="picked">
        </div>
        <p v-if="working" class="mt-2 text-xs text-slate-600" aria-live="polite">Preparing the photo...</p>
        <p v-if="problem || error" id="intake-consent_photo" class="mt-2 text-xs text-rose-700" role="alert">{{ problem || error }}</p>
    </section>
</template>
