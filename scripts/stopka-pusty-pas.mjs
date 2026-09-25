/* PUSTY PAS POD STOPKĄ I WYSOKOŚĆ STOPKI — pomiar geometrii, nie treści arkusza.
 *
 * ZGŁOSZENIE 1 (22.09, wydanie ce76842). Pod trzema kolumnami stopki wysoki
 * biały pas, a pod nim drugi, szary: 484 px pustki przy 320 i 768 px (gość).
 * Rezerwa na przypiętą dolną nawigację była policzona DWA RAZY — w stopce
 * (`marka-rama.css`) i w `body` (`szybki-wyglad.css`) — i naliczała się
 * także gościowi, który belki nie ma (`.bottom-nav` stoi pod `@auth`).
 *
 * ZGŁOSZENIE 2 (24.09, wydanie 390640c, desktop ~1190 px, gość). Pierwsza
 * poprawka zostawiała „tylko" 132 px pustki na desktopie i właśnie to
 * właściciel uznał za za dużo: pod białą stopką szary pas ~110 px, a sama
 * stopka wysoka — odnośniki co ~56 px (48 px celu + 8 px odstępu), nagłówki
 * kolumn z własnym marginesem, pasek z motywem i wersją z grubym wypełnieniem.
 * Docelowo: bez belki koniec strony = koniec stopki + najwyżej 24 px;
 * z belką rezerwa na belkę, policzona raz.
 *
 * DLACZEGO TEN STRAŻNIK MIERZY, A NIE CZYTA CSS-a.
 * Test szukający reguły w arkuszu przechodzi także wtedy, gdy regułę przykryje
 * inna o wyższej swoistości albo gdy trzecie miejsce doda rezerwę po raz
 * kolejny. Liczy się ułożony dokument: `scrollHeight` i prostokąty.
 *
 * CZTERY KIERUNKI, BO KAŻDY SAM DAŁBY SIĘ OSZUKAĆ.
 *  1. Górny próg pustki — żeby pas nie wrócił.
 *  2. Z belką: ostatnia treść stopki MIEŚCI SIĘ NAD belką (WCAG 2.4.11) —
 *     inaczej najtańszą naprawą 1. byłoby wycięcie rezerwy w całości.
 *  3. Pływający przycisk „Wygląd" nie leży na treści stopki. Odkąd pod
 *     stopką nie ma pionowej rezerwy na ten przycisk, miejsce na niego jest
 *     zostawione Z BOKU paska technicznego — i to trzeba mierzyć.
 *  4. Minima UX 50+: 48 px celu, 18 px tekstu, brak przewijania w poziomie
 *     przy 320 px — żeby niższej stopki nie dało się kupić ściśnięciem celów.
 * Do tego górny próg WYSOKOŚCI stopki na desktopie (zgłoszenie 2).
 *
 * DWA TRYBY.
 *  - na żywym serwisie: `sprawdzStopke()` z `port-projektu.mjs` (serwer,
 *    konto, `/o-kuking`), albo `--adres=http://127.0.0.1:PORT`;
 *  - `--statycznie`: kaskada z `public/build` (po `npm run build`) na
 *    odtworzonym szkielecie `layout.blade.php`, bez PHP i bazy. Ten tryb
 *    robi też KONTROLĘ UJEMNĄ (sabotaże niżej) i zrzuty. `--css=plik.css`
 *    podmienia arkusz (pomiar „przed"), `--bez-asercji` tylko wypisuje liczby.
 */
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';

const NAZWA = 'stopka-pusty-pas';

/* Bez belki: od ostatniej treści stopki do końca dokumentu najwyżej tyle.
   To jest oddech pod paskiem technicznym, nie rezerwa na nakładki. */
const PROG_BEZ_BELKI = 24;

/* Z belką: rezerwa na belkę (zmierzoną, nie wpisaną) plus ten sam oddech. */
const ODDECH_NAD_BELKA = 24;

