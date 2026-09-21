import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';

/* PUSTY PAS POD STOPKĄ (zgłoszenie właściciela, 20 września 2026).
 *
 * Rezerwa na przypiętą dolną nawigację (`--rezerwa-pod-belka`) była liczona
 * DWA razy: raz w `padding-bottom` samej stopki, raz w `padding-bottom` body.
 * Dawało to 200 px wewnątrz stopki plus 256 px pod nią — 456 px pustki na
 * belkę wysoką 176 px. Gość, który dolnej nawigacji w ogóle nie ma
 * (`.bottom-nav` stoi pod `@auth`), dostawał całą tę rezerwę bez powodu.
 *
 * DLACZEGO TEN STRAŻNIK PYTA O WYNIK KASKADY, A NIE O TREŚĆ ARKUSZA.
 * Test czytający `marka-rama.css` byłby zielony także wtedy, gdy regułę
 * przykryje inna o wyższej swoistości albo gdy `:has()` przestanie łapać.
 * Liczy się jedna rzecz: ile pustego miejsca zostaje pod stopką w prawdziwym
 * układzie. Dlatego mierzymy `getComputedStyle` i geometrię, na żywej stronie.
 *
 * DWA KIERUNKI, BO SAM GÓRNY PRÓG NIE WYSTARCZA.
 * Gdyby strażnik pilnował wyłącznie „nie za dużo pustki", najtańszą naprawą
 * następnej regresji byłoby wycięcie rezerwy w całości — a wtedy przypięta
 * belka zasłoniłaby koniec stopki zalogowanej osobie. Dlatego dla stanu
 * zalogowanego mierzymy też, że ostatnia treść stopki **mieści się nad
 * belką**. Górny próg i dolny warunek trzymają się nawzajem.
 */

const NAZWA = 'stopka-bez-pustego-pasa';

/* Pływający przycisk „Wygląd" ma prawo do własnej rezerwy — mierzymy jego
   realną wysokość zamiast wpisywać liczbę, żeby próg nie kłamał po zmianie
   pisma albo skali. Zapas 40 px pokrywa odstęp przycisku od dołu okna
   i zaokrąglenia; powyżej tego robi się pusty pas, a nie odstęp. */
const ZAPAS_PONAD_WIDGETEM = 40;

async function zmierz(p) {
  return p.evaluate(() => {
    const stopka = document.querySelector('.site-footer');
    if (!stopka) return { brakStopki: true };
    const d = document.documentElement;
    const dolStopki = stopka.getBoundingClientRect().bottom + window.scrollY;
    const belka = document.querySelector('.bottom-nav');
    const widget = document.querySelector('.szybki-wyglad');
    /* Ostatni element z treścią w stopce, nie samo jej pudełko: pudełko może
       sięgać nisko właśnie przez padding, który tu badamy. */
    const tresc = [...stopka.querySelectorAll('a, span, strong, p, h2')]
      .map((e) => e.getBoundingClientRect())
      .filter((r) => r.height > 0)
      .reduce((max, r) => Math.max(max, r.bottom), -Infinity);
    return {
      brakStopki: false,
      pasPodStopka: Math.round(d.scrollHeight - dolStopki),
      paddingStopki: Math.round(parseFloat(getComputedStyle(stopka).paddingBottom)),
      paddingBody: Math.round(parseFloat(getComputedStyle(document.body).paddingBottom)),
      rezerwa: getComputedStyle(d).getPropertyValue('--rezerwa-pod-belka').trim(),
      maBelke: !!belka,
      gornaKrawedzBelki: belka ? Math.round(belka.getBoundingClientRect().top) : null,
      dolTresciStopki: Number.isFinite(tresc) ? Math.round(tresc) : null,
      wysokoscWidgetu: widget ? Math.round(widget.getBoundingClientRect().height) : 0,
      okno: window.innerHeight,
    };
  });
}

