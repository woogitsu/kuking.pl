/* #581: Sześć scenariuszy prawdziwego zoomu 200%.
 * Runner odpowiada za izolację DB, mailer array i syntetyczną fixture.
 */
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readdirSync, readFileSync, writeFileSync, rmSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { przypadkiDetails, sprawdzScenariuszDetails, screenshot } from './panel-details.mjs';

export async function sprawdzZoomDetails({ chromium, adres, sesja, fixture, scenariusze = fixture?.scenariusze, outputDir, executablePath }) {
  const base = new URL(adres);
  assert(['http:', 'https:'].includes(base.protocol) && base.hostname === '127.0.0.1'
    && !base.username && !base.password && base.pathname === '/' && !base.search && !base.hash, 'DETAILS_LOCAL_ORIGIN_REQUIRED');
  assert.equal(fixture?.phase, 'pelny', 'DETAILS_FULL_FIXTURE_REQUIRED');
  assert(Array.isArray(scenariusze), 'DETAILS_SCENARIOS_REQUIRED');
  const messages = scenariusze.filter(s => s.rodzina === 'wiadomosc');
  assert.equal(messages.length, 1, 'DETAILS_MESSAGE_FIXTURE_REQUIRED');
  assert(typeof messages[0].path === 'string' && /^\/admin\/wiadomosci\/[^/?#\\]+$/.test(messages[0].path)
    && new URL(messages[0].path, base.origin).origin === base.origin, 'DETAILS_MESSAGE_PATH');
  assert(typeof outputDir === 'string' && outputDir.length > 0, 'DETAILS_OUTPUT_REQUIRED');
  outputDir = resolve(outputDir);
  mkdirSync(outputDir, { recursive: true, mode: 0o700 });
  assert.equal(readdirSync(outputDir).length, 0, 'DETAILS_OLD_EVIDENCE');
  const state = typeof sesja === 'string' ? JSON.parse(readFileSync(sesja, 'utf8')) : sesja;
  assert(state && Array.isArray(state.cookies), 'DETAILS_SESSION_REQUIRED');
  const rows = [], requests = [];
  const save = () => writeFileSync(join(outputDir, 'details-zoom200.json'), JSON.stringify({ scope: '3 details x 2 motywy, tekst140, zoom200, CSS320x900, DPR2; bez submit/mailto', rows, requests }, null, 2), { mode: 0o600 });
  const directory = mkdtempSync(join(tmpdir(), 'kuking-details-zoom-'));
  chmodSync(directory, 0o700);
  try {
    const extension = join(directory, 'extension'); mkdirSync(extension, { mode: 0o700 });
    writeFileSync(join(extension, 'manifest.json'), JSON.stringify({ manifest_version: 3, name: 'Pomiar details Kuking', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }), { mode: 0o600 });
    writeFileSync(join(extension, 'worker.js'), 'chrome.runtime.onInstalled.addListener(() => {});', { mode: 0o600 });
    for (const theme of ['light', 'dark']) for (const c of przypadkiDetails(messages[0])) {
      const row = { id: c.id, theme, scale: 140, physicalWidth: 640, physicalHeight: 1800 };
      let context, page;
      try {
        const profile = mkdtempSync(join(directory, 'profile-')); chmodSync(profile, 0o700);
        context = await chromium.launchPersistentContext(profile, {
          executablePath: executablePath || chromium.executablePath(), headless: true,
          viewport: { width: 640, height: 1800 }, reducedMotion: 'reduce', serviceWorkers: 'block',
          args: ['--disable-extensions-except=' + extension, '--load-extension=' + extension],
        });
        await context.addCookies(state.cookies);
        // Odtworzenie tylko localStorage właściwego origin; nic z sesji nie trafia do raportu.
        const local = (state.origins || []).find(s => s.origin === base.origin)?.localStorage || [];
        await context.addInitScript(({ origin, local }) => {
          if (location.origin === origin) for (const { name, value } of local) localStorage.setItem(name, value);
        }, { origin: base.origin, local });
        const extensionWorker = w => w.url().startsWith('chrome-extension://');
        const worker = context.serviceWorkers().find(extensionWorker)
          || await context.waitForEvent('serviceworker', { predicate: extensionWorker, timeout: 15000 });
        page = await context.newPage();
        const przygotuj = async page => {
          row.zoom = await worker.evaluate(async url => {
            const tabs = (await chrome.tabs.query({})).filter(t => t.url === url);
            if (tabs.length !== 1) throw new Error('DETAILS_ZOOM_TAB');
            await chrome.tabs.setZoom(tabs[0].id, 2);
            return chrome.tabs.getZoom(tabs[0].id);
          }, page.url());
          assert.equal(row.zoom, 2, 'DETAILS_ZOOM_FACTOR');
          const stable = await page.evaluate(() => new Promise(resolve => {
            let count = 0, frame;
            const timer = setTimeout(() => { cancelAnimationFrame(frame); resolve(false); }, 2000);
            const sample = () => {
              count = Math.abs(parseFloat(getComputedStyle(document.body).fontSize) - 25.2) < .1 ? count + 1 : 0;
              if (count >= 3) { clearTimeout(timer); resolve(true); }
              else frame = requestAnimationFrame(sample);
            };
            frame = requestAnimationFrame(sample);
          }));
          assert(stable, 'DETAILS_ZOOM_FONT_UNSTABLE');
        };
        await sprawdzScenariuszDetails({ page, context, origin: base.origin, c, width: 320, theme, scale: 140,
          outputDir, name: `details-zoom200-${c.id}-${theme}`, row, requests, dpr: 2, przygotuj });
      } catch (error) {
        row.pass = false;
        row.error = /^DETAILS_[A-Z_]+/.exec(String(error?.message))?.[0] || 'DETAILS_ZOOM_BROWSER';
        if (page) await screenshot(page, join(outputDir, `details-zoom200-${c.id}-${theme}-FAIL.png`)).catch(() => {});
      } finally {
        rows.push(row);
        try { save(); } finally { await context?.close(); }
      }
    }
    assert.equal(rows.length, 6, 'DETAILS_ZOOM_COUNT');
    assert.equal(requests.length, 0, 'DETAILS_FORBIDDEN_REQUEST');
    assert(rows.every(row => row.pass), 'DETAILS_ZOOM_CASE_FAILED');
    return { rows, requests };
  } finally {
    try { save(); } finally { rmSync(directory, { recursive: true, force: true }); }
  }
}
