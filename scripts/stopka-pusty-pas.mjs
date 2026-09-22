/* PUSTY PAS POD STOPKĄ — pomiar geometrii, nie treści arkusza.
 *
 * ZGŁOSZENIE. Właściciel patrzy na `https://kuking.pl` (wydanie ce76842)
 * i widzi pod trzema kolumnami stopki wysoki biały pas, a pod nim drugi,
 * szary. Zmierzone w Chromium na `/o-kuking`, gość, skala tekstu 100%:
 * pod ostatnią treścią stopki zostawało 484 px pustki przy 320 px i przy
 * 768 px — 200 px wewnątrz stopki (biały, bo `--color-surface-raised`)
 * i 284 px pod nią (szary, bo tło `body`).
 *
 * PRZYCZYNA. Rezerwa na przypiętą dolną nawigację była policzona DWA RAZY,
 * w dwóch różnych arkuszach: w `padding-bottom` stopki (`marka-rama.css`)
 * i w `padding-bottom` body (`szybki-wyglad.css`). Do tego naliczała się
 * KAŻDEMU, choć `.bottom-nav` stoi w `layout.blade.php` pod `@auth` — gość
 * dostawał 352 px miejsca na belkę, której u siebie nie ma.
 *
 * DLACZEGO TEN STRAŻNIK MIERZY, A NIE CZYTA CSS-a.
 * Test szukający reguły w arkuszu przechodzi także wtedy, gdy regułę przykryje
 * inna o wyższej swoistości, gdy `:has()` przestanie łapać albo gdy trzecie
 * miejsce doda rezerwę po raz kolejny. Liczy się jedna rzecz: ile PUSTEGO
 * MIEJSCA zostaje pod ostatnią treścią stopki w ułożonym dokumencie. Dlatego
 * mierzymy `scrollHeight` i prostokąty na żywej stronie.
 *
 * DWA KIERUNKI, BO SAM GÓRNY PRÓG BY NIE WYSTARCZYŁ.
 * Gdyby strażnik pilnował tylko „nie za dużo pustki", najtańszą naprawą
 * następnej regresji byłoby wycięcie rezerwy w całości — a wtedy przypięta
 * belka zasłoniłaby koniec stopki osobie zalogowanej (WCAG 2.4.11).
 * Dlatego dla stanu zalogowanego mierzymy też, że ostatnia treść stopki
 * MIEŚCI SIĘ NAD belką. Górny próg i dolny warunek trzymają się nawzajem.
 *
 * TRZECI KIERUNEK — MINIMA UX 50+.
 * Pas można skrócić także tak, że stopka przestanie spełniać minima projektu:
 * 48 px celu dotykowego, 18 px tekstu, brak przewijania w poziomie przy
 * 320 px (tam PR #918 podnosił minima, a #1013 naprawiał wywołane tym
 * przepełnienie — łatwo tu zepsuć jedno, naprawiając drugie). Te trzy liczby
 * są mierzone w tej samej pętli, żeby „skrócenie pasa" nie dało się kupić
 * ściśnięciem stopki.
 */
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';

const NAZWA = 'stopka-pusty-pas';

/* Własny odstęp stopki od dołu jej pudełka — 24 px w `marka-rama.css`.
   To NIE jest rezerwa na nakładki, tylko oddech pod ostatnim wierszem. */
const WLASNY_ODSTEP_STOPKI = 24;

/* Zapas ponad tym, co nakładki naprawdę zajmują: jeden cel dotykowy projektu.
   Powyżej tego przestaje to być odstęp, a zaczyna być pusty pas. */
const ZAPAS = 48;

/* Minima UX 50+ — te same liczby, których pilnuje `UX_50_PLUS.md`. */
const MIN_CEL = 48;
const MIN_TEKST = 18;

const SZEROKOSCI_GOSCIA = [320, 360, 390, 414, 768, 1440];
const SKALE = [100, 140];
const SZEROKOSCI_ZALOGOWANEGO = [320, 768, 1440];

