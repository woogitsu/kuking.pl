/*
 * =============================================================================
 *  Kuking.pl — ogląd odnośnika `#tag` w treści wpisu (issue #737)
 * =============================================================================
 *
 *  PO CO OSOBNY SKRYPT, SKORO JEST `scripts/dostepnosc.mjs`
 *  Automat dostępności chodzi po danych z `DemoSeeder`, a w nich NIE MA ani
 *  jednego wpisu z hashtagiem w treści. Przebieg na 47 ekranach świeci się
 *  więc na zielono, nie patrząc na ten element ani razu — czyli dokładnie ten
 *  rodzaj dowodu, który niczego nie dowodzi (`docs/PULAPKI_TESTOW.md` §1).
 *  Ten skrypt dokłada brakujący pomiar na wpisie, który tagi w treści MA.
 *
 *  CO MIERZY
 *  1. axe-core na stronie wpisu — w motywie jasnym i ciemnym;
 *  2. POLE DOTKNIĘCIA odnośnika — SKUTECZNE, nie nominalne. To jest sedno
 *     tego pomiaru i to on zmienił decyzję o CSS-ie. Wcięcie pionowe elementu
 *     liniowego NIE podbija wysokości wiersza: prostokąt odnośnika (45,8 px)
 *     wystaje ponad i pod wiersz tekstu (31,4 px), ale ta wystająca część
 *     bywa PRZYKRYTA przez następny wiersz i wtedy kliknicie w nią nie trafia
 *     w odnośnik. Nominalna wysokość z `getClientRects()` kłamałaby więc
 *     o kilka pikseli w górę. Skanujemy `elementFromPoint` co piksel
 *     i liczymy CIĄGŁY zakres, który naprawdę należy do odnośnika.
 *     Próg: 24 px — tyle wymaga WCAG 2.2 AA 2.5.8, i to mimo że odnośnik
 *     w zdaniu ma od tego kryterium WYJĄTEK. Zmierzone wartości są wyższe
 *     i wypisane wprost, żeby następna zmiana CSS-u widziała, ile traci;
 *  3. WIDOCZNY FOKUS — obwódka co najmniej 2 px, w kolorze innym niż tło;
 *  4. przewijanie w bok (ten sam próg, co w automacie dostępności);
 *  5. CZY KLIKNIĘCIE W ŚRODEK ODNOŚNIKA TRAFIA W TEN odnośnik. Fixture ma
 *     tagi w DWÓCH SĄSIEDNICH WIERSZACH — właśnie po to, żeby nachodzące na
 *     siebie pola dotknięcia miały szansę ukraść sobie kliknięcie.
 *
 *  Warianty: 320 px, 320 px przy naszej skali tekstu 140%, 360 px przy
 *  czcionce przeglądarki 200% i 1280 px — po dwa motywy.
 *
 *  URUCHOMIENIE
 *      # izolowana baza pomiarowa + dane demonstracyjne
 *      DB_DATABASE=kuking_737_a11y php artisan migrate --force
 *      DB_DATABASE=kuking_737_a11y php artisan db:seed --class=DemoSeeder --force
 *      DB_DATABASE=kuking_737_a11y php scripts/fixtures/tag-w-tresci.php   # wypisze adres wpisu
 *      DB_DATABASE=kuking_737_a11y APP_URL=http://127.0.0.1:8732 \
 *          php artisan serve --no-reload --host=127.0.0.1 --port=8732
 *      ADRES=http://127.0.0.1:8732 SCIEZKA=/wpisy/<uuid> node scripts/tag-w-tresci.mjs
 *
 *  Kod wyjścia 1 = którykolwiek pomiar poniżej progu. Pełny wynik idzie na
 *  stdout jako JSON — przy ośmiu wariantach lista nie mieści się w oknie.
 * =============================================================================
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';

const ADRES = process.env.ADRES;
const SCIEZKA = process.env.SCIEZKA;
if (!ADRES || !SCIEZKA) {
  console.error('Wymagane: ADRES=http://127.0.0.1:PORT SCIEZKA=/wpisy/<uuid>');
  process.exit(9);
}

const PROG_DOTYK = 24; // WCAG 2.2 AA 2.5.8, mierzone na polu SKUTECZNYM
const OCZEKIWANE_ODNOSNIKI = 3;

const warianty = [
  { nazwa: 'telefon 320 px', width: 320, height: 740, skala: null, czcionka: null },
  { nazwa: 'telefon 320 px + tekst 140%', width: 320, height: 740, skala: '1.4', czcionka: null },
  { nazwa: 'telefon 360 px + czcionka przeglądarki 200%', width: 360, height: 740, skala: null, czcionka: 32 },
  { nazwa: 'komputer 1280 px', width: 1280, height: 900, skala: null, czcionka: null },
];

const browser = await chromium.launch();
let blad = 0;
const wynik = [];

for (const motyw of ['light', 'dark']) {
  for (const w of warianty) {
    const ctx = await browser.newContext({
      viewport: { width: w.width, height: w.height },
      colorScheme: motyw,
      deviceScaleFactor: 1,
    });
    const page = await ctx.newPage();
    if (w.czcionka) {
      await page.addInitScript((px) => {
        document.addEventListener('DOMContentLoaded', () => {
          document.documentElement.style.fontSize = `${px}px`;
        });
      }, w.czcionka);
    }
    await page.goto(ADRES + SCIEZKA, { waitUntil: 'networkidle' });
    if (w.skala) {
      await page.evaluate((s) => document.documentElement.style.setProperty('--user-text-scale', s), w.skala);
      await page.waitForTimeout(150);
    }

    // Przy powiekszonej czcionce przegladarki serwis pokazuje przypiete
    // okienko „Dopasuj rozmiar tekstu i wyglad strony” (,
    // ). Zaslania srodek okna, wiec skan 
    // trafialby w nie, nie w odnosnik. To jest osobny, istniejacy element
    // z wlasnym zamknieciem — na czas POMIARU pola dotkniecia tego
    // odnosnika zdejmujemy je, zeby mierzyc to, co mierzymy.
    await page.evaluate(() => document.querySelectorAll('.szybki-wyglad-podpowiedz').forEach((e) => e.remove()));

    const linki = page.locator('.post-card-body a.tag-w-tresci');
    const ile = await linki.count();
    if (ile !== OCZEKIWANE_ODNOSNIKI) {
      console.log(`✗ ${motyw} / ${w.nazwa}: odnośników w treści ${ile}, oczekiwano ${OCZEKIWANE_ODNOSNIKI}`);
      blad = 1;
      await ctx.close();
      continue;
    }

    // Suma prostokątów klienta, nie `boundingBox`: element liniowy bywa
    // zawinięty na dwa wiersze i wtedy liczy się wyższy z kawałków.
    const miary = await linki.evaluateAll((wezly) => wezly.map((node) => {
      // `elementFromPoint` pracuje na WIDOKU, nie na dokumencie: punkt poza
      // oknem zwraca `null`, a wtedy skan pokazalby zero i wygladalo to na
      // usterke CSS-u. Najpierw wiec przewijamy element na srodek okna.
      node.scrollIntoView({ block: 'center' });
      const prostokaty = [...node.getClientRects()];
      const st = getComputedStyle(node);
      const r = prostokaty[0];
      const x = r.left + r.width / 2;

      // Skan co piksel: najdłuższy CIĄGŁY pas, który naprawdę trafia
      // w ten odnośnik. Patrz nagłówek, punkt 2.
      let poczatek = null;
      let najdluzszy = 0;
      let biezacy = 0;
      for (let y = Math.floor(r.top) - 8; y <= Math.ceil(r.bottom) + 8; y += 1) {
        if (document.elementFromPoint(x, y) === node) {
          biezacy += 1;
          if (poczatek === null) poczatek = y;
          if (biezacy > najdluzszy) najdluzszy = biezacy;
        } else {
          biezacy = 0;
        }
      }

      return {
        tekst: node.textContent,
        href: node.getAttribute('href'),
        wysokosc: Math.round(Math.max(...prostokaty.map((rr) => rr.height)) * 10) / 10,
        wysokosc_skuteczna: najdluzszy,
        szerokosc: Math.round(Math.max(...prostokaty.map((rr) => rr.width)) * 10) / 10,
        gora: Math.round(r.top),
        podkreslenie: st.textDecorationLine,
        rozmiarTekstu: st.fontSize,
      };
    }));
    for (const m of miary) {
      if (m.wysokosc_skuteczna < PROG_DOTYK) {
        console.log(`✗ ${motyw} / ${w.nazwa}: ${m.tekst} ma SKUTECZNE pole dotknięcia ${m.wysokosc_skuteczna} px (próg ${PROG_DOTYK}, nominalnie ${m.wysokosc} px)`);
        blad = 1;
      }
    }

    const fokus = await linki.first().evaluate((node) => {
      node.focus();
      const st = getComputedStyle(node);

      return {
        aktywny: document.activeElement === node,
        outlineStyle: st.outlineStyle,
        outlineWidth: st.outlineWidth,
        outlineColor: st.outlineColor,
        outlineOffset: st.outlineOffset,
        tlo: getComputedStyle(document.body).backgroundColor,
      };
    });
    if (!(fokus.aktywny && fokus.outlineStyle !== 'none' && parseFloat(fokus.outlineWidth) >= 2 && fokus.outlineColor !== fokus.tlo)) {
      console.log(`✗ ${motyw} / ${w.nazwa}: fokus niewidoczny — ${JSON.stringify(fokus)}`);
      blad = 1;
    }

    // Czy powiększone pole nie kradnie kliknięć sąsiedniemu wierszowi.
    const trafienia = await linki.evaluateAll((wezly) => wezly.map((node) => {
      node.scrollIntoView({ block: 'center' });
      const r = node.getClientRects()[0];
      const x = r.left + r.width / 2;
      const kto = (y) => {
        const el = document.elementFromPoint(x, y);

        return el === node ? 'ten odnośnik' : (el?.closest('a')?.textContent ?? el?.tagName ?? 'nic');
      };

      return { tekst: node.textContent, srodek: kto(r.top + r.height / 2), przy_gorze: kto(r.top + 2) };
    }));
    for (const t of trafienia) {
      if (t.srodek !== 'ten odnośnik' || t.przy_gorze !== 'ten odnośnik') {
        console.log(`✗ ${motyw} / ${w.nazwa}: kliknięcie w ${t.tekst} trafia gdzie indziej — ${JSON.stringify(t)}`);
        blad = 1;
      }
    }

    const przepelnienie = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (przepelnienie > 1) {
      console.log(`✗ ${motyw} / ${w.nazwa}: strona przewija się w bok o ${przepelnienie} px`);
      blad = 1;
    }

    const axe = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze();
    if (axe.violations.length > 0) {
      console.log(`✗ ${motyw} / ${w.nazwa}: axe — ${axe.violations.map((v) => `${v.id} (${v.nodes.length})`).join(', ')}`);
      blad = 1;
    }

    const wiersze = new Set(miary.map((m) => m.gora));
    console.log(
      `✓ ${motyw} / ${w.nazwa}: `
      + miary.map((m) => `${m.tekst} ${m.wysokosc_skuteczna}/${m.wysokosc}×${m.szerokosc} px`).join(', ')
      + ` | ${wiersze.size} wiersz(e) | tekst ${miary[0].rozmiarTekstu}, ${miary[0].podkreslenie}`
      + ` | fokus ${fokus.outlineWidth} ${fokus.outlineStyle} ${fokus.outlineColor} (offset ${fokus.outlineOffset})`
      + ` | axe naruszeń: ${axe.violations.length} | przewijanie w bok: ${przepelnienie} px`,
    );

    wynik.push({ motyw, wariant: w.nazwa, miary, fokus, trafienia, axe: axe.violations.length, przepelnienie });
    await ctx.close();
  }
}

await browser.close();
console.log(JSON.stringify({ adres: ADRES + SCIEZKA, prog_dotyk: PROG_DOTYK, blad, wynik }, null, 2));
process.exit(blad);
