import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source=readFileSync(new URL('../../resources/js/utils/philippineTime.ts',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ES2022}}).outputText;
const {manilaDateTime,manilaInputToUtc,philippineTimestamp,calendarDateLabel,storedUtcToManilaInput}=await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

test('Philippine midnight fields round-trip independently of device timezone',()=>{
 const prior=process.env.TZ;try{for(const tz of ['UTC','America/Los_Angeles','Asia/Tokyo']){process.env.TZ=tz;assert.equal(manilaDateTime(new Date('2026-10-18T16:05:00Z')),'2026-10-19T00:05');assert.equal(manilaInputToUtc('2026-10-19T00:05'),'2026-10-18T16:05:00.000Z');}}finally{if(prior===undefined)delete process.env.TZ;else process.env.TZ=prior;}
});
test('UTC database and explicit-offset records have the same Philippine display',()=>{
 const expected=philippineTimestamp('2026-10-18T16:05:00Z');assert.equal(philippineTimestamp('2026-10-18 16:05:00'),expected);assert.equal(philippineTimestamp('2026-10-19T00:05:00+08:00'),expected);assert.equal(calendarDateLabel('2026-10-19'),calendarDateLabel('2026-10-19T23:00:00Z'));
});
test('missing and malformed time input has a safe recoverable result',()=>{
 assert.equal(manilaInputToUtc(''),'');assert.equal(manilaInputToUtc('invalid'),'');assert.equal(philippineTimestamp(null),'Not recorded');assert.equal(philippineTimestamp('invalid'),'Time unavailable');
});
test('stored conference times reload into Philippine form fields on every device timezone',()=>{
 const prior=process.env.TZ;
 try{for(const tz of ['UTC','America/Los_Angeles','Asia/Tokyo']){process.env.TZ=tz;assert.equal(storedUtcToManilaInput('2026-10-20 06:30:00'),'2026-10-20T14:30');assert.equal(storedUtcToManilaInput('2026-10-20T14:30:00+08:00'),'2026-10-20T14:30');assert.equal(storedUtcToManilaInput(null),'');assert.equal(storedUtcToManilaInput('invalid'),'');}}
 finally{if(prior===undefined)delete process.env.TZ;else process.env.TZ=prior;}
});

test('database UTC timestamps without a zone are read as UTC, not device time',async()=>{
 const {formatPh,parseStored,manilaDayKey,ageFromBirthdate}=await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);
 const prior=process.env.TZ;
 try{for(const tz of ['UTC','America/New_York','Asia/Manila']){process.env.TZ=tz;
  // Stored 2026-09-19 20:30 UTC is 4:30 AM on September 20 in Manila.
  assert.equal(parseStored('2026-09-19 20:30:00').toISOString(),'2026-09-19T20:30:00.000Z');
  assert.equal(manilaDayKey('2026-09-19 20:30:00'),'2026-09-20');
  assert.equal(formatPh('2026-09-19 20:30:00','dateShort'),'Sep 20');
  // A calendar date stays on its own day.
  assert.equal(manilaDayKey('2026-09-19'),'2026-09-19');
  assert.equal(formatPh(null,'date','Not recorded'),'Not recorded');
  assert.equal(ageFromBirthdate('2010-10-22','2026-10-21'),15);
  assert.equal(ageFromBirthdate('2010-10-22','2026-10-22'),16);
 }}finally{if(prior===undefined)delete process.env.TZ;else process.env.TZ=prior;}
});