/* Sufit wysokości całej stopki przy 1440 i 1190 px, gość, skala 100%.
   Na produkcji 390640c było 450 px (odnośniki co 56 px, hasło i licznik
   w osobnych wierszach nad kolumnami, pasek 65 px), w #1250 przed 24.09 —
   518 px (doszło 68 px rezerwy na przycisk „Wygląd"). Po poprawce 290 px.
   Sufit zostawia 14 px luzu na zmianę pisma, ale nie tyle, żeby zmieścił
   się powrót odstępów (sabotaż niżej daje 330 px). */
const MAKS_WYSOKOSC_STOPKI_DESKTOP = 304;

const MIN_CEL = 48;
const MIN_TEKST = 18;

const SZEROKOSCI_GOSCIA = [320, 360, 390, 414, 768, 1190, 1440];
const SKALE = [100, 140];
const SZEROKOSCI_ZALOGOWANEGO = [320, 390, 768, 1190, 1440];

/* CZCIONKA PRZEGLĄDARKI 32 px (tekst 200%) — CI #1250, 24.09. Boczna rezerwa
   paska technicznego rośnie z `rem` i skalą, a szerokość okna nie: przy 414 px
   i skali 140% wypełnienie (382 px) było szersze od paska (350 px) i strona
   przewijała się w poziomie o 16 px. Tu pilnujemy minim UX 50+ (brak
   przewijania w poziomie, cele, pismo) ORAZ tego, że przycisk „Wygląd" nie
   leży na treści paska (#1525: do 24.09 to sprawdzenie było tu pomijane,
   a przycisk szeroki na 220–336 px przykrywał motyw i wersję). Progi
   pustki dotyczą zwykłej czcionki: przy 32 px pod paskiem MUSI zostać
   miejsce na przycisk, bo z boku się nie mieści. */
const DUZA_CZCIONKA = 32;
const SZEROKOSCI_DUZEJ_CZCIONKI = [320, 360, 390, 414];

async function zmierz(page) {
  return page.evaluate(() => {
    const stopka = document.querySelector('.site-footer');

    if (!stopka) return { brakStopki: true };

    const d = document.documentElement;
    const przewiniecie = window.scrollY;

    /* OSTATNIA TREŚĆ, NIE PUDEŁKO STOPKI — pudełko sięga nisko właśnie przez
       wypełnienie, które tu badamy. */
    const tresc = [...stopka.querySelectorAll('a, span, strong, p, button')]
      .filter((e) => !e.closest('.visually-hidden'))
      .map((e) => e.getBoundingClientRect())
      .filter((r) => r.height > 0 && r.width > 0);
    const dolTresci = tresc.reduce((max, r) => Math.max(max, r.bottom + przewiniecie), -Infinity);

    const widoczna = (e) => {
      if (!e) return false;
      const s = getComputedStyle(e);

      return s.position === 'fixed' && s.display !== 'none' && s.visibility !== 'hidden' && e.getBoundingClientRect().height > 0;
    };

    const belka = document.querySelector('.bottom-nav');
    const belkaWidoczna = widoczna(belka);

    /* Przycisk „Wygląd" (zwinięty) — to jego prostokąt może leżeć na stopce. */
    const wyglad = document.querySelector('.szybki-wyglad:not([open]) > summary');
    const rWyglad = wyglad && widoczna(wyglad.parentElement) ? wyglad.getBoundingClientRect() : null;
    const naTresci = rWyglad
      ? tresc.filter((r) => r.left < rWyglad.right && r.right > rWyglad.left && r.top < rWyglad.bottom && r.bottom > rWyglad.top).length
      : 0;

    const odnosniki = [...stopka.querySelectorAll('.site-footer-grupa ul a')].map((a) => ({
      wysokosc: a.getBoundingClientRect().height,
      pismo: parseFloat(getComputedStyle(a).fontSize),
    }));
    const rStopki = stopka.getBoundingClientRect();

    return {
      brakStopki: false,
      dolTresci: Number.isFinite(dolTresci) ? Math.round(dolTresci) : null,
      /* LICZBA ZE ZGŁOSZENIA: pustka od ostatniego słowa stopki do końca
         dokumentu, bez względu na to, który element ją tworzy. */
      pasPodTrescia: Number.isFinite(dolTresci) ? Math.round(d.scrollHeight - dolTresci) : null,
      wStopce: Math.round(parseFloat(getComputedStyle(stopka).paddingBottom)),
      podStopka: Math.round(d.scrollHeight - (rStopki.bottom + przewiniecie)),
      wysokoscStopki: Math.round(rStopki.height),
      maBelke: belkaWidoczna,
      wysokoscBelki: belkaWidoczna ? Math.round(window.innerHeight - belka.getBoundingClientRect().top) : 0,
      dolTresciWOknie: Number.isFinite(dolTresci) ? Math.round(dolTresci - przewiniecie) : null,
      gornaKrawedzBelkiWOknie: belkaWidoczna ? Math.round(belka.getBoundingClientRect().top) : null,
      wygladNaTresci: naTresci,
      przepelnieniePoziome: d.scrollWidth > d.clientWidth + 1,
      scrollWidth: d.scrollWidth,
      clientWidth: d.clientWidth,
      najnizszyCel: odnosniki.length === 0 ? null : Math.min(...odnosniki.map((o) => o.wysokosc)),
      najmniejszePismo: odnosniki.length === 0 ? null : Math.min(...odnosniki.map((o) => o.pismo)),
      liczbaOdnosnikow: odnosniki.length,
    };
  });
}

