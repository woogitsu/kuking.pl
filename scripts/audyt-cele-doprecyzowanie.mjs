/*
 * Kuking.pl — doprecyzowanie pomiaru celów dotknięcia.
 *
 * Pierwszy przebieg (`audyt-ux50plus.mjs`) mierzy sam element interaktywny.
 * Dla trzech wzorców to jest POMIAR NIE TEGO, W CO SIĘ STUKA:
 *   • `input[type=radio|checkbox]` w `label.choice` — palec trafia w etykietę,
 *   • `input[type=file]` schowany pod własną etykietą-przyciskiem,
 *   • odnośnik autora w karcie wpisu — sprawdzamy, czy obok niego jest drugi,
 *     duży cel prowadzący w to samo miejsce (awatar).
 * Ten skrypt mierzy CAŁY klikalny obszar: element albo jego `<label>`.
 */
import { chromium } from 'playwright';

const ADRES = process.env.ADRES || 'http://127.0.0.1:8137';
const KONTO = 'ania';
const HASLO = 'haslo-testowe-123';

const EKRANY = [
  { nazwa: 'ustawienia — czytelność', adres: '/ustawienia/czytelnosc' },
  { nazwa: 'dodaj zdjęcie', adres: '/dodaj/zdjecie' },
  { nazwa: 'dodaj przepis', adres: '/dodaj/przepis' },
  { nazwa: 'zadaj pytanie', adres: '/pytania/zadaj' },
  { nazwa: 'tablica startowa', adres: '/home' },
  { nazwa: 'tryb gotowania', adres: `${process.env.PRZEPIS || '/przepisy/rosol-babci-zofii'}/gotuj` },
  { nazwa: 'Poradźcie (pytania)', adres: '/pytania' },
  { nazwa: 'stopka (strona powitalna)', adres: '/' },
];

const ZMIERZ = () => {
  const r = (el) => {
    const b = el.getBoundingClientRect();
    return { w: Math.round(b.width * 10) / 10, h: Math.round(b.height * 10) / 10 };
  };
  const widoczny = (el) => {
    const b = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return b.width > 0 && b.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
  };

  const wynik = { pola: [], stopka: [], autorzy: [] };

  for (const pole of document.querySelectorAll('input[type=radio], input[type=checkbox], input[type=file]')) {
    const etykieta = pole.closest('label')
      || (pole.id ? document.querySelector(`label[for="${CSS.escape(pole.id)}"]`) : null);
    wynik.pola.push({
      typ: pole.type,
      nazwa: pole.name,
      wartosc: pole.value,
      pole: r(pole),
      poleWidoczne: widoczny(pole),
      etykieta: etykieta ? r(etykieta) : null,
      etykietaKlasa: etykieta ? [...etykieta.classList].join('.') : null,
    });
  }

  for (const a of document.querySelectorAll('.site-footer a')) {
    if (!widoczny(a)) continue;
    wynik.stopka.push({ napis: a.innerText.replace(/\s+/g, ' ').trim().slice(0, 30), ...r(a), px: getComputedStyle(a).fontSize });
  }

  for (const glowka of document.querySelectorAll('.post-card-head')) {
    const awatar = glowka.querySelector('.post-card-awatar');
    const nazwa = glowka.querySelector('.post-card-tozsamosc a');
    wynik.autorzy.push({
      awatar: awatar ? { ...r(awatar), href: awatar.getAttribute('href') } : null,
      nazwa: nazwa ? { ...r(nazwa), href: nazwa.getAttribute('href'), napis: nazwa.innerText.trim().slice(0, 25) } : null,
    });
  }

  return wynik;
};

async function main() {
  const p = await chromium.launch();
  const k0 = await p.newContext();
  const s0 = await k0.newPage();
  await s0.goto(`${ADRES}/login`);
  await s0.fill('input[name="login"]', KONTO);
  await s0.fill('input[name="password"]', HASLO);
  await Promise.all([
    s0.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
    s0.click('button[type="submit"]'),
  ]);
  const stan = await k0.storageState();
  await k0.close();

  for (const szerokosc of [320, 390, 1440]) {
    const kontekst = await p.newContext({ viewport: { width: szerokosc, height: 900 }, storageState: stan });
    const strona = await kontekst.newPage();
    for (const e of EKRANY) {
      const odp = await strona.goto(ADRES + e.adres, { waitUntil: 'networkidle' });
      if (!odp || odp.status() >= 400) { console.log(`${e.nazwa} @${szerokosc}: HTTP ${odp && odp.status()}`); continue; }
      const d = await strona.evaluate(ZMIERZ);
      console.log(`\n=== ${e.nazwa} @ ${szerokosc}px`);
      for (const f of d.pola) {
        console.log(`  ${f.typ.padEnd(8)} ${String(f.nazwa).padEnd(14)} pole=${f.pole.w}×${f.pole.h}`
          + ` widoczne=${f.poleWidoczne}  etykieta=${f.etykieta ? `${f.etykieta.w}×${f.etykieta.h} (.${f.etykietaKlasa})` : 'BRAK'}`);
      }
      if (d.stopka.length) {
        console.log('  -- stopka --');
        for (const a of d.stopka) console.log(`    ${String(a.napis).padEnd(26)} ${a.w}×${a.h}  ${a.px}`);
      }
      if (d.autorzy.length) {
        console.log('  -- autorzy w kartach (awatar / nazwa) --');
        for (const a of d.autorzy.slice(0, 3)) {
          console.log(`    awatar=${a.awatar ? `${a.awatar.w}×${a.awatar.h} → ${a.awatar.href}` : 'brak'}`
            + `  nazwa=${a.nazwa ? `${a.nazwa.w}×${a.nazwa.h} „${a.nazwa.napis}" → ${a.nazwa.href}` : 'brak'}`);
        }
      }
    }
    await kontekst.close();
  }
  await p.close();
}

main().catch((e) => { console.error(e); process.exit(1); });
