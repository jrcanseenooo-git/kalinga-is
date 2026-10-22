import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source=readFileSync(new URL('../../resources/js/utils/caseSearch.ts',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ES2022}}).outputText;
const {understandCaseSearch}=await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);
const today=new Date(2026,9,21);

test('plain phrases become filters and leave no stray words',()=>{
 const r=understandCaseSearch('urgent follow-up cases opened this month',today);
 assert.equal(r.filters.priority,'urgent');assert.equal(r.filters.status,'FOR_FOLLOW_UP');assert.equal(r.filters.from,'2026-10-01');assert.equal(r.filters.to,'2026-10-21');assert.equal(r.filters.search,'');
 assert.deepEqual(r.understood.map(i=>i.value),['For follow-up','Urgent','Opened this month']);
});
test('names and case numbers stay a search',()=>{
 const r=understandCaseSearch('KLG-2026-00001',today);assert.equal(r.filters.search,'klg-2026-00001');assert.equal(r.understood.length,0);
 const n=understandCaseSearch('high priority Dela Cruz',today);assert.equal(n.filters.priority,'high');assert.equal(n.filters.search,'dela cruz');
});
test('needs action, active and last month',()=>{
 const r=understandCaseSearch('needs action active last month',today);
 assert.equal(r.filters.attention,true);assert.equal(r.filters.active,true);assert.equal(r.filters.from,'2026-09-01');assert.equal(r.filters.to,'2026-09-30');
});
test('closed is a status, not active',()=>{
 const r=understandCaseSearch('closed',today);assert.equal(r.filters.status,'CLOSED');assert.equal(r.filters.active,undefined);
});
