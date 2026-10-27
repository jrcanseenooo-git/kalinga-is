<script setup lang="ts">
/** Remarks from supervisors and monitors, with replies. The case manager can reply and mark them as read. */
import { formatPh } from '@/utils/philippineTime';
import { useForm } from '@inertiajs/vue3';
import { CheckCheck, Reply } from '@lucide/vue';
import { ref } from 'vue';
import type { Remark } from '@/types/remarks';

const props = defineProps<{ remarks: Remark[]; caseId?: number; canReply?: boolean }>();

const replying = ref<number | null>(null);
const reply = useForm({ body: '' });
const send = (remark: Remark) => reply.post(route('cases.remarks.reply', [props.caseId, remark.id]), { preserveScroll: true, onSuccess: () => { reply.reset(); replying.value = null; } });
const ack = useForm({});
const acknowledge = (remark: Remark) => ack.post(route('cases.remarks.acknowledge', [props.caseId, remark.id]), { preserveScroll: true });
const tone = (kind: string) => kind === 'closure_approved' ? 'border-emerald-300 bg-emerald-50' : kind === 'closure_returned' ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white';
</script>

<template>
    <div>
        <ol v-if="remarks.length" class="space-y-3">
            <li v-for="remark in remarks" :key="remark.id" class="rounded-xl border p-3 text-sm" :class="tone(remark.kind)">
                <p class="text-xs text-slate-600"><b class="text-slate-800">{{ remark.author || 'Former user' }}</b>, {{ remark.role }} · {{ formatPh(remark.created_at, 'dateTimeShort') }}</p>
                <p class="mt-1 whitespace-pre-wrap text-slate-900">{{ remark.body }}</p>
                <ol v-if="remark.replies.length" class="mt-2 space-y-2 border-l-2 border-brand-200 pl-3">
                    <li v-for="item in remark.replies" :key="item.id"><p class="text-xs text-slate-600"><b class="text-slate-800">{{ item.author }}</b> · {{ formatPh(item.created_at, 'dateTimeShort') }}</p><p class="whitespace-pre-wrap text-slate-900">{{ item.body }}</p></li>
                </ol>
                <p v-if="!canReply" class="mt-2 text-[11px] font-semibold" :class="remark.acknowledged_at ? 'text-emerald-700' : 'text-amber-800'">{{ remark.acknowledged_at ? `Read by the case manager ${formatPh(remark.acknowledged_at, 'dateShort')}` : 'Not yet read' }}</p>
                <div v-if="canReply && caseId" class="mt-2 flex flex-wrap gap-2">
                    <button v-if="!remark.acknowledged_at" type="button" class="ui-button min-h-10 px-3 text-xs" :disabled="ack.processing" @click="acknowledge(remark)"><CheckCheck class="h-4 w-4" aria-hidden="true" />Mark as read</button>
                    <button type="button" class="ui-button min-h-10 px-3 text-xs" :aria-expanded="replying === remark.id" @click="replying = replying === remark.id ? null : remark.id"><Reply class="h-4 w-4" aria-hidden="true" />Reply</button>
                </div>
                <form v-if="replying === remark.id" class="mt-2 space-y-2" data-skip-generic-confirm="true" @submit.prevent="send(remark)">
                    <label class="sr-only" :for="`reply-${remark.id}`">Reply</label>
                    <textarea :id="`reply-${remark.id}`" v-model="reply.body" rows="2" maxlength="2000" required class="block w-full rounded-lg border-slate-300 text-sm"></textarea>
                    <button type="submit" class="ui-button-primary min-h-10 px-3 text-xs" :disabled="reply.processing || reply.body.trim().length < 2">Send reply</button>
                </form>
            </li>
        </ol>
        <p v-else class="text-sm text-slate-600">No remarks yet.</p>
    </div>
</template>
