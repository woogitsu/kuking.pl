/*
 * POMIAR SKUTKU KOLIZJI NR 3 — `.przepis-liczby`, 7rem (martwe) vs 10rem (żywe).
 *
 * Pytanie: czy `10rem` z warstwy `marka` zamiast `7rem` z warstwy `components`
 * psuje coś REALNEGO na stronie przepisu przy 320 px i powiększonym piśmie?
 *
 * Mierzymy usterki OSOBNO, bo to różne rzeczy:
 *   1. poziome przewijanie dokumentu,
 *   2. przepełnienie kafla (scrollWidth > clientWidth — tekst ucięty/wystający),
 *   3. przepełnienie samej SIATKI (ul.scrollWidth > ul.clientWidth).
 *
 * Wariant `wymuszone-7rem` wstrzykuje 7rem tam, gdzie wartość NAPRAWDĘ
 * obowiązuje (warstwa `marka`), a nie w martwej regule — inaczej mierzylibyśmy
 * ponownie to samo 10rem i wyszłoby, że „różnicy nie ma".
 *
 * CSP: strona ma `style-src 'self' 'nonce-...'`, więc `page.addStyleTag`
 * (inline bez nonce) jest BLOKOWANE. Bierzemy nonce z samej strony.
 */
import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { writeFileSync } from 'node:fs';

const BAZA = 'kuking_audyt_kaskada';
const SZEROKOSCI = [320, 360, 1280];

/* Slugi WPROST z bazy pomiarowej — /odkryj i / nie wystawiają gościowi linku
   do przepisu. `rosol-babci-zofii` to najgorszy przypadek: ma WSZYSTKIE TRZY
   kafle (Czas, Ilość, Poziom). `chleb-...` też ma trzy. */
const SLUGI = ['rosol-babci-zofii', 'chleb-pszenno-zytni-na-zakwasie'];

/* Dwa RÓŻNE mechanizmy powiększania pisma; mylenie ich to pułapka opisana
   w docs/PULAPKI_TESTOW.md:
   - `nasze-*`  → data-text-scale z profilu: rośnie TYLKO token tekstu,
                  `rem` w grid-template-columns zostaje bez zmian;
   - `korzen-*` → podmiana font-size korzenia przez CSSOM (ta sama droga,
                  której używa scripts/dostepnosc.mjs): rośnie `rem`, więc
                  rosną też 7rem/10rem. To odpowiednik ustawienia pisma
                  w przeglądarce. */
const PISMA = [
  { nazwa: 'bez', rodzaj: 'brak' },
  { nazwa: 'nasze-140', rodzaj: 'nasze', wartosc: '140' },
  { nazwa: 'korzen-140', rodzaj: 'korzen', wartosc: 16 * 1.4 },
  { nazwa: 'korzen-150', rodzaj: 'korzen', wartosc: 16 * 1.5 },
  /* Najgorszy realny przypadek: profil 140% ORAZ pismo przeglądarki 150%.
     Te mechanizmy się mnożą i nic ich nie wyklucza wzajemnie. */
  { nazwa: 'nasze-140+korzen-150', rodzaj: 'oba', wartosc: '140', korzen: 16 * 1.5 },
];

const WARIANTY_CSS = [
  { nazwa: 'stan-obecny-10rem', css: null },
  {
    nazwa: 'wymuszone-7rem',
    // Warstwa `marka` jest ostatnia przed `utilities`, więc to bije
    // marka-ekrany.css tak, jak marka-ekrany.css bije app.css.
    css: '@layer marka { .przepis-liczby { grid-template-columns: repeat(auto-fit, minmax(min(7rem, 100%), 1fr)); } }',
  },
];

const wolnyPort = () => new Promise((resolve, reject) => {
  const g = createServer();
  g.on('error', reject);
  g.listen(0, '127.0.0.1', () => { const { port } = g.address(); g.close(() => resolve(port)); });
});

async function podniesSerwer() {
  const port = await wolnyPort();
  const adres = `http://127.0.0.1:${port}`;
  const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, DB_DATABASE: BAZA, DB_PORT: '55439', DB_HOST: '127.0.0.1' },
  });
  for (let i = 0; i < 60; i++) {
    try { const o = await fetch(`${adres}/health`); if (o.ok) return { adres, zamknij: () => proces.kill('SIGTERM') }; } catch { /* wstaje */ }
    await new Promise((r) => setTimeout(r, 500));
  }
  proces.kill('SIGKILL');
  throw new Error('serwer nie wstał');
}

