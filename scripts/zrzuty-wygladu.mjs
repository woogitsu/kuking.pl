/**
 * ZRZUTY EKRANU CAŁEGO SERWISU — materiał do pracy nad wyglądem.
 *
 * PO CO OSOBNY SKRYPT, SKORO JEST `dostepnosc.mjs`
 * Tamten skrypt MIERZY (axe-core, przepełnienie w poziomie) i wynik zwraca
 * liczbami. Ten POKAZUJE, i wynik zwraca obrazkami. Do rozmowy o tym, że
 * „nie wygląda schludnie", liczby są bezużyteczne — trzeba zobaczyć.
 *
 * Świadomie NIE dopisałem tego do `dostepnosc.mjs`: tamten plik ma 1143
 * wiersze i jedno zadanie, a zrzuty przy każdym przebiegu automatu
 * dostępności to kilkadziesiąt megabajtów, których nikt tam nie chce.
 *
 * URUCHOMIENIE
 *   node scripts/zrzuty-wygladu.mjs              # własna baza kuking_zrzuty
 *   KATALOG=/tmp/zrzuty node scripts/zrzuty-wygladu.mjs
 *   ADRES=http://127.0.0.1:8123 node scripts/zrzuty-wygladu.mjs   # gotowy serwer
 *
 * UWAGA NA BAZĘ: bez `ADRES` skrypt robi `migrate:fresh --seed` na bazie
 * `kuking_zrzuty` (albo na tej z `DB_DATABASE`). To jest kasowanie danych,
 * więc nazwa bazy jest tu jawna i osobna — nie `kuking`, nie `kuking_test`.
 */

import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BAZA_DOMYSLNA = 'kuking_zrzuty';
const KATALOG = process.env.KATALOG || 'storage/app/zrzuty-wygladu';

const log = (...a) => console.log('[zrzuty]', ...a);

/*
 * Ekrany. Kolejność jest celowa: od tego, co widzi ktoś z zewnątrz, przez
 * to, co widzi zalogowany, do formularzy i dokumentów. Kto przegląda te
 * pliki po nazwie, przechodzi serwis w tej samej kolejności, w której
 * przechodzi go człowiek.
 */
const EKRANY = [
  { nazwa: '01-powitalna', adres: '/' },
  { nazwa: '02-odkryj', adres: '/odkryj' },
  { nazwa: '03-logowanie', adres: '/login' },
  { nazwa: '04-rejestracja', adres: '/register' },
  { nazwa: '05-profil', adres: '/@basia' },
  { nazwa: '06-tag', adres: null, znajdz: 'tag' },
  { nazwa: '07-przepis', adres: null, znajdz: 'przepis' },
  { nazwa: '08-tablica', adres: '/home', zalogowany: true },
  { nazwa: '09-szukaj', adres: '/szukaj?q=rosol', zalogowany: true },
  { nazwa: '10-zeszyt', adres: '/zeszyt', zalogowany: true },
  { nazwa: '11-powiadomienia', adres: '/powiadomienia', zalogowany: true },
  { nazwa: '12-dodaj-zdjecie', adres: '/dodaj/zdjecie', zalogowany: true },
  { nazwa: '13-dodaj-przepis', adres: '/dodaj/przepis', zalogowany: true },
  { nazwa: '14-czytelnosc', adres: '/ustawienia/czytelnosc', zalogowany: true },
  { nazwa: '15-polityka', adres: '/prywatnosc' },
];

/*
 * Warianty. Dwie szerokości i dwa motywy to cztery obrazki na ekran — i to
 * jest minimum, przy którym da się rozmawiać o wyglądzie tego serwisu:
 * motyw ciemny nie jest ozdobą (jest przełącznikiem w stopce), a telefon
 * jest podstawowym urządzeniem tej grupy odbiorców.
 *
 * 1440 px, nie 1280: kolumna treści ma sufit 45rem, więc dopiero na szerszym
 * ekranie widać, ile miejsca zostaje niewykorzystanego — a to jest jedna
 * z rzeczy, które w obecnym układzie wyglądają nieschludnie.
 */
