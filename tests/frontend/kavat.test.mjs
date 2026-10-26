import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source=readFileSync(new URL('../../resources/js/Casework/kavat.ts',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ES2022}}).outputText;
const {scoreKavat,priorityLabel}=await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);
// Kept equal to App\Services\Kavat::definition() by KalingaFormsAndKavatTest.
const definition=JSON.parse(readFileSync(new URL('./fixtures/kavat-definition.json',import.meta.url),'utf8'));

const example=()=>{const part1={};for(let n=1;n<=25;n++)part1[n]=[1,2,3,6,7].includes(n)?'no':'maybe';
 return {part1,part2:{living_arrangement:'1',family_support:'1',dependents:'1',education:'1',economic_support:'1',breadwinner:'yes',income:'yes',partner_age_gap:'1',age_at_union:'minor',decision:'1',violence:'yes',safety:'no'}};};

test('browser score matches the guideline worked example',()=>{
 const s=scoreKavat(definition,example());
 assert.equal(s.complete,true);assert.equal(s.part1,65);assert.equal(s.part2,88);
 assert.equal(s.empowerment_rate,52);assert.equal(s.objective_risk_rate,89.8);assert.equal(s.final_score,26.92);assert.equal(s.priority,'high');
 assert.equal(priorityLabel(s.priority),'High Priority (Eligible)');
});
test('reversed statements and refusals',()=>{
 const s=scoreKavat(definition,{part1:{4:'yes',1:'yes'},part2:{safety:'rta'}});
 assert.equal(s.part1,6);assert.equal(s.complete,false);assert.equal(s.final_score,null);assert.equal(s.red_flags[0].question,'safety');assert.equal(s.part2,10);
});
test('domain bands use the guideline ranges',()=>{
 const part1={};for(let n=1;n<=25;n++)part1[n]='yes';
 const s=scoreKavat(definition,{part1,part2:{}});
 const d1=s.domains.find(d=>d.code==='I');assert.equal(d1.score,16);assert.equal(d1.level,1);
});
