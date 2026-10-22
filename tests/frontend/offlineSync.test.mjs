import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

// Exercise the actual transport-independent TypeScript orchestrator, without
// adding a second implementation or a new runtime dependency.
const source = readFileSync(new URL('../../resources/js/Offline/syncQueue.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { synchronizeQueue, prepareConflictRetry } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);
const ownerSource = readFileSync(new URL('../../resources/js/Offline/workspaceOwner.ts', import.meta.url), 'utf8');
const ownerCompiled = ts.transpileModule(ownerSource, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { assertWorkspaceOwner } = await import(`data:text/javascript;base64,${Buffer.from(ownerCompiled).toString('base64')}`);
const entry = (id = 'one') => ({ local_id: id, entity_type: 'case_note', operation: 'create', payload: { body: 'Synthetic encrypted work' }, sync_status: 'pending', retry_count: 0, created_offline_at: '2026-10-18T00:00:00Z' });

test('newly appended entries stay outside the immutable upload batch', async () => {
    const entries = [entry()]; const sent = []; const recorded = [];
    const result = await synchronizeQueue({ load: async () => entries.map(item => structuredClone(item)), active: () => true,
        send: async item => { entries.push(entry('two')); sent.push(item.local_id); return { status: 'synced', offline_id: item.local_id }; },
        record: async (item, status) => recorded.push([item.local_id, status]) });
    assert.deepEqual(sent, ['one']); assert.deepEqual(recorded, [['one', 'synced']]);
    assert.equal(entries[1].local_id, 'two'); assert.equal(result.synced, 1);
});

for (const status of [401, 403, 419, 500, 429]) test(`HTTP ${status} retains work and stops the batch`, async () => {
    const sent = []; const recorded = [];
    const result = await synchronizeQueue({ load: async () => [entry(), entry('two')], active: () => true,
        send: async item => { sent.push(item.local_id); throw { response: { status, data: { message: 'Synthetic private server narrative' } } }; },
        record: async (item, state, message) => recorded.push({ id: item.local_id, state, message }) });
    assert.deepEqual(sent, ['one']); assert.equal(recorded[0].state, 'failed');
    assert.ok(!recorded[0].message.includes('private server narrative'));
    assert.equal(result.authorizationExpired, [401, 403, 419].includes(status));
});

for (const acknowledgement of [{}, { status: 'synced', offline_id: 'other' }, { status: 'queued', offline_id: 'one' }, 'Login page']) test(`unmatched acknowledgement ${JSON.stringify(acknowledgement)} cannot clear work`, async () => {
    const recorded = [];
    await synchronizeQueue({ load: async () => [entry()], active: () => true, send: async () => acknowledgement,
        record: async (_item, state) => recorded.push(state) });
    assert.deepEqual(recorded, ['failed']);
});

test('acknowledgement persistence failure keeps the same UUID available for idempotent retry', async () => {
    const original = entry(); const ids = []; let storageAvailable = false;
    const dependencies = { load: async () => [structuredClone(original)], active: () => true,
        send: async item => { ids.push(item.local_id); return { status: 'synced', offline_id: item.local_id }; },
        record: async () => { if (!storageAvailable) throw new Error('QuotaExceededError'); } };
    await assert.rejects(synchronizeQueue(dependencies), /QuotaExceededError/);
    assert.equal(original.sync_status, 'pending'); storageAvailable = true;
    assert.equal((await synchronizeQueue(dependencies)).synced, 1); assert.deepEqual(ids, ['one', 'one']);
});

test('conflicted entries are not automatically re-uploaded', async () => {
    const entries = [entry(), { ...entry('conflict'), sync_status: 'conflict' }]; const sent = []; const recorded = [];
    await synchronizeQueue({ load: async () => entries, active: () => true,
        send: async item => { sent.push(item.local_id); throw { response: { status: 409 } }; },
        record: async (item, status) => recorded.push([item.local_id, status]) });
    assert.deepEqual(sent, ['one']); assert.deepEqual(recorded, [['one', 'conflict']]);
});

test('lock/revocation while request is pending cannot persist a late acknowledgement', async () => {
    let active = true; let records = 0;
    await synchronizeQueue({ load: async () => [entry()], active: () => active,
        send: async item => { active = false; return { status: 'synced', offline_id: item.local_id }; }, record: async () => records++ });
    assert.equal(records, 0);
});

test('each pass is bounded and excludes completed and exhausted records', async () => {
    const entries = Array.from({ length: 52 }, (_, index) => entry(String(index)));
    entries.unshift({ ...entry('done'), sync_status: 'synced' }, { ...entry('exhausted'), retry_count: 10 });
    const sent = [];
    await synchronizeQueue({ load: async () => entries, active: () => true,
        send: async item => { sent.push(item.local_id); return { status: 'synced', offline_id: item.local_id }; }, record: async () => {} });
    assert.equal(sent.length, 50); assert.equal(sent.includes('done'), false); assert.equal(sent.includes('exhausted'), false);
});

test('ownership permits an empty provisioned workspace and matching original pack only', () => {
    assert.doesNotThrow(() => assertWorkspaceOwner(null, 1, false));
    assert.doesNotThrow(() => assertWorkspaceOwner({ user_id: 1 }, 1, true));
    assert.throws(() => assertWorkspaceOwner({ user_id: 1 }, 2, true), /belongs to another account/);
    assert.throws(() => assertWorkspaceOwner(null, 1, true), /cannot be confirmed/);
    assert.throws(() => assertWorkspaceOwner({ user_id: '1' }, 1), /belongs to another account/);
    assert.throws(() => assertWorkspaceOwner(null, NaN), /Sign in/);
});

test('reviewed retry preserves request identity, content and original version', () => {
    const original = { ...entry(), base_version: 1, sync_status: 'conflict', retry_count: 2 };
    const reviewed = prepareConflictRetry(original, 2, true, '2026-10-18T04:00:00Z');
    assert.equal(reviewed.local_id, original.local_id); assert.deepEqual(reviewed.payload, original.payload);
    assert.equal(reviewed.original_base_version, 1); assert.equal(reviewed.base_version, 2);
    assert.equal(reviewed.sync_status, 'pending'); assert.equal(original.sync_status, 'conflict');
    assert.equal(original.base_version, 1);
});

test('conflict retry refuses missing review, stale versions and already acknowledged work', () => {
    const original = { ...entry(), base_version: 1, sync_status: 'conflict' };
    assert.throws(() => prepareConflictRetry(original, 2, false, '2026-10-18T04:00:00Z'), /Review/);
    assert.throws(() => prepareConflictRetry(original, 1, true, '2026-10-18T04:00:00Z'), /Refresh/);
    assert.throws(() => prepareConflictRetry({ ...original, sync_status: 'synced' }, 2, true, '2026-10-18T04:00:00Z'), /Review/);
    assert.throws(() => prepareConflictRetry({ ...original, entity_type: 'case_intake' }, 2, true, '2026-10-18T04:00:00Z'), /Review/);
    assert.throws(() => prepareConflictRetry(original, 2, true, 'invalid date'), /Refresh/);
});

const draftSource = readFileSync(new URL('../../resources/js/Offline/intakeDraft.ts', import.meta.url), 'utf8');
const draftCompiled = ts.transpileModule(draftSource, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { reviseDraft, promoteDraft } = await import(`data:text/javascript;base64,${Buffer.from(draftCompiled).toString('base64')}`);

test('draft revision rejects stale edits, another owner/device and promoted resurrection', () => {
    const draft = reviseDraft(undefined, 'draft', 7, 'device', 0, { first_name: 'Synthetic' });
    assert.equal(draft.revision, 1);
    assert.throws(() => reviseDraft(draft, 'draft', 7, 'device', 0, {}));
    assert.throws(() => reviseDraft(draft, 'draft', 8, 'device', 1, {}));
    assert.throws(() => reviseDraft(draft, 'draft', 7, 'other', 1, {}));
    assert.throws(() => reviseDraft({ ...draft, state: 'promoted' }, 'draft', 7, 'device', 1, {}));
    assert.equal(draft.payload.first_name, 'Synthetic');
});
test('draft promotion preserves queued records and leaves a narrative-free idempotent receipt', () => {
    const payload = { first_name: 'Synthetic', last_name: 'Draft' };
    const draft = reviseDraft(undefined, 'draft', 7, 'device', 0, payload);
    const result = promoteDraft([draft], [entry()], 'draft', 7, 'device', 1, payload, 'digest');
    assert.equal(result.queue.length, 2); assert.equal(result.queue[1].local_id, 'draft');
    assert.deepEqual(result.drafts[0].payload, {});
    assert.equal(result.drafts[0].state, 'promoted');
    assert.equal(promoteDraft(result.drafts, result.queue, 'draft', 7, 'device', 1, payload, 'digest').queue.length, 2);
    assert.throws(() => promoteDraft(result.drafts, result.queue, 'draft', 7, 'device', 1, {}, 'different'));
    assert.throws(() => promoteDraft([draft], [], 'draft', 7, 'device', 0, payload, 'digest'));
    assert.throws(() => promoteDraft([draft], [], 'draft', 8, 'device', 1, payload, 'digest'));
    assert.throws(() => promoteDraft([], [], 'draft', 7, 'device', 1, payload, 'digest'));
});

const intakeErrorSource = readFileSync(new URL('../../resources/js/Casework/intakeErrors.ts', import.meta.url), 'utf8');
const intakeErrorCompiled = ts.transpileModule(intakeErrorSource, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { intakeStepForError, intakeFieldId } = await import(`data:text/javascript;base64,${Buffer.from(intakeErrorCompiled).toString('base64')}`);
test('online intake server errors route to the existing responsible step and control group', () => {
    assert.equal(intakeStepForError('privacy_notice_method'), 1);
    assert.equal(intakeStepForError('immediate_actions'), 2);
    assert.equal(intakeStepForError('first_name'), 3);
    assert.equal(intakeStepForError('cefmu_classifications.0'), 4);
    assert.equal(intakeStepForError('problem_presented'), 5);
    assert.equal(intakeStepForError('unknown_field'), 5);
    assert.equal(intakeFieldId('cefmu_classifications.0'), 'intake-cefmu_classifications');
    assert.equal(intakeFieldId('first_name'), 'intake-first_name');
});
