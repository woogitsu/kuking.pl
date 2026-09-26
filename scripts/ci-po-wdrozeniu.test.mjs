import test from 'node:test';
import assert from 'node:assert/strict';
import { adresApi, czyCiByloZielonePrzedDeployem } from './ci-po-wdrozeniu.mjs';

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

test('wcześniejsza zielona próba pozostaje ważna po późniejszym rerun', () => {
    assert.equal(czyCiByloZielonePrzedDeployem([
        ci,
        { ...ci, conclusion: 'cancelled', updated_at: '2026-09-26T21:00:00Z' },
    ], sha, wdrozoneO), true);
});

test('adres GitHub Enterprise zachowuje prefiks API', () => {
    assert.equal(adresApi('https://github.example/api/v3', '/repos/o/r/actions/runs').href,
        'https://github.example/api/v3/repos/o/r/actions/runs');
    assert.equal(adresApi('https://api.github.com', 'repos/o/r/actions/runs').href,
        'https://api.github.com/repos/o/r/actions/runs');
});

test('niepełny czas lub SHA nie daje fałszywego potwierdzenia', () => {
    assert.equal(czyCiByloZielonePrzedDeployem([ci], '', wdrozoneO), false);
    assert.equal(czyCiByloZielonePrzedDeployem([ci], sha, ''), false);
});
