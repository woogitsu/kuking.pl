/*
 * =============================================================================
 *  Kuking.pl — przejście KLAWIATURĄ przez główne formularze
 * =============================================================================
 *
 *  MIERZY TRZY RZECZY, KTÓRYCH AXE NIE ZMIERZY
 *
 *  1. CZY FOKUS WIDAĆ. Nie „czy jest reguła `:focus-visible`" — tylko czy
 *     UŁOŻONY element wygląda inaczej z fokusem niż bez. Mierzymy to różnicą
 *     `getComputedStyle` w stanie z fokusem i bez, na tym samym elemencie:
 *     obrys, cień, tło, ramka. Reguła w arkuszu może być przykryta inną
 *     regułą i dalej istnieć — piksel nie kłamie.
 *
 *  2. CZY OBRYS JEST WIDOCZNY NA EKRANIE. Element może mieć obrys i być pod
 *     przyklejoną belką albo poza oknem. Sprawdzamy prostokąt elementu
 *     względem okna i względem elementów `position: fixed`.
 *
 *  3. CZY FOKUS NIE WPADA W PUŁAPKĘ. Chodzimy Tabem do 60 kroków i notujemy
 *     ciąg elementów. Pułapka to cykl KRÓTSZY niż cała strona, z którego
 *     Tab nie wychodzi — czyli powtarzający się zestaw elementów, wśród
 *     których nie ma przycisku wysyłającego formularz.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8137';
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';
const KROKI = 60;

const FORMULARZE = [
  { nazwa: 'logowanie', adres: '/login' },
  { nazwa: 'rejestracja', adres: '/register' },
  { nazwa: 'nie pamiętam hasła', adres: '/nie-pamietam-hasla' },
  { nazwa: 'napisz do nas', adres: '/napisz-do-nas' },
  { nazwa: 'dodaj zdjęcie', adres: '/dodaj/zdjecie', zalogowany: true },
  { nazwa: 'dodaj przepis', adres: '/dodaj/przepis', zalogowany: true },
  { nazwa: 'zadaj pytanie', adres: '/pytania/zadaj', zalogowany: true },
  { nazwa: 'ustawienia — profil', adres: '/ustawienia/profil', zalogowany: true },
  { nazwa: 'ustawienia — czytelność', adres: '/ustawienia/czytelnosc', zalogowany: true },
];

const OPISZ_FOKUS = () => {
  const el = document.activeElement;
  if (!el || el === document.body) return null;
  const s = getComputedStyle(el);
  const r = el.getBoundingClientRect();

  /*
   * Elementy przyklejone, które mogą przykryć element z fokusem.
   *
   * ELEMENT STOJĄCY W SAMEJ BELCE NIE JEST PRZEZ NIĄ PRZYKRYTY — i to jest
   * poprawka fałszywego alarmu z pierwszego przebiegu. Odnośnik `wordmark`
   * albo pozycja dolnego paska LEŻY w prostokącie belki, bo JEST belką;
   * liczenie tego jako „przykryty" dawało cztery zgłoszenia na każdym
   * formularzu i ani jednego prawdziwego. Pomijamy więc przykrywkę, która
   * jest przodkiem elementu z fokusem.
   */
  const przykrywki = [...document.querySelectorAll('body *')]
    .filter((e) => {
      const st = getComputedStyle(e);
      return (st.position === 'fixed' || st.position === 'sticky')
        && st.visibility !== 'hidden' && st.display !== 'none'
        && e.getBoundingClientRect().height > 0
        && !e.contains(el);
    })
    .map((e) => e.getBoundingClientRect());

  /*
   * Przykrycie liczymy tylko wtedy, gdy przesłonięty jest ZNACZĄCY kawałek
   * elementu (ponad ćwierć jego wysokości). Styk krawędzi na piksel nie
   * przeszkadza nikomu, a zgłoszony jako usterka zasypuje prawdziwe.
   */
  const przykryty = przykrywki.some((p) => {
    const wspolneY = Math.min(r.bottom, p.bottom) - Math.max(r.top, p.top);
    const wspolneX = Math.min(r.right, p.right) - Math.max(r.left, p.left);
    return wspolneY > r.height * 0.25 && wspolneX > 0;
  });

  return {
    indeks: el.getAttribute('data-kuking-fokus'),
    sciezka: el.tagName.toLowerCase()
      + (el.id ? `#${el.id}` : '')
      + (el.name ? `[name=${el.name}]` : '')
      + (el.classList.length ? `.${[...el.classList].slice(0, 2).join('.')}` : ''),
    nazwa: (el.getAttribute('aria-label') || el.innerText || el.value || '')
      .replace(/\s+/g, ' ').trim().slice(0, 40),
    typ: el.getAttribute('type') || '',
    styl: {
      outline: `${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}`,
      outlineOffset: s.outlineOffset,
      boxShadow: s.boxShadow,
      border: `${s.borderStyle} ${s.borderWidth} ${s.borderColor}`,
      background: s.backgroundColor,
    },
    rect: {
      top: Math.round(r.top), bottom: Math.round(r.bottom),
      left: Math.round(r.left), right: Math.round(r.right),
      w: Math.round(r.width), h: Math.round(r.height),
    },
    wOknie: r.top >= -1 && r.bottom <= window.innerHeight + 1,
    przykryty,
  };
};

