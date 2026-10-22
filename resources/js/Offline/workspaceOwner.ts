export function assertWorkspaceOwner(pack: { user_id: unknown } | null, currentUserId: number, hasQueuedWork = false, sealedOwner: number | null = null): void {
    if (!Number.isSafeInteger(currentUserId) || currentUserId < 1) throw new Error('Sign in to the original account before unlocking this workspace.');
    if (pack && pack.user_id !== currentUserId) throw new Error('This encrypted workspace belongs to another account. Sign in to the original account to recover it. No local records have been removed.');
    if (!pack && hasQueuedWork && sealedOwner !== currentUserId) throw new Error('Ownership of the encrypted queue cannot be confirmed without its original case pack. Keep this device unchanged and request recovery assistance.');
}
