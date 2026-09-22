/*
 * =============================================================================
 *  Kuking.pl — pomiar hierarchii powierzchni (rozdzielenie ról klasy `.card`)
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Zarzut brzmiał: „wszystko na ekranie ma tę samą rangę wizualną, więc nic
 *  nie ma rangi". To jest zdanie o wrażeniu, a wrażenia nie da się porównać
 *  przed zmianą i po. Ten skrypt zamienia je na trzy liczby na ekran:
 *
 *    powierzchnie   — ile szerokich bloków z własnym tłem/obwódką/cieniem
 *                     stoi w kolumnie głównej,
 *    sygnatury      — na ile RÓŻNYCH wyglądów się dzielą (tło + obwódka
 *                     + promień + cień, czytane z `getComputedStyle`),
 *    największa     — ile z nich wygląda dokładnie tak samo.
 *
 *  Ekran, na którym `powierzchnie == największa`, nie ma hierarchii: każdy
 *  blok mówi tym samym głosem. Celem zmiany jest zejście `największa` poniżej
 *  `powierzchnie` tam, gdzie bloki pełnią RÓŻNE role.
 *
 *  Skrypt NIE jest bramką i nie ma progu — jest miarą. Progi na hierarchię
 *  byłyby zgadywaniem; liczby mają iść do opisu zmiany, gdzie patrzy na nie
 *  człowiek.
 *
 *  DLACZEGO TYLKO SZEROKIE BLOKI
 *  Filtr `szerokość ≥ 60% kolumny` odsiewa przyciski, pola i plakietki —
 *  one też mają tło i obwódkę, ale nikt nie myli ich z sekcją strony.
 *  Zarzut dotyczył białych prostokątów na całą szerokość i tylko je mierzymy.
 *
 *  URUCHOMIENIE
 *      node scripts/warstwy-pomiar.mjs
 *      ADRES=http://127.0.0.1:8123 node scripts/warstwy-pomiar.mjs
 *      WYNIK=/tmp/przed.json node scripts/warstwy-pomiar.mjs
 *
 *  Bez `ADRES` skrypt sam podnosi `php artisan serve` i sam go gasi.
 *  Dane bierze z `DemoSeeder` — ekran bez treści nie ma czego mierzyć.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { writeFileSync, existsSync } from 'node:fs';

const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';
const WYNIK = process.env.WYNIK ?? 'storage/warstwy.json';

/* Ta sama reguła co w `scripts/dostepnosc.mjs`: przeglądarka z paczki, jeśli
   jest, a w obrazie deweloperskim ta spod stałej ścieżki. */
function znajdzChromium() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  try {
    const wlasna = chromium.executablePath();
    if (wlasna && existsSync(wlasna)) return undefined;
  } catch { /* idziemy dalej */ }
  const deweloperska = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
  return existsSync(deweloperska) ? deweloperska : undefined;
}

/*
 * EKRANY. `gosc: true` znaczy „mierzymy bez logowania" — i to nie jest
 * drobiazg (D-099): ten sam adres dla gościa i dla zalogowanego to dwa różne
 * ekrany, a `/napisz-do-nas` ma dla gościa o jedno pole więcej.
 */
const EKRANY = [
  { nazwa: 'napisz-do-nas (gość)', adres: '/napisz-do-nas', gosc: true },
  { nazwa: 'zgłoś nielegalną treść (gość)', adres: '/zglos-nielegalna-tresc', gosc: true },
  { nazwa: 'logowanie (gość)', adres: '/login', gosc: true },
  { nazwa: 'ustawienia: bezpieczeństwo', adres: '/ustawienia/bezpieczenstwo' },
  { nazwa: 'ustawienia: czytelność', adres: '/ustawienia/czytelnosc' },
  { nazwa: 'ustawienia: adres e-mail', adres: '/ustawienia/e-mail' },
  { nazwa: 'dodaj', adres: '/dodaj' },
  { nazwa: 'dodaj przepis (jedna strona)', adres: '/dodaj/przepis/jedna-strona' },
  { nazwa: 'strumień', adres: '/home' },
];

/*
 * DWA STANY TEGO SAMEGO ADRESU TO DWA EKRANY (D-099, D-106).
 *
 * `/ustawienia/e-mail` pokazuje formularz zmiany adresu tylko wtedy, gdy
 * poczta DZIAŁA; przy `MAIL_MAILER=log` (tak stoi w kontenerze agenta) ta
 * gałąź nie renderuje ani jednego pola i cały blok jest sekcją, nie panelem.
 * Pomiar jednego z tych stanów opisuje więc połowę prawdy — i wygląda
 * identycznie jak drugi, bo oba kończą się kodem 200.
 *
 *     MAIL_MAILER=smtp node scripts/warstwy-pomiar.mjs
 */