/*
 * STAN ODNIESIENIA ZBIERAMY PRZED PIERWSZYM TABEM, A NIE PRZEZ `blur()`.
 *
 * Obrys rysuje `:focus-visible`, a to jest heurystyka przeglądarki: po
 * `element.blur(); element.focus()` z JavaScriptu Chromium potrafi go NIE
 * dopasować. Porównywanie „z fokusem" do „po sztucznym odebraniu fokusu"
 * mierzyłoby więc tę heurystykę, a nie nasz arkusz — i pokazywałoby brak
 * widocznego fokusu tam, gdzie człowiek z klawiaturą widzi go dobrze.
 * Dlatego zdejmujemy styl KAŻDEGO elementu ogniskowalnego zanim cokolwiek
 * dostanie fokus, i do tego porównujemy.
 */
const STYLE_SPOCZYNKOWE = () => {
  const SEL = 'a[href], button, summary, select, textarea, '
    + 'input:not([type=hidden]), [tabindex]:not([tabindex="-1"]), [role="button"]';
  const mapa = {};
  document.querySelectorAll(SEL).forEach((el, i) => {
    el.setAttribute('data-kuking-fokus', String(i));
    const s = getComputedStyle(el);
    mapa[i] = {
      outline: `${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}`,
      outlineOffset: s.outlineOffset,
      boxShadow: s.boxShadow,
      border: `${s.borderStyle} ${s.borderWidth} ${s.borderColor}`,
      background: s.backgroundColor,
    };
  });
  return mapa;
};

async function stanZalogowanego(przegladarka) {
  const kontekst = await przegladarka.newContext();
  const strona = await kontekst.newPage();
  await strona.goto(`${ADRES}/login`);
  await strona.fill('input[name="login"]', KONTO);
  await strona.fill('input[name="password"]', HASLO);
  await Promise.all([
    strona.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
    strona.click('button[type="submit"]'),
  ]);
  const stan = await kontekst.storageState();
  await kontekst.close();
  return stan;
}

