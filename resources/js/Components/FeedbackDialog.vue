<script setup lang="ts">
/** "Send feedback": suggestions, problem reports and questions about the system, with the user's earlier feedback and replies. */
import axios from 'axios';
import { Star } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/UI/FormField.vue';
import { formatPh } from '@/utils/philippineTime';

const emit = defineEmits<{ close: [] }>();
const categories = { suggestion: 'Suggestion', problem: 'Something is not working', question: 'Question', praise: 'Something works well' } as const;
const form = ref({ category: 'suggestion', subject: '', message: '', rating: 0 });
const errors = ref<Record<string, string>>({});
const sending = ref(false);
const sent = ref(false);
const mine = ref<Array<{ id: number; subject: string; status: string; response: string | null; created_at: string }>>([]);
const statusLabel: Record<string, string> = { new: 'Sent', in_review: 'Being looked at', planned: 'Planned', resolved: 'Resolved', closed: 'Closed' };
onMounted(async () => { try { mine.value = (await axios.get(route('feedback.mine'))).data.items; } catch { mine.value = []; } });
async function send() {
  sending.value = true; errors.value = {};
  try {
    await axios.post(route('feedback.store'), { ...form.value, rating: form.value.rating || null, page: window.location.pathname });
    sent.value = true;
  } catch (error: any) {
    errors.value = Object.fromEntries(Object.entries(error?.response?.data?.errors ?? { message: ['Could not send. Check your connection and try again.'] }).map(([key, value]) => [key, (value as string[])[0]]));
  } finally { sending.value = false; }
}
</script>

<template>
  <Modal :show="true" max-width="lg" aria-label="Send feedback" @close="emit('close')">
    <section class="space-y-4 p-5">
      <h2 class="text-lg font-semibold text-slate-900">Send feedback</h2>
      <template v-if="!sent">
        <p class="text-sm text-slate-600">Tell the Kalinga team what works, what does not, or what you need. Do not write names or case details here.</p>
        <form class="space-y-3" data-skip-generic-confirm="true" @submit.prevent="send">
          <FormField id="feedback-category" label="Kind of feedback" :error="errors.category"><select id="feedback-category" v-model="form.category" class="ui-field"><option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option></select></FormField>
          <FormField id="feedback-subject" label="Subject" :error="errors.subject" required><input id="feedback-subject" v-model="form.subject" required minlength="3" maxlength="160" class="ui-field"></FormField>
          <FormField id="feedback-message" label="Details" :error="errors.message" hint="What were you doing, what happened, and what you expected." required><textarea id="feedback-message" v-model="form.message" required minlength="5" maxlength="3000" rows="5" class="ui-field"></textarea></FormField>
          <fieldset><legend class="text-sm font-medium text-slate-800">How easy is Kalinga to use? (optional)</legend><div class="mt-1 flex gap-1"><button v-for="n in 5" :key="n" type="button" class="grid h-10 w-10 place-items-center rounded-lg hover:bg-amber-50" :aria-label="`${n} of 5`" :aria-pressed="form.rating === n" @click="form.rating = form.rating === n ? 0 : n"><Star class="h-5 w-5" :class="n <= form.rating ? 'fill-amber-400 text-amber-500' : 'text-slate-400'" aria-hidden="true" /></button></div></fieldset>
          <div class="flex justify-end gap-2"><button type="button" class="ui-button" @click="emit('close')">Cancel</button><button type="submit" class="ui-button-primary" :disabled="sending">{{ sending ? 'Sending...' : 'Send' }}</button></div>
        </form>
      </template>
      <div v-else role="status" class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">Thank you. Your feedback was sent. You will get a notification when the team replies.<div class="mt-3"><button type="button" class="ui-button" @click="emit('close')">Close</button></div></div>
      <details v-if="mine.length" class="text-sm"><summary class="min-h-9 cursor-pointer font-semibold text-slate-700">My earlier feedback ({{ mine.length }})</summary>
        <ul class="mt-2 space-y-2"><li v-for="item in mine" :key="item.id" class="rounded-lg border border-slate-200 p-3"><p class="font-medium text-slate-900">{{ item.subject }}</p><p class="text-xs text-slate-600">{{ formatPh(item.created_at, 'dateShort') }} - {{ statusLabel[item.status] ?? item.status }}</p><p v-if="item.response" class="mt-1 rounded bg-brand-50 p-2 text-xs text-brand-950">Reply: {{ item.response }}</p></li></ul>
      </details>
    </section>
  </Modal>
</template>
