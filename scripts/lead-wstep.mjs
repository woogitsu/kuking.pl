/* #713 D4 — akapit wstępny `text-lead` na czterech ekranach po usunięciu
   martwej klasy `lead` (PR #716).

   CO TO ZAMYKA. Do #716 cztery widoki (`Poradźcie`, `Twoje tagi`, spis tagów,
   strona tagu) niosły `class="lead"` — klasę bez reguły w jakimkolwiek
   arkuszu — więc wstęp renderował się pismem podstawowym (18 px). Poprawka
   zmieniła to na `text-lead` (22 px), czyli widoczną zmianę na dwóch stronach
   publicznych. `ZadenWidokNieUzywaMartwejKlasyTest` pilnuje źródeł, ale nie
   mówi, jak wstęp WYGLĄDA. Raport z 20.09.2026 powoływał się na
   `pomiar-d4.mjs`, którego w repozytorium nigdy nie było; ten skrypt jest
   trwałym zapisem tamtego pomiaru.

   MIERZY, na prawdziwych stronach, dla czterech ekranów × 4 szerokości
   (320/390/768/1440) × jasny/ciemny × tekst 100/140% oraz czcionka
   przeglądarki podwojona (CDP `Page.setFontSizes`):
     - rozmiar pisma wstępu = 22 px × (rozmiar pisma podstawowego / 18),
       czyli STRICTE większy od tekstu podstawowego;
     - w dokumencie nie ma żadnego elementu z klasą `lead`;
     - brak poziomego przewijania, wstęp mieści się w oknie i w kolumnie;
     - kontrast koloru pisma wstępu z tłem co najmniej 4,5:1.

   CZEGO NIE DOWODZI. „Poradźcie" jest za flagą `KUKING_QUESTIONS_ENABLED`
   (włączona tylko w tym procesie), więc na produkcji tego ekranu nie ma.
   Zoom przeglądarki (Ctrl +) nie jest tu emulowany: podwojona czcionka
   bazowa to co innego niż powiększenie strony.

   URUCHOMIENIE (własna baza po `DemoSeeder`):
     CHROMIUM_PATH=… DB_DATABASE=kuking_713_pomiar node scripts/lead-wstep.mjs */
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { wymagajStanu } from './lib/stan-ustalony.mjs';
import { tinker, uruchomPrzegladarke, uruchomSerwer, zaloguj } from './lib/serwer-lokalny.mjs';

const SZEROKOSCI = [320, 390, 768, 1440];
const MOTYWY = ['light', 'dark'];
/* `null` = zwykłe okno; liczby to `data-text-scale` z profilu; 'przegladarka-200'
   to podwojona czcionka bazowa przeglądarki (inny mechanizm niż skala profilu). */
const SKALE = [100, 140, 'przegladarka-200'];

function pomiarWstepu(selektor) {
  const wstep = document.querySelector(selektor);
  if (!wstep) return { brak: true };
  const r = wstep.getBoundingClientRect();
  const main = (wstep.closest('main') ?? document.body).getBoundingClientRect();
  const s = getComputedStyle(wstep);
  const tlo = (el) => { for (let e = el; e; e = e.parentElement) { const c = getComputedStyle(e).backgroundColor; if (c && !/rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)|transparent/.test(c)) return c; } return 'rgb(255, 255, 255)'; };
  return {
    brak: false,
    font: parseFloat(s.fontSize),
    fontCiala: parseFloat(getComputedStyle(document.body).fontSize),
    kolor: s.color, tlo: tlo(wstep),
    lewa: r.left, prawa: r.right, mainLewa: main.left, mainPrawa: main.right,
    scroll: document.documentElement.scrollWidth, okno: innerWidth,
    martwa: document.querySelectorAll('.lead').length,
  };
}

