import test from 'node:test';
import assert from 'node:assert/strict';
import { czyCiByloZielonePrzedDeployem } from './ci-po-wdrozeniu.mjs';

const sha = 'a'.repeat(40);
const wdrozoneO = '2026-09-26T20:38:06Z';
const ci = { name: 'CI', event: 'push', head_sha: sha, status: 'completed',
    conclusion: 'success', updated_at: '2026-09-26T20:30:00Z' };

test('zielone CI tego samego SHA przed wdrożeniem przechodzi', () => {
    assert.equal(czyCiByloZielonePrzedDeployem([ci], sha, wdrozoneO), true);
});

test('anulowany CI obok zielonego Push on main alarmuje', () => {
    assert.equal(czyCiByloZielonePrzedDeployem([
        { ...ci, conclusion: 'cancelled' },
        { ...ci, name: 'Push on main' },
    ], sha, wdrozoneO), false);
});

test('późniejszy rerun i sukces innego SHA nie maskują wdrożenia bez CI', () => {
    assert.equal(czyCiByloZielonePrzedDeployem([
        { ...ci, updated_at: '2026-09-26T20:48:00Z' },
        { ...ci, head_sha: 'b'.repeat(40) },
    ], sha, wdrozoneO), false);
});

test('niepełny czas lub SHA nie daje fałszywego potwierdzenia', () => {
    assert.equal(czyCiByloZielonePrzedDeployem([ci], '', wdrozoneO), false);
    assert.equal(czyCiByloZielonePrzedDeployem([ci], sha, ''), false);
});
