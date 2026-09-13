/* Pomiar portu na prawdziwych stronach Laravel i danych demonstracyjnych. */
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { sprawdzKompozycje } from './kompozycje-marki.mjs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

/* Osobna baza pomiarowa — ten skrypt robi `migrate:fresh`. Wskazanie `kuking`
   albo `kuking_test` kasowałoby czyjąś pracę (AGENTS.md §6). */
const BAZA_DOMYSLNA = 'kuking_port_pomiar';

/* Domyślny rozmiar pisma przeglądarki; wariant 200% ustawia dwa razy tyle
   przez CDP `Page.setFontSizes`. To jest EMULACJA CZCIONKI BAZOWEJ, a nie
   powiększenie strony: piksel CSS zostaje ten sam, rosną tylko `rem` i `em`. */
const BAZOWA_CZCIONKA_PX = 16;

/* Znacznik wariantu „czcionka przeglądarki podwojona" — celowo NIE liczba,
   bo liczby znaczą tu `data-text-scale`, czyli nasze ustawienie z profilu.
   To są dwa różne mechanizmy (patrz komentarz w `scripts/dostepnosc.mjs`). */
const PRZEGLADARKA_200 = 'przegladarka-200';

const SZEROKOSCI = [
  { nazwa: '320 px', szerokosc: 320, wysokosc: 740 },
  { nazwa: '360 px', szerokosc: 360, wysokosc: 740 },
  { nazwa: '390 px', szerokosc: 390, wysokosc: 740 },
  { nazwa: '414 px', szerokosc: 414, wysokosc: 740 },
  { nazwa: '768 px', szerokosc: 768, wysokosc: 740 },
];

/* Wysokość okna 740 px w KAŻDYM wariancie, nie „naturalna" dla danego
   telefonu: pomiar mówi o stosunku wysokości kafla do wysokości okna, więc
   zmienna wysokość okna mieszałaby dwie przyczyny w jednej liczbie. 740 to ta
   sama wysokość, na której mierzy focus `scripts/dostepnosc.mjs`, żeby dało
   się liczby z obu plików położyć obok siebie. */

const WARIANTY_PISMA = [null, 140, PRZEGLADARKA_200];

function etykietaPisma(wariant) {
  if (wariant === null) return '100%';

  return wariant === PRZEGLADARKA_200 ? 'przeglądarka 200%' : `tekst ${wariant}%`;
}

function znajdzChromium() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  try {
    const wlasna = chromium.executablePath();
    if (wlasna && existsSync(wlasna)) return undefined;
  } catch { /* idziemy dalej */ }
  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

  return existsSync(deweloperska) ? deweloperska : undefined;
}

function env() {
  return { ...process.env, DB_DATABASE: process.env.DB_DATABASE || BAZA_DOMYSLNA };
}

