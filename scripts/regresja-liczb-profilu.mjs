// Wykonuje dokładnie funkcje pomiaru ze skanera, bez drugiej implementacji.
import { chromium } from 'playwright';
import { readFileSync, writeFileSync, mkdtempSync, statSync, realpathSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';

const address = process.env.ADRES || 'http://127.0.0.1:8203';
if (!['localhost', '127.0.0.1'].includes(new URL(address).hostname)) throw new Error('LOCAL_ONLY');
const source = 'resources/views/pages/profile/show.blade.php';
if (!realpathSync(source).startsWith(realpathSync(process.cwd()) + '/')) throw new Error('SOURCE_OUTSIDE_CHECKOUT');
execFileSync('git', ['ls-files', '--error-unmatch', source]);
const scanner = readFileSync('scripts/dostepnosc.mjs', 'utf8');
const start = scanner.indexOf('function pomiarLiczbProfilu()');
const end = scanner.indexOf('// END POMIAR_LICZB_PROFILU');
if (start < 0 || end <= start) throw new Error('MEASUREMENT_MARKERS_MISSING');
const shared = scanner.slice(start, end);
const { pomiarLiczbProfilu, bledyLiczbProfilu } = new Function(shared + '; return {pomiarLiczbProfilu, bledyLiczbProfilu};')();
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
const md5 = p => createHash('md5').update(readFileSync(p)).digest('hex');
const backup = mkdtempSync(tmpdir() + '/profile-counters-negative-') + '/show.blade.php';
execFileSync('cp', ['-p', source, backup]);
const original = readFileSync(source, 'utf8'), hash = md5(source), mtime = statSync(source).mtimeMs;
const band = original.match(/    <div class="marka-profil-statystyki">[\s\S]*?<\/div>/)?.[0];
if (!band) throw new Error('SOURCE_MARKER_MISSING');
try {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(address + '/login');
  await page.locator('[name=login]').fill(process.env.KONTO || 'ania');
  await page.locator('[name=password]').fill(process.env.HASLO || 'haslo-testowe-123');
  await Promise.all([page.waitForURL('**/home'), page.locator('.panel-formularza button[type=submit]').click()]);
  const session = await context.storageState();
  await context.close();
  const probe = async (full = true) => {
    const results = [];
    for (const width of (full ? [360, 1280, 1512] : [1512])) {
      for (const [path, logged] of (full ? [['/@basia', false], ['/@basia', true], ['/@ania', true]] : [['/@ania', true]])) {
        const ctx = await browser.newContext({ viewport: { width, height: 900 }, ...(logged ? { storageState: session } : {}) });
        try {
          const p = await ctx.newPage();
          const response = await p.goto(address + path, { waitUntil: 'networkidle' });
          if (response.status() !== 200) throw new Error('HTTP_' + response.status());
          await p.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
          const measured = await p.evaluate(pomiarLiczbProfilu);
          results.push({ width, path, logged, errors: bledyLiczbProfilu(measured), measured });
        } finally { await ctx.close(); }
      }
    }
    return results;
  };
  const positive = async () => {
    const results = await probe();
    if (results.some(r => r.errors.length)) throw new Error('POSITIVE_FAILED ' + JSON.stringify(results));
    console.log('PROFILE_COUNTER_POSITIVE_OK 9/9');
  };
  await positive();
  const cases = [
    ['position', 'PROFILE_COUNTER_POSITION', original.replace(band, '').replace('    </header>', band + '\n    </header>')],
    ['missing', 'PROFILE_COUNTER_COPIES', original.replace(band, '')],
    ['duplicate', 'PROFILE_COUNTER_COPIES', original.replace(band, band + '\n' + band)],
  ];
  for (const [name, expected, mutated] of cases) {
    let result;
    try {
      writeFileSync(source, mutated);
      execFileSync('php', ['artisan', 'view:clear']);
      result = await probe(false);
    } finally {
      execFileSync('cp', ['-p', backup, source]);
      execFileSync('php', ['artisan', 'view:clear']);
      if (md5(source) !== hash || statSync(source).mtimeMs !== mtime) throw new Error('RESTORE_FAILED');
    }
    await positive();
    if (!result.every(r => r.errors.some(error => error.startsWith(expected)))) throw new Error('NEGATIVE_WRONG_RESULT ' + name + ' ' + JSON.stringify(result));
    console.log(`PROFILE_COUNTER_NEGATIVE_OK ${name} ${expected} MD5=${hash} mtime=restored`);
  }
} finally { await browser.close(); }
