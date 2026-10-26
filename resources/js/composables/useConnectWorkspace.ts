import axios from 'axios';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import { registerUnsavedCaseWork, reloadAfterAccessDenial } from '@/Security/browserHistory';
import type { PageProps } from '@/types';
import type { CoordinationDraft, CoordinationMessage, CoordinationPage, CoordinationProps } from '@/types/connect';

const emptyDraft = ():CoordinationDraft => ({body:'',parent_id:null,case_id:'',is_urgent:false,follow_up:false,mention_user_ids:[]});
const hasWork = (draft:CoordinationDraft) => draft.body.length>0 || Boolean(draft.parent_id || draft.case_id || draft.is_urgent || draft.follow_up || draft.mention_user_ids.length);

export function useConnectWorkspace(props:CoordinationProps) {
    const page=usePage<PageProps>();
    const permissions=computed(()=>new Set(page.props.auth.permissions));
    const activeCircle=ref<number|null>(props.circles[0]?.id??null), items=ref([...props.messages]), circleMembers=ref([...props.members]);
    const activeRecipient=ref<number|null>(null);
    const draftKey=()=>`${activeCircle.value}:${activeRecipient.value??'group'}`;
    const endpoint=(action:'index'|'store'|'search')=>activeRecipient.value
        ? route(action==='search'?'cpu.direct.search':`cpu.direct.messages.${action}`,[activeCircle.value,activeRecipient.value])
        : route(action==='search'?'cpu.coordination.search':`cpu.coordination.messages.${action}`,activeCircle.value!);
    const pagination=ref(props.pagination??{before:null,has_more:false}), unread=ref(props.unread_before_opening??0);
    const loading=ref(false), sending=ref(false), capturing=ref(false), refreshRequired=ref(false), uncertainSend=ref(false), uncertainCapture=ref(false);
    const searchTerm=ref(''),appliedSearch=ref(''),requestError=ref(''),notice=ref(''),lastReadAt=ref<string|null>(null);
    const errorSummary=ref<HTMLElement|null>(null),captureErrorSummary=ref<HTMLElement|null>(null);
    const drafts=reactive<Record<string,CoordinationDraft>>({});
    const composer=useForm<CoordinationDraft>(emptyDraft());
    const capture=useForm({case_id:'',record_type:page.props.auth.user.role==='cpu_member'?'mdt_note':'progress_note',professional_summary:'',confirmed:false});
    const selectedMessage=ref<CoordinationMessage|null>(null);
    const busy=computed(()=>loading.value||sending.value||capturing.value);
    const canPost=computed(()=>Boolean(activeCircle.value)&&permissions.value.has('cpu.chat.post'));
    const canCapture=computed(()=>!activeRecipient.value&&permissions.value.has('cpu.chat.capture'));
    const directContacts=computed(()=>permissions.value.has('cpu.chat.post')?circleMembers.value.filter(member=>member.id!==page.props.auth.user.id&&['social_worker','cpu_member'].includes(member.role)):[]);
    const currentRecipient=computed(()=>circleMembers.value.find(member=>member.id===activeRecipient.value));
    const currentCircle=computed(()=>props.circles.find(circle=>circle.id===activeCircle.value));
    const dirty=computed(()=>hasWork(composer.data())||Object.values(drafts).some(hasWork)||capture.professional_summary.length>0);
    let sequence=0;
    function reauthorize() {
        sequence++;items.value=[];circleMembers.value=[];selectedMessage.value=null;composer.reset();capture.reset();
        for(const key of Object.keys(drafts))delete drafts[key];
        reloadAfterAccessDenial();
    }
    function denied(cause:unknown) {
        if(axios.isAxiosError(cause)&&[401,403,404,419].includes(cause.response?.status??0)) {
            reauthorize();return true;
        }return false;
    }
    async function fail(text:string) {requestError.value=text;await nextTick();(selectedMessage.value?captureErrorSummary.value:errorSummary.value)?.focus();}
    function fields(cause:unknown):Record<string,string> {
        if(!axios.isAxiosError(cause)||cause.response?.status!==422)return {};
        return Object.fromEntries(Object.entries(cause.response.data?.errors??{}).flatMap(([key,value])=>Array.isArray(value)&&typeof value[0]==='string'?[[key,value[0]]]:[]));
    }
    async function fetchCircle() {
        const id=activeCircle.value;if(!id)return;
        const {data}=await axios.get<CoordinationPage>(endpoint('index'));
        if(!Array.isArray(data.messages)||!Array.isArray(data.members)||!data.pagination)throw Error('Conversation unavailable');
        items.value=data.messages;circleMembers.value=data.members;pagination.value=data.pagination;appliedSearch.value='';unread.value=0;lastReadAt.value=new Date().toISOString();
    }
    async function loadCircle() {
        if(busy.value||!activeCircle.value)return;
        loading.value=true;requestError.value='';
        try {await fetchCircle();refreshRequired.value=false;}
        catch(cause){if(!denied(cause)){refreshRequired.value=true;await fail('The conversation could not be refreshed. Check your connection and use Refresh conversation. Unsent text remains in this tab.');}}
        finally{loading.value=false;}
    }
    async function chooseCircle(id:number) {
        if(busy.value||uncertainSend.value||id===activeCircle.value||!props.circles.some(circle=>circle.id===id))return;
        if(activeCircle.value)drafts[draftKey()]={...composer.data(),mention_user_ids:[...composer.mention_user_ids]};
        activeCircle.value=id;activeRecipient.value=null;Object.assign(composer,emptyDraft(),drafts[draftKey()]??{});composer.clearErrors();
        selectedMessage.value=null;capture.reset();items.value=[];circleMembers.value=[];pagination.value={before:null,has_more:false};searchTerm.value='';notice.value='';
        await loadCircle();
    }
    async function chooseRecipient(id:number|null) {
        if(busy.value||uncertainSend.value||id===activeRecipient.value||!activeCircle.value)return;
        if(id&&!directContacts.value.some(member=>member.id===id))return;
        drafts[draftKey()]={...composer.data(),mention_user_ids:[...composer.mention_user_ids]};
        activeRecipient.value=id;Object.assign(composer,emptyDraft(),drafts[draftKey()]??{});composer.clearErrors();
        selectedMessage.value=null;capture.reset();items.value=[];pagination.value={before:null,has_more:false};searchTerm.value='';appliedSearch.value='';notice.value='';
        await loadCircle();
    }
    async function post() {
        if(!canPost.value||!activeCircle.value||busy.value||refreshRequired.value||uncertainSend.value)return;
        sending.value=true;composer.clearErrors();requestError.value='';notice.value='';let acknowledged=false;
        try {
            const payload=activeRecipient.value
                ? {body:composer.body,parent_id:composer.parent_id,is_urgent:composer.is_urgent,follow_up:composer.follow_up}
                : {...composer.data(),mention_user_ids:[...composer.mention_user_ids]};
            const {data}=await axios.post<{id:number}>(endpoint('store'),payload);
            if(!Number.isInteger(data.id))throw Error('Acknowledgement unavailable');
            acknowledged=true;composer.reset();delete drafts[draftKey()];notice.value='Message sent.';
            await fetchCircle();refreshRequired.value=false;
        }catch(cause){
            if(denied(cause))return;
            if(!acknowledged&&axios.isAxiosError(cause)&&[422,429].includes(cause.response?.status??0)){composer.setError(fields(cause));await fail(cause.response?.status===429?'Too many requests. Wait before trying again.':'Review the highlighted message fields. Unsent text remains in this tab.');}
            else{refreshRequired.value=true;uncertainSend.value=!acknowledged;await fail(acknowledged?'Message sent, but readback failed. Refresh conversation before another send; do not submit it again.':'The send outcome could not be confirmed. Check your connection, refresh the conversation and review it before any retry.');}
        }finally{sending.value=false;}
    }
    async function resumeSend() {
        if(busy.value||refreshRequired.value||!uncertainSend.value||!window.confirm('Have you reviewed the refreshed conversation and confirmed this message still needs sending? A prior send may have succeeded.'))return;
        uncertainSend.value=false;requestError.value='';
    }
    function discardMessage() {
        if(busy.value||!window.confirm('Discard this unfinished message and its reply, case tag and mention selections?'))return;
        composer.reset();delete drafts[draftKey()];uncertainSend.value=false;requestError.value='';
    }
    async function search() {
        if(!activeCircle.value||busy.value||refreshRequired.value)return;
        if(!searchTerm.value.trim()){await loadCircle();return;}
        const token=++sequence;loading.value=true;requestError.value='';
        try{const term=searchTerm.value.trim();const {data}=await axios.post<CoordinationPage>(endpoint('search'),{q:term});if(token!==sequence)return;items.value=data.messages;pagination.value=data.pagination;appliedSearch.value=term;}
        catch(cause){if(!denied(cause))await fail('Search could not be completed. Check the search text and connection, then try again.');}
        finally{if(token===sequence)loading.value=false;}
    }
    async function loadEarlier() {
        if(!activeCircle.value||!pagination.value.has_more||busy.value||refreshRequired.value)return;
        loading.value=true;requestError.value='';
        try{
            const {data}=appliedSearch.value?await axios.post<CoordinationPage>(endpoint('search'),{q:appliedSearch.value,before:pagination.value.before}):await axios.get<CoordinationPage>(endpoint('index'),{params:{before:pagination.value.before}});
            const ids=new Set(items.value.map(message=>message.id));items.value=[...data.messages.filter(message=>!ids.has(message.id)),...items.value];pagination.value=data.pagination;
        }catch(cause){if(!denied(cause))await fail('Earlier messages could not be loaded. Check your connection and try again.');}finally{loading.value=false;}
    }
    function reply(message:CoordinationMessage) {if(busy.value||refreshRequired.value||uncertainSend.value)return;composer.parent_id=message.id;composer.case_id=message.case_id?String(message.case_id):'';void nextTick(()=>document.getElementById('coordination-body')?.focus());}
    function captureMessage(message:CoordinationMessage) {if(busy.value||uncertainCapture.value)return;selectedMessage.value=message;capture.reset();capture.case_id=message.case_id?String(message.case_id):'';requestError.value='';}
    function closeCapture() {
        if(capturing.value)return;
        if(capture.professional_summary.length>0&&!window.confirm(uncertainCapture.value?'The capture may have succeeded. Close this editor and check the formal case record before another capture?':'Discard this unfinished professional summary?'))return;
        selectedMessage.value=null;capture.reset();
    }
    async function saveCapture() {
        if(!activeCircle.value||!selectedMessage.value||busy.value||!canCapture.value||uncertainCapture.value)return;
        capturing.value=true;capture.clearErrors();requestError.value='';
        try{
            const {data}=await axios.post<{id:number}>(route('cpu.coordination.messages.capture',[activeCircle.value,selectedMessage.value.id]),capture.data());
            if(!Number.isInteger(data.id))throw Error('Capture acknowledgement unavailable');
            selectedMessage.value=null;capture.reset();notice.value='Reviewed summary recorded. The original chat remains separate.';
        }catch(cause){if(denied(cause))return;if(axios.isAxiosError(cause)&&[422,429].includes(cause.response?.status??0)){capture.setError(fields(cause));await fail('Review the capture fields and current case eligibility. Your professional summary remains in this dialog.');}else{uncertainCapture.value=true;await fail('The capture outcome could not be confirmed. Close this dialog and review the formal case record before another capture. No automatic retry will be made.');}}
        finally{capturing.value=false;}
    }
    const remove=registerUnsavedCaseWork(()=>busy.value||dirty.value);onBeforeUnmount(remove);
    return {permissions,activeCircle,currentCircle,activeRecipient,currentRecipient,directContacts,chooseRecipient,items,circleMembers,pagination,unread,loading,sending,capturing,busy,refreshRequired,uncertainSend,uncertainCapture,searchTerm,appliedSearch,requestError,notice,lastReadAt,errorSummary,captureErrorSummary,composer,capture,selectedMessage,canPost,canCapture,dirty,loadCircle,chooseCircle,post,resumeSend,discardMessage,search,loadEarlier,reply,captureMessage,closeCapture,saveCapture,reauthorize};
}