async function wolnyPort() {
  const { createServer } = await import('node:net');

  return new Promise((resolve, reject) => {
    const gniazdo = createServer();
    gniazdo.unref();
    gniazdo.on('error', reject);
    gniazdo.listen(0, '127.0.0.1', () => {
      const { port } = gniazdo.address();
      gniazdo.close(() => resolve(port));
    });
  });
}

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zamknij: () => {} };

  console.log('Czyszczę zapamiętaną konfigurację...');
  execFileSync('php', ['artisan', 'config:clear'], { stdio: 'ignore', env: env() });

  /* Strona wciąga zbudowany `public/build/assets/app-*.css` przez manifest
     Vite — pomiar bez przebudowania opisywałby POPRZEDNIĄ wersję arkusza. */
  console.log('Buduję arkusz (vite build)...');
  execFileSync('npm', ['run', 'build'], { stdio: 'ignore', env: process.env });

  try {
    execFileSync('createdb', [env().DB_DATABASE], {
      stdio: 'ignore',
      env: {
        ...process.env,
        PGHOST: process.env.DB_HOST || '127.0.0.1',
        PGUSER: process.env.DB_USERNAME || 'kuking',
        PGPASSWORD: process.env.DB_PASSWORD || 'kuking',
      },
    });
  } catch { /* baza już istnieje */ }

  console.log(`Przygotowuję dane demonstracyjne w bazie ${env().DB_DATABASE}...`);
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--seeder=DemoSeeder', '--force'], {
    stdio: 'ignore',
    env: env(),
  });

  const bledy = [];

  for (let podejscie = 1; podejscie <= 3; podejscie++) {
    const port = await wolnyPort();
    const adres = `http://127.0.0.1:${port}`;
    const dziennik = [];

    const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: env(),
    });

    proces.stdout.on('data', (b) => dziennik.push(String(b)));
    proces.stderr.on('data', (b) => dziennik.push(String(b)));

    let umarl = null;
    proces.on('exit', (kod) => { umarl = kod; });

    let wstal = false;

    for (let i = 0; i < 60 && umarl === null; i++) {
      try {
        const odp = await fetch(`${adres}/health`);
        if (odp.ok) { wstal = true; break; }
      } catch { /* jeszcze nie wstał */ }
      await new Promise((r) => setTimeout(r, 500));
    }

    if (wstal) return { adres, zamknij: () => proces.kill('SIGTERM') };

    proces.kill('SIGKILL');
    bledy.push(`  podejście ${podejscie}, port ${port}: `
      + (umarl !== null ? `proces zakończył się kodem ${umarl}` : 'brak odpowiedzi z /health')
      + (dziennik.length > 0 ? `\n${dziennik.join('').trimEnd()}` : ''));
  }

  throw new Error(`Nie udało się podnieść \`php artisan serve\`.\n${bledy.join('\n')}`);
}


const CHROMIUM = znajdzChromium();
const { adres, zamknij } = await podniesSerwer();

console.log(`Serwer: ${adres}`);

const przegladarka = await chromium.launch({ executablePath: CHROMIUM });

/* Logujemy się RAZ i przenosimy ciasteczko przez `storageState`: piętnaście
   logowań pod rząd z jednego adresu trafia na `throttle` i pomiar leciałby
   na ekranie „Za dużo prób" zamiast na tablicy. */
const kontekstLogowania = await przegladarka.newContext({ viewport: { width: 390, height: 844 } });
const stronaLogowania = await kontekstLogowania.newPage();

await stronaLogowania.goto(`${adres}/login`);
await stronaLogowania.fill('input[name="login"]', KONTO);
await stronaLogowania.fill('input[name="password"]', HASLO);
await Promise.all([
  stronaLogowania.waitForURL((u) => ! u.pathname.endsWith('/login'), { timeout: 15000 }),
  stronaLogowania.click('button[type="submit"]'),
]);

const sesja = await kontekstLogowania.storageState();

await kontekstLogowania.close();


