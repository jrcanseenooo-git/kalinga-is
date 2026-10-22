import type { OfflineOperation } from './offlineTypes';

export interface QueueSyncDependencies {
    load: () => Promise<OfflineOperation[]>;
    send: (item: OfflineOperation) => Promise<{ status?: string; offline_id?: string }>;
    record: (item: OfflineOperation, status: 'synced' | 'failed' | 'conflict', message?: string) => Promise<void>;
    active: () => boolean;
}
export interface QueueSyncResult { synced: number; failed: number; conflicts: number; authorizationExpired: boolean }

export function prepareConflictRetry(item: OfflineOperation, currentVersion: number, confirmed: boolean, reviewedAt: string): OfflineOperation {
    if (!confirmed || item.sync_status !== 'conflict' || item.operation !== 'create'
        || !['case_note', 'monitoring_visit'].includes(item.entity_type)) throw new Error('Review the conflicted entry before retrying.');
    if (!Number.isSafeInteger(item.base_version) || !Number.isSafeInteger(currentVersion) || currentVersion <= item.base_version!
        || !Number.isFinite(Date.parse(reviewedAt))) throw new Error('Refresh the authorized pack before reviewing this conflict.');
    return { ...item, original_base_version: item.original_base_version ?? item.base_version,
        base_version: currentVersion, review_confirmed_at: reviewedAt,
        sync_status: 'pending', retry_count: 0, error_message: undefined };
}

export async function synchronizeQueue(dependencies: QueueSyncDependencies): Promise<QueueSyncResult> {
    const result: QueueSyncResult = { synced: 0, failed: 0, conflicts: 0, authorizationExpired: false };
    // A bounded immutable upload batch. New work stays in durable storage for
    // the next explicit/reconnect sync, not in this in-flight batch.
    const batch = (await dependencies.load()).filter(item => ['pending', 'failed'].includes(item.sync_status) && item.retry_count < 10).slice(0, 50);
    for (const sent of batch) {
        if (!dependencies.active()) break;
        let status: 'synced' | 'failed' | 'conflict' = 'synced';
        let message: string | undefined;
        try {
            const acknowledgement = await dependencies.send(sent);
            if (acknowledgement.status !== 'synced' || acknowledgement.offline_id !== sent.local_id) throw new Error('Unconfirmed acknowledgement');
        } catch (error) {
            const httpStatus = (error as { response?: { status?: number } }).response?.status;
            status = httpStatus === 409 ? 'conflict' : 'failed';
            result.authorizationExpired = [401, 403, 419].includes(httpStatus ?? 0);
            message = result.authorizationExpired ? 'Authorization expired. Reconnect and sign in or refresh your authorized pack before retrying.'
                : status === 'conflict' ? 'The server record changed. Refresh the authorized pack and review this item.'
                    : 'No matching acknowledgement received. Your encrypted item is retained for retry.';
        }
        if (!dependencies.active()) break;
        // If persisting an acknowledgement fails (quota/lock/reload), leave the
        // original item recoverable and stop. Retry uses its unchanged UUID.
        await dependencies.record(sent, status, message);
        if (status === 'synced') result.synced++;
        else if (status === 'conflict') result.conflicts++;
        else result.failed++;
        if (result.authorizationExpired || status === 'failed') break;
    }
    return result;
}
