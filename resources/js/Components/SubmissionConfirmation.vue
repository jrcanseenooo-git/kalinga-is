<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import ConfirmPanel from '@/Components/UI/ConfirmPanel.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Confirmation before any form is recorded (except forms inside a dialog, the
 * sign-in form, the intake wizard and forms marked data-skip-generic-confirm).
 *
 * The wording comes from the form itself:
 *   data-confirm-title   heading (default: the submit button text + "?")
 *   data-confirm-message explanation
 *   data-confirm-action  confirm button text (default: the submit button text)
 *   data-confirm-tone="danger" for actions that remove, revoke or close something
 */
const pendingForm = ref<HTMLFormElement | null>(null);
let approvedPending: HTMLFormElement | null = null;
const approvedForms = new WeakSet<HTMLFormElement>();

const DANGER = /\b(delete|remove|revoke|withdraw|archive|close|cancel|decline|reject|deactivate|disable|reset)\b/i;

const copy = computed(() => {
    const form = pendingForm.value;
    if (!form) return { title: '', message: '', action: '', danger: false };
    const button = form.querySelector<HTMLButtonElement | HTMLInputElement>('button[type="submit"], button:not([type]), input[type="submit"]');
    const label = (button instanceof HTMLInputElement ? button.value : button?.textContent ?? '').replace(/\s+/g, ' ').trim();
    const action = form.dataset.confirmAction || label || 'Save';
    const danger = form.dataset.confirmTone === 'danger' || (!form.dataset.confirmTone && DANGER.test(action));
    return {
        title: form.dataset.confirmTitle || `${action.replace(/[.?!]+$/, '')}?`,
        message: form.dataset.confirmMessage || (danger
            ? 'This change is recorded in the audit trail and may not be reversible. Check the details before you continue.'
            : 'Check the details you entered. Once recorded, this is saved to the record and the audit trail.'),
        action,
        danger,
    };
});

function interceptSubmission(event: SubmitEvent): void {
    const form = event.target;
    const isLoginForm = form instanceof HTMLFormElement
        && Boolean(form.querySelector('input[autocomplete="username"]'))
        && Boolean(form.querySelector('input[autocomplete="current-password"]'))
        && Boolean(form.querySelector('input[name="remember"]'));
    if (!(form instanceof HTMLFormElement) || isLoginForm || form.closest('[role="dialog"], dialog') || form.dataset.skipGenericConfirm === 'true' || form.classList.contains('intake-card')) return;
    if (approvedForms.has(form)) {
        approvedForms.delete(form);
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    pendingForm.value = form;
}

function cancel(): void { approvedPending = null; pendingForm.value = null; }
function continueSubmission(): void {
    approvedPending = pendingForm.value;
    pendingForm.value = null;
}
function submitAfterClose(): void {
    const form = approvedPending;
    approvedPending = null;
    // The native dialog must release focus and inertness before a fast server
    // validation response can focus its error summary.
    if (!form?.isConnected) return;
    approvedForms.add(form);
    form.requestSubmit();
}

onMounted(() => document.addEventListener('submit', interceptSubmission, true));
onBeforeUnmount(() => document.removeEventListener('submit', interceptSubmission, true));
</script>

<template>
    <Modal :show="Boolean(pendingForm)" aria-labelledby="generic-submit-title" aria-describedby="generic-submit-message" max-width="md" @close="cancel" @closed="submitAfterClose">
        <ConfirmPanel id-prefix="generic-submit" confirm-id="generic-submit-confirm" :title="copy.title" :message="copy.message" :confirm-label="copy.action" cancel-label="Go back"
            :tone="copy.danger ? 'danger' : 'success'" @confirm="continueSubmission" @cancel="cancel" />
    </Modal>
</template>
