import { askConfirm } from '@/utils/confirmDialog';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { registerUnsavedCaseWork } from '@/Security/browserHistory';
import type { MdtCaseData, MdtDiscussion, MdtNote } from '@/types/mdt';

export function useMdtWorkspace(caseId: number, initialAssignment: number) {
    const data = ref<MdtCaseData | null>(null), thread = ref<MdtDiscussion[]>([]);
    const assignment = ref(initialAssignment), loading = ref(false), saving = ref(false), denied = ref(false), fresh = ref(false);
    const error = ref(''), notice = ref(''), uncertain = ref(false), reviewed = ref(false);
    const errors = ref<Record<string, string>>({}), errorBox = ref<HTMLElement | null>(null);
    const message = ref(''), file = ref<File | null>(null), fileKey = ref(0);
    const note = reactive({ category: '', findings: '', recommendation: '' });
    const attachment = reactive({ purpose_category: 'supporting_record', mdt_action_id: '', mdt_note_id: '' });
    const addendumFor = ref<MdtNote | null>(null), addendumReason = ref('');
    const dirty = computed(() => Boolean(message.value || file.value || note.category || note.findings || note.recommendation || addendumReason.value));
    const busy = computed(() => loading.value || saving.value);
    const locked = computed(() => busy.value || denied.value || uncertain.value || !fresh.value);
    const params = () => ({ mdt_access_id: assignment.value });
    function clearNote() { Object.assign(note, { category: '', findings: '', recommendation: '' }); addendumFor.value = null; addendumReason.value = ''; }
    function clearEntries() { clearNote(); message.value = ''; file.value = null; fileKey.value++; }
    function accessDenied(cause: unknown) {
        if (!axios.isAxiosError(cause) || ![401, 403, 404, 419].includes(cause.response?.status ?? 0)) return false;
        data.value = null; thread.value = []; clearEntries(); denied.value = true;
        error.value = 'This assignment is unavailable or your access has ended. Return to My referred cases.';
        return true;
    }
    async function showError(text: string) { error.value = text; await nextTick(); errorBox.value?.focus(); }
    async function read() {
        const [record, discussion] = await Promise.all([
            axios.get<MdtCaseData>(route('cases.mdt.show', caseId), { params: params() }),
            axios.get<MdtDiscussion[]>(route('cases.mdt.discussion.index', caseId), { params: params() }),
        ]);
        if (Number(record.data.grant?.id) !== assignment.value || !Array.isArray(discussion.data)) throw Error('Assignment readback mismatch');
        data.value = record.data; thread.value = discussion.data; denied.value = false; fresh.value = true;
    }
    async function load() {
        if (busy.value) return;
        loading.value = true; error.value = ''; fresh.value = false; reviewed.value = false;
        try { await read(); reviewed.value = true; }
        catch (cause) { if (!accessDenied(cause)) await showError('The assignment could not be refreshed. Check your connection and try Refresh. Unsent entries remain in this tab.'); }
        finally { loading.value = false; }
    }
    async function choose(id: number) {
        if (busy.value || id === assignment.value) return;
        if (dirty.value || uncertain.value) { await showError('Save or discard unfinished entries before changing assignments.'); return; }
        if (!data.value?.assignments.some(item => Number(item.id) === id)) return;
        assignment.value = id; data.value = null; thread.value = []; notice.value = ''; errors.value = {};
        attachment.mdt_action_id = ''; attachment.mdt_note_id = ''; await load();
        if (data.value) { const url = new URL(window.location.href); url.searchParams.set('mdt_access_id', String(id)); window.history.replaceState(window.history.state, '', url); }
    }
    async function write(method: 'post' | 'patch', url: string, body: Record<string, unknown> | FormData, success: () => void, terminal = false) {
        if (locked.value || !data.value) return;
        saving.value = true; error.value = ''; errors.value = {}; notice.value = ''; reviewed.value = false;
        if (body instanceof FormData) body.set('mdt_access_id', String(assignment.value));
        else body = { ...body, ...params() };
        let acknowledged = false;
        try {
            await axios.request({ method, url, data: body }); acknowledged = true; success(); notice.value = 'Saved.';
            if (terminal) { saving.value = false; router.visit(route('mdt.collaboration.index'), { replace: true }); return; }
            await read();
        } catch (cause) {
            if (accessDenied(cause)) return;
            if (!acknowledged && axios.isAxiosError(cause) && [422, 429].includes(cause.response?.status ?? 0)) {
                errors.value = Object.fromEntries(Object.entries(cause.response?.data?.errors ?? {}).map(([key, values]) => [key, Array.isArray(values) ? String(values[0]) : String(values)]));
                await showError(cause.response?.status === 429 ? 'Too many requests. Wait before trying again.' : 'Review the highlighted fields. Your unfinished entries have been retained.');
            } else {
                uncertain.value = true;
                await showError(acknowledged ? 'Saved, but the updated record could not be loaded. Refresh and review it before another write.' : 'The save outcome is unconfirmed. Refresh and review the notes, actions, files or discussion before any retry.');
            }
        } finally { saving.value = false; }
    }
    async function resolveUncertainty() {
        if (busy.value || !reviewed.value) return;
        if (await askConfirm({ title: 'Did the last save go through?', message: 'Check the refreshed record first. Do not submit the same entry again if it is already recorded.', confirmLabel: 'I have checked' })) { uncertain.value = false; error.value = ''; }
    }
    async function discard() {
        if (busy.value) return;
        if (await askConfirm({ title: 'Discard unfinished entries?', message: 'The unfinished note, discussion message and selected file in this tab are cleared. Saved records are not deleted.', confirmLabel: 'Discard', tone: 'danger' })) { clearEntries(); error.value = ''; }
    }
    async function respond(action: number, status: 'responded' | 'completed') {
        if (dirty.value) { void showError('Save or discard unfinished entries before responding to an action.'); return; }
        if (status === 'completed' && !(await askConfirm({ title: 'Complete this action?', message: 'This ends your access to the case for this action. Save your notes and files first.', confirmLabel: 'Complete the action' }))) return;
        return write('patch', route('cases.mdt.actions.respond', [caseId, action]), { status }, () => {}, status === 'completed');
    }
    function saveNote() {
        const target = addendumFor.value;
        return write('post', target ? route('cases.mdt.notes.addendum', [caseId, target.id]) : route('cases.mdt.notes.store', caseId), { ...note, ...(target ? { reason: addendumReason.value } : {}) }, clearNote);
    }
    async function finalize(item: MdtNote) {
        if (dirty.value) { void showError('Save or discard unfinished entries before finalizing a note.'); return; }
        if (await askConfirm({ title: 'Finalize this note?', message: 'The saved findings and recommendation cannot be changed afterwards; corrections need a linked addendum.', confirmLabel: 'Finalize' })) return write('patch', route('cases.mdt.notes.finalize', [caseId, item.id]), {}, () => {});
    }
    function startAddendum(item: MdtNote) {
        if (dirty.value || locked.value) { void showError('Save or discard unfinished entries before starting an addendum.'); return; }
        clearNote(); addendumFor.value = item; void nextTick(() => document.getElementById('mdt-note-category')?.focus());
    }
    async function cancelAddendum() {
        if (busy.value) return;
        if (!dirty.value || await askConfirm({ title: 'Discard this unfinished addendum?', message: 'The saved final note stays unchanged.', confirmLabel: 'Discard', tone: 'danger' })) clearNote();
    }
    function upload() {
        if (!file.value) return;
        const body = new FormData(); body.append('attachment', file.value); body.append('purpose_category', attachment.purpose_category);
        if (attachment.mdt_action_id) body.append('mdt_action_id', attachment.mdt_action_id);
        if (attachment.mdt_note_id) body.append('mdt_note_id', attachment.mdt_note_id);
        return write('post', route('cases.mdt.attachments.store', caseId), body, () => { file.value = null; fileKey.value++; attachment.mdt_action_id = ''; attachment.mdt_note_id = ''; });
    }
    const remove = registerUnsavedCaseWork(() => dirty.value || busy.value || uncertain.value);
    onBeforeUnmount(remove); onMounted(load);
    return { data, thread, assignment, loading, saving, busy, locked, denied, error, errorBox, errors, notice, uncertain, reviewed, dirty,
        message, file, fileKey, note, attachment, addendumFor, addendumReason, load, choose, discard, resolveUncertainty, respond, saveNote, finalize, startAddendum, clearNote, upload,
        cancelAddendum,
        post: () => write('post', route('cases.mdt.discussion.store', caseId), { body: message.value }, () => { message.value = ''; }),
        acknowledge: () => write('patch', route('cases.mdt.access.acknowledge', [caseId, assignment.value]), {}, () => {}),
        download: (id: number) => route('cases.mdt.attachments.download', [caseId, id]) + '?mdt_access_id=' + assignment.value,
    };
}