async function naKoniec(page) {
  await page.evaluate(() => document.fonts.ready);
  /* Dwa razy: pierwsze przewinięcie uruchamia `geometry()` w skrypcie
     przycisku „Wygląd" (rezerwa belki), a po zmianie skali układ dojeżdża
     jeszcze klatkę później — jedno przewinięcie potrafiło stanąć 50 px
     przed końcem i „treść pod belką" była wtedy błędem pomiaru. */
  for (let i = 0; i < 2; i++) {
    await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
    await page.waitForTimeout(150);
  }
}

/* Wszystkie progi w jednym miejscu, żeby tryb żywy, statyczny i kontrola
   ujemna oblewały na tej samej regule. Zwraca listę naruszeń. */
export function naruszenia(m, { kto, width, skala, czcionka }) {
  const opis = `${kto}, ${width} px, skala ${skala ?? 100}%${czcionka ? `, czcionka ${czcionka} px` : ''}`;
  const bledy = [];
  const sprawdz = (warunek, tekst) => { if (!warunek) bledy.push(`${tekst} (${opis})`); };

  if (m.brakStopki) return [`stopki nie ma na stronie (${opis})`];

  sprawdz(m.dolTresci !== null, 'stopka nie ma mierzalnej treści');
  sprawdz(m.liczbaOdnosnikow >= 6, `stopka ma tylko ${m.liczbaOdnosnikow} odnośników w kolumnach — pomiar minim byłby pusty`);
  sprawdz(m.najnizszyCel >= MIN_CEL - 0.5, `cel dotykowy w stopce zszedł do ${m.najnizszyCel} px przy wymaganych ${MIN_CEL} px`);
  sprawdz(m.najmniejszePismo >= MIN_TEKST - 0.5, `tekst w stopce zszedł do ${m.najmniejszePismo} px przy wymaganych ${MIN_TEKST} px`);
  sprawdz(!m.przepelnieniePoziome, `strona przewija się w poziomie: ${m.scrollWidth} > ${m.clientWidth}`);
  sprawdz(m.wygladNaTresci === 0, `przycisk „Wygląd" leży na ${m.wygladNaTresci} elementach treści stopki`);
  if (czcionka) return bledy;

  if (kto === 'gość') sprawdz(!m.maBelke, 'gość nie powinien mieć przypiętej .bottom-nav');

  if (m.maBelke) {
    sprawdz(
      m.dolTresciWOknie <= m.gornaKrawedzBelkiWOknie,
      `treść stopki wchodzi pod przypiętą belkę: dół treści ${m.dolTresciWOknie} px, górna krawędź belki ${m.gornaKrawedzBelkiWOknie} px`,
    );
    const prog = m.wysokoscBelki + ODDECH_NAD_BELKA;

    sprawdz(m.pasPodTrescia <= prog, `pusty pas pod stopką: ${m.pasPodTrescia} px przy dopuszczalnych ${prog} px (belka ${m.wysokoscBelki} px + ${ODDECH_NAD_BELKA} px)`);
  } else {
    sprawdz(
      m.pasPodTrescia <= PROG_BEZ_BELKI,
      `pusty pas pod stopką: ${m.pasPodTrescia} px przy dopuszczalnych ${PROG_BEZ_BELKI} px; wewnątrz stopki ${m.wStopce} px, pod stopką ${m.podStopka} px`,
    );
  }

  if (kto === 'gość' && width >= 1024 && (skala ?? 100) === 100) {
    sprawdz(
      m.wysokoscStopki <= MAKS_WYSOKOSC_STOPKI_DESKTOP,
      `stopka ma ${m.wysokoscStopki} px wysokości przy suficie ${MAKS_WYSOKOSC_STOPKI_DESKTOP} px`,
    );
  }

  return bledy;
}

