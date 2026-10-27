<script setup lang="ts">
/** Shows the confirmations asked with askConfirm() (one per page layout). */
import Modal from '@/Components/Modal.vue';
import ConfirmPanel from '@/Components/UI/ConfirmPanel.vue';
import { confirmState, settleConfirm } from '@/utils/confirmDialog';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const shown = ref(confirmState.request);
let answer = false;
watch(() => confirmState.request, value => { if (value) { shown.value = value; answer = false; } });
onMounted(() => { confirmState.hosts++; });
onBeforeUnmount(() => { confirmState.hosts--; if (confirmState.request) settleConfirm(false); });
const finish = (value: boolean) => { answer = value; confirmState.request = null; };
// Resolve only after the dialog has closed, so focus is back on the page first.
const closed = () => settleConfirm(answer);
</script>

<template>
    <Modal :show="Boolean(confirmState.request)" aria-labelledby="confirm-dialog-title" :aria-describedby="shown?.message ? 'confirm-dialog-message' : undefined" max-width="md" @close="finish(false)" @closed="closed">
        <ConfirmPanel v-if="shown" id-prefix="confirm-dialog" confirm-id="confirm-dialog-accept" :title="shown.title" :message="shown.message" :confirm-label="shown.confirmLabel ?? 'Continue'"
            :cancel-label="shown.cancelLabel ?? 'Go back'" :tone="shown.tone ?? 'default'" @confirm="finish(true)" @cancel="finish(false)" />
    </Modal>
</template>
