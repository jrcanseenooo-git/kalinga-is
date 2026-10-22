import type { OfflineOperation } from './offlineTypes';

export type IntakeDraft = {
    id: string; user_id: number; device_id: string; revision: number;
    state: 'draft' | 'promoted'; payload: Record<string, unknown>;
    updated_at: string; promoted_hash?: string;
};

export function reviseDraft(existing: IntakeDraft | undefined, id: string, userId: number, deviceId: string,
    revision: number, payload: Record<string, unknown>): IntakeDraft {
    if (existing && (existing.user_id !== userId || existing.device_id !== deviceId)) throw new Error('This draft belongs to another workspace.');
    if ((existing?.revision ?? 0) !== revision || existing?.state === 'promoted') throw new Error('This draft changed in another tab. Your entered text is retained. Reload the saved draft before editing it again.');
    return { id, user_id: userId, device_id: deviceId, revision: revision + 1, state: 'draft', payload, updated_at: new Date().toISOString() };
}

export function promoteDraft(drafts: IntakeDraft[], queue: OfflineOperation[], id: string, userId: number,
    deviceId: string, revision: number, payload: Record<string, unknown>, hash: string) {
    const draft = drafts.find(item => item.id === id);
    if (!draft || draft.user_id !== userId || draft.device_id !== deviceId) throw new Error('The original encrypted draft is unavailable in this workspace.');
    if (draft.state === 'promoted') {
        if (draft.promoted_hash !== hash) throw new Error('This draft was already queued with different content.');
        return { drafts, queue };
    }
    if (draft.revision !== revision) throw new Error('This draft changed in another tab. Review its saved version before queueing.');
    if (queue.some(item => item.local_id === id)) throw new Error('This draft identifier already exists in the queue.');
    const operation: OfflineOperation = { local_id: id, entity_type: 'case_intake', operation: 'create', payload,
        sync_status: 'pending', retry_count: 0, created_offline_at: new Date().toISOString() };
    return {
        drafts: drafts.map(item => item.id === id ? { ...item, state: 'promoted' as const, revision: item.revision + 1,
            payload: {}, promoted_hash: hash, updated_at: new Date().toISOString() } : item),
        queue: [...queue, operation],
    };
}