function wiersz(przypadek, m) {
  return {
    ...przypadek,
    pas: m.pasPodTrescia,
    podStopka: m.podStopka,
    wStopce: m.wStopce,
    stopka: m.wysokoscStopki,
    belka: m.wysokoscBelki,
    wygladNaTresci: m.wygladNaTresci,
  };
}

export async function sprawdzStopke({ browser, adres, sesja, out = 'storage/port-projektu/stopka' }) {
  mkdirSync(out, { recursive: true });
  const wyniki = [];
  const sciezka = `${adres.replace(/\/$/, '')}/o-kuking`;
  const przebieg = async (kontekst, przypadek) => {
    const strona = await kontekst.newPage();

    await strona.goto(sciezka, { waitUntil: 'networkidle' });

    if (przypadek.skala !== 100) {
      await strona.evaluate((s) => { document.documentElement.dataset.textScale = String(s); }, przypadek.skala);
    }

    await naKoniec(strona);
    const m = await zmierz(strona);
    const bledy = naruszenia(m, przypadek);

    assert.deepEqual(bledy, [], bledy.join('\n'));
    wyniki.push({ ...wiersz(przypadek, m), wynik: 'PASS' });
    await strona.close();
  };

  for (const width of SZEROKOSCI_GOSCIA) {
    for (const skala of SKALE) {
      const kontekst = await browser.newContext({ viewport: { width, height: 740 }, serviceWorkers: 'block' });

      await przebieg(kontekst, { kto: 'gość', width, skala });
      await kontekst.close();
    }
  }

  if (sesja) {
    for (const width of SZEROKOSCI_ZALOGOWANEGO) {
      const kontekst = await browser.newContext({ storageState: sesja, viewport: { width, height: 740 }, reducedMotion: 'reduce' });

      await przebieg(kontekst, { kto: 'zalogowany', width, skala: 100 });
      await kontekst.close();
    }
  }

  /* KONTROLA DODATNIA: zielone z pustej pętli wygląda tak samo jak zielone
     z pomiaru (`docs/PULAPKI_TESTOW.md`). */
  const oczekiwane = SZEROKOSCI_GOSCIA.length * SKALE.length + (sesja ? SZEROKOSCI_ZALOGOWANEGO.length : 0);

  assert.equal(wyniki.length, oczekiwane, `macierz niepełna: ${wyniki.length} z ${oczekiwane} przypadków`);
  writeFileSync(`${out}/${NAZWA}.json`, JSON.stringify(wyniki, null, 2));

  return wyniki;
}

/* =============================================================================
   TRYB STATYCZNY — szkielet `layout.blade.php` na kaskadzie z buildu.
   Odtwarza to, co decyduje o geometrii końca strony: klasy `body`, powłokę,
   stopkę z tymi samymi odnośnikami, belkę (tylko „zalogowany") i przycisk
   „Wygląd" z prawdziwym skryptem `szybki-wyglad.js`.
   ========================================================================== */
const SLOWO = '<span class="kuking-word"><span aria-hidden="true">ku<strong>KING</strong></span><span class="visually-hidden">kuking</span></span>';
const IKONA = '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="currentColor"/></svg>';

/* `js` to PRAWDZIWY `resources/js/szybki-wyglad.js` — to on ustawia
   `--wyglad-dol` i `--rezerwa-belki`, więc pomiar bez niego mierzyłby atrapę. */
