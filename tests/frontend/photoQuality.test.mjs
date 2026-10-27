import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source = readFileSync(new URL('../../resources/js/utils/photoQuality.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { toGray, measure, assess, enhance } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

/** RGBA image filled by a function of (x, y) returning a gray level. */
function image(width, height, level) {
    const data = new Uint8ClampedArray(width * height * 4);
    for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
        const i = (y * width + x) * 4; const v = level(x, y);
        data[i] = data[i + 1] = data[i + 2] = v; data[i + 3] = 255;
    }
    return data;
}
const check = (data, w, h, shortSide = 1200) => assess({ ...measure(toGray(data, w, h), w, h), shortSide });
// A "form": white paper with dark lines of writing.
const form = (x, y) => (y % 12 < 3 && x > 10 && x < 190 ? 20 : 235);

test('a clear form photo passes', () => {
    const report = check(image(200, 260, form), 200, 260);
    assert.deepEqual(report.issues, []);
    assert.equal(report.good, true);
});

test('a dark photo is flagged and not also called faint', () => {
    const report = check(image(200, 260, (x, y) => form(x, y) * 0.2), 200, 260);
    assert.ok(report.issues.includes('dark'));
    assert.ok(!report.issues.includes('low_contrast'));
});

test('a blank or blurry photo is flagged as blurry', () => {
    const report = check(image(200, 260, () => 200), 200, 260);
    assert.ok(report.issues.includes('blurry'));
});

test('faint writing and a small photo are flagged', () => {
    const report = check(image(200, 260, (x, y) => (form(x, y) < 100 ? 190 : 215)), 200, 260, 400);
    assert.ok(report.issues.includes('low_contrast'));
    assert.ok(report.issues.includes('small'));
});

test('making the text clearer stretches faint writing to black and white', () => {
    const data = image(200, 260, (x, y) => (form(x, y) < 100 ? 150 : 200));
    enhance(data, 200, 260);
    const gray = toGray(data, 200, 260);
    assert.ok(Math.min(...gray) <= 5);
    assert.ok(Math.max(...gray) >= 250);
    assert.ok(measure(gray, 200, 260).contrast > 60);
});