const POMIAR = () => {
  const ul = document.querySelector('.przepis-liczby');
  if (!ul) return { brak: true };
  const s = getComputedStyle(ul);
  const kafle = [...ul.querySelectorAll('.przepis-liczba')].map((li) => {
    const r = li.getBoundingClientRect();
    return {
      tekst: (li.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 30),
      szer: Math.round(r.width), wys: Math.round(r.height),
      x: Math.round(r.left),
      przepelnienie: li.scrollWidth - li.clientWidth,
    };
  });
  // Ile RZĘDÓW zajęły kafle — po liczbie różnych pozycji pionowych.
  const rzedy = new Set(kafle.map((k) => k.wys && Math.round(k.szer) && k.x)).size;
  return {
    display: s.display,
    trackList: s.gridTemplateColumns,
    ulSzer: Math.round(ul.getBoundingClientRect().width),
    ulWys: Math.round(ul.getBoundingClientRect().height),
    ulPrzepelnienie: ul.scrollWidth - ul.clientWidth,
    kolumnWRzedzie: rzedy,
    korzenFont: getComputedStyle(document.documentElement).fontSize,
    userTextScale: getComputedStyle(document.documentElement).getPropertyValue('--user-text-scale').trim() || '1',
    kafle,
    przewijaniePoziome: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  };
};

const serwer = await podniesSerwer();
const przegladarka = await chromium.launch();
const wyniki = [];
try {
  for (const wariant of WARIANTY_CSS) {
    for (const slug of SLUGI) {
      for (const szer of SZEROKOSCI) {
        for (const pismo of PISMA) {
          const ctx = await przegladarka.newContext({ viewport: { width: szer, height: 800 } });
          const strona = await ctx.newPage();
          const odp = await strona.goto(`${serwer.adres}/przepisy/${slug}`, { waitUntil: 'networkidle' });
          if (!odp.ok()) { console.log(`- ${slug}: HTTP ${odp.status()}`); await ctx.close(); continue; }

          if (wariant.css) {
            // Nonce bierzemy ze strony — inaczej CSP odrzuca <style>.
            const wstrzyknieto = await strona.evaluate((css) => {
              const n = document.querySelector('style[nonce], script[nonce], link[nonce]');
              const nonce = n?.nonce || n?.getAttribute('nonce') || '';
              const st = document.createElement('style');
              if (nonce) st.setAttribute('nonce', nonce);
              st.textContent = css;
              document.head.appendChild(st);
              return st.sheet ? st.sheet.cssRules.length : 0;
            }, wariant.css);
            if (!wstrzyknieto) { console.log(`! ${slug}/${szer}: NIE UDAŁO SIĘ wstrzyknąć 7rem — pomiar nieważny`); await ctx.close(); continue; }
          }
          if (pismo.rodzaj === 'nasze' || pismo.rodzaj === 'oba') {
            await strona.evaluate((v) => document.documentElement.setAttribute('data-text-scale', v), pismo.wartosc);
          }
          if (pismo.rodzaj === 'korzen') {
            await strona.evaluate((v) => { document.documentElement.style.fontSize = `${v}px`; }, pismo.wartosc);
          }
          if (pismo.rodzaj === 'oba') {
            await strona.evaluate((v) => { document.documentElement.style.fontSize = `${v}px`; }, pismo.korzen);
          }
          await strona.waitForTimeout(200);
          const pomiar = await strona.evaluate(POMIAR);
          const klucz = `${wariant.nazwa}/${slug.slice(0, 16)}/${szer}/${pismo.nazwa}`;
          if (pomiar.brak) { console.log(`- ${klucz}: brak .przepis-liczby`); await ctx.close(); continue; }
          wyniki.push({ klucz, wariant: wariant.nazwa, slug, szerokosc: szer, pismo: pismo.nazwa, ...pomiar });
          const zle = [];
          if (pomiar.przewijaniePoziome > 1) zle.push(`PRZEWIJANIE ${pomiar.przewijaniePoziome}px`);
          if (pomiar.ulPrzepelnienie > 1) zle.push(`siatka +${pomiar.ulPrzepelnienie}px`);
          const kafleZle = pomiar.kafle.filter((k) => k.przepelnienie > 1);
          if (kafleZle.length) zle.push(`KAFLE: ${kafleZle.map((k) => `"${k.tekst}" +${k.przepelnienie}px`).join(', ')}`);
          const geo = pomiar.kafle.map((k) => `${k.szer}x${k.wys}@${k.x}`).join(' ');
          console.log(`${zle.length ? '✗' : '✓'} ${klucz.padEnd(56)} disp=${pomiar.display} korzeń=${pomiar.korzenFont.padStart(7)} skala=${pomiar.userTextScale.padStart(4)} ul=${pomiar.ulSzer}x${pomiar.ulWys} kafle[${geo}] ${zle.join(' | ')}`);
          await ctx.close();
        }
      }
    }
    console.log('');
  }
} finally {
  await przegladarka.close();
  serwer.zamknij();
}
writeFileSync('/tmp/pomiar-liczby.json', JSON.stringify(wyniki, null, 2));
console.log('Pełny wynik: /tmp/pomiar-liczby.json');