function szkielet({ kto, css, font, js }) {
  const zalogowany = kto === 'zalogowany';
  const grupa = (nazwa, linki) => `<nav class="site-footer-grupa" aria-label="${nazwa}"><p class="site-footer-naglowek" aria-hidden="true">${nazwa}</p><ul>${linki.map((l) => `<li><a href="#">${l}</a></li>`).join('')}</ul></nav>`;
  const akapity = Array.from({ length: 6 }, () => '<p>Gotujemy po swojemu i pokazujemy, co dziś wyszło z garnka. Kilka zdań, żeby strona miała treść dłuższą niż ekran.</p>').join('');
  const belka = zalogowany
    ? `<nav class="bottom-nav" aria-label="Nawigacja główna">${['Start', 'Szukaj', 'Dodaj', 'Moje', 'Profil'].map((n) => `<a class="bottom-nav-item" href="#">${IKONA} ${n}</a>`).join('')}</nav>`
    : '';

  return `<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>@font-face { font-family: 'Inter Variable'; src: url(data:font/woff2;base64,${font}) format('woff2'); font-weight: 100 900; }</style>
<style>${css}</style></head>
<body class="${zalogowany ? '' : 'uklad-solo'}" data-marka="kuking-2026">
<div class="app-shell">
  <header class="topbar"><div class="topbar-inner"><a href="#">${SLOWO}</a></div></header>
  <div class="app-body marka-rama ${zalogowany ? '' : 'app-body-solo'}">
    ${zalogowany ? '<nav class="side-nav" aria-label="Menu"><ul><li><a href="#">Start</a></li></ul></nav>' : ''}
    <main class="app-main" id="tresc"><h1>O ${SLOWO}</h1>${akapity}</main>
  </div>
  <footer class="site-footer"><div class="site-footer-inner">
    <p class="site-footer-haslo">${SLOWO} — gotujemy po swojemu.</p>
    <p class="site-footer-liczba">23 ${SLOWO}ów</p>
    <div class="site-footer-grupy">
      ${grupa('O serwisie', [`O ${SLOWO}`, 'Zasady'])}
      ${grupa('Pomoc i kontakt', ['Napisz do nas', 'Pomoc'])}
      ${grupa('Sprawy formalne', ['Regulamin', 'Prywatność', 'Zgłoś nielegalną treść'])}
      ${zalogowany ? grupa('Konto', ['Twoje zgłoszenia']) : ''}
    </div>
    <div class="site-footer-pasek">
      <form class="site-footer-motyw"><span class="visually-hidden">Wygląd strony: jasny.</span><button class="btn btn-quiet site-footer-motyw-przycisk" type="button" aria-label="Włącz ciemny wygląd">${IKONA}</button></form>
      <span class="site-version"><strong class="site-version-etap">Alfa 0.68</strong><span class="site-version-wydanie">24.09.2026 · 390640c</span></span>
    </div>
  </div></footer>
</div>
${belka}
<details class="szybki-wyglad" data-szybki-wyglad><summary><span aria-hidden="true">Aa · </span>Wygląd</summary>
  <section class="szybki-wyglad-panel" aria-labelledby="szybki-wyglad-tytul"><h2 id="szybki-wyglad-tytul">Dopasuj wygląd</h2>
    <form method="POST" action="#">
      <label for="szybka-skala">Rozmiar tekstu</label>
      <div class="szybki-wyglad-skala"><button class="btn btn-secondary" type="button" data-skala-krok="-1" hidden>A−</button>
        <select id="szybka-skala" name="text_scale"><option value="100" selected>100%</option><option value="140">140%</option></select>
        <button class="btn btn-secondary" type="button" data-skala-krok="1" hidden>A+</button></div>
      <label for="szybki-motyw">Wygląd strony</label>
      <select id="szybki-motyw" name="theme"><option value="light" selected>Jasny</option><option value="dark">Ciemny</option></select>
      <p role="status" data-wyglad-status></p>
      <button class="btn btn-secondary" type="button" data-wyglad-zamknij hidden>Zamknij</button>
    </form></section></details>
<aside class="szybki-wyglad-podpowiedz" data-wyglad-podpowiedz hidden><p>Dopasuj rozmiar tekstu.</p><button class="btn btn-secondary" type="button">Rozumiem</button></aside>
<script type="module">${js}</script>
</body></html>`;
}