const { mkdirSync, readFileSync, appendFileSync, mkdtempSync } = await import('node:fs');
const { createHash } = await import('node:crypto');
const { tmpdir } = await import('node:os');
mkdirSync('storage/port-projektu', { recursive: true });
const slugPrzepisu = execFileSync('php', ['artisan', 'tinker', '--execute',
  "echo optional(App\\Models\\Recipe::where('status','published')->where('visibility','public')->orderBy('id')->first())->slug;",
], { env: env() }).toString().trim();
if (!slugPrzepisu) throw new Error('Brak przepisu do pomiaru marki');
const przepis = '/przepisy/' + slugPrzepisu;
const ekranyMarki = ['/home', '/', '/odkryj', '/szukaj', '/zeszyt', '/@ania', '/@zofia_z_bieszczad', przepis, '/dodaj', '/ustawienia', '/ustawienia/czytelnosc', '/powiadomienia'];
const wyniki = [];
const pomiarLanding = async (page, width) => {
  const response = await page.goto(adres + '/', { waitUntil: 'networkidle' });
  if (response.status() !== 200) throw new Error('LANDING_HTTP: ' + response.status());
  await page.evaluate(async () => { await document.fonts.ready; for (let i = 0; i < 30; i++) await new Promise(requestAnimationFrame); });
  const result = await page.evaluate(() => {
    const title = document.querySelector('.landing-opowiesc-tytul');
    const card = document.querySelector('.landing-wykonanie-karta');
    const steps = [...document.querySelectorAll('.landing-kroki > li')];
    const ratio = parseFloat(getComputedStyle(document.body).fontSize) / 18;
    return {
      scroll: document.documentElement.scrollWidth, ratio,
      title: title ? parseFloat(getComputedStyle(title).fontSize) : 0,
      card: card ? { background: getComputedStyle(card).backgroundColor, radius: parseFloat(getComputedStyle(card).borderTopLeftRadius) } : null,
      steps: steps.map(el => ({ background: getComputedStyle(el).backgroundColor, shadow: getComputedStyle(el).boxShadow, radius: parseFloat(getComputedStyle(el).borderRadius), border: parseFloat(getComputedStyle(el).borderTopWidth), y: el.getBoundingClientRect().y })),
      columns: matchMedia('(min-width: 48rem)').matches,
      photo: document.querySelector('.landing-wykonanie-zdjecie img')?.getBoundingClientRect().width || 0,
    };
  });
  if (result.scroll > width + 1) throw new Error('LANDING_OVERFLOW: ' + JSON.stringify(result));
  if (result.title < 32 * result.ratio - 1) throw new Error('LANDING_TYTUL: ' + JSON.stringify(result));
  if (result.steps.length !== 3 || result.steps.some(s => s.background !== 'rgba(0, 0, 0, 0)' || s.shadow !== 'none' || s.radius !== 0 || s.border < 1)) throw new Error('LANDING_KROKI: ' + JSON.stringify(result));
  if (result.columns && result.steps.some(s => Math.abs(s.y - result.steps[0].y) > 1)) throw new Error('LANDING_KOLUMNY: ' + JSON.stringify(result));
  if (!result.card || result.card.background !== 'rgb(21, 23, 20)' || result.card.radius < 28) throw new Error('LANDING_BLOK: ' + JSON.stringify(result));
  if (result.photo < 100) throw new Error('LANDING_ZDJECIE: brak fotografii do pomiaru');
  return result;
};
const pomiar = async (page, width, path) => {
  const response = await page.goto(`${adres}${path}`, { waitUntil: 'networkidle' });
  if (response.status() !== 200) throw new Error(`${path}: HTTP ${response.status()}`);
  await page.evaluate(() => document.fonts.ready);
  const result = await page.evaluate(() => {
    const main = document.querySelector('.app-main').getBoundingClientRect();
    const nav = document.querySelector('.marka-nawigacja');
    const top = document.querySelector('.marka-topbar');
    return { width: innerWidth, scroll: document.documentElement.scrollWidth,
      main: main.width, radius: getComputedStyle(top).borderTopLeftRadius,
      navigation: nav ? getComputedStyle(nav).display : null,
      brand: document.body.dataset.marka,
      heading: document.querySelector('h1')?.textContent.trim(),
      profil: document.querySelector('.marka-profil') ? getComputedStyle(document.querySelector('.marka-profil')).backgroundColor : null,
      przepisFont: document.querySelector('.przepis-uklad > header h1') ? parseFloat(getComputedStyle(document.querySelector('.przepis-uklad > header h1')).fontSize) : null,
      rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize),
      bodyFont: parseFloat(getComputedStyle(document.body).fontSize),
      tablica: (() => {
        const rozmiary = selector => [...document.querySelectorAll(selector)].map(el => {
          const box = el.getBoundingClientRect();
          return { width: box.width, height: box.height, font: parseFloat(getComputedStyle(el).fontSize) };
        });
        return {
          wrappers: rozmiary('.marka-tablica .kuking-board-avatar'),
          avatars: rozmiary('.marka-tablica .kuking-board-avatar .avatar'),
          photos: rozmiary('.marka-tablica .kuking-board-post-photo img'),
        };
      })(),
      opisy: [...document.querySelectorAll('.ustawienia-nawigacja-tu, .ustawienia-nawigacja-opis')].map(el => parseFloat(getComputedStyle(el).fontSize)),
      wybory: [...document.querySelectorAll('.panel-formularza .choice-help')].map(el => parseFloat(getComputedStyle(el).fontSize)),
      wzor: document.querySelector('.start-naglowek') ? (() => {
        const composer = document.querySelector('.marka-publikacja');
        const intro = document.querySelector('.marka-tablica-wstep');
        const people = document.querySelector('.marka-tablica .kuking-board-kolumna');
        const ring = getComputedStyle(composer, '::after');
        return {
          tytul: parseFloat(getComputedStyle(composer.querySelector('.composer-title')).fontSize),
          pierscien: [parseFloat(ring.width), parseFloat(ring.borderTopWidth)],
          wstep: intro && getComputedStyle(intro).backgroundColor,
          kartaOsob: people && getComputedStyle(people).backgroundColor,
          powierzchnia: getComputedStyle(top).backgroundColor,
          menu: [...nav.querySelectorAll('a')].map(a => a.textContent.trim()),
          szerokosc: composer.getBoundingClientRect().width,
          akcje: composer.querySelector('.marka-publikacja-akcje').getBoundingClientRect().width,
          szynaX: intro?.getBoundingClientRect().x,
          glownaPrawa: composer.getBoundingClientRect().right,
        };
      })() : null,
    };
  });
  if (result.brand !== 'kuking-2026') throw new Error('Brak portu marki');
  if (['/home', '/', '/odkryj', '/szukaj'].includes(path)) {
    const t = result.tablica;
    const zlyRozmiar = (el, size) => Math.abs(el.width - size) > 0.5 || Math.abs(el.height - size) > 0.5;
    if (!t.wrappers.length || !t.avatars.length || !t.photos.length) throw new Error('TABLICA_DANE: brak osób lub zdjęć do pomiaru ' + path);
    if (t.wrappers.some(el => zlyRozmiar(el, 52)) || t.avatars.some(el => zlyRozmiar(el, 52) || Math.abs(el.font - result.bodyFont) > 0.5)) throw new Error('TABLICA_AWATAR: ' + path + ' ' + JSON.stringify(t));
    if (t.photos.some(el => zlyRozmiar(el, 72))) throw new Error('TABLICA_ZDJECIE: ' + path + ' ' + JSON.stringify(t));
  }
  if (result.scroll > width + 1) throw new Error(`${path}: poziome przewijanie ${JSON.stringify(result)}`);
  if (result.main < Math.min(width - 60, 500)) throw new Error(`WASKA_KOLUMNA: ${JSON.stringify(result)}`);
  if (result.radius !== '20px') throw new Error('Nagłówek nie używa nowego projektu');
  if (width === 1440 && result.navigation === 'none') throw new Error('Brak menu desktop');
  if (path.startsWith('/@') && result.profil !== 'rgb(21, 23, 20)') throw new Error('MARKA_PROFIL: ' + JSON.stringify(result));
  if (path === przepis && (!result.przepisFont || result.przepisFont < result.rootFont * 2.125 - 0.5)) throw new Error('MARKA_TYTUL: ' + JSON.stringify(result));
  if (path.startsWith('/ustawienia') && (!result.opisy.length || result.opisy.some(size => size < result.bodyFont - 0.5))) throw new Error('MARKA_OPISY: ' + JSON.stringify(result));
  if (path === '/ustawienia/czytelnosc' && (!result.wybory.length || result.wybory.some(size => size < result.bodyFont - 0.5))) throw new Error('MARKA_WYBORY: ' + JSON.stringify(result));
  if (path === '/home' || path === '/') {
    const w = result.wzor;
    const braki = [];
    if (!w) throw new Error('KOMPOZYCJA: brak nagłówka startu');
    if (w.tytul < result.bodyFont * 28 / 18 - 0.5) braki.push('TYTUL_KAFLA');
    if (w.pierscien[0] < 200 || w.pierscien[1] < 30) braki.push('PIERSCIEN');
    if (w.wstep !== 'rgb(21, 23, 20)') braki.push('CIEMNY_WSTEP');
    if (w.kartaOsob !== w.powierzchnia) braki.push('KARTA_OSOB');
    if (w.menu.join('|') !== 'Start|Odkrywaj|Mój zeszyt') braki.push('MENU');
    if (width >= 1280 && result.rootFont <= 16) {
      if (w.szerokosc < 740 || w.szerokosc > 760 || w.szynaX - w.glownaPrawa < 39) braki.push('KOLUMNY');
      if (w.akcje > 501) braki.push('SZEROKOSC_AKCJI');
    }
    if (braki.length) throw new Error('KOMPOZYCJA: ' + braki.join(', '));
  }
  return result;
};
try {
  for (const width of [320, 360, 390, 414, 768, 1440]) {
    for (const dark of [false, true]) {
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
      const page = await context.newPage();
      await page.addInitScript((dark) => { if (dark) document.addEventListener('DOMContentLoaded', () => document.documentElement.dataset.theme = 'dark'); }, dark);
      for (const path of ekranyMarki) {
        const result = await pomiar(page, width, path);
        wyniki.push({ path, dark, ...result });
        if (!dark && ((width === 390 && ['/home', '/@ania', '/szukaj', '/@zofia_z_bieszczad', przepis, '/ustawienia/czytelnosc'].includes(path)) || (width === 1440 && path === '/home'))) {
          const name = `${width}-${path.replaceAll('/', '').replace('@', '')}`;
          const buffer = await page.screenshot({ path: `storage/port-projektu/${name}.jpg`, type: 'jpeg', quality: 65 });
          console.log(`PORT_SCREEN_${name} ${buffer.toString('base64')}`);
        }
      }
      await context.close();
    }
  }

  for (const width of [320, 360, 390, 414, 768, 1440]) {
    for (const dark of [false, true]) {
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
      const page = await context.newPage();
      await page.addInitScript((dark) => document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.dataset.textScale = '140';
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      }), dark);
      for (const path of ['/home', '/']) wyniki.push({ text140: true, dark, path, ...await pomiar(page, width, path) });
      await context.close();
    }
  }
  for (const width of [320, 1440]) {
    const guest = await przegladarka.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    const guestPage = await guest.newPage();
    for (const path of ['/', '/login', '/register', '/o-kuking']) {
      const response = await guestPage.goto(adres + path, { waitUntil: 'networkidle' });
      if (response.status() !== 200) throw new Error(path + ': HTTP ' + response.status());
      const scroll = await guestPage.evaluate(() => document.documentElement.scrollWidth);
      if (scroll > width + 1) throw new Error(path + ': poziome przewijanie gościa ' + scroll);
      wyniki.push({ guest: true, path, width, scroll });
    }
    await guest.close();
  }
  for (const width of [320, 360, 390, 414, 768, 1440]) for (const dark of [false, true]) for (const scale of [100, 140, PRZEGLADARKA_200]) {
    const guest = await przegladarka.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
    const page = await guest.newPage();
    if (scale === PRZEGLADARKA_200) await (await guest.newCDPSession(page)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
    await page.addInitScript(({ dark, scale }) => document.addEventListener('DOMContentLoaded', () => {
      document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      document.documentElement.dataset.textScale = String(scale === 140 ? 140 : 100);
    }), { dark, scale });
    wyniki.push({ landing: true, width, dark, scale, ...await pomiarLanding(page, width) });
    if ((width === 1440 && scale === 100) || (width === 320 && scale === 140)) {
      await page.locator('#jak-dziala').screenshot({ path: `storage/port-projektu/landing-kroki-${width}-${dark}.png` });
      await page.locator('#ugotowalem').screenshot({ path: `storage/port-projektu/landing-wykonanie-${width}-${dark}.png` });
    }
    await guest.close();
  }
  const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 320, height: 740 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
  for (const path of ekranyMarki) {
    wyniki.push({ largeText: true, path, ...await pomiar(page, 320, path) });
  }
  await context.close();

  await sprawdzKompozycje({ browser: przegladarka, adres, sesja, przepis });

  // Kontrola ujemna zmienia źródło CSS, nie wynik pomiaru ani atrapę DOM.
  const source = 'resources/css/marka-rama.css';
  const copy = `${mkdtempSync(`${tmpdir()}/kuking-port-`)}/marka-rama.css`;
  const hash = () => createHash('md5').update(readFileSync(source)).digest('hex');
  const before = hash();
  execFileSync('cp', [source, copy]);
  try {
    appendFileSync(source, '\n[data-marka] .marka-rama:not([data-tryb-panelu]) { width: 80px; }\n');
    console.log(`KONTROLA_UJEMNA przed=${before} zmieniony=${hash()}`);
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    const negativeContext = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
    const negativePage = await negativeContext.newPage();
    let detected = false;
    try { await pomiar(negativePage, 390, '/home'); }
    catch (error) { if (/WASKA_KOLUMNA|poziome przewijanie/.test(error.message)) { detected = true; console.log(`KONTROLA_UJEMNA wykryto=${error.message}`); } else throw error; }
    await negativeContext.close();
    if (!detected) throw new Error('Kontrola ujemna nie wykryła zwężenia strony');
  } finally {
    execFileSync('cp', [copy, source]);
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    if (hash() !== before) throw new Error('Źródło nie zostało odtworzone');
    console.log(`KONTROLA_UJEMNA przywrocony=${hash()}`);
  }
  const restored = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
  await pomiar(await restored.newPage(), 390, '/home');
  await restored.close();
  // Każda regresja dotyczy rzeczywistego arkusza; jeden build, cztery różne ekrany.
  execFileSync('cp', [source, copy]);
  try {
    appendFileSync(source, '\n[data-marka] .marka-profil.blok-ciemny { background-color: #fff; }\n[data-marka] .przepis-uklad > header h1 { font-size: 12px; }\n[data-marka] .ustawienia-nawigacja-opis { font-size: 12px; }\n[data-marka] .panel-formularza .choice-help { font-size: 12px; }\n');
    console.log('MARKA_UJEMNA przed=' + before + ' zmieniony=' + hash());
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    for (const [path, kod] of [['/@zofia_z_bieszczad', 'MARKA_PROFIL'], [przepis, 'MARKA_TYTUL'], ['/ustawienia', 'MARKA_OPISY']]) {
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
      let wykryto = false;
      try { await pomiar(await context.newPage(), 390, path); }
      catch (error) { if (error.message.startsWith(kod)) wykryto = true; else throw error; }
      finally { await context.close(); }
      if (!wykryto) throw new Error('Kontrola ujemna nie wykryła ' + kod);
      console.log('MARKA_UJEMNA wykryto=' + kod);
    }
    // Opisy ustawień nie mogą zamaskować osobnego sprawdzenia opisów wyboru.
    execFileSync('cp', [copy, source]);
    appendFileSync(source, '\n[data-marka] .panel-formularza .choice-help { font-size: 12px; }\n');
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
    let wykryto = false;
    try { await pomiar(await context.newPage(), 390, '/ustawienia/czytelnosc'); }
    catch (error) { if (error.message.startsWith('MARKA_WYBORY')) wykryto = true; else throw error; }
    finally { await context.close(); }
    if (!wykryto) throw new Error('Kontrola ujemna nie wykryła MARKA_WYBORY');
    console.log('MARKA_UJEMNA wykryto=MARKA_WYBORY');
  } finally {
    execFileSync('cp', [copy, source]);
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    if (hash() !== before) throw new Error('Źródło marki nie zostało odtworzone');
    console.log('MARKA_UJEMNA przywrocony=' + hash());
  }
  const kontekstPrzywrocony = await przegladarka.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
  const stronaPrzywrocona = await kontekstPrzywrocony.newPage();
  for (const path of ['/@zofia_z_bieszczad', przepis, '/ustawienia', '/ustawienia/czytelnosc']) await pomiar(stronaPrzywrocona, 390, path);
  await kontekstPrzywrocony.close();

  // Wzorzec właściciela: sabotaż czterech niezależnych cech rzeczywistego CSS.
  // Pomiar zbiera wszystkie braki, więc pierwszy nie maskuje pozostałych.
  execFileSync('cp', [source, copy]);
  try {
    appendFileSync(source, '\n[data-marka] .marka-publikacja .composer-title { font-size: 14px; }\n[data-marka] .marka-publikacja::after { border-width: 0; }\n[data-marka] .marka-tablica-wstep { background: #fff; }\n[data-marka] .marka-tablica .kuking-board-kolumna { background: transparent; }\n');
    console.log('KOMPOZYCJA_UJEMNA przed=' + before + ' zmieniony=' + hash());
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
    let blad = '';
    try { await pomiar(await context.newPage(), 1440, '/home'); }
    catch (error) { blad = error.message; }
    finally { await context.close(); }
    for (const kod of ['TYTUL_KAFLA', 'PIERSCIEN', 'CIEMNY_WSTEP', 'KARTA_OSOB']) {
      if (!blad.startsWith('KOMPOZYCJA:') || !blad.includes(kod)) throw new Error('Niewykryty sabotaż ' + kod + ': ' + blad);
      console.log('KOMPOZYCJA_UJEMNA wykryto=' + kod);
    }
  } finally {
    execFileSync('cp', [copy, source]);
    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
    if (hash() !== before) throw new Error('Nie odtworzono CSS po kontroli kompozycji');
    console.log('KOMPOZYCJA_UJEMNA przywrocony=' + hash());
  }
  const odbior = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
  await pomiar(await odbior.newPage(), 1440, '/home');
  await odbior.close();
  // Issue #503: regresja selektora szyny nie może przejść tylko dlatego,
  // że /home ma poprawny rozmiar. Mutujemy prawdziwy CSS i mierzymy /odkryj.
  for (const [selector, declaration, kod] of [
    ['.marka-tablica .kuking-board-avatar, [data-marka] .marka-tablica .kuking-board-avatar .avatar', 'width: 120px; height: 120px;', 'TABLICA_AWATAR'],
    ['.marka-tablica .kuking-board-post-photo img', 'width: 120px; height: 120px;', 'TABLICA_ZDJECIE'],
  ]) {
    execFileSync('cp', ['-p', source, copy]);
    try {
      appendFileSync(source, `\n[data-marka] ${selector} { ${declaration} }\n`);
      console.log('TABLICA_UJEMNA przed=' + before + ' zmieniony=' + hash());
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      const context = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
      let wykryto = false;
      try { await pomiar(await context.newPage(), 1440, '/odkryj'); }
      catch (error) { if (error.message.startsWith(kod)) wykryto = true; else throw error; }
      finally { await context.close(); }
      if (!wykryto) throw new Error('Niewykryty sabotaż ' + kod);
      console.log('TABLICA_UJEMNA wykryto=' + kod);
    } finally {
      execFileSync('cp', ['-p', copy, source]);
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      if (hash() !== before) throw new Error('Nie odtworzono CSS po kontroli tablicy');
      console.log('TABLICA_UJEMNA przywrocony=' + hash());
    }
  }
  const tablicaOdbior = await przegladarka.newContext({ storageState: sesja, viewport: { width: 1440, height: 900 } });
  for (const path of ['/home', '/', '/odkryj', '/szukaj']) await pomiar(await tablicaOdbior.newPage(), 1440, path);
  await tablicaOdbior.close();
  // D-208: rzeczywista kompozycja strony publicznej, nie sam brak overflow.
  for (const [selector, declaration, kod] of [
    ['.landing-opowiesc-tytul', 'font-size: 18px;', 'LANDING_TYTUL'],
    ['.landing-kroki > li', 'background: white; border-radius: 24px;', 'LANDING_KROKI'],
    ['.landing-wykonanie-karta', 'background: white;', 'LANDING_BLOK'],
  ]) {
    execFileSync('cp', ['-p', source, copy]);
    try {
      appendFileSync(source, `\n[data-marka] ${selector} { ${declaration} }\n`);
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      const guest = await przegladarka.newContext({ viewport: { width: 1440, height: 900 } });
      let wykryto = false;
      try { await pomiarLanding(await guest.newPage(), 1440); }
      catch (error) { if (error.message.startsWith(kod)) wykryto = true; else throw error; }
      finally { await guest.close(); }
      if (!wykryto) throw new Error('Niewykryty sabotaż ' + kod);
      console.log('LANDING_UJEMNA wykryto=' + kod + ' przed=' + before + ' zmieniony=' + hash());
    } finally {
      execFileSync('cp', ['-p', copy, source]);
      execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
      if (hash() !== before) throw new Error('Nie odtworzono CSS landingu');
      console.log('LANDING_UJEMNA przywrocony=' + hash());
    }
  }
  const landingOdbior = await przegladarka.newContext({ viewport: { width: 1440, height: 900 } });
  await pomiarLanding(await landingOdbior.newPage(), 1440);
  await landingOdbior.close();
  console.log(`PORT_OK ${JSON.stringify(wyniki)}`);
} finally {
  await przegladarka.close();
  zamknij();
}
