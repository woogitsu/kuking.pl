// Local-only physical source mutations; never enabled implicitly by CI.
import assert from 'node:assert/strict';
import { copyFileSync, mkdtempSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { przypadkiDetails, sprawdzScenariuszDetails } from './panel-details.mjs';

export async function sprawdzNegatywyDetails({ browser, adres, sesja, fixture, outputDir }) {
  assert(process.env.GITHUB_ACTIONS !== 'true' && process.env.DB_PORT === '55439'
    && process.env.DB_HOST === '127.0.0.1' && process.env.DB_DATABASE === 'kuking_581_details'
    && ['local', 'testing'].includes(process.env.APP_ENV) && process.env.MAIL_MAILER === 'array'
    && new URL(adres).hostname === '127.0.0.1', 'DETAILS_NEGATIVE_ISOLATION');
  const message = fixture.scenariusze.filter(s => s.rodzina === 'wiadomosc');
  assert.equal(message.length, 1);
  const cases = przypadkiDetails(message[0]);
  const backup = mkdtempSync(join(tmpdir(), 'kuking-details-negative-'));
  const md5 = path => createHash('md5').update(readFileSync(path)).digest('hex');
  const rebuild = () => execFileSync('npm', ['run', 'build'], { stdio: 'pipe' });
  const clearViews = () => execFileSync(process.env.PHP_BINARY || 'php', ['artisan', 'view:clear'], {
    cwd: process.cwd(), env: { ...process.env, APP_BASE_PATH: process.cwd() }, stdio: 'pipe',
  });
  const probe = async (id, stage, scale = 100) => {
    const folder = join(outputDir, stage); mkdirSync(folder, { recursive: true });
    const context = await browser.newContext({ storageState: sesja, serviceWorkers: 'block', viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    try {
      return await sprawdzScenariuszDetails({ page: await context.newPage(), context,
        origin: new URL(adres).origin, c: cases.find(c => c.id === id), width: 1440,
        theme: 'light', scale, outputDir: folder, name: stage, row: {}, requests: [] });
    } finally { await context.close(); }
  };
  const mutations = [
    { id: 'scroll-reserve', page: 'tablica', scale: 140, file: 'resources/css/marka-panel.css', expected: 'DETAILS_FOCUS_CLIPPED',
      change: s => {
        const from = '[data-marka-panel] .confirm-summary,\n[data-marka-panel] .confirm-body button {\n  scroll-margin-block: .5rem;\n}';
        assert.equal(s.split(from).length, 2); return s.replace(from, '');
      } },
    { id: 'target', page: 'kolaz', file: 'resources/css/marka-panel.css', expected: 'DETAILS_TARGET_48',
      change: s => s + '\n[data-marka-panel] .confirm-body > form > button { min-height:0!important; height:46px!important; padding-block:0!important; }\n' },
    { id: 'retention', page: 'wiadomosc', file: 'resources/css/marka-panel.css', expected: 'DETAILS_LAST_PARAGRAPH_OCCLUDED',
      change: s => s + '\n[data-marka-panel] .marka-panel-tresc > p.meta.mt-5 { opacity:0!important; }\n' },
    { id: 'retention-missing', page: 'wiadomosc', file: 'resources/views/pages/admin/wiadomosc.blade.php', expected: 'DETAILS_RETENTION_NOTICE_MISSING',
      change: s => { const from = '<p class="meta mt-5">'; assert.equal(s.split(from).length, 2); const start = s.indexOf(from), end = s.indexOf('</p>', start); assert(end > start); return s.slice(0, start) + s.slice(end + 4); } },
    { id: 'focus', page: 'kolaz', file: 'resources/css/marka-panel.css', expected: 'DETAILS_FOCUS_CLIPPED',
      change: s => s + '\n[data-marka-panel] .confirm-body > form { overflow:hidden!important; }\n' },
  ];
  mkdirSync(outputDir, { recursive: true });
  const results = [];
  for (const mutation of mutations) {
    await probe(mutation.page, mutation.id + '-baseline', mutation.scale);
    const source = resolve(mutation.file), saved = join(backup, mutation.id);
    const stat = statSync(source, { bigint: true });
    const before = { md5: md5(source), mtime: stat.mtimeNs.toString(), atime: stat.atimeNs.toString() };
    copyFileSync(source, saved);
    let failure;
    try {
      writeFileSync(source, mutation.change(readFileSync(source, 'utf8')));
      assert.notEqual(md5(source), before.md5, 'DETAILS_NEGATIVE_UNCHANGED');
      rebuild();
      if (mutation.file.endsWith('.blade.php')) clearViews();
      try { await probe(mutation.page, mutation.id + '-mutated', mutation.scale); }
      catch (error) { failure = /^DETAILS_[A-Z_0-9]+/.exec(error.message)?.[0] || 'UNEXPECTED_ERROR'; }
    } finally {
      copyFileSync(saved, source);
      execFileSync('python3', ['-c', 'import os,sys; os.utime(sys.argv[1], ns=(int(sys.argv[2]),int(sys.argv[3])))', source, before.atime, before.mtime]);
      assert.equal(md5(source), before.md5, 'DETAILS_NEGATIVE_RESTORE_MD5');
      assert.equal(statSync(source, { bigint: true }).mtimeNs.toString(), before.mtime, 'DETAILS_NEGATIVE_RESTORE_MTIME');
      if (mutation.file.endsWith('.blade.php')) clearViews();
      rebuild();
    }
    const row = { id: mutation.id, source: mutation.file, expected: mutation.expected, failure: failure || null, restored: before, positive: false };
    results.push(row);
    writeFileSync(join(outputDir, 'details-negative.json'), JSON.stringify(results, null, 2));
    assert.equal(failure, mutation.expected, 'DETAILS_NEGATIVE_NOT_DETECTED');
    await probe(mutation.page, mutation.id + '-restored', mutation.scale); row.positive = true;
    writeFileSync(join(outputDir, 'details-negative.json'), JSON.stringify(results, null, 2));
  }
  return results;
}