/* SABOTAŻE — każdy przywraca jedną z usterek zgłoszeń. Strażnik, który ich
   nie wykrywa, nic nie pilnuje. */
const SABOTAZE = [
  ['szary pas pod stopką (body padding-bottom 80 px, jak do 22.09)', 'body:has(.szybki-wyglad){padding-bottom:80px !important}', { kto: 'gość', width: 1190, skala: 100 }],
  ['rezerwa na przycisk „Wygląd" w stopce (92 px, jak w #1250 przed 24.09)', '[data-marka] .site-footer{padding-bottom:92px !important}', { kto: 'gość', width: 1440, skala: 100 }],
  ['odstęp 8 px między odnośnikami (jak na produkcji 390640c)', '.site-footer-grupa ul{gap:var(--spacing-2) !important}.site-footer-naglowek{margin-bottom:var(--spacing-2) !important}.site-footer-pasek{padding-top:var(--spacing-4) !important}.site-footer-inner{gap:var(--spacing-6) !important}', { kto: 'gość', width: 1440, skala: 100 }],
  ['rezerwa zdjęta także zalogowanemu z belką', '[data-marka] .site-footer{padding-bottom:16px !important}', { kto: 'zalogowany', width: 390, skala: 100 }],
  ['pasek techniczny bez miejsca na przycisk „Wygląd"', '[data-marka] .site-footer-pasek{padding-right:0 !important}.site-version{margin-left:auto !important}', { kto: 'gość', width: 1190, skala: 100 }],
  ['odnośniki ściśnięte poniżej 48 px', '.site-footer-grupa ul a{min-height:0 !important}', { kto: 'gość', width: 390, skala: 100 }],
  ['czcionka 32 px bez miejsca pod paskiem, „Aa · Wygląd" (#1525)', ':root[data-wyglad-pod-paskiem] [data-marka] .site-footer{padding-bottom:16px !important}', { kto: 'gość', width: 414, skala: 100, czcionka: DUZA_CZCIONKA }],
  ['czcionka 32 px bez miejsca pod paskiem, samo „Wygląd" (#1525)', ':root[data-wyglad-pod-paskiem] [data-marka] .site-footer{padding-bottom:16px !important}', { kto: 'zalogowany', width: 320, skala: 140, czcionka: DUZA_CZCIONKA }],
  ['rezerwa paska bez sufitu (jak w #1250 przed poprawką CI)', '[data-marka] .site-footer-pasek{padding-right:calc(var(--spacing-3) + 8rem * var(--user-text-scale, 1)) !important}', { kto: 'gość', width: 414, skala: 140, czcionka: DUZA_CZCIONKA }],
];