const WARIANTY = [
  { nazwa: 'komputer-jasny', szerokosc: 1440, wysokosc: 900, motyw: 'light' },
  { nazwa: 'komputer-ciemny', szerokosc: 1440, wysokosc: 900, motyw: 'dark' },
  { nazwa: 'telefon-jasny', szerokosc: 390, wysokosc: 844, motyw: 'light' },
  { nazwa: 'telefon-ciemny', szerokosc: 390, wysokosc: 844, motyw: 'dark' },
];

function znajdzChromium() {
  for (const p of [process.env.CHROMIUM_PATH, '/opt/pw-browsers/chromium/chrome-linux/chrome', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome']) {
    if (!p) continue;
    try { execFileSync('test', ['-x', p]); return p; } catch { /* następny */ }
  }
  return undefined;
}

async function podniesSerwer() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zabij: () => {} };

  const baza = process.env.DB_DATABASE || BAZA_DOMYSLNA;
  log(`Przygotowuję dane demonstracyjne w bazie ${baza}...`);
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], {
    stdio: 'inherit',
    env: { ...process.env, DB_DATABASE: baza },
  });

  const port = 8000 + Math.floor(Math.random() * 900);
  const adres = `http://127.0.0.1:${port}`;
    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
    stdio: 'ignore',
    env: { ...process.env, DB_DATABASE: baza },
  });

  for (let i = 0; i < 60; i++) {
    try {
      const odp = await fetch(`${adres}/health`);
      if (odp.ok) { log(`Serwer stoi na ${adres}`); return { adres, zabij: () => proces.kill() }; }
    } catch { /* jeszcze nie wstał */ }
    await new Promise((r) => setTimeout(r, 500));
  }

  proces.kill();
  throw new Error('Serwer nie wstał w 30 sekund.');
}

/*
 * Adresy przepisu i tagu bierzemy Z BAZY, nie z domysłu.
 *
 * Wpisany na sztywno slug jest tu pułapką, w którą ten projekt już wpadł:
 * `scripts/dostepnosc.mjs` mierzy `/tag/zupy`, a po wczytaniu dostarczonego
 * słownika `zupy` jest ALIASEM `zupa`, więc kanonicznego tagu o tym slugu
 * nie ma i ten ekran zwraca 404. Trasa przepisu to też `/przepisy/`, nie
 * `/przepis/` — dlatego pytamy o jedno i drugie zamiast zgadywać.
 */
function zBazy(wyrazenie, opis) {
  const baza = process.env.DB_DATABASE || BAZA_DOMYSLNA;
  const wynik = execFileSync('php', ['artisan', 'tinker', '--execute', `echo ${wyrazenie};`],
    { env: { ...process.env, DB_DATABASE: baza } }).toString().trim();

  if (!wynik) throw new Error(`Baza nie ma czego pokazać: ${opis}. Seeder nie zrobił swojego.`);

  return wynik;
}

const adresPrzepisu = () => `/przepisy/${zBazy("App\\Models\\Recipe::query()->whereNotNull('published_at')->value('slug')", 'opublikowany przepis')}`;
const adresTagu = () => `/tag/${zBazy("App\\Models\\Tag::query()->whereHas('posts')->value('slug')", 'tag z wpisami')}`;

const { adres, zabij } = await podniesSerwer();

