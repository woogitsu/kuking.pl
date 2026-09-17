// Kontrola prawdziwego arkusza w izolowanej bazie #638. Nigdy na produkcji.
import { copyFileSync, mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { sprawdzEtykietyDolnejNawigacji } from './nawigacja-etykiety.mjs';

export async function sprawdzNegatywNawigacji({ browser, adres, sesja, outputDir, duzyFont = false }) {
  if (process.env.GITHUB_ACTIONS === 'true' || process.env.DB_PORT !== '55439'
    || process.env.DB_DATABASE !== (duzyFont ? 'kuking_638_font_negative' : 'kuking_638_negative') || !['127.0.0.1', 'localhost'].includes(new URL(adres).hostname)) throw new Error('NAV638_IZOLACJA');
  const backup = mkdtempSync(join(tmpdir(), 'kuking-nav638-negative-'));
  const source = resolve('resources/css/marka-rama.css');
  const saved = join(backup, 'marka-rama.css');
  const md5 = () => createHash('md5').update(readFileSync(source)).digest('hex');
  const before = { md5: md5(), mtime: statSync(source, { bigint: true }).mtimeNs.toString(), atime: statSync(source, { bigint: true }).atimeNs.toString() };
  copyFileSync(source, saved);
  const build = () => execFileSync('npm', ['run', 'build'], { stdio: 'pipe' });
  const probe = async () => {
    const context = await browser.newContext({ storageState: sesja, viewport: { width: 305, height: 900 }, reducedMotion: 'reduce' });
    try {
      const page = await context.newPage();
      if (duzyFont) await (await context.newCDPSession(page)).send('Page.setFontSizes', {fontSizes:{standard:32,fixed:32}});
      if ((await page.goto(`${adres}/home`, {waitUntil:'networkidle'})).status() !== 200) throw new Error('NAV638_HTTP');
      await page.evaluate(async scale => { document.documentElement.dataset.textScale=String(scale); await document.fonts.ready; }, duzyFont ? 140 : 100);
      await page.waitForFunction(font=>Math.abs(parseFloat(getComputedStyle(document.body).fontSize)-font)<.15,duzyFont?50.4:18);
      return await sprawdzEtykietyDolnejNawigacji(page);
    } finally { await context.close(); }
  };
  const baseline = await probe();
  const original = readFileSync(source, 'utf8');
  const block = duzyFont
    ? /\[data-marka\] :where\(\.bottom-nav:not\(\.bottom-nav-panel\)\) > \.bottom-nav-item:not\(\.bottom-nav-item-glowna\) \{[^}]+\}/g
    : /\[data-marka\] :where\(\.bottom-nav:not\(\.bottom-nav-panel\)\) > \.bottom-nav-item \{[^}]+\}/g;
  if ([...original.matchAll(block)].length !== 1) throw new Error('NAV638_CEL_MUTACJI');
  let failure;
  try {
    writeFileSync(source, original.replace(block, ''));
    build();
    try { await probe(); } catch(error) { failure = error.message; }
  } finally {
    copyFileSync(saved, source);
    execFileSync('python3', ['-c', 'import os,sys; os.utime(sys.argv[1], ns=(int(sys.argv[2]),int(sys.argv[3])))', source, before.atime, before.mtime]);
    if (md5() !== before.md5 || statSync(source, {bigint:true}).mtimeNs.toString() !== before.mtime) throw new Error('NAV638_PRZYWROCENIE');
    build();
  }
  const detected = failure?.startsWith('NAV638_ETYKIETY ');
  const positive = await probe();
  writeFileSync(join(outputDir,duzyFont?'nav638-font-negative.json':'nav638-negative.json'),JSON.stringify({backup,source:'resources/css/marka-rama.css',before,restoredMd5:md5(),restoredMtimeNs:statSync(source,{bigint:true}).mtimeNs.toString(),detected,failure:failure?.startsWith('NAV638_')?failure.split(' ')[0]:'NIEZNANY',baseline,positive},null,2));
  if (!detected) throw new Error('NAV638_NEGATYW_NIEWYKRYTY');
  return {detected:true,restored:true};
}