async function sprawdzStatycznie({ browser, cssPlik, bezAsercji, out }) {
  mkdirSync(out, { recursive: true });
  const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
  const css = readFileSync(cssPlik ?? `public/build/${manifest['resources/css/app.css'].file}`, 'utf8');
  const fontPlik = readdirSync('public/build/assets').find((n) => n.startsWith('inter-latin-wght-normal-') && n.endsWith('.woff2'));
  const font = readFileSync(`public/build/assets/${fontPlik}`).toString('base64');
  const js = readFileSync('resources/js/szybki-wyglad.js', 'utf8');

  const pomiar = async (przypadek, { sabotaz = null, zrzut = null } = {}) => {
    const kontekst = await browser.newContext({ viewport: { width: przypadek.width, height: 740 }, reducedMotion: 'reduce' });

    await kontekst.route('**/*', (r) => r.abort());
    const strona = await kontekst.newPage();

    if (przypadek.czcionka) {
      await (await kontekst.newCDPSession(strona)).send('Page.setFontSizes', { fontSizes: { standard: przypadek.czcionka, fixed: przypadek.czcionka } });
    }

    await strona.setContent(szkielet({ kto: przypadek.kto, css, font, js }));

    if (przypadek.skala !== 100) {
      await strona.evaluate((s) => { document.documentElement.dataset.textScale = String(s); }, przypadek.skala);
    }

    if (sabotaz) await strona.addStyleTag({ content: sabotaz });
    await naKoniec(strona);
    const m = await zmierz(strona);

    if (zrzut) await strona.screenshot({ path: `${out}/${zrzut}.png` });
    await kontekst.close();

    return m;
  };

  const wyniki = [];
  const przypadki = [
    ...SZEROKOSCI_GOSCIA.flatMap((width) => SKALE.map((skala) => ({ kto: 'gość', width, skala }))),
    ...SZEROKOSCI_ZALOGOWANEGO.flatMap((width) => SKALE.map((skala) => ({ kto: 'zalogowany', width, skala }))),
    ...['gość', 'zalogowany'].flatMap((kto) => SZEROKOSCI_DUZEJ_CZCIONKI.flatMap((width) => SKALE.map((skala) => ({ kto, width, skala, czcionka: DUZA_CZCIONKA })))),
  ];

  for (const p of przypadki) {
    const zrzut = !p.czcionka && p.skala === 100 && [1440, 1190, 390].includes(p.width) ? `${p.kto === 'gość' ? 'gosc' : 'zalogowany'}-${p.width}` : null;
    const m = await pomiar(p, { zrzut });
    const bledy = naruszenia(m, p);

    wyniki.push({ ...wiersz(p, m), wynik: bledy.length ? 'FAIL' : 'PASS', bledy });
  }

  console.table(wyniki.map(({ bledy, ...w }) => w));
  wyniki.flatMap((w) => w.bledy).forEach((b) => console.log(`  ✗ ${b}`));

  if (bezAsercji) return wyniki;

  const oblane = wyniki.filter((w) => w.bledy.length);

  assert.equal(oblane.length, 0, oblane.flatMap((w) => w.bledy).join('\n'));
  assert.equal(wyniki.length, przypadki.length, 'macierz niepełna');

  /* KONTROLA UJEMNA. Każdy sabotaż MUSI oblać — na tej samej funkcji progów. */
  for (const [nazwa, sabotaz, p] of SABOTAZE) {
    const bledy = naruszenia(await pomiar(p, { sabotaz }), p);

    assert(bledy.length > 0, `KONTROLA UJEMNA: sabotaż „${nazwa}" przeszedł niezauważony`);
    console.log(`kontrola ujemna OK — ${nazwa}: ${bledy[0]}`);
  }

  writeFileSync(`${out}/${NAZWA}.json`, JSON.stringify(wyniki, null, 2));

  return wyniki;
}

/* Uruchomienie samodzielne:
   node scripts/stopka-pusty-pas.mjs --statycznie            (po `npm run build`)
   node scripts/stopka-pusty-pas.mjs --adres=http://127.0.0.1:8137
   Pełna macierz z kontem na żywym serwisie chodzi w `port-projektu.mjs`. */
if (process.argv[1] && process.argv[1].endsWith('stopka-pusty-pas.mjs')) {
  const { chromium } = await import('playwright');
  const opcja = (nazwa) => process.argv.find((a) => a.startsWith(`--${nazwa}=`))?.slice(nazwa.length + 3);
  const browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });

  try {
    if (process.argv.includes('--statycznie')) {
      const wyniki = await sprawdzStatycznie({
        browser,
        cssPlik: opcja('css'),
        bezAsercji: process.argv.includes('--bez-asercji'),
        out: opcja('out') ?? 'storage/stopka-pusty-pas',
      });

      console.log(`Stopka (statycznie): ${wyniki.length} przypadków.`);
    } else {
      const adres = opcja('adres') ?? process.env.ADRES;

      assert(adres, 'Podaj --statycznie, --adres=http://127.0.0.1:PORT albo zmienną ADRES.');
      const wyniki = await sprawdzStopke({ browser, adres });

      console.log(`Pusty pas pod stopką: ${wyniki.length} przypadków w progu.`);
    }
  } finally {
    await browser.close();
  }
}
