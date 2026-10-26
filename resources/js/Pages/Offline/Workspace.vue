<script setup lang="ts">
import OptionSelect from '@/Components/UI/OptionSelect.vue';
import { VISIT_LOCATIONS } from '@/utils/caseOptions';
import IntakeForm from '@/Components/Intake/IntakeForm.vue';
import { DEFAULT_INTAKE_OPTIONS, INTAKE_STEPS, emptyIntake as newIntake, intakePayload, validateIntakeStep, type IntakeData, type IntakeOptions } from '@/Casework/intake';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { prepareConflictRetry, synchronizeQueue } from '@/Offline/syncQueue';
import { assertWorkspaceOwner } from '@/Offline/workspaceOwner';
import { fieldAppReady as checkFieldAppReady, prepareFieldApp } from '@/Offline/fieldApp';
import type { PageProps } from '@/types';
import type { OfflineOperation } from '@/Offline/offlineTypes';
import { useConnectivity } from '@/Offline/connectivity';
import type { IntakeDraft } from '@/Offline/intakeDraft';
import { loadIntakeDrafts, saveIntakeDraft, promoteIntakeDraft, addOperation, configured, deviceId, loadPack, loadQueue, lock, savePack, updateOperation, removeOperation, recordSyncResult, setPassphrase, unlock, clearSecureWorkspace, workspaceOwnerId } from '@/Offline/secureStore';
import { formatPh, parseStored } from '@/utils/philippineTime';

const props = defineProps<{ intakeOptions?: IntakeOptions }>();
/** Risk factor options come with the page; a device that has never loaded them uses the defaults. */
const intakeOptions = computed<IntakeOptions>(() => props.intakeOptions?.riskFactors?.length ? props.intakeOptions : DEFAULT_INTAKE_OPTIONS);
type OfflineCase = { id:number; case_number:string; status:string; priority:string; category?:string; offline_version:number; first_name:string; last_name:string; region?:string; province?:string; municipality?:string; barangay?:string };
type OfflinePack = { generated_at:string; expires_at?:string; user_id:number; cases:OfflineCase[] };
type DeviceRecord = { id:number; device_id:string; device_name:string|null; last_seen_at:string; pack_expires_at:string|null; revoked_at:string|null; created_at:string };

const passphrase = ref(''); const confirmPassphrase = ref(''); const isConfigured = ref(false); const isUnlocked = ref(false);
const page = usePage<PageProps>();
const workspaceChannel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel('kalinga-workspace-security');
const { online } = useConnectivity();
const fieldAppReady = ref(false); const pack = ref<OfflinePack | null>(null); const operations = ref<OfflineOperation[]>([]); const selectedCaseId = ref<number | null>(null); const activity = ref<'case_note'|'monitoring_visit'>('case_note'); const body = ref(''); const location = ref(''); const message = ref(''); const error = ref(''); const syncing = ref(false);

const devices = ref<DeviceRecord[]>([]);
const devicesLoading = ref(false);
const renamingDeviceId = ref<number | null>(null);
const renameValue = ref('');
const packExpiryWarning = ref('');
const revocationDetected = ref(false);
const fieldSaving = ref(false);
const preparingFieldApp = ref(false);
const preparationError = ref('');
const reviewTarget = ref<OfflineOperation | null>(null);
const reviewConfirmed = ref(false);
const reviewSaving = ref(false);
const reviewError = ref('');
const reviewCase = computed(() => pack.value?.cases.find(item => item.id === Number(reviewTarget.value?.payload.case_id)));
const conflictItems = computed(() => operations.value.filter(i => i.sync_status === 'conflict'));
const failedItems = computed(() => operations.value.filter(i => i.sync_status === 'failed'));
const activeTab = ref<'workspace'|'devices'|'conflicts'>('workspace');
const toast = ref(''); let toastTimer: ReturnType<typeof setTimeout> | null = null;
function notify(text: string) { toast.value = text; if (toastTimer) clearTimeout(toastTimer); toastTimer = setTimeout(() => { toast.value = ''; }, 8000); }
const deferredSync = ref(false);
const lastSyncedAt = ref<string | null>(null);
function readLastSync() { try { lastSyncedAt.value = localStorage.getItem('kalinga_offline_last_sync'); } catch { lastSyncedAt.value = null; } }

const emptyIntake = (): IntakeData => newIntake();
const intakeStep = ref(1);
/** The step stored with the current draft; moving to another step saves it again. */
let draftStep = 1;
const intake = ref(emptyIntake());
const savedDrafts = ref<IntakeDraft[]>([]);
const activeDraftId = ref<string | null>(null);
const draftRevision = ref(0);
const draftStatus = ref('');
const draftError = ref('');
const draftDirty = ref(false);
let suppressDraftWatch = false;
let draftSession = 0;
let draftWrites: Promise<void> = Promise.resolve();
let draftWriteBlocked = false;
let draftEdit = 0;
function replaceIntake(payload: Record<string, unknown>) {
  suppressDraftWatch = true;
  const { __step: savedStep, ...answers } = payload;
  intake.value = { ...emptyIntake(), ...answers } as IntakeData;
  // A recovered draft opens on the step where the worker stopped.
  intakeStep.value = Math.min(INTAKE_STEPS.length, Math.max(1, Number(savedStep) || 1));
  draftStep = intakeStep.value;
  suppressDraftWatch = false;
}
function clearIntakeMemory() {
  draftSession++; activeDraftId.value = null; draftRevision.value = 0;
  savedDrafts.value = []; draftDirty.value = false; draftWriteBlocked = false;
  draftStatus.value = ''; draftError.value = ''; replaceIntake({});
}
async function recoverIntakeDrafts() {
  if (!canCollectIntake.value) return;
  savedDrafts.value = await loadIntakeDrafts(page.props.auth.user.id);
  if (!activeDraftId.value && savedDrafts.value.length) selectDraft(savedDrafts.value[0]);
}
function selectDraft(draft: IntakeDraft) {
  if (draftDirty.value || fieldSaving.value) return;
  draftSession++; activeDraftId.value = draft.id; draftRevision.value = draft.revision;
  draftWriteBlocked = false; draftError.value = ''; replaceIntake(draft.payload);
  draftStatus.value = 'Recovered encrypted draft. Not yet queued.';
}
function scheduleDraftSave() {
  if (suppressDraftWatch || !isUnlocked.value || !canCollectIntake.value) return;
  // An untouched form is not worth a draft.
  if (!activeDraftId.value && !intakeHasContent.value) return;
  activeDraftId.value ??= crypto.randomUUID();
  const id = activeDraftId.value;
  const session = draftSession;
  const edit = ++draftEdit;
  const snapshot = { ...JSON.parse(JSON.stringify(intake.value)), __step: intakeStep.value };
  draftDirty.value = true; draftStatus.value = 'Saving encrypted draft...';
  draftWrites = draftWrites.then(async () => {
    if (session !== draftSession || draftWriteBlocked) return;
    try {
      await saveIntakeDraft(id, page.props.auth.user.id, draftRevision.value, snapshot);
      if (session !== draftSession) return;
      draftRevision.value++;
      if (edit === draftEdit) { draftDirty.value = false; draftStatus.value = 'Draft encrypted and saved on this device. Not yet queued.'; draftError.value = ''; }
    } catch {
      if (session !== draftSession) return;
      draftWriteBlocked = true; draftStatus.value = '';
      draftError.value = 'Draft not saved. Keep this page open. Recover storage and retry, or save a separate draft if another tab changed this draft.';
    }
  });
}
function retryDraftSave() { draftWriteBlocked = false; scheduleDraftSave(); }
async function separateDraft() {
  await draftWrites;
  activeDraftId.value = null; draftRevision.value = 0; draftWriteBlocked = false; draftSession++;
  scheduleDraftSave();
}
function protectUnsavedDraft(event: BeforeUnloadEvent) {
  if (draftDirty.value) { event.preventDefault(); event.returnValue = ''; }
}
watch(intake, scheduleDraftSave, { deep: true, flush: 'sync' });
watch(intakeStep, step => { if (step === draftStep) return; draftStep = step; if (intakeHasContent.value) scheduleDraftSave(); });