async function zmierz(page) {
  return page.evaluate(() => {
    const stopka = document.querySelector('.site-footer');

    if (!stopka) return { brakStopki: true };

    const d = document.documentElement;
    const przewiniecie = window.scrollY;

    /* OSTATNIA TREŚĆ, NIE PUDEŁKO STOPKI. Pudełko sięga nisko właśnie przez
       `padding-bottom`, który tu badamy — mierząc je, mierzylibyśmy własną
       usterkę jako „treść". */
    const dolTresci = [...stopka.querySelectorAll('a, span, strong, p, h2, button')]
      .map((e) => e.getBoundingClientRect())
      .filter((r) => r.height > 0 && r.width > 0)
      .reduce((max, r) => Math.max(max, r.bottom + przewiniecie), -Infinity);

    /* Ile dolnej krawędzi okna zajmują NAKŁADKI: pływający przycisk „Wygląd",
       jego podpowiedź i — u zalogowanego — przypięta nawigacja. Liczymy
       zmierzoną geometrię, a nie wpisane liczby, żeby próg nie kłamał po
       zmianie pisma albo skali. Podpowiedź przycisku („Wygląd" pokazuje ją
       raz) celowo NIE liczy się do progu: jest chwilowa, a miejsca pod
       stopką nie rezerwuje się na rzeczy, które zaraz znikną. */
    const nakladki = [...document.querySelectorAll('.szybki-wyglad, .bottom-nav')]
      .filter((e) => {
        const s = getComputedStyle(e);

        return s.position === 'fixed' && s.display !== 'none' && s.visibility !== 'hidden' && e.getBoundingClientRect().height > 0;
      })
      .map((e) => e.getBoundingClientRect());

    /* „Belka jest" znaczy: stoi na ekranie. Od 64rem `.bottom-nav` zostaje
       w drzewie, ale znika z widoku — pytanie o sam `querySelector` dawało
       wtedy prostokąt zerowej wysokości u góry okna. */
    const belka = document.querySelector('.bottom-nav');
    const stylBelki = belka ? getComputedStyle(belka) : null;
    const belkaWidoczna = !!belka
      && stylBelki.position === 'fixed'
      && stylBelki.display !== 'none'
      && stylBelki.visibility !== 'hidden'
      && belka.getBoundingClientRect().height > 0;

    const odnosniki = [...stopka.querySelectorAll('.site-footer-grupa ul a')].map((a) => ({
      wysokosc: a.getBoundingClientRect().height,
      pismo: parseFloat(getComputedStyle(a).fontSize),
    }));

    return {
      brakStopki: false,
      dolTresci: Number.isFinite(dolTresci) ? Math.round(dolTresci) : null,
      /* TO JEST LICZBA ZE ZGŁOSZENIA: pustka od ostatniego słowa stopki
         do końca dokumentu, bez względu na to, który element ją tworzy. */
      pasPodTrescia: Number.isFinite(dolTresci) ? Math.round(d.scrollHeight - dolTresci) : null,
      wStopce: Math.round(parseFloat(getComputedStyle(stopka).paddingBottom)),
      podStopka: Math.round(d.scrollHeight - (stopka.getBoundingClientRect().bottom + przewiniecie)),
      nakladki: nakladki.length === 0 ? 0 : Math.round(window.innerHeight - Math.min(...nakladki.map((r) => r.top))),
      maBelke: !!belkaWidoczna,
      gornaKrawedzBelki: belkaWidoczna ? Math.round(belka.getBoundingClientRect().top + przewiniecie) : null,
      dolTresciWOknie: Number.isFinite(dolTresci) ? Math.round(dolTresci - przewiniecie) : null,
      gornaKrawedzBelkiWOknie: belkaWidoczna ? Math.round(belka.getBoundingClientRect().top) : null,
      przepelnieniePoziome: d.scrollWidth > d.clientWidth + 1,
      scrollWidth: d.scrollWidth,
      clientWidth: d.clientWidth,
      najnizszyCel: odnosniki.length === 0 ? null : Math.min(...odnosniki.map((o) => o.wysokosc)),
      najmniejszePismo: odnosniki.length === 0 ? null : Math.min(...odnosniki.map((o) => o.pismo)),
      liczbaOdnosnikow: odnosniki.length,
    };
  });
}

async function ustawIPoczekaj(page, adres, skala) {
  await page.goto(adres, { waitUntil: 'networkidle' });

  if (skala !== null) {
    await page.evaluate((s) => { document.documentElement.dataset.textScale = String(s); }, skala);
  }

  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  await page.waitForTimeout(250);
}

function sprawdzMinima(m, opis) {
  assert(m.liczbaOdnosnikow >= 6, `stopka ma tylko ${m.liczbaOdnosnikow} odnośników w kolumnach — pomiar minim byłby pusty (${opis})`);
  assert(
    m.najnizszyCel >= MIN_CEL - 0.5,
    `cel dotykowy w stopce zszedł do ${m.najnizszyCel} px przy wymaganych ${MIN_CEL} px (${opis})`,
  );
  assert(
    m.najmniejszePismo >= MIN_TEKST - 0.5,
    `tekst w stopce zszedł do ${m.najmniejszePismo} px przy wymaganych ${MIN_TEKST} px (${opis})`,
  );
  assert(
    !m.przepelnieniePoziome,
    `strona przewija się w poziomie: ${m.scrollWidth} > ${m.clientWidth} (${opis})`,
  );
}

