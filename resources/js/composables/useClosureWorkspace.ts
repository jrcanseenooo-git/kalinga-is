import { askConfirm } from '@/utils/confirmDialog';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { registerUnsavedCaseWork, reloadAfterAccessDenial } from '@/Security/browserHistory';
import type { ClosureRecord, ClosureWorkspace } from '@/types/closure';

export function useClosureWorkspace(caseId: number) {
    const data = ref<ClosureWorkspace | null>(null), selected = ref<ClosureRecord | null>(null);
    const summary = ref(''), baseline = ref(''), expectedVersion = ref(0);
    const busy = ref(false), refreshRequired = ref(false), reconciliationRequired = ref(false);
    const error = ref(''), fieldError = ref(''), message = ref(''), errorSummary = ref<HTMLElement | null>(null);
    const dirty = computed(() => summary.value !== baseline.value);
    const disabled = computed(() => busy.value || !data.value || refreshRequired.value || reconciliationRequired.value);
    async function fail(text: string) { error.value = text; await nextTick(); errorSummary.value?.focus(); }
    function denied(cause: unknown) {
        if (axios.isAxiosError(cause) && [401,403,404,419].includes(cause.response?.status ?? 0)) {
            data.value = null; selected.value = null; summary.value = ''; baseline.value = ''; reloadAfterAccessDenial(); return true;
        }
        return false;
    }
    function adopt() {
        summary.value = data.value?.surviving_projection?.summary ?? data.value?.current?.summary ?? '';
        baseline.value = summary.value; expectedVersion.value = Number(data.value?.case.offline_version ?? 0);
        reconciliationRequired.value = false;
    }
    async function fetchData(page = 1) {
        const response = await axios.get<ClosureWorkspace>(route('cases.closure-records.index', caseId), {params:{page}});
        if (response.data.case?.id !== caseId || !response.data.history || !response.data.allowed_actions) throw Error('Closure readback unavailable');
        data.value = response.data; selected.value = null;
    }
    async function load(page = 1) {
        if (busy.value) return;
        busy.value = true; error.value = ''; fieldError.value = '';
        try {
            await fetchData(page);
            if (!dirty.value) adopt();
            else if (refreshRequired.value || expectedVersion.value !== Number(data.value?.case.offline_version)) reconciliationRequired.value = true;
            refreshRequired.value = false;
        } catch (cause) { if (!denied(cause)) { refreshRequired.value = true; await fail('Records could not be refreshed. Check your connection and use Refresh. Unfinished entries remain on this page.'); } }
        finally { busy.value = false; }
    }
    async function discard() {
        if (busy.value) return;
        if (!(await askConfirm({ title: 'Discard the unfinished revision?', message: 'The refreshed saved record is used instead.', confirmLabel: 'Discard', tone: 'danger' }))) return;
        if (refreshRequired.value) { await load(); if (refreshRequired.value) return; }
        adopt(); error.value = ''; fieldError.value = '';
    }
    async function view(id: number) {
        if (busy.value) return;
        busy.value = true; error.value = ''; selected.value = null;
        try {
            const response = await axios.get<{record:ClosureRecord}>(route('cases.closure-records.show', [caseId,id]));
            if (response.data.record?.id !== id) throw Error('Closure history unavailable');
            selected.value = response.data.record;
        } catch (cause) { if (!denied(cause)) await fail('This historical record could not be loaded. Check your connection and try again.'); }
        finally { busy.value = false; }
    }
    async function write(action: 'record' | 'review' | 'close' | 'reopen') {
        if (disabled.value || !data.value?.allowed_actions[action] || (action !== 'record' && dirty.value)) return;
        if (action !== 'record' && !(await askConfirm({ review: { title: 'Review the saved closure record?', message: 'Your independent review is recorded.', confirmLabel: 'Record my review' }, close: { title: 'Close this case?', message: 'The case is closed using the current reviewed record and the readiness checks.', confirmLabel: 'Close the case', tone: 'danger' as const }, reopen: { title: 'Reopen this case?', message: 'A new draft planning cycle starts. The former closure stays in the history.', confirmLabel: 'Reopen the case' } }[action]))) return;
        busy.value = true; error.value = ''; fieldError.value = ''; message.value = '';
        let acknowledged = false;
        try {
            const url = action === 'record' ? route('cases.closure.store',caseId) : action === 'review' ? route('cases.closure.review',caseId) : route('cases.transition',caseId);
            const body = {expected_version:expectedVersion.value,...(action === 'record' ? {closure_summary:summary.value} : action === 'close' || action === 'reopen' ? {status:action === 'close' ? 'CLOSED':'REOPENED'} : {})};
            const response = await axios.request<{saved:boolean}>({url,method:action === 'record'?'post':'patch',data:body});
            if (response.data.saved !== true) throw Error('Acknowledgement unavailable');
            acknowledged = true; baseline.value = summary.value;
            message.value = {record:'Closure record saved. Any revision requires independent review.',review:'Independent review recorded.',close:'Case closed. The closure record remains available.',reopen:'Case reopened. A new draft planning cycle is available; the former closure remains in history.'}[action];
            await fetchData(); adopt();
        } catch (cause) {
            if (denied(cause)) return;
            if (!acknowledged && axios.isAxiosError(cause) && cause.response?.status === 422) {
                fieldError.value = cause.response.data?.errors?.closure_summary?.[0] ?? '';
                await fail(fieldError.value ? 'Review the closure summary. Your entries remain on this page.' : 'This action is unavailable under the current workflow or readiness checks. Refresh and review the saved record.');
            } else {
                refreshRequired.value = true;
                await fail(acknowledged ? 'The change was saved, but readback failed. Use Refresh before another change; do not submit it again.' : 'The outcome could not be confirmed or this case changed. Use Refresh and review the saved record before another change.');
            }
        } finally { busy.value = false; }
        return acknowledged;
    }
    const unregister = registerUnsavedCaseWork(() => dirty.value || busy.value);
    onBeforeUnmount(unregister); onMounted(() => load());
    return {data,selected,summary,busy,dirty,disabled,refreshRequired,reconciliationRequired,error,fieldError,message,errorSummary,load,discard,view,write};
}