const selectedCase = computed(() => pack.value?.cases.find((item) => item.id === selectedCaseId.value));
const pendingCount = computed(() => operations.value.filter((item) => item.sync_status !== 'synced').length);
const packExpired = computed(() => !pack.value?.expires_at || (parseStored(pack.value.expires_at)?.getTime() ?? 0) <= Date.now());
const canCollectOffline = computed(() => Boolean(pack.value) && !packExpired.value && !revocationDetected.value);
/**
 * A new intake needs no case data, so it works with no signal and no current
 * case pack: only the unlocked, encrypted device workspace. Sync still needs a
 * current authorization; the pack is refreshed automatically before sending.
 */
const canCollectIntake = computed(() => isUnlocked.value && !revocationDetected.value);
watch(canCollectIntake, available => { if (!available) clearIntakeMemory(); });
const intakeHasContent = computed(() => Boolean(intake.value.first_name || intake.value.last_name || intake.value.birthdate || intake.value.privacy_notice_acknowledged));
/** True while a worker is in the middle of an intake: sync waits so nothing interrupts them. */
const intakeInProgress = computed(() => draftDirty.value || fieldSaving.value || (intakeHasContent.value && Boolean(activeDraftId.value)));
const offlineIntakes = computed(() => operations.value.filter(item => item.entity_type === 'case_intake').map(item => ({ id: item.local_id, name: `${String(item.payload.first_name ?? '')} ${String(item.payload.last_name ?? '')}`.trim() || 'Unnamed intake', place: [item.payload.barangay, item.payload.municipality].filter(Boolean).join(', '), risk: String(item.payload.initial_risk_level ?? ''), savedAt: item.created_offline_at, status: item.sync_status, error: item.error_message })).sort((a, b) => b.savedAt.localeCompare(a.savedAt)));
const offlineUpdates = computed(() => operations.value.filter(item => item.entity_type !== 'case_intake'));
const readiness = computed(() => [
  { key: 'protect', label: 'Device protected with your offline passphrase', done: isConfigured.value },
  { key: 'app', label: 'Kalinga saved on this device for no-signal use', done: fieldAppReady.value },
  { key: 'pack', label: pack.value && !packExpired.value ? `Your ${pack.value.cases.length} case(s) downloaded until ${formatPh(pack.value.expires_at ?? '', 'dateTimeShort')}` : 'Your assigned cases downloaded (needed only for case updates)', done: Boolean(pack.value) && !packExpired.value },
]);
watch(() => operations.value.filter(item => item.sync_status !== 'synced').length, count => {
  // Count only (no case content) so the sidebar can show "n waiting to sync".
  try { localStorage.setItem('kalinga_offline_pending', String(count)); } catch { /* optional */ }
  window.dispatchEvent(new Event('kalinga:offline-queue'));
});

async function readOwnedQueue(): Promise<{ pack: OfflinePack | null; queue: OfflineOperation[] }> {
  const savedPack = await loadPack<OfflinePack>();
  assertWorkspaceOwner(savedPack, page.props.auth.user.id);
  const savedQueue = await loadQueue();
  assertWorkspaceOwner(savedPack, page.props.auth.user.id, savedQueue.length > 0, workspaceOwnerId());
  return { pack: savedPack, queue: savedQueue };
}

async function refreshSecureData() {
  const owned = await readOwnedQueue();
  pack.value = owned.pack; operations.value = owned.queue;
  if (!selectedCaseId.value && pack.value?.cases.length) selectedCaseId.value = pack.value.cases[0].id;
}

async function provision() { error.value = ''; if (passphrase.value !== confirmPassphrase.value) { error.value = 'The offline passphrases do not match.'; return; } try { await setPassphrase(passphrase.value, page.props.auth.user.id); isConfigured.value = true; isUnlocked.value = true; passphrase.value = ''; confirmPassphrase.value = ''; message.value = 'Offline encryption is ready. Download your case pack while online.'; } catch (reason:any) { error.value = reason.message; } }

async function unlockWorkspace() {
  error.value = '';
  try {
    await unlock(passphrase.value);
    passphrase.value = '';
    await refreshSecureData();
    isUnlocked.value = true;
    checkPackExpiry();
    await recoverIntakeDrafts();
    if (online.value) await checkDeviceStatus();
  } catch (reason) {
    clearIntakeMemory(); lock(); isUnlocked.value = false; pack.value = null; operations.value = []; selectedCaseId.value = null;
    error.value = reason instanceof Error ? reason.message : 'Could not unlock the encrypted workspace.';
  }
}

async function verifyFieldAppCache() { fieldAppReady.value = await checkFieldAppReady(); }

async function prepareFieldWorkspace() {
  if (!online.value || preparingFieldApp.value) return;
  preparingFieldApp.value = true; preparationError.value = '';
  try {
    await prepareFieldApp(page.props);
    await verifyFieldAppCache();
    if (!fieldAppReady.value) throw new Error('Field app not ready');
  } catch {
    fieldAppReady.value = false;
    preparationError.value = 'Offline reload is not ready. Stay online and retry preparation. Your encrypted records are unchanged.';
  } finally { preparingFieldApp.value = false; }
}