async function main() {
  const przegladarka = await chromium.launch();
  const stan = await stanZalogowanego(przegladarka);
  const kontekst = await przegladarka.newContext({
    viewport: { width: 390, height: 844 },
    storageState: stan,
  });
  const strona = await kontekst.newPage();

  const raport = [];
  for (const f of FORMULARZE) {
    const odp = await strona.goto(ADRES + f.adres, { waitUntil: 'networkidle', timeout: 30000 });
    if (!odp || odp.status() >= 400) {
      raport.push({ formularz: f.nazwa, blad: `HTTP ${odp && odp.status()}` });
      continue;
    }
    const spoczynkowe = await strona.evaluate(STYLE_SPOCZYNKOWE);
    await strona.evaluate(() => document.body.focus());
    const kroki = [];
    for (let i = 0; i < KROKI; i += 1) {
      await strona.keyboard.press('Tab');
      /*
       * CZEKAMY NA KONIEC PRZEJŚCIA, ZANIM ZMIERZYMY POŁOŻENIE.
       *
       * `.skip-link` ma `transition: top 120ms` i wjeżdża zza górnej krawędzi
       * dopiero po dostaniu fokusu. Pomiar zaraz po `Tab` łapał go w połowie
       * drogi (`top: -46 px`) albo na samym starcie (`-64 px`) i zgłaszał
       * „element z fokusem poza oknem" — usterkę, której w produkcie nie ma.
       * 200 ms to zapas nad zadeklarowanymi 120 ms; nie jest to zakład
       * o szybkość maszyny, bo czas przejścia stoi w arkuszu, nie w sprzęcie.
       */
      await strona.waitForTimeout(200);
      const z = await strona.evaluate(OPISZ_FOKUS);
      if (!z) { kroki.push({ krok: i + 1, poza: true }); continue; }
      const bez = spoczynkowe[z.indeks];
      const rozniceStylu = bez
        ? Object.keys(z.styl).filter((k) => z.styl[k] !== bez[k])
        : ['(element pojawił się po wczytaniu — brak odniesienia)'];
      const maObrys = z.styl.outline.includes('none') === false
        && parseFloat(z.styl.outlineWidth || z.styl.outline.split(' ')[1]) !== 0;
      kroki.push({
        krok: i + 1,
        ...z,
        rozniceStylu,
        widocznyFokus: rozniceStylu.length > 0,
        obrysNiezerowy: maObrys,
      });
    }

    // Pułapka: ostatnie 20 kroków to zapętlony zbiór mniejszy niż 20,
    // a w tym zbiorze nie ma przycisku wysyłającego.
    const ogon = kroki.slice(-20).map((k) => k.sciezka || '(poza stroną)');
    const unikalne = new Set(ogon);
    const maSubmit = kroki.some((k) => (k.typ === 'submit')
      || /button/.test(k.sciezka || '') && /opublikuj|zaloguj|wyślij|zapisz|załóż|dalej/i.test(k.nazwa || ''));
    raport.push({
      formularz: f.nazwa,
      adres: f.adres,
      krokow: kroki.length,
      bezWidocznegoFokusu: kroki.filter((k) => k.sciezka && !k.widocznyFokus)
        .map((k) => ({ krok: k.krok, sciezka: k.sciezka, nazwa: k.nazwa, styl: k.styl })),
      przykryteBelka: kroki.filter((k) => k.przykryty)
        .map((k) => ({ krok: k.krok, sciezka: k.sciezka, rect: k.rect })),
      pozaOknem: kroki.filter((k) => k.sciezka && !k.wOknie)
        .map((k) => ({ krok: k.krok, sciezka: k.sciezka, rect: k.rect })),
      podejrzenieP: unikalne.size < 4 && !maSubmit,
      ogonUnikalnych: [...unikalne],
      osiagnietoSubmit: maSubmit,
      kroki,
    });
  }

  await przegladarka.close();
  mkdirSync('storage', { recursive: true });
  writeFileSync('storage/audyt-klawiatura.json', JSON.stringify(raport, null, 1));

  for (const r of raport) {
    if (r.blad) { console.log(`${r.formularz}: BŁĄD ${r.blad}`); continue; }
    console.log(`${r.formularz.padEnd(24)} kroków=${r.krokow} bez-widocznego-fokusu=${r.bezWidocznegoFokusu.length}`
      + ` przykryte=${r.przykryteBelka.length} poza-oknem=${r.pozaOknem.length}`
      + ` submit-osiągnięty=${r.osiagnietoSubmit ? 'tak' : 'NIE'} pułapka=${r.podejrzenieP ? 'PODEJRZENIE' : 'nie'}`);
    for (const b of r.bezWidocznegoFokusu.slice(0, 5)) console.log(`    ! krok ${b.krok}: ${b.sciezka} „${b.nazwa}"`);
    for (const b of r.przykryteBelka.slice(0, 5)) console.log(`    ~ krok ${b.krok} przykryty: ${b.sciezka} ${JSON.stringify(b.rect)}`);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
