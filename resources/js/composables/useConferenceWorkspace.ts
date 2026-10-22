import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { registerUnsavedCaseWork, reloadAfterAccessDenial } from '@/Security/browserHistory';
import type { PlanningData } from '@/types/planning';
import type { ConferenceDetail, ConferenceIndex, ConferenceOperation } from '@/types/conferences';

export function useConferenceWorkspace(caseId: number) {
    const index = ref<ConferenceIndex>({conferences:[],sources:{},riskReferences:[],participationReferences:[]});
    const detail = ref<ConferenceDetail | null>(null);
    const selectedId = ref<number | null>(null);
    const busy = ref(false), loaded = ref(false), refreshRequired = ref(false);
    const error = ref(''), message = ref(''), failedKey = ref('');
    const errors = ref<Record<string,string>>({}), errorSummary = ref<HTMLElement | null>(null);
    const forms = reactive<Record<string,PlanningData>>({}), baselines = reactive<Record<string,string>>({});
    const dirtyKeys = computed(() => Object.keys(forms).filter(key => JSON.stringify(forms[key]) !== baselines[key]));
    const disabled = computed(() => busy.value || !loaded.value || refreshRequired.value);
    function form(key:string, defaults:PlanningData):PlanningData {
        if (!forms[key]) { forms[key] = {...defaults}; baselines[key] = JSON.stringify(defaults); }
        return forms[key];
    }
    function reset(key:string, defaults:PlanningData) { forms[key] = {...defaults}; baselines[key] = JSON.stringify(defaults); }
    async function showError(text:string) { error.value = text; await nextTick(); errorSummary.value?.focus(); }
    function denied(cause:unknown):boolean {
        if (axios.isAxiosError(cause) && [403,404].includes(cause.response?.status ?? 0)) { detail.value = null; reloadAfterAccessDenial(); return true; }
        return false;
    }
    async function fetchGraph(preferred = selectedId.value) {
        const response = await axios.get<ConferenceIndex>(route('cases.conferences.index',caseId));
        if (!Array.isArray(response.data.conferences) || !response.data.sources) throw Error('Conference list unavailable');
        const id = response.data.conferences.some(item => item.id === preferred) ? preferred : response.data.conferences[0]?.id ?? null;
        const record = id ? (await axios.get<ConferenceDetail>(route('cases.conferences.show',[caseId,id]))).data : null;
        if (record && (record.conference?.id !== id || !record.allowed_actions)) throw Error('Conference unavailable');
        index.value = response.data; detail.value = record; selectedId.value = id;
        loaded.value = true; refreshRequired.value = false;
    }
    async function load(id = selectedId.value) {
        if (busy.value) return;
        busy.value = true; error.value = ''; errors.value = {};
        if (id !== selectedId.value) detail.value = null;
        try { await fetchGraph(id); }
        catch (cause) { if (!denied(cause)) { refreshRequired.value = true; await showError('Conference records could not be refreshed. Check your connection and use Refresh. Unsubmitted entries remain on this page.'); } }
        finally { busy.value = false; }
    }
    async function save(operation:ConferenceOperation):Promise<boolean> {
        if (disabled.value) return false;
        busy.value = true; error.value = ''; message.value = ''; errors.value = {}; failedKey.value = operation.key ?? '';
        let acknowledged = false;
        try {
            const response = await axios.request<{id?:number}>({url:operation.url,method:operation.method,data:{...operation.body}});
            acknowledged = true;
            if (operation.key && operation.defaults) reset(operation.key,operation.defaults);
            if (operation.selectCreated && Number.isInteger(response.data?.id)) selectedId.value = response.data.id!;
            message.value = operation.success;
            await fetchGraph(); return true;
        } catch (cause) {
            if (denied(cause)) return false;
            if (!acknowledged && axios.isAxiosError(cause) && cause.response?.status === 422) {
                const fields:unknown = cause.response.data?.errors;
                if (fields && typeof fields === 'object') for (const [key,value] of Object.entries(fields)) if (Array.isArray(value) && typeof value[0] === 'string') errors.value[key] = value[0];
                await showError(Object.keys(errors.value).length ? 'Review the fields below. Your entries remain on this page.' : typeof cause.response.data?.message === 'string' ? cause.response.data.message : 'This change is unavailable in the current workflow. Refresh and review the record.');
            } else {
                refreshRequired.value = true;
                await showError(acknowledged ? 'The change was saved, but records could not be refreshed. Use Refresh before another change; do not submit it again.' : 'The outcome could not be confirmed. Use Refresh and review the records before attempting another change.');
            }
            return false;
        } finally { busy.value = false; }
    }
    const remove = registerUnsavedCaseWork(() => busy.value || dirtyKeys.value.length > 0);
    onBeforeUnmount(remove); onMounted(() => load());
    return {index,detail,selectedId,busy,loaded,refreshRequired,disabled,error,message,errors,errorSummary,failedKey,forms,dirtyKeys,form,reset,load,save,showError};
}