export async function sprawdzStopke({ browser, adres, sesja, out = 'storage/port-projektu/stopka' }) {
  mkdirSync(out, { recursive: true });
  const wyniki = [];
  const sciezka = `${adres.replace(/\/$/, '')}/o-kuking`;

  /* GOŚĆ — tu usterka była widoczna i tu próg jest najostrzejszy, bo gość
     nie ma przypiętej belki, więc nie ma jej po co rezerwować miejsca. */
  for (const width of SZEROKOSCI_GOSCIA) {
    for (const skala of SKALE) {
      const strona = await browser.newPage({ viewport: { width, height: 740 }, serviceWorkers: 'block' });
      const opis = `gość, ${width} px, skala ${skala}%`;

      await ustawIPoczekaj(strona, sciezka, skala);

      const m = await zmierz(strona);

      assert(!m.brakStopki, `stopki nie ma na stronie (${opis})`);
      assert(m.dolTresci !== null, `stopka nie ma mierzalnej treści (${opis})`);
      assert.equal(m.maBelke, false, `gość nie powinien mieć przypiętej .bottom-nav (${opis})`);

      sprawdzMinima(m, opis);

      const prog = m.nakladki + WLASNY_ODSTEP_STOPKI + ZAPAS;

      assert(
        m.pasPodTrescia <= prog,
        `pusty pas pod stopką: ${m.pasPodTrescia} px przy dopuszczalnych ${prog} px `
        + `(${opis}); wewnątrz stopki ${m.wStopce} px, pod stopką ${m.podStopka} px, `
        + `nakładki zajmują ${m.nakladki} px dolnej krawędzi okna`,
      );

      wyniki.push({ kto: 'gość', width, skala, pas: m.pasPodTrescia, prog, nakladki: m.nakladki, wynik: 'PASS' });
      await strona.close();
    }
  }

  /* ZALOGOWANY — drugi kierunek. Rezerwa MA być i MA wystarczać, ale nadal
     tylko jedna: górny próg zostaje, bo podwójne liczenie bolało też tutaj
     (200 px w stopce plus 256 px pod nią). */
  if (sesja) {
    for (const width of SZEROKOSCI_ZALOGOWANEGO) {
      const kontekst = await browser.newContext({ storageState: sesja, viewport: { width, height: 740 }, reducedMotion: 'reduce' });
      const strona = await kontekst.newPage();
      const opis = `zalogowany, ${width} px`;

      await ustawIPoczekaj(strona, sciezka, null);

      const m = await zmierz(strona);

      assert(!m.brakStopki, `stopki nie ma na stronie (${opis})`);
      sprawdzMinima(m, opis);

      if (m.maBelke) {
        /* WARUNEK, DLA KTÓREGO REZERWA W OGÓLE ISTNIEJE (WCAG 2.4.11).
           Strona jest przewinięta do końca, więc porównujemy współrzędne
           w oknie: ostatnia treść stopki musi kończyć się nad belką. */
        assert(
          m.dolTresciWOknie <= m.gornaKrawedzBelkiWOknie,
          `treść stopki wchodzi pod przypiętą belkę: dół treści ${m.dolTresciWOknie} px, `
          + `górna krawędź belki ${m.gornaKrawedzBelkiWOknie} px (${opis})`,
        );
      }

      const prog = m.nakladki + WLASNY_ODSTEP_STOPKI + ZAPAS;

      assert(
        m.pasPodTrescia <= prog,
        `pusty pas pod stopką: ${m.pasPodTrescia} px przy dopuszczalnych ${prog} px `
        + `(${opis}); wewnątrz stopki ${m.wStopce} px, pod stopką ${m.podStopka} px, `
        + `nakładki zajmują ${m.nakladki} px dolnej krawędzi okna`,
      );

      wyniki.push({ kto: 'zalogowany', width, pas: m.pasPodTrescia, prog, nakladki: m.nakladki, belka: m.maBelke, wynik: 'PASS' });
      await strona.close();
      await kontekst.close();
    }
  }

  /* KONTROLA DODATNIA. Skan, który nic nie zmierzył, przechodzi
     (`docs/PULAPKI_TESTOW.md`) — więc pilnujemy, że macierz naprawdę się
     wykonała. Zielone z pustej pętli wygląda dokładnie tak samo jak zielone
     z pomiaru. */
  const oczekiwane = SZEROKOSCI_GOSCIA.length * SKALE.length + (sesja ? SZEROKOSCI_ZALOGOWANEGO.length : 0);

  assert.equal(wyniki.length, oczekiwane, `macierz niepełna: ${wyniki.length} z ${oczekiwane} przypadków`);

  writeFileSync(`${out}/${NAZWA}.json`, JSON.stringify(wyniki, null, 2));

  return wyniki;
}

/* Uruchomienie samodzielne, bez całego `port-projektu.mjs`:
   node scripts/stopka-pusty-pas.mjs --adres=http://127.0.0.1:8137
   Pełna macierz z kontem chodzi w `port-projektu.mjs`, który sam stawia
   serwer i logowanie. */
if (process.argv[1] && process.argv[1].endsWith('stopka-pusty-pas.mjs')) {
  const { chromium } = await import('playwright');
  const arg = process.argv.find((a) => a.startsWith('--adres='));
  const adres = arg ? arg.slice('--adres='.length) : process.env.ADRES;

  assert(adres, 'Podaj --adres=http://127.0.0.1:PORT albo zmienną ADRES.');

  const browser = await chromium.launch();

  try {
    const wyniki = await sprawdzStopke({ browser, adres });

    console.log(`Pusty pas pod stopką: ${wyniki.length} przypadków w progu.`);

    if (process.argv.includes('--json')) console.log(JSON.stringify(wyniki, null, 2));
  } finally {
    await browser.close();
  }
}