async function downloadPack() { if (!online.value) return; error.value = ''; try { await readOwnedQueue(); const response = await axios.get<OfflinePack>('/offline-workspace/pack', { headers: { 'X-Kalinga-Device-ID': await deviceId() } }); assertWorkspaceOwner(response.data, page.props.auth.user.id); await savePack(response.data); await refreshSecureData(); await verifyFieldAppCache(); checkPackExpiry(); await recoverIntakeDrafts(); message.value = `${response.data.cases.length} authorized case record(s) encrypted on this device.`; } catch { error.value = 'Could not download the offline case pack. Confirm the original account, stay online and try again.'; } }

function checkPackExpiry() {
  if (!pack.value?.expires_at) { packExpiryWarning.value = ''; return; }
  const expires = parseStored(pack.value.expires_at) ?? new Date(0);
  const hoursLeft = (expires.getTime() - Date.now()) / 3600000;
  if (hoursLeft <= 0) { packExpiryWarning.value = 'Your offline case pack has expired. Download a fresh pack while online to continue field work.'; pack.value = null; selectedCaseId.value = null; }
  else if (hoursLeft <= 6) { packExpiryWarning.value = `Your offline case pack expires in ${Math.ceil(hoursLeft)} hour(s). Refresh while online.`; }
  else { packExpiryWarning.value = ''; }
}

async function checkDeviceStatus() {
  if (!online.value || !isUnlocked.value) return;
  try {
    const did = await deviceId();
    const { data } = await axios.get('/offline-workspace/device-status', { headers: { 'X-Kalinga-Device-ID': did } });
    if (data.revoked) {
      workspaceChannel?.postMessage('revoked');
      revocationDetected.value = true;
      error.value = 'This device has been revoked by an administrator. Your local encrypted data will be securely cleared.';
      lock();
      isUnlocked.value = false;
      pack.value = null; operations.value = [];
      selectedCaseId.value = null;
      body.value = ''; location.value = ''; clearIntakeMemory();
      try { await clearSecureWorkspace(); isConfigured.value = false; }
      catch { error.value = 'Device access is revoked. Close other Kalinga tabs and reload to finish clearing encrypted storage.'; }
    }
  } catch { /* offline or server error - skip silently */ }
}

async function persistFieldOperation(operation: OfflineOperation): Promise<boolean> {
  fieldSaving.value = true;
  error.value = ''; message.value = '';
  try { await addOperation(operation); return true; }
  catch {
    error.value = 'Could not save on this device. Your entered text is still here. Keep this page open, free device storage, then retry. Do not clear site data.';
    return false;
  } finally { fieldSaving.value = false; }
}

async function saveActivity() {
  if (fieldSaving.value) return;
  if (!canCollectOffline.value) { error.value = 'Refresh the authorized offline pack before recording new work.'; return; }
  if (!selectedCase.value || !body.value.trim()) return;
  const payload: Record<string, unknown> = activity.value === 'case_note'
    ? { case_id:selectedCase.value.id, body:body.value.trim() }
    : { case_id:selectedCase.value.id, visited_at:new Date().toISOString().slice(0,10), observations:body.value.trim(), location:location.value.trim() || null };
  if (!await persistFieldOperation({ local_id:crypto.randomUUID(), entity_type:activity.value, operation:'create', payload, base_version:selectedCase.value.offline_version, sync_status:'pending', retry_count:0, created_offline_at:new Date().toISOString() })) return;
  body.value = ''; location.value = '';
  await refreshSecureData();
  message.value = 'Saved securely on this device. It will sync automatically after reconnection.';
  if (!online.value) notify('Update saved securely on this device. It will be sent when you are back online.');
}

async function saveIntake() {
  if (fieldSaving.value) return;
  if (!canCollectIntake.value) { error.value = 'Unlock the offline workspace before collecting a new intake.'; return; }
  const draft = JSON.parse(JSON.stringify(intake.value)) as IntakeData;
  for (let check = 1; check <= INTAKE_STEPS.length; check++) {
    const problems = validateIntakeStep(check, draft, intakeOptions.value);
    if (Object.keys(problems).length) { intakeStep.value = check; error.value = `Step ${check} (${INTAKE_STEPS[check - 1]}): ${Object.values(problems)[0]}`; return; }
  }
  error.value = '';
  const payload = intakePayload(draft);
  fieldSaving.value = true;
  try {
    await draftWrites;
    if (draftDirty.value || !activeDraftId.value || JSON.stringify(draft) !== JSON.stringify(intake.value)) throw new Error('Draft changed or is not yet saved.');
    await promoteIntakeDraft(activeDraftId.value, page.props.auth.user.id, draftRevision.value, payload);
    clearIntakeMemory(); await refreshSecureData();
    message.value = 'CEFMU Intake Sheet encrypted and queued once. A case folder will be created after secure sync.';
    notify(online.value ? 'Intake saved on this device. Press Sync now to send it, or it will be sent on the next sync.' : 'Intake saved securely on this device. It will be sent automatically when you are back online.');
    if (online.value && deferredSync.value) void autoSync();
  } catch { error.value = 'Could not queue this intake. Your draft is retained. Keep this page open, recover storage or review changes in another tab, then retry.'; }
  finally { fieldSaving.value = false; } }

let syncSession = 0;

async function sync() {
  if (!online.value || !isUnlocked.value || syncing.value || revocationDetected.value) return;
  const session = syncSession;
  syncing.value = true; error.value = ''; message.value = '';
  try {
    const result = await synchronizeQueue({
      load: async () => (await readOwnedQueue()).queue,
      active: () => isUnlocked.value && !revocationDetected.value && session === syncSession,
      send: async item => (await axios.post('/sync', {
        offline_id:item.local_id, resource:item.entity_type, operation:item.operation,
        payload:item.payload, base_version:item.base_version,
      }, { headers: { 'X-Kalinga-Device-ID': await deviceId() } })).data,
      record: recordSyncResult,
    });
    if (!isUnlocked.value || session !== syncSession) return;
    await refreshSecureData();
    message.value = `Sync finished: ${result.synced} acknowledged, ${result.failed} retained for retry, ${result.conflicts} need review. New entries remain queued for the next sync.`;
    try { const now = new Date().toISOString(); localStorage.setItem('kalinga_offline_last_sync', now); lastSyncedAt.value = now; } catch { /* optional */ }
    const parts = [result.synced ? `${result.synced} record${result.synced === 1 ? '' : 's'} sent` : 'Nothing new to send'];
    if (result.failed) parts.push(`${result.failed} will retry`);
    if (result.conflicts) parts.push(`${result.conflicts} need${result.conflicts === 1 ? 's' : ''} your review`);
    notify(`Sync complete: ${parts.join(', ')}.${result.synced ? ' New cases now appear in Cases.' : ''}`);
    if (result.authorizationExpired) error.value = 'Authorization could not be confirmed. Encrypted work is retained; sign in and refresh your authorized pack before retrying.';
    if (result.conflicts) activeTab.value = 'conflicts';
  } catch {
    if (isUnlocked.value && session === syncSession) {
      error.value = 'Sync stopped because encrypted queue state could not be saved. Keep this page open, recover device storage or unlock again, then retry. Unacknowledged work is retained.';
      try { await refreshSecureData(); } catch { /* Preserve the current view until storage recovers. */ }
    }
  } finally { syncing.value = false; }
}

