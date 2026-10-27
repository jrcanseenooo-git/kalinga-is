<script setup lang="ts">
/** The look shared by every confirmation in Kalinga: icon, title, explanation and two large buttons. */
import { AlertTriangle, CheckCircle2, HelpCircle } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ title: string; message?: string; confirmLabel?: string; cancelLabel?: string; tone?: 'danger' | 'default' | 'success'; idPrefix: string; confirmId?: string }>(),
    { message: '', confirmLabel: 'Continue', cancelLabel: 'Go back', tone: 'default', confirmId: undefined });
const emit = defineEmits<{ confirm: []; cancel: [] }>();
const icon = computed(() => props.tone === 'danger' ? AlertTriangle : props.tone === 'success' ? CheckCircle2 : HelpCircle);
</script>

<template>
    <section class="p-5 sm:p-6">
        <div class="flex flex-col items-center gap-3 text-center sm:flex-row sm:items-start sm:text-left">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full ring-8" :class="tone === 'danger' ? 'bg-rose-100 text-rose-700 ring-rose-50' : 'bg-brand-100 text-brand-700 ring-brand-50'" aria-hidden="true">
                <component :is="icon" class="h-6 w-6" />
            </span>
            <div class="min-w-0">
                <h2 :id="`${idPrefix}-title`" class="text-lg font-semibold leading-7 text-slate-900">{{ title }}</h2>
                <p v-if="message" :id="`${idPrefix}-message`" class="mt-1 whitespace-pre-line text-[15px] leading-6 text-slate-600">{{ message }}</p>
            </div>
        </div>
        <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
            <button type="button" class="ui-button min-h-12 px-5 text-base" @click="emit('cancel')">{{ cancelLabel }}</button>
            <button :id="confirmId" type="button" class="ui-button min-h-12 border-transparent px-5 text-base text-white" :class="tone === 'danger' ? 'bg-rose-700 hover:bg-rose-800' : 'ui-button-primary'" @click="emit('confirm')">{{ confirmLabel }}</button>
        </div>
    </section>
</template>