function luminancja(rgb) {
  const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const kontrast = (a, b) => { const [x, y] = [luminancja(a), luminancja(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };

export async function sprawdzLeadWstep({ browser, adres, ekrany }) {
  let ile = 0;
  for (const ekran of ekrany) for (const width of SZEROKOSCI) for (const motyw of MOTYWY) for (const skala of SKALE) {
    const kontekst = await browser.newContext({ storageState: ekran.sesja, viewport: { width, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
    try {
      const p = await kontekst.newPage();
      if (skala === 'przegladarka-200') await (await kontekst.newCDPSession(p)).send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 32 } });
      const odp = await p.goto(adres + ekran.sciezka);
      assert.equal(odp.status(), 200, `${ekran.nazwa}: HTTP ${odp.status()} ${ekran.sciezka}`);
      await p.evaluate(({ motyw, skala }) => {
        document.documentElement.dataset.theme = motyw;
        document.documentElement.dataset.textScale = String(typeof skala === 'number' ? skala : 100);
      }, { motyw, skala });
      await wymagajStanu(p, {
        opis: `${ekran.nazwa}: fonty i przejścia przed pomiarem`, limitMs: 10_000,
        warunek: (_, przejscia) => document.fonts.status === 'loaded' && przejscia().length === 0,
        pomiar: (_, przejscia) => ({ fonty: document.fonts.status, przejscia: przejscia().slice(0, 6) }),
      });
      const m = await p.evaluate(pomiarWstepu, ekran.selektor);
      const et = `${ekran.nazwa} ${width}px ${motyw} tekst ${skala}`;
      assert(!m.brak, `${et}: brak akapitu wstępnego (${ekran.selektor})`);
      assert.equal(m.martwa, 0, `${et}: w dokumencie została klasa .lead`);
      // Wstęp = 22 px przy zwykłym 18 px: stosunek 11/9, po skali i po czcionce bazowej.
      const oczekiwany = m.fontCiala * 22 / 18;
      assert(Math.abs(m.font - oczekiwany) <= 0.6, `${et}: wstęp ${m.font} px, oczekiwano ${oczekiwany.toFixed(1)} px przy tekście podstawowym ${m.fontCiala} px`);
      assert(m.font > m.fontCiala + 1, `${et}: wstęp nie jest większy od tekstu podstawowego (${m.font} vs ${m.fontCiala})`);
      assert(m.scroll <= m.okno + 1, `${et}: poziome przewijanie ${m.scroll} > ${m.okno}`);
      assert(m.lewa >= m.mainLewa - 1 && m.prawa <= m.mainPrawa + 1 && m.prawa <= m.okno + 1, `${et}: wstęp wystaje z kolumny ${JSON.stringify(m)}`);
      const k = kontrast(m.kolor, m.tlo);
      assert(k >= 4.5, `${et}: kontrast wstępu ${k.toFixed(2)}:1 (${m.kolor} na ${m.tlo})`);
      ile++;
    } finally { await kontekst.close(); }
  }
  console.log(`LEAD_WSTEP_OK ${ile} konfiguracji na ${ekrany.length} ekranach`);
  return ile;
}

async function przebiegLokalny() {
  const { adres, env, zamknij } = await uruchomSerwer({ KUKING_QUESTIONS_ENABLED: 'true' });
  let przegladarka;
  try {
    const slugTagu = /TAG:([\w-]+)/.exec(tinker(env, "echo 'TAG:'.App\\Models\\Tag::query()->orderBy('id')->firstOrFail()->slug;"))?.[1];
    assert(slugTagu, 'Brak tagu do pomiaru strony tagu (zasiej bazę DemoSeeder).');
    przegladarka = await uruchomPrzegladarke();
    const sesja = await zaloguj(przegladarka, adres, 'ania');
    await sprawdzLeadWstep({
      browser: przegladarka, adres,
      ekrany: [
        { nazwa: 'Poradźcie', sciezka: '/pytania', selektor: 'main p.text-lead', sesja },
        { nazwa: 'Twoje tagi', sciezka: '/ustawienia/tagi', selektor: 'main p.text-lead', sesja },
        { nazwa: 'spis tagów', sciezka: '/tagi', selektor: 'main p.tag-directory-intro', sesja: undefined },
        { nazwa: 'strona tagu', sciezka: `/tag/${slugTagu}`, selektor: 'main p.text-lead', sesja: undefined },
      ],
    });
  } finally {
    await przegladarka?.close();
    zamknij();
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  przebiegLokalny().catch((blad) => { console.error(blad.message); process.exitCode = 1; });
}
