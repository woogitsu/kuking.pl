// Fizyczne kontrole ujemne wyłącznie w izolowanym lokalnym runtime.
import { copyFileSync, mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { sprawdzZwijaniePanelu } from './panel-marki.mjs';

export async function sprawdzNegatywyMenu({ browser, adres, sesja, outputDir }) {
  if (process.env.GITHUB_ACTIONS === 'true' || process.env.DB_PORT !== '55439'
    || process.env.DB_DATABASE !== 'kuking_581_menu_extra' || !['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('P581_NEGATYW_IZOLACJA');
  const backup = mkdtempSync(join(tmpdir(), 'kuking-menu-negative-'));
  const md5 = path => createHash('md5').update(readFileSync(path)).digest('hex');
  const build = () => execFileSync('npm', ['run', 'build:assets'], { stdio: 'pipe' });
  const probe = async () => {
    const context = await browser.newContext({ storageState: sesja, viewport: { width: 320, height: 900 }, reducedMotion: 'reduce' });
    try {
      const page = await context.newPage();
      if ((await page.goto(`${adres}/admin/zgloszenia`, { waitUntil: 'load' })).status() !== 200) throw new Error('P581_NEGATYW_HTTP');
      await sprawdzZwijaniePanelu(page);
    } finally { await context.close(); }
  };
  const wyniki = [];
  const mutations = [
    ['resources/js/panel-menu.js', text => {
      const from = 'content.hidden = !desktop.matches && !expanded;';
      if (!text.includes(from)) throw new Error('P581_NEGATYW_JS_CEL');
      return text.replace(from, 'content.hidden = false;');
    }],
    ['resources/css/marka-panel.css', text => text + '\n[data-marka-panel] .panel-menu-przelacznik:not([hidden]) { visibility: hidden !important; }\n'],
  ];
  for (const [relative, mutate] of mutations) {
    const source = resolve(relative), saved = join(backup, relative.endsWith('.js') ? 'menu.js' : 'panel.css');
    const before = { md5: md5(source), mtime: statSync(source, { bigint: true }).mtimeNs.toString(), atime: statSync(source, { bigint: true }).atimeNs.toString() };
    copyFileSync(source, saved);
    let failure;
    try {
      writeFileSync(source, mutate(readFileSync(source, 'utf8')));
      build();
      try { await probe(); } catch (error) { failure = error.message; }
    } finally {
      copyFileSync(saved, source);
      execFileSync('python3', ['-c', 'import os,sys; os.utime(sys.argv[1], ns=(int(sys.argv[2]),int(sys.argv[3])))', source, before.atime, before.mtime]);
      if (md5(source) !== before.md5 || statSync(source, { bigint: true }).mtimeNs.toString() !== before.mtime) throw new Error('P581_NEGATYW_PRZYWROCENIE');
      build();
    }
    if (!failure?.startsWith('P581_MENU_')) {
      writeFileSync(join(outputDir, 'menu-negatyw-blad.json'), JSON.stringify({ source: relative, failure: failure || null, restored: true }, null, 2));
      throw new Error('P581_NEGATYW_NIEWYKRYTY: ' + relative);
    }
    await probe();
    wyniki.push({ source: relative, failure, restoredMd5: before.md5, restoredMtimeNs: before.mtime, positive: true });
    writeFileSync(join(outputDir, 'menu-negatywy.json'), JSON.stringify(wyniki, null, 2));
  }
  return wyniki;
}
