<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { CircleAlert, CircleCheck, X } from '@lucide/vue';

// Shows the one-time outcome message a redirect carries (success or a refused change).
// Messages are operational text only; case narratives never travel in flash data.
const page = usePage();
const flash = computed(() => (page.props.flash ?? {}) as { success?: string | null; error?: string | null });
const message = ref<{ tone: 'success' | 'error'; text: string } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

function show() {
  const error = flash.value.error; const success = flash.value.success;
  clearTimeout(timer);
  if (error) message.value = { tone: 'error', text: error };
  else if (success) { message.value = { tone: 'success', text: success }; timer = setTimeout(() => (message.value = null), 6000); }
}
watch(flash, show, { immediate: true });
const stop = router.on('start', () => { if (message.value?.tone === 'success') message.value = null; });
onBeforeUnmount(() => { clearTimeout(timer); stop(); });
</script>

<template>
  <div class="pointer-events-none fixed inset-x-4 top-20 z-[60] flex justify-end sm:left-auto sm:right-6">
    <Transition enter-from-class="translate-y-2 opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
      <div v-if="message" :aria-live="message.tone === 'error' ? 'assertive' : 'polite'" class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-white p-4 text-sm shadow-lg" :class="message.tone === 'error' ? 'border-rose-200' : 'border-emerald-200'">
        <CircleAlert v-if="message.tone === 'error'" class="mt-0.5 h-5 w-5 shrink-0 text-rose-600" aria-hidden="true" />
        <CircleCheck v-else class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
        <p class="min-w-0 flex-1 leading-6" :class="message.tone === 'error' ? 'text-rose-900' : 'text-slate-800'"><span class="sr-only">{{ message.tone === 'error' ? 'Not recorded: ' : 'Done: ' }}</span>{{ message.text }}</p>
        <button type="button" class="-m-1 grid h-8 w-8 shrink-0 place-items-center rounded-lg text-slate-600 hover:bg-slate-100" aria-label="Dismiss message" @click="message = null"><X class="h-4 w-4" aria-hidden="true" /></button>
      </div>
    </Transition>
  </div>
</template>