try {
  mkdirSync(KATALOG, { recursive: true });

  const przegladarka = await chromium.launch({ executablePath: znajdzChromium() });

  // Sesja zalogowanej osoby zdobyta raz, prawdziwym formularzem — nie
  // podstawiona w obejściu, żeby zrzut pokazywał to, co widzi człowiek.
  const kontekstLogowania = await przegladarka.newContext();
  const stronaLogowania = await kontekstLogowania.newPage();
  await stronaLogowania.goto(`${adres}/login`);
  // `ania`, nie `basia`: „basia" jest jednocześnie personą treści
  // zalążkowej, a persony mają hasło LOSOWE i nie są logowalne (D-025).
  await stronaLogowania.fill('input[name="login"]', 'ania');
  await stronaLogowania.fill('input[name="password"]', 'haslo-testowe-123');
  await Promise.all([
    stronaLogowania.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    stronaLogowania.click('button[type="submit"]'),
  ]);
  const stanZalogowany = await kontekstLogowania.storageState();
  await kontekstLogowania.close();

  const sciezki = new Map();
  for (const e of EKRANY) {
    sciezki.set(e.nazwa, e.adres ?? (e.znajdz === 'tag' ? adresTagu() : adresPrzepisu()));
  }

  const spis = [];

  for (const wariant of WARIANTY) {
    const kontekst = await przegladarka.newContext({
      viewport: { width: wariant.szerokosc, height: wariant.wysokosc },
      storageState: stanZalogowany,
      colorScheme: wariant.motyw === 'dark' ? 'dark' : 'light',
    });

    /*
     * MOTYW USTAWIAMY PRZEŁĄCZNIKIEM ZE STOPKI, NIE CIASTECZKIEM Z RĘKI.
     *
     * Pierwsza wersja tego skryptu wstawiała ciasteczko `motyw=dark` wprost
     * przez `addCookies()`. Zrzuty wyszły — i wszystkie ciemne były BAJT
     * W BAJT identyczne z jasnymi (`md5sum`). Laravel szyfruje ciasteczka,
     * więc wartość wpisana jawnie jest dla aplikacji śmieciem i motyw
     * zostaje domyślny. Skrypt „działał", produkował 60 plików i połowa
     * z nich była kłamstwem — dokładnie ten rodzaj cichej porażki, którego
     * ten projekt pilnuje w kodzie produkcyjnym.
     *
     * `colorScheme` w kontekście też nie wystarcza: reguła
     * `prefers-color-scheme` została z arkusza celowo usunięta, żeby motyw
     * brał się WYŁĄCZNIE z wyboru człowieka.
     *
     * Dlatego klikamy prawdziwy przycisk w stopce — tą samą drogą, którą
     * przechodzi człowiek — i sprawdzamy `data-theme` na `<html>`, zamiast
     * zakładać, że się udało.
     */
    const strojenie = await kontekst.newPage();
    await strojenie.goto(adres + '/', { waitUntil: 'networkidle' });

    const motywTeraz = async () => strojenie.evaluate(() => document.documentElement.dataset.theme || 'light');

    if (await motywTeraz() !== wariant.motyw) {
      await Promise.all([
        strojenie.waitForNavigation({ waitUntil: 'networkidle' }),
        strojenie.click('form.site-footer-motyw button[type="submit"]'),
      ]);
    }

    const ustawiony = await motywTeraz();
    if (ustawiony !== wariant.motyw) {
      throw new Error(`Nie udało się ustawić motywu ${wariant.motyw} — strona zgłasza „${ustawiony}". Bez tego połowa zrzutów byłaby duplikatem drugiej połowy.`);
    }

    await strojenie.close();

    for (const ekran of EKRANY) {
      const strona = await kontekst.newPage();
      const url = adres + sciezki.get(ekran.nazwa);

      try {
        const odp = await strona.goto(url, { waitUntil: 'networkidle', timeout: 20000 });
        const status = odp?.status() ?? 0;

        // Ekran, który zwrócił 404 albo 500, wygląda schludnie i nic nie
        // pokazuje — cichy zrzut takiej strony byłby gorszy niż jej brak.
        if (status >= 400) {
          log(`  POMINIĘTY ${ekran.nazwa} (${wariant.nazwa}): HTTP ${status}`);
          spis.push({ ekran: ekran.nazwa, wariant: wariant.nazwa, plik: null, blad: `HTTP ${status}` });
          await strona.close();
          continue;
        }

        const plik = `${KATALOG}/${ekran.nazwa}--${wariant.nazwa}.png`;
        await strona.screenshot({ path: plik, fullPage: true });
        spis.push({ ekran: ekran.nazwa, wariant: wariant.nazwa, plik, adres: sciezki.get(ekran.nazwa) });
        log(`  ${ekran.nazwa} — ${wariant.nazwa}`);
      } catch (e) {
        log(`  BŁĄD ${ekran.nazwa} (${wariant.nazwa}): ${e.message}`);
        spis.push({ ekran: ekran.nazwa, wariant: wariant.nazwa, plik: null, blad: e.message });
      }

      await strona.close();
    }

    await kontekst.close();
  }

  await przegladarka.close();

  writeFileSync(`${KATALOG}/spis.json`, JSON.stringify({ data: new Date().toISOString(), spis }, null, 1));

  const udane = spis.filter((s) => s.plik).length;
  log(`Gotowe: ${udane} zrzutów z ${spis.length} prób, katalog ${KATALOG}`);

  if (udane === 0) process.exitCode = 1;
} finally {
  zabij();
}
