/* Rzeczywisty kreator: trzy kroki, dwa motywy, 320/390 px i czcionka
   przeglądarki 16/32 px. Nie publikuje przepisu ani nie wysyła poczty.
   Domyślnie używa bazy przygotowanej przez port-projektu.mjs. Można podać
   ADRES i SZKIC_ID istniejącego lokalnego szkicu, bez tworzenia danych. */
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

const env = { ...process.env, DB_DATABASE: process.env.DB_DATABASE || 'kuking_port_pomiar' };
assert.match(env.DB_DATABASE, /^[a-zA-Z0-9_]+$/, 'Nieprawidłowa nazwa bazy pomiarowej');
let adres = process.env.ADRES;
let szkic = process.env.SZKIC_ID;
let serwer;
let browser;

async function wolnyPort() {
  const socket = createServer();
  await new Promise((resolve, reject) => { socket.once('error', reject); socket.listen(0, '127.0.0.1', resolve); });
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  return port;
}

try {
  if (adres) assert(['127.0.0.1', 'localhost'].includes(new URL(adres).hostname), 'Pomiar zapisuje szkic tylko lokalnie');
  if (!szkic) {
    // Sprawdzamy także konfigurację odczytaną przez Laravel: cache config
    // nie może skierować fabryki do innej bazy niż deklarowana zmienna.
    szkic = execFileSync('php', ['artisan', 'tinker', '--execute', `
      $baza = config('database.connections.'.config('database.default').'.database');
      if ($baza !== '${env.DB_DATABASE}' || (!str_ends_with($baza, '_pomiar') && !str_starts_with($baza, 'kuking_qa_'))) {
          throw new RuntimeException('Kreator wymaga osobnej bazy pomiarowej');
      }
      $osoba = App\\Models\\User::whereHas('profile', fn($q) => $q->where('username', 'ania'))->firstOrFail();
      $szkic = App\\Models\\Recipe::factory()->draft()->create([
          'author_id' => $osoba->getKey(), 'title' => 'Lokalny pomiar kroków kreatora', 'visibility' => 'private',
      ]);
      echo $szkic->getKey();
    `], { env, encoding: 'utf8' }).trim();
  }
  assert.match(szkic, /^[0-9a-f-]{36}$/i, 'Brak prawdziwego szkicu do pomiaru');
  if (!adres) {
    const port = await wolnyPort();
    adres = `http://127.0.0.1:${port}`;
    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
    serwer = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { env, stdio: 'ignore' });
    let gotowy = false;
    for (let proba = 0; proba < 60; proba++) {
      try { if ((await fetch(adres + '/health')).ok) { gotowy = true; break; } } catch { /* start serwera */ }
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    assert(gotowy, 'Serwer pomiarowy nie wystartował');
  }
  browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
  const page = await browser.newPage({ viewport: { width: 390, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
  await page.goto(adres + '/login');
  await page.fill('[name="login"]', 'ania');
  await page.fill('[name="password"]', 'haslo-testowe-123');
  await Promise.all([page.waitForURL(u => !u.pathname.endsWith('/login')), page.click('button[type="submit"]')]);
  const response = await page.goto(`${adres}/dodaj/przepis?szkic=${szkic}`);
  assert.equal(response.status(), 200);
  const cdp = await page.context().newCDPSession(page);
  const wyniki = [];
  mkdirSync('storage/kroki-kreatora', { recursive: true });
  for (let krok = 1; krok <= 3; krok++) {
    await page.locator('.wizard-steps-current').filter({ hasText: `Krok ${krok} z 3` }).waitFor();
    for (const width of [320, 390]) for (const font of [16, 32]) for (const dark of [false, true]) {
      await page.setViewportSize({ width, height: 900 });
      await cdp.send('Page.setFontSizes', { fontSizes: { standard: font, fixed: font } });
      await page.evaluate(dark => { document.documentElement.dataset.theme = dark ? 'dark' : 'light'; delete document.documentElement.dataset.textScale; }, dark);
      await page.evaluate(async () => { await document.fonts.ready; for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame); });
      const wynik = await page.evaluate(() => {
        const box = e => { const r = e.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; };
        return { scroll: document.documentElement.scrollWidth, font: parseFloat(getComputedStyle(document.documentElement).fontSize),
          rodzic: box(document.querySelector('.wizard-steps')), etykieta: box(document.querySelector('.wizard-steps-current')),
          pasek: box(document.querySelector('.wizard-steps-track')), kropki: [...document.querySelectorAll('.wizard-steps-dot')].map(box) };
      });
      const dane = { krok, width, font, dark, ...wynik };
      wyniki.push(dane);
      writeFileSync('storage/kroki-kreatora/wyniki.json', JSON.stringify(wyniki, null, 2));
      assert.equal(wynik.font, font, 'Nie zastosowano rzeczywistej skali czcionki');
      assert(wynik.scroll <= width + 1, `KROKI_OVERFLOW ${JSON.stringify(dane)}`);
      assert.equal(wynik.kropki.length, 3, 'Nie można uzyskać sukcesu przez usunięcie wskaźnika');
      for (const element of [wynik.etykieta, wynik.pasek, ...wynik.kropki]) {
        assert(element.width > 0 && element.left >= wynik.rodzic.left - 1 && element.right <= wynik.rodzic.right + 1,
          `KROKI_POZA_RAMKA ${JSON.stringify(dane)}`);
      }
      if (width === 320 && font === 32) {
        await page.locator('.wizard-steps').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `storage/kroki-kreatora/krok-${krok}-${dark ? 'dark' : 'light'}.png` });
      }
    }
    if (krok < 3) await page.locator('[wire\\:click="next"]').click();
  }
  assert.equal(wyniki.length, 24, 'Każdy krok musi mieć pełną macierz pomiaru');
  console.log('Kroki kreatora: 24/24 warianty poprawne; szkic pozostał nieopublikowany.');
} finally {
  await browser?.close();
  serwer?.kill('SIGTERM');
}