const POMIAR = () => {
  const main = document.querySelector('main');
  if (! main) return { blad: 'brak <main>' };

  const szerokoscKolumny = main.getBoundingClientRect().width;
  const tloStrony = getComputedStyle(document.body).backgroundColor;

  /* KONTROLKA NIE JEST POWIERZCHNIĄ. Pole tekstowe na całą szerokość też ma
     tło, obwódkę i promień, ale nikt nie myli go z sekcją strony — a wliczone
     do sumy zagłuszało różnicę, którą ten skrypt ma pokazać. Odnośnika NIE MA
     na tej liście, bo kafel akcji („Zdjęcie i kilka słów" na /dodaj) jest
     <a> i jest pełnoprawną powierzchnią. */
  const KONTROLKI = ['input', 'textarea', 'select', 'button', 'label', 'summary', 'option'];

  const powierzchnie = [];

  for (const el of main.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    const prostokat = el.getBoundingClientRect();

    if (KONTROLKI.includes(el.tagName.toLowerCase())) continue;
    if (prostokat.width < szerokoscKolumny * 0.6) continue;
    if (prostokat.height < 40) continue;

    const maTlo = cs.backgroundColor !== 'rgba(0, 0, 0, 0)'
      && cs.backgroundColor !== 'transparent'
      && cs.backgroundColor !== tloStrony;
    const maObwodke = parseFloat(cs.borderTopWidth) > 0
      || parseFloat(cs.borderLeftWidth) > 0;
    const maCien = cs.boxShadow !== 'none';

    if (! maTlo && ! maObwodke && ! maCien) continue;

    powierzchnie.push({
      znacznik: el.tagName.toLowerCase(),
      klasy: el.className?.toString?.().slice(0, 90) ?? '',
      sygnatura: [
        cs.backgroundColor,
        `${cs.borderTopWidth} ${cs.borderTopColor}`,
        cs.borderTopLeftRadius,
        cs.boxShadow,
      ].join(' | '),
    });
  }

  const grupy = new Map();
  for (const p of powierzchnie) {
    grupy.set(p.sygnatura, (grupy.get(p.sygnatura) ?? 0) + 1);
  }

  return {
    powierzchnie: powierzchnie.length,
    sygnatury: grupy.size,
    najwieksza: grupy.size === 0 ? 0 : Math.max(...grupy.values()),
    szczegoly: powierzchnie,
  };
};

async function podnies() {
  if (process.env.ADRES) return { adres: process.env.ADRES, zabij: () => {} };

  const port = 8000 + Math.floor(Math.random() * 900);
    /* `--no-reload` — patrz `scripts/port-projektu.mjs`: bez niego `artisan serve`
       wycina procesowi `php -S` zmienne środowiska joba i aplikacja spada na
       `.env`, czyli na współdzielony port 5432. */
  const serwer = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
    stdio: 'ignore',
  });
  const adres = `http://127.0.0.1:${port}`;

  for (let proba = 0; proba < 60; proba++) {
    await new Promise((r) => setTimeout(r, 500));
    try {
      const odp = await fetch(`${adres}/login`);
      if (odp.ok) break;
    } catch { /* jeszcze nie wstał */ }
  }

  return { adres, zabij: () => serwer.kill() };
}

const { adres, zabij } = await podnies();
const przegladarka = await chromium.launch({ executablePath: znajdzChromium(), headless: true });

const wyniki = [];

try {
  const gosc = await przegladarka.newContext();
  const zalogowany = await przegladarka.newContext();

  /* Logowanie przez PRAWDZIWY formularz, nie przez podstawione ciasteczko —
     ta sama zasada co w automacie dostępności. */
  {
    const karta = await zalogowany.newPage();
    await karta.goto(`${adres}/login`, { waitUntil: 'domcontentloaded' });
    await karta.fill('input[name="login"]', KONTO);
    await karta.fill('input[name="password"]', HASLO);
    await karta.click('button[type="submit"]');
    await karta.waitForLoadState('domcontentloaded');
    await karta.close();
  }

  for (const ekran of EKRANY) {
    const kontekst = ekran.gosc ? gosc : zalogowany;
    const karta = await kontekst.newPage();
    await karta.setViewportSize({ width: 1280, height: 900 });

    const odp = await karta.goto(adres + ekran.adres, { waitUntil: 'domcontentloaded' });
    const kod = odp?.status() ?? 0;

    /* KOD HTTP JEST CZĘŚCIĄ POMIARU. Strona 403 albo 302 na logowanie ma
       zero powierzchni i w tabeli wygląda jak ekran idealnie uporządkowany. */
    const wynik = kod === 200 ? await karta.evaluate(POMIAR) : { blad: `HTTP ${kod}` };

    wyniki.push({ ekran: ekran.nazwa, adres: ekran.adres, kod, ...wynik });
    await karta.close();
  }
} finally {
  await przegladarka.close();
  zabij();
}

writeFileSync(WYNIK, JSON.stringify(wyniki, null, 2));

console.log('');
console.log('ekran                                 kod  powierzchnie  sygnatury  największa grupa');
console.log('-'.repeat(88));
for (const w of wyniki) {
  if (w.blad) {
    console.log(`${w.ekran.padEnd(36)}  ${String(w.kod).padStart(3)}  ${w.blad}`);
    continue;
  }
  console.log(
    `${w.ekran.padEnd(36)}  ${String(w.kod).padStart(3)}  `
    + `${String(w.powierzchnie).padStart(12)}  ${String(w.sygnatury).padStart(9)}  `
    + `${String(w.najwieksza).padStart(16)}`,
  );
}
console.log('');
console.log(`Szczegóły (klasa po klasie): ${WYNIK}`);