async function discardOperation(localId: string) {
  if (syncing.value || !window.confirm('Permanently discard this unsynced local item? This cannot be undone.')) return;
  try { await removeOperation(localId); await refreshSecureData(); message.value = 'Discarded the selected local item.'; }
  catch { error.value = 'Could not update encrypted storage. The item is retained; recover storage and retry.'; }
}

async function retryOperation(localId: string) {
  if (syncing.value) return;
  try {
    await updateOperation(localId, item => item.sync_status === 'failed'
      ? { ...item, sync_status: 'pending', retry_count: 0, error_message: undefined } : item);
    await refreshSecureData();
    if (online.value) await sync();
  } catch { error.value = 'Could not update encrypted storage. The item is retained; recover storage and retry.'; }
}

function openConflictReview(item: OfflineOperation) {
  if (syncing.value || !canCollectOffline.value) { error.value = 'Refresh the authorized pack before reviewing a conflict.'; return; }
  const current = pack.value?.cases.find(record => record.id === Number(item.payload.case_id));
  if (!current || current.offline_version <= (item.base_version ?? 0)) {
    error.value = 'Refresh the authorized case pack before reviewing this conflict. The case must still be included in your current pack.';
    return;
  }
  error.value = ''; reviewError.value = ''; reviewConfirmed.value = false;
  reviewTarget.value = JSON.parse(JSON.stringify(item));
}

function closeConflictReview() {
  if (reviewSaving.value) return;
  reviewTarget.value = null; reviewConfirmed.value = false; reviewError.value = '';
}

async function confirmConflictReview() {
  if (!reviewTarget.value || !reviewConfirmed.value || !reviewCase.value || reviewSaving.value || !canCollectOffline.value) return;
  reviewSaving.value = true; reviewError.value = '';
  try {
    const selected = reviewTarget.value;
    const next = prepareConflictRetry(selected, reviewCase.value.offline_version, reviewConfirmed.value, new Date().toISOString());
    let found = false;
    await updateOperation(selected.local_id, current => {
      found = true;
      if (JSON.stringify(current) !== JSON.stringify(selected)) throw new Error('The queued item changed in another tab. Reopen its review.');
      return next;
    });
    if (!found) throw new Error('The queued item was removed in another tab. Refresh the queue.');
    reviewTarget.value = null; reviewConfirmed.value = false;
    await refreshSecureData();
    if (online.value) await sync();
  } catch (reason) {
    reviewError.value = reason instanceof Error ? reason.message : 'The item remains encrypted. Recover storage and retry the review.';
  } finally { reviewSaving.value = false; }
}

watch(() => reviewCase.value?.offline_version, () => { reviewConfirmed.value = false; });
watch([canCollectOffline, reviewCase, isUnlocked], () => {
  if (!canCollectOffline.value || !reviewCase.value || !isUnlocked.value) {
    reviewTarget.value = null; reviewConfirmed.value = false; reviewError.value = '';
  }
});
function canViewQueuedPayload(item: OfflineOperation): boolean {
  if (!isUnlocked.value || !canCollectOffline.value) return false;
  if (item.entity_type === 'case_note' || item.entity_type === 'monitoring_visit') {
    return Boolean(pack.value?.cases.some(record => record.id === Number(item.payload.case_id)));
  }
  return true;
}
async function loadDevices() {
  if (!online.value) return;
  devicesLoading.value = true;
  try {
    const { data } = await axios.get('/offline-workspace/devices');
    devices.value = data.devices;
  } catch { /* silently fail */ }
  finally { devicesLoading.value = false; }
}

async function revokeDevice(id: number) {
  try {
    await axios.post(`/offline-workspace/devices/${id}/revoke`);
    message.value = 'Device revoked.';
    await loadDevices();
  } catch { error.value = 'Could not revoke device.'; }
}

async function renameDevice(id: number) {
  if (!renameValue.value.trim()) return;
  try {
    await axios.patch(`/offline-workspace/devices/${id}/rename`, { device_name: renameValue.value.trim() });
    renamingDeviceId.value = null; renameValue.value = '';
    await loadDevices();
  } catch { error.value = 'Could not rename device.'; }
}

function lockWorkspace() { if (draftDirty.value && !window.confirm('The latest intake edits are not saved. Lock and retain only the last encrypted saved version?')) return; workspaceChannel?.postMessage('locked'); clearIntakeMemory(); syncSession++; lock(); isUnlocked.value = false; pack.value = null; operations.value = []; selectedCaseId.value = null; body.value = ''; location.value = ''; message.value = 'Offline workspace locked.'; }

if (workspaceChannel) workspaceChannel.onmessage = event => {
  if (event.data !== 'locked' && event.data !== 'revoked') return;
  clearIntakeMemory(); syncSession++; lock(); isUnlocked.value = false;
  pack.value = null; operations.value = []; selectedCaseId.value = null; body.value = ''; location.value = '';
  reviewTarget.value = null; message.value = 'Workspace locked in another tab. Unlock again only if authorized.';
  if (event.data === 'revoked') { revocationDetected.value = true; isConfigured.value = false; }
};

let expiryInterval: ReturnType<typeof setInterval> | null = null;
let statusInterval: ReturnType<typeof setInterval> | null = null;

watch(activeTab, (tab) => { if (tab === 'devices' && online.value) loadDevices(); });

onMounted(async () => {
  readLastSync();
  window.addEventListener('beforeunload', protectUnsavedDraft);
  isConfigured.value = await configured();
  await verifyFieldAppCache();
  if (online.value) void prepareFieldWorkspace();
  navigator.serviceWorker?.addEventListener('controllerchange', verifyFieldAppCache);
  expiryInterval = setInterval(() => { if (isUnlocked.value) checkPackExpiry(); }, 60000);
  statusInterval = setInterval(() => { if (isUnlocked.value && online.value) checkDeviceStatus(); }, 300000);
});

onUnmounted(() => { clearIntakeMemory(); syncSession++; lock();
  workspaceChannel?.close();
  window.removeEventListener('beforeunload', protectUnsavedDraft);
  navigator.serviceWorker?.removeEventListener('controllerchange', verifyFieldAppCache);
  if (expiryInterval) clearInterval(expiryInterval);
  if (statusInterval) clearInterval(statusInterval);
});