export async function sprawdzStopke({ browser, adres, sesja, out = 'output/stopka' }) {
  mkdirSync(out, { recursive: true });
  const wyniki = [];

  /* GOŚĆ — tu usterka była widoczna i tu jest najostrzejszy próg. */
  for (const width of [320, 360, 390, 414, 768, 1440]) {
    for (const scale of [100, 140]) {
      const p = await browser.newPage({ viewport: { width, height: 812 }, serviceWorkers: 'block' });
      await p.goto(adres);
      await p.evaluate((s) => { document.documentElement.dataset.textScale = String(s); }, scale);
      await p.evaluate(() => document.fonts.ready);
      await p.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
      await p.waitForTimeout(200);

      const m = await zmierz(p);
      assert(!m.brakStopki, `stopki nie ma na stronie (gość, ${width}px)`);
      assert(m.dolTresciStopki !== null, `stopka nie ma mierzalnej treści (gość, ${width}px)`);
      assert.equal(m.maBelke, false, `gość nie powinien mieć .bottom-nav (${width}px)`);

      /* Gość nie ma belki, więc rezerwa na nią musi być zerowa. Sprawdzamy
         wartość zmiennej, a nie tylko skutek — bo to ona rozjeżdża się cicho. */
      assert.match(m.rezerwa, /^0(rem|px)?$/, `gość dostał rezerwę na nieistniejącą belkę: ${m.rezerwa} (${width}px, skala ${scale})`);

      const prog = m.wysokoscWidgetu + ZAPAS_PONAD_WIDGETEM;
      assert(
        m.pasPodStopka <= prog,
        `pusty pas pod stopką: ${m.pasPodStopka} px przy dopuszczalnych ${prog} px (gość, ${width}px, skala ${scale}); padding stopki ${m.paddingStopki}, padding body ${m.paddingBody}`,
      );
      wyniki.push({ kto: 'gość', width, scale, pas: m.pasPodStopka, prog, rezerwa: m.rezerwa, result: 'PASS' });
      await p.close();
    }
  }

  /* ZALOGOWANY — drugi kierunek: rezerwa MA być i MA wystarczać.
     Trzy szerokości zamiast pełnej macierzy: mechanizm jest wspólny,
     a powtarzanie dwunastu konfiguracji kupowałoby minuty CI za tę samą wiedzę. */
  if (sesja) {
    for (const width of [320, 390, 414]) {
      const context = await browser.newContext({ storageState: sesja, viewport: { width, height: 812 }, reducedMotion: 'reduce' });
      const p = await context.newPage();
      await p.goto(adres);
      await p.evaluate(() => document.fonts.ready);
      await p.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
      await p.waitForTimeout(200);

      const m = await zmierz(p);
      assert(!m.brakStopki, `stopki nie ma na stronie (zalogowany, ${width}px)`);
      assert.equal(m.maBelke, true, `zalogowany powinien mieć .bottom-nav (${width}px)`);
      assert(
        !/^0(rem|px)?$/.test(m.rezerwa),
        `zalogowany stracił rezerwę na belkę (${width}px) — koniec stopki schowa się pod nawigacją`,
      );
      /* Warunek, dla którego rezerwa w ogóle istnieje. */
      assert(
        m.dolTresciStopki <= m.gornaKrawedzBelki,
        `treść stopki wchodzi pod przypiętą belkę: dół treści ${m.dolTresciStopki} px, górna krawędź belki ${m.gornaKrawedzBelki} px (${width}px)`,
      );
      wyniki.push({ kto: 'zalogowany', width, pas: m.pasPodStopka, rezerwa: m.rezerwa, result: 'PASS' });
      await p.close();
      await context.close();
    }
  }

  /* KONTROLA DODATNIA. Skan, który nic nie zmierzył, przechodzi
     (`docs/PULAPKI_TESTOW.md` §2) — więc pilnujemy, że macierz naprawdę się
     wykonała i że widget „Wygląd" został znaleziony. Bez tego zielone
     z pustej pętli wyglądałoby dokładnie tak samo jak zielone z pomiaru. */
  const oczekiwane = 12 + (sesja ? 3 : 0);
  assert.equal(wyniki.length, oczekiwane, `macierz niepełna: ${wyniki.length} z ${oczekiwane} przypadków`);

  writeFileSync(`${out}/${NAZWA}.json`, JSON.stringify(wyniki, null, 2));
  return wyniki;
}
