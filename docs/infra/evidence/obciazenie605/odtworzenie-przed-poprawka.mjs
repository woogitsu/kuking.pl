#!/usr/bin/env node
/*
 * ODTWORZENIE USTERKI SPRZED POPRAWKI — #605, PR #679.
 *
 * Wycina NIEZMIENIONE ciało funkcji `zadanie()` ze wskazanej wersji
 * `scripts/generator-obciazenia-605.mjs` (domyślnie z SHA
 * 0e5d27079007195173c98768f8b474e315c1f44a) i kieruje je na
 * `scripts/serwer-scenariuszy-605.mjs`, na porcie przydzielonym dynamicznie.
 *
 * Nie dotyka aplikacji, bazy, kolejki, mediów ani produkcji. Żadna liczba
 * stąd nie jest wynikiem wydajnościowym Kukinga.
 *
 * Użycie (z katalogu repozytorium):
 *   git show 0e5d270:scripts/generator-obciazenia-605.mjs > /tmp/generator-0e5d270.mjs
 *   node docs/infra/evidence/obciazenie605/odtworzenie-przed-poprawka.mjs /tmp/generator-0e5d270.mjs
 *
 * Każdy scenariusz ma zewnętrzny watchdog — badana funkcja może nie wrócić,
 * a wiszący skrypt nie byłby wynikiem.
 */
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const KORZEN = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..');
const { uruchomSerwer } = await import(
  pathToFileURL(join(KORZEN, 'scripts', 'serwer-scenariuszy-605.mjs')).href
);

const zrodlo = process.argv[2];
if (!zrodlo) throw new Error('Podaj ścieżkę do wersji generatora sprzed poprawki.');
const tekst = readFileSync(zrodlo, 'utf8');

const POCZATEK = 'function zadanie({';
const KONIEC = '/** Sklejarka ciasteczek';
const i = tekst.indexOf(POCZATEK);
const j = tekst.indexOf(KONIEC);
if (i < 0 || j < 0 || j < i) throw new Error('Nie znaleziono jednoznacznej funkcji zadanie() w źródle.');
const cialo = tekst.slice(i, j).trimEnd();

const serwer = await uruchomSerwer();

// Funkcja jest brana DOSŁOWNIE; dokładamy tylko to, czego potrzebuje z modułu:
// `http`, `adres` wskazujący na stanowisko i `agent` o tych samych ustawieniach.
const modul = join(mkdtempSync(join(tmpdir(), 'kuking-605-przed-')), 'zadanie-oryginalne.mjs');
writeFileSync(modul, [
  "import http from 'node:http';",
  `const adres = new URL('http://127.0.0.1:${serwer.port}');`,
  'const agent = new http.Agent({ keepAlive: true, maxSockets: 4096, maxFreeSockets: 512 });',
  'export const zamknijAgenta = () => agent.destroy();',
  `export ${cialo}`,
  '',
].join('\n'));

const { zadanie, zamknijAgenta } = await import(pathToFileURL(modul).href);

async function probuj(nazwa, cfg, watchdogMs) {
  const start = Date.now();
  let wynik = null;
  let stan = 'ZAKOŃCZONE';
  await Promise.race([
    zadanie(cfg).then((w) => { wynik = w; }),
    new Promise((r) => setTimeout(() => { if (!wynik) stan = 'ZAWIESZONE'; r(); }, watchdogMs)),
  ]);
  return {
    scenariusz: nazwa,
    stan,
    ms_do_rozwiazania: wynik ? Date.now() - start : null,
    watchdog_ms: watchdogMs,
    limitMs_zadany: cfg.limitMs ?? null,
    wynik: wynik
      ? { status: wynik.status, ms: Math.round(wynik.ms), bajty: wynik.bajty, blad: wynik.blad ?? null }
      : null,
  };
}

const scenariusze = [
  await probuj('A. poprawna odpowiedź', { sciezka: '/pelna', limitMs: 2000 }, 5000),
  await probuj('B. brak odpowiedzi', { sciezka: '/brak-odpowiedzi', limitMs: 300 }, 5000),
  await probuj('C. przerwana odpowiedź po nagłówkach', { sciezka: '/urwana', limitMs: 200 }, 4000),
  await probuj('D. nagłówki bez zakończenia body', { sciezka: '/naglowki-bez-konca', limitMs: 300 }, 5000),
  await probuj('E. powolne fragmenty 12 B co 75 ms (12 sztuk)', { sciezka: '/powolne?ile=12&co=75&bajty=12', limitMs: 200 }, 6000),
  await probuj('F. powolne fragmenty bez końca', { sciezka: '/powolne-bez-konca?co=75&bajty=12', limitMs: 200 }, 4000),
];

console.log(JSON.stringify({
  zrodlo,
  port_stanowiska: serwer.port,
  scenariusze,
  polaczenia_otwarte_po_probach: serwer.otwartePolaczenia(),
}, null, 1));

await serwer.zamknij();
zamknijAgenta();