/**
 * Sends queued work when the connection returns. An intake being filled in is
 * never interrupted: sync waits until it is saved, then runs and notifies.
 * An expired case pack is refreshed first (sync needs a current authorization).
 */
async function autoSync() {
  if (!online.value || !isUnlocked.value || syncing.value || revocationDetected.value) return;
  if (intakeInProgress.value) { deferredSync.value = true; return; }
  deferredSync.value = false;
  if (!operations.value.some(item => item.sync_status === 'pending' || item.sync_status === 'failed')) return;
  if (!pack.value || packExpired.value) { await downloadPack(); if (!pack.value || packExpired.value) return; }
  await sync();
}
watch(online, (isOnline) => {
  if (isOnline) {
    verifyFieldAppCache();
    if (isUnlocked.value) {
      void checkDeviceStatus();
      if (intakeInProgress.value && pendingCount.value) { deferredSync.value = true; notify('You are back online. Finish and save this intake; sending will start after you save.'); }
      else void autoSync();
    }
  } else if (isUnlocked.value) notify('No signal. Keep working; everything you save stays encrypted on this device.');
});
watch(intakeInProgress, busy => { if (!busy && deferredSync.value) void autoSync(); });
</script>

<template>
  <Head title="Field mode" />
  <AuthenticatedLayout><section class="w-full max-w-none">
    <header class="field-hero">
      <div class="min-w-0">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100">GIDA field mode</p>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Field mode</h1>
        <p class="mt-1 max-w-2xl text-sm text-brand-50">Do intakes and case updates with or without signal. Everything you save is encrypted on this device and sent when you are back online. Nothing you type is lost.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="hero-pill" :class="online ? 'pill-on' : 'pill-off'" aria-live="polite"><span class="h-2 w-2 rounded-full" :class="online ? 'bg-emerald-400' : 'bg-amber-400'" aria-hidden="true"/>{{ online ? 'Online' : 'Offline' }}</span>
        <span aria-live="polite" class="hero-pill">{{ fieldAppReady ? 'Field app ready' : preparingFieldApp ? 'Preparing field app...' : 'Not yet saved for no-signal use' }}</span>
        <button v-if="!fieldAppReady && online" type="button" :disabled="preparingFieldApp" class="hero-pill hero-btn" @click="prepareFieldWorkspace">Prepare field app</button>
        <span v-if="lastSyncedAt" class="hero-pill">Last sync {{ formatPh(lastSyncedAt, 'dateTimeShort') }}</span>
      </div>
    </header>

    <p v-if="revocationDetected" class="mt-5 rounded-xl bg-rose-50 border border-rose-200 p-4 text-sm font-semibold text-rose-800">This device has been revoked. All local encrypted data has been securely cleared. Set up a new offline workspace if re-authorized.</p>
    <p v-if="packExpiryWarning" class="mt-5 rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm font-semibold text-amber-800">{{ packExpiryWarning }} Expired case details are locked; existing encrypted drafts remain available for recovery after reauthorization.</p>
    <p v-if="preparationError" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{{ preparationError }}</p>
    <p v-if="message" role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ message }}</p>
    <p v-if="error" role="alert" class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ error }}</p>

    <section v-if="!isConfigured || !isUnlocked" class="mx-auto mt-8 max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">{{ isConfigured ? 'Unlock offline workspace' : 'Set up this device' }}</h2><p class="mt-2 text-sm leading-6 text-slate-500">{{ isConfigured ? 'Enter the device-only offline passphrase. It is never sent to Kalinga IS and cannot be recovered by an administrator.' : 'Create a device-specific passphrase before collecting offline field data.' }}</p><label class="mt-5 block text-sm font-bold">Offline passphrase<input v-model="passphrase" type="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border-slate-300" /></label><label v-if="!isConfigured" class="mt-4 block text-sm font-bold">Confirm passphrase<input v-model="confirmPassphrase" type="password" autocomplete="new-password" class="mt-2 w-full rounded-xl border-slate-300" /></label><button type="button" class="mt-6 w-full rounded-xl bg-brand-700 px-4 py-3 font-bold text-white disabled:opacity-50" :disabled="!online && !isConfigured" @click="isConfigured ? unlockWorkspace() : provision()">{{ isConfigured ? 'Unlock workspace' : 'Set up encrypted workspace' }}</button></section>

    <template v-else>
      <div v-if="toast" aria-live="polite" class="field-toast">{{ toast }}</div>
      <p v-if="deferredSync" class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">You are back online. Syncing waits until you save the intake you are working on, so nothing is interrupted.</p>
      <nav class="field-nav" aria-label="Field workspace sections">
        <button v-for="tab in (['workspace','devices','conflicts'] as const)" :key="tab" type="button" :aria-current="activeTab === tab ? 'page' : undefined" :class="activeTab === tab ? 'active' : ''" @click="activeTab = tab">{{ tab === 'workspace' ? 'Field workspace' : tab === 'devices' ? 'My devices' : `Conflicts (${conflictItems.length})` }}</button>
      </nav>

      <!-- WORKSPACE TAB -->
      <template v-if="activeTab === 'workspace'">
        <section class="mt-5 grid gap-4 lg:grid-cols-[1.15fr_1fr]">
          <article class="ui-panel p-5">
            <h2 class="ui-section-title">Ready for no-signal areas?</h2>
            <p class="mt-1 text-sm text-slate-600">Do these while you still have signal. New intakes need only the first two.</p>
            <ol class="mt-4 space-y-3">
              <li v-for="(step, index) in readiness" :key="step.key" class="flex items-start gap-3">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold" :class="step.done ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600'" aria-hidden="true">{{ step.done ? 'OK' : index + 1 }}</span>
                <p class="min-w-0 flex-1 text-sm" :class="step.done ? 'text-slate-700' : 'font-semibold text-slate-900'">{{ step.label }}<span class="sr-only">{{ step.done ? ' - done' : ' - to do' }}</span></p>
              </li>
            </ol>
            <nav class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3" aria-label="Jump to"><a href="#field-intake" class="jump">New intake</a><a href="#field-saved" class="jump">Saved in field mode ({{ offlineIntakes.length }})</a><a href="#field-cases" class="jump">Update a case</a></nav>
          </article>
          <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
            <article class="field-stat"><p class="text-xs text-slate-600">My cases on this device</p><p class="text-2xl font-bold text-slate-900">{{ pack?.cases.length ?? 0 }}</p><p v-if="pack?.expires_at" class="text-xs text-slate-500">Until {{ formatPh(pack.expires_at, 'dateTimeShort') }}</p><button type="button" class="ui-button-primary mt-2 min-h-10 text-sm" :disabled="!online" @click="downloadPack">{{ pack ? 'Refresh case pack' : 'Download case pack' }}</button></article>
            <article class="field-stat"><p class="text-xs text-slate-600">Waiting to sync</p><p class="text-2xl font-bold" :class="pendingCount ? 'text-amber-700' : 'text-emerald-700'">{{ pendingCount }}</p><p v-if="failedItems.length" class="text-xs font-semibold text-amber-700">{{ failedItems.length }} failed - will retry</p><p v-else class="text-xs text-slate-500">{{ online ? 'Syncs automatically when signal returns.' : 'Will sync when signal returns.' }}</p><button type="button" class="ui-button mt-2 min-h-10 text-sm" :disabled="!online || !pendingCount || syncing" @click="sync">{{ syncing ? 'Syncing...' : 'Sync now' }}</button></article>
            <article class="field-stat"><p class="text-xs text-slate-600">Device security</p><p class="font-semibold text-emerald-700">Encrypted and unlocked</p><p class="text-xs text-slate-500">Lock when you hand over the device.</p><button type="button" class="ui-button mt-2 min-h-10 text-sm" @click="lockWorkspace">Lock workspace</button></article>
          </div>
        </section>

        <section v-if="canCollectIntake" id="field-intake" class="mt-6 scroll-mt-24 rounded-2xl border border-brand-200 bg-white p-5 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-extrabold uppercase tracking-[.12em] text-brand-700">Intake / registration</p><h2 class="mt-1 font-extrabold text-slate-900">CEFMU Safety &amp; Case Intake</h2><p class="mt-1 text-sm text-slate-500">Direct CEFMU case registration for field work. This creates a protected case folder after secure sync; it does not enter the report screening queue.</p></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800">Works with no signal</span></div>
          
          <div id="intake-draft-recovery" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
            <p aria-live="polite">{{ draftStatus || 'Unfinished intake edits are encrypted automatically on this device. Only completed intakes are queued for upload.' }}</p>
            <p v-if="draftError" role="alert" class="mt-2 text-rose-800">{{ draftError }}</p>
            <div v-if="draftError" class="mt-2 flex flex-wrap gap-2">
              <button type="button" class="min-h-11 rounded-lg border px-3 font-bold" :disabled="fieldSaving" @click="retryDraftSave">Retry encrypted draft save</button>
              <button type="button" class="min-h-11 rounded-lg border px-3 font-bold" :disabled="fieldSaving" @click="separateDraft">Save as separate draft</button>
            </div>
            <div v-if="savedDrafts.length > 1" class="mt-2 flex flex-wrap gap-2">
              <button v-for="draft in savedDrafts" :key="draft.id" type="button" class="min-h-11 rounded-lg border px-3" :disabled="draftDirty || fieldSaving || draft.id === activeDraftId" @click="selectDraft(draft)">Recover draft saved {{ formatPh(draft.updated_at, 'dateTimeShort') }}</button>
            </div>
          </div>
          <p v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">{{ error }}</p>
          <div class="mt-4">
            <IntakeForm v-model:step="intakeStep" :data="intake" :options="intakeOptions" :busy="fieldSaving" submit-label="Save intake on this device" @submit="saveIntake">
              <template #status><p class="text-center text-xs text-slate-600" aria-live="polite">{{ draftDirty ? 'Saving draft...' : intakeHasContent ? 'Draft is safe on this device.' : 'Your draft saves itself as you type.' }}</p></template>
            </IntakeForm>
          </div>
        </section>

        <section id="field-saved" class="mt-6 scroll-mt-24 ui-panel overflow-hidden" aria-labelledby="offline-intakes-title">
          <header class="ui-panel-header"><div><h2 id="offline-intakes-title" class="ui-section-title">Intakes saved in field mode</h2><p class="text-xs text-slate-600">Each becomes a case folder once it syncs. Names show only while this device is unlocked.</p></div><a v-if="online" :href="route('cases.index')" class="ui-link">Open Cases</a></header>
          <ul class="divide-y divide-slate-100">
            <li v-for="item in offlineIntakes" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
              <div class="min-w-0"><p class="font-semibold text-slate-900">{{ item.name }}</p><p class="text-xs text-slate-600">Saved {{ formatPh(item.savedAt, 'dateTimeShort') }}<span v-if="item.place"> - {{ item.place }}</span><span v-if="item.risk"> - {{ item.risk }} risk</span></p><p v-if="item.error" class="text-xs text-rose-700">{{ item.error }}</p></div>
              <span class="status-chip" :class="`st-${item.status}`">{{ item.status === 'synced' ? 'Synced - case created' : item.status === 'pending' ? 'Waiting to sync' : item.status === 'syncing' ? 'Syncing...' : item.status === 'conflict' ? 'Needs review' : 'Will retry' }}</span>
            </li>
          </ul>
          <p v-if="!offlineIntakes.length" class="p-5 text-sm text-slate-600">No intakes saved in field mode yet.</p>
        </section>
        <section v-if="pack" id="field-cases" class="mt-6 grid scroll-mt-24 gap-6 lg:grid-cols-[19rem_1fr]"><aside class="overflow-hidden rounded-2xl border bg-white"><div class="border-b p-4"><h2 class="font-bold">Downloaded case pack</h2><p class="mt-1 text-xs text-slate-500">Updated {{ formatPh(pack.generated_at, 'dateTimeShort') }}</p></div><button v-for="item in pack.cases" :key="item.id" type="button" class="block w-full border-b p-4 text-left hover:bg-brand-50" :class="selectedCaseId === item.id ? 'bg-brand-50' : ''" @click="selectedCaseId = item.id"><p class="font-bold text-slate-900">{{ item.first_name }} {{ item.last_name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.case_number }} · {{ item.status }}</p></button><p v-if="!pack.cases.length" class="p-5 text-sm text-slate-500">No authorized cases were included in this pack.</p></aside><article class="rounded-2xl border bg-white p-6"><template v-if="selectedCase"><p class="text-sm font-bold text-brand-700">{{ selectedCase.case_number }}</p><h2 class="mt-1 text-2xl font-extrabold">{{ selectedCase.first_name }} {{ selectedCase.last_name }}</h2><p class="mt-2 text-sm text-slate-500">{{ [selectedCase.barangay, selectedCase.municipality, selectedCase.province, selectedCase.region].filter(Boolean).join(', ') || 'Location not recorded' }}</p><div class="mt-6 border-t pt-6"><h3 class="font-bold">Record field update</h3><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-bold">Update type<select v-model="activity" class="mt-2 w-full rounded-xl border-slate-300"><option value="case_note">Case note</option><option value="monitoring_visit">Monitoring visit</option></select></label><label v-if="activity === 'monitoring_visit'" class="text-sm font-bold">Visit location<OptionSelect v-model="location" :options="VISIT_LOCATIONS" /></label></div><label class="mt-4 block text-sm font-bold">{{ activity === 'case_note' ? 'Case note' : 'Observations' }}<textarea v-model="body" rows="7" class="mt-2 w-full rounded-xl border-slate-300" placeholder="Record factual field observations only…" /></label><button type="button" class="mt-4 rounded-xl bg-brand-700 px-4 py-3 text-sm font-bold text-white disabled:opacity-50" :disabled="fieldSaving || !body.trim()" @click="saveActivity">Save encrypted update on this device</button></div></template><p v-else class="text-slate-500">Select a case from the encrypted offline pack.</p></article></section>
        <section v-else id="field-cases" class="mt-6 scroll-mt-24 rounded-2xl border border-dashed p-8 text-center text-sm text-slate-600">Case updates need a current authorized pack. Connect securely and download a fresh pack. New intakes still work without it, and existing encrypted drafts are retained.</section>

        <section v-if="operations.length" id="encrypted-operation-queue" aria-labelledby="operation-queue-title" :aria-busy="syncing" class="mt-6 overflow-hidden rounded-2xl border bg-white"><div class="border-b p-4"><h2 id="operation-queue-title" class="font-bold">Local encrypted queue</h2></div><div v-for="item in operations" :key="item.local_id" class="flex flex-col items-start justify-between gap-3 border-b p-4 last:border-0 sm:flex-row"><div><p class="font-bold">{{ item.entity_type.replaceAll('_', ' ') }} · <span :class="{'text-emerald-700': item.sync_status === 'synced', 'text-amber-700': item.sync_status === 'pending' || item.sync_status === 'syncing', 'text-rose-700': item.sync_status === 'failed', 'text-violet-700': item.sync_status === 'conflict'}">{{ item.sync_status }}</span></p><p class="mt-1 text-sm text-slate-500">Saved {{ formatPh(item.created_offline_at, 'dateTimeShort') }}<span v-if="item.retry_count > 0"> · {{ item.retry_count }} attempt(s)</span><span v-if="item.error_message"> · {{ item.error_message }}</span></p></div><div v-if="item.sync_status === 'failed'" class="flex shrink-0 gap-2"><button type="button" class="min-h-11 rounded-lg border px-3 py-1.5 text-xs font-bold text-brand-700 hover:bg-brand-50" @click="retryOperation(item.local_id)">Retry now</button><button type="button" class="min-h-11 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="discardOperation(item.local_id)">Discard</button></div></div></section>
      </template>

      <!-- DEVICES TAB -->
      <template v-if="activeTab === 'devices'">
        <section class="mt-6 rounded-2xl border bg-white">
          <div class="border-b p-5">
            <h2 class="font-extrabold text-slate-900">Registered offline devices</h2>
            <p class="mt-1 text-sm text-slate-500">Devices that have downloaded offline case packs. Revoke a device to immediately block its access and clear its local data on next connection.</p>
          </div>
          <p v-if="devicesLoading" class="p-5 text-sm text-slate-500">Loading devices…</p>
          <p v-else-if="!devices.length" class="p-5 text-sm text-slate-500">No offline devices registered.</p>
          <div v-else v-for="d in devices" :key="d.id" class="border-b p-5 last:border-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-bold text-slate-900">{{ d.device_name || 'Unnamed device' }}</p>
                <p class="mt-1 text-xs font-mono text-slate-500">{{ d.device_id.slice(0, 8) }}…</p>
                <p class="mt-1 text-xs text-slate-500">
                  Last seen {{ d.last_seen_at ? formatPh(d.last_seen_at, 'dateTimeShort') : 'never' }}
                  <span v-if="d.pack_expires_at"> · Pack {{ new Date(d.pack_expires_at) > new Date() ? 'expires' : 'expired' }} {{ formatPh(d.pack_expires_at, 'dateTimeShort') }}</span>
                </p>
              </div>
              <div class="flex shrink-0 gap-2">
                <template v-if="d.revoked_at">
                  <span class="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700">Revoked {{ formatPh(d.revoked_at, 'date') }}</span>
                </template>
                <template v-else>
                  <button v-if="renamingDeviceId !== d.id" type="button" class="rounded-lg border px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="renamingDeviceId = d.id; renameValue = d.device_name || ''">Rename</button>
                  <button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="revokeDevice(d.id)">Revoke</button>
                </template>
              </div>
            </div>
            <div v-if="renamingDeviceId === d.id" class="mt-3 flex gap-2">
              <input v-model="renameValue" maxlength="80" placeholder="Device name (e.g., Field tablet)" class="flex-1 rounded-xl border-slate-300 text-sm" @keyup.enter="renameDevice(d.id)" />
              <button type="button" class="rounded-xl bg-brand-700 px-3 py-2 text-sm font-bold text-white" @click="renameDevice(d.id)">Save</button>
              <button type="button" class="rounded-xl border px-3 py-2 text-sm font-bold text-slate-600" @click="renamingDeviceId = null">Cancel</button>
            </div>
          </div>
          <div class="border-t p-4">
            <button type="button" class="rounded-xl border border-brand-200 px-3 py-2 text-sm font-bold text-brand-700 disabled:opacity-50" :disabled="!online || devicesLoading" @click="loadDevices">Refresh</button>
          </div>
        </section>
      </template>

      <!-- CONFLICTS TAB -->
      <template v-if="activeTab === 'conflicts'">
        <section class="mt-6 rounded-2xl border bg-white">
          <div class="border-b p-5">
            <h2 class="font-extrabold text-slate-900">Sync conflicts</h2>
            <p class="mt-1 text-sm text-slate-500">These items could not sync because the case changed on the server while you were offline. Review each item and choose to retry (after refreshing your pack) or discard.</p>
          </div>
          <p v-if="!conflictItems.length && !failedItems.length" class="p-5 text-sm text-slate-500">No conflicts or failed items.</p>
          <div v-for="item in [...conflictItems, ...failedItems]" :key="item.local_id" class="border-b p-5 last:border-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-bold"><span :class="item.sync_status === 'conflict' ? 'text-violet-700' : 'text-rose-700'">{{ item.sync_status === 'conflict' ? 'Version conflict' : 'Sync failed' }}</span> · {{ item.entity_type.replaceAll('_', ' ') }}</p>
                <p class="mt-1 text-sm text-slate-500">Created offline {{ formatPh(item.created_offline_at, 'dateTimeShort') }}</p>
                <p v-if="item.error_message" class="mt-1 text-sm text-slate-600">{{ item.error_message }}</p>
                <div v-if="canViewQueuedPayload(item)" class="mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                  <p class="font-bold text-slate-700 mb-1">Saved data preview:</p>
                  <p v-if="item.payload.body"><strong>Note:</strong> {{ (item.payload.body as string).slice(0, 200) }}{{ (item.payload.body as string).length > 200 ? '…' : '' }}</p>
                  <p v-if="item.payload.observations"><strong>Observations:</strong> {{ (item.payload.observations as string).slice(0, 200) }}{{ (item.payload.observations as string).length > 200 ? '…' : '' }}</p>
                  <p v-if="item.payload.first_name"><strong>Name:</strong> {{ item.payload.first_name }} {{ item.payload.last_name }}</p>
                  <p v-if="item.payload.case_id"><strong>Case ID:</strong> {{ item.payload.case_id }}</p>
                  <p v-if="item.base_version"><strong>Base version:</strong> {{ item.base_version }}</p>
                </div>
              </div>
              <div class="flex shrink-0 gap-2">
                <button v-if="item.sync_status === 'conflict'" type="button" :disabled="syncing" class="min-h-11 rounded-lg border border-brand-200 px-3 py-1.5 text-xs font-bold text-brand-700 hover:bg-brand-50 disabled:opacity-50" @click="openConflictReview(item)">Review and retry</button>
                <button v-else type="button" :disabled="syncing" class="min-h-11 rounded-lg border border-brand-200 px-3 py-1.5 text-xs font-bold text-brand-700 hover:bg-brand-50 disabled:opacity-50" @click="retryOperation(item.local_id)">Retry</button>
                <button type="button" class="min-h-11 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="discardOperation(item.local_id)">Discard</button>
              </div>
            </div>
          </div>
        </section>
        <p v-if="conflictItems.length" class="mt-4 rounded-xl bg-brand-50 p-4 text-sm text-brand-800">
          <strong>To resolve conflicts:</strong> Refresh your case pack (Field workspace tab), review the complete saved entry against the current case, then explicitly confirm a retry. New server changes are checked again. Discard remains a separate confirmed action.
        </p>
      </template>
    </template>
    <Modal :show="Boolean(reviewTarget) && canCollectOffline && Boolean(reviewCase) && isUnlocked" aria-labelledby="queued-review-title" :closeable="!reviewSaving" max-width="xl" @close="closeConflictReview">
      <section class="p-5 sm:p-6">
        <h2 id="queued-review-title" class="text-xl font-bold text-slate-900">Review queued work</h2>
        <p class="mt-2 text-sm text-slate-700">Compare the saved observation with the current authorized case. Retrying creates a new note or visit after server access and version checks.</p>
        <dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
          <div><dt>Saved base version</dt><dd class="font-bold">{{ reviewTarget?.base_version }}</dd></div>
          <div><dt>Refreshed case version</dt><dd class="font-bold">{{ reviewCase?.offline_version }}</dd></div>
        </dl>
        <a v-if="reviewCase" :href="route('cases.show', reviewCase.id)" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex min-h-11 items-center text-sm font-bold text-brand-700 underline">Open current authorized case</a>
        <div role="region" aria-label="Complete saved field entry" tabindex="0" class="mt-3 max-h-64 overflow-y-auto rounded-xl border border-slate-200 p-4 text-sm text-slate-800">
          <p v-if="reviewTarget?.payload.visited_at" class="mb-2">Visit date: {{ reviewTarget.payload.visited_at }}</p>
          <p v-if="reviewTarget?.payload.location" class="mb-2">Recorded location: {{ reviewTarget.payload.location }}</p>
          <p class="whitespace-pre-wrap break-words">{{ reviewTarget?.payload.body || reviewTarget?.payload.observations }}</p>
        </div>
        <label class="mt-4 flex min-h-11 items-start gap-2 text-sm font-semibold text-slate-800"><input v-model="reviewConfirmed" type="checkbox" class="mt-1 rounded border-slate-300" />I reviewed the saved entry and the current case information.</label>
        <p v-if="reviewError" role="alert" class="mt-3 text-sm text-rose-800">{{ reviewError }}</p>
        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :disabled="reviewSaving" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700" @click="closeConflictReview">Cancel</button>
          <button type="button" :disabled="!reviewConfirmed || reviewSaving || !canCollectOffline" class="min-h-11 rounded-xl bg-brand-700 px-4 text-sm font-bold text-white disabled:opacity-50" @click="confirmConflictReview">Confirm review and retry</button>
        </div>
      </section>
    </Modal>
  </section></AuthenticatedLayout>
