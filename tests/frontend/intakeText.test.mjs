import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source = readFileSync(new URL('../../resources/js/Casework/intakeText.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { isEmptyFamilyMember, normalizePhone, tidyName } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

const blank = { name: '', relationship_type: '', sex: '', birthdate: '', civil_status: '', education: '', occupation: '', same_address: false, house_no_street: '', region: '', province: '', municipality: '', barangay: '' };

test('a family card is empty until something is typed or chosen', () => {
    assert.equal(isEmptyFamilyMember(blank), true);
    assert.equal(isEmptyFamilyMember({ ...blank, same_address: true }), true);
    assert.equal(isEmptyFamilyMember({ ...blank, name: '  ' }), true);
    assert.equal(isEmptyFamilyMember({ ...blank, occupation: 'Farmer' }), false);
});

test('mobile numbers are tidied to 09XXXXXXXXX', () => {
    assert.equal(normalizePhone('+63 917-123 4567'), '09171234567');
    assert.equal(normalizePhone('639171234567'), '09171234567');
    assert.equal(normalizePhone('0917 123 4567'), '09171234567');
    assert.equal(normalizePhone('12345'), '12345');
    assert.equal(normalizePhone(''), '');
});

test('names typed all in capitals or small letters get proper capitals', () => {
    assert.equal(tidyName('MARIA  DELA cruz'), 'MARIA DELA cruz');
    assert.equal(tidyName('maria dela cruz'), 'Maria Dela Cruz');
    assert.equal(tidyName('JUAN O\'NEIL-SANTOS'), "Juan O'Neil-Santos");
    assert.equal(tidyName('Ma. Teresa'), 'Ma. Teresa');
    assert.equal(tidyName('ñino'), 'Ñino');
});