</template>

<style scoped>
input:not([type='checkbox']), select, textarea { box-sizing: border-box; min-width: 0; width: 100%; }
input[type='checkbox'] { box-sizing: border-box; width: 1rem; height: 1rem; flex: 0 0 auto; }
textarea { overflow-y: hidden; resize: none; }
select { cursor: pointer; }
fieldset { min-width: 0; }
@media (max-width: 639px) { section { min-width: 0; } }
.field-hero{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;border-radius:1rem;background:linear-gradient(135deg,#4c1d95,#6d28d9 60%,#7c3aed);padding:1.25rem 1.5rem;color:#fff}
.hero-pill{display:inline-flex;align-items:center;gap:.4rem;min-height:2.25rem;border-radius:999px;background:rgb(255 255 255/.15);padding:.35rem .75rem;font-size:.78rem;font-weight:600;color:#fff}.hero-btn{border:1px solid rgb(255 255 255/.5)}.pill-on{background:rgb(16 185 129/.25)}.pill-off{background:rgb(245 158 11/.3)}
.field-nav{display:flex;gap:.25rem;overflow-x:auto;margin-top:1.25rem;border-radius:.9rem;background:#f1f0f7;padding:.25rem}.field-nav button{flex-shrink:0;min-height:2.75rem;border-radius:.7rem;padding:0 .9rem;font-size:.85rem;font-weight:600;color:#475569;white-space:nowrap}.field-nav button.active{background:#fff;color:#5b21b6;box-shadow:0 1px 2px rgb(15 23 42/.08)}
.field-stat{border:1px solid var(--color-line);border-radius:.9rem;background:#fff;padding:.9rem 1rem}
.jump{display:inline-flex;min-height:2.5rem;align-items:center;border-radius:999px;background:#f5f3ff;padding:0 .9rem;font-size:.8rem;font-weight:600;color:#5b21b6}
.field-toast{position:fixed;left:50%;bottom:5.5rem;z-index:70;max-width:calc(100% - 2rem);transform:translateX(-50%);border-radius:.8rem;background:#1e1b3a;padding:.75rem 1rem;font-size:.85rem;color:#fff;box-shadow:0 10px 30px rgb(15 23 42/.3)}
@media(min-width:1024px){.field-toast{bottom:1.5rem}}
.status-chip{border-radius:999px;padding:.25rem .6rem;font-size:.75rem;font-weight:700}.st-synced{background:#ecfdf5;color:#047857}.st-pending,.st-syncing{background:#fffbeb;color:#b45309}.st-failed{background:#fff1f2;color:#be123c}.st-conflict{background:#f5f3ff;color:#6d28d9}
</style>





