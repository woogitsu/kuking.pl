/*
 * =============================================================================
 *  Regresje kryterium nasycenia, powrotu do normy i doboru ruchu — #605
 * =============================================================================
 *
 *  Uruchamiany z `scripts/przyrzad-605.test.mjs` (a więc przez job CI
 *  „Przyrząd testu obciążeniowego (#605)" i `scripts/check.sh`); samodzielnie:
 *      node scripts/nasycenie-605.test.mjs
 *
 *  CO TU JEST SPRAWDZANE
 *  PRZYRZĄD, nie portal: czy kryterium nasycenia klasyfikuje stopnie zgodnie ze
 *  swoją jawną definicją, czy rampa staje po nasyceniu i po serwisie, który nie
 *  wrócił, czy druga strona feedu idzie z prawdziwym kursorem, a dobór widzów
 *  obejmuje konta z 800 i 1200 obserwowanymi. Żadna liczba stąd nie jest
 *  wynikiem wydajnościowym Kukinga — dane są sztuczne.
 *
 *  KONTROLE UJEMNE SĄ FIZYCZNE: każda psuje KOPIĘ pliku (z asercją, że
 *  podmiana coś zmieniła), uruchamia to samo sprawdzenie i wymaga, żeby OBLAŁO.
 *  Oryginały są porównywane sumą MD5 przed i po.
 * =============================================================================
 */

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import http from 'node:http';
import {
  chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { uruchomSerwer } from './serwer-scenariuszy-605.mjs';

const KATALOG = dirname(fileURLToPath(import.meta.url));
const KORZEN = dirname(KATALOG);
const NASYCENIE = join(KATALOG, 'nasycenie-605.mjs');
const GENERATOR = join(KATALOG, 'generator-obciazenia-605.mjs');
const RAMPA = join(KATALOG, 'rampa-obciazenia-605.sh');
const DOWODY = join(KORZEN, 'docs/infra/evidence/obciazenie605');
const md5 = (sciezka) => createHash('md5').update(readFileSync(sciezka)).digest('hex');
const MD5_NA_WEJSCIU = { [NASYCENIE]: md5(NASYCENIE), [GENERATOR]: md5(GENERATOR), [RAMPA]: md5(RAMPA) };

const roboczy = mkdtempSync(join(tmpdir(), 'kuking-605-nasycenie-'));
let zdane = 0;
const powiedz = (co) => { zdane += 1; process.stdout.write(`  ✓ ${co}\n`); };
const czekaj = (ms) => new Promise((r) => setTimeout(r, ms));

// Każdy załadowany generator (oryginał i kopie zmutowane) ma własnego agenta HTTP
// z keep-alive; bez zamknięcia proces testu nie kończyłby się.
// Serwery atrap sprzątane na końcu także wtedy, gdy sprawdzenie na zepsutej kopii
// przerwie się asercją w środku (inaczej otwarty port trzymałby proces).
const sprzatanie = [];
const zaladowane = [];
const modul = async (sciezka) => {
  const m = await import(`${pathToFileURL(sciezka).href}?v=${Math.random().toString(36).slice(2)}`);
  zaladowane.push(m);
  return m;
};
const N = await modul(NASYCENIE);

// -----------------------------------------------------------------------------
//  Dane sztuczne
// -----------------------------------------------------------------------------

function seriaSyn({
  rps, blad = 0, przep = rps, p95 = 100, p95b = p95, wLocie = rps * 2,
  powody = { ok: 100 }, statusy = { 200: 100 }, start = '2026-09-29T10:00:00.000Z', trwanie = 120,
}) {
  return {
    seria: `r${rps}`,
    zadany_rps: rps,
    start,
    trwanie_s: trwanie,
    razem: {
      zadan_wyslanych: 1000, poprawnych: 1000, blad_procent: blad, przepustowosc_rps: przep,
      p50: p95 / 3, p95, p99: p95 * 1.5, p95_z_bledami: p95b, p99_z_bledami: p95b, porzuconych_przez_limit: 0,
    },
    w_locie_szczyt: wLocie,
    endpointy: { strona: { powody, statusy } },
  };
}

function zapiszStopien(katalog, nazwa, seria, werdykt = 'CZYSTY', probnik = []) {
  if (seria) writeFileSync(join(katalog, `seria-${nazwa}.json`), JSON.stringify(seria));
  writeFileSync(join(katalog, `werdykt-${nazwa}.json`), JSON.stringify({ seria: nazwa, werdykt }));
  if (probnik.length) writeFileSync(join(katalog, `probnik-${nazwa}.jsonl`), probnik.map((p) => JSON.stringify(p)).join('\n'));
}

const NASYCONA = (rps) => seriaSyn({
  rps, blad: 60, przep: 4, p95: 20000, p95b: 20001, wLocie: 600,
  powody: { ok: 40, bezczynnosc: 60 }, statusy: { 200: 40, bezczynnosc: 60 },
});

/** Katalog wyników udający rampę: 5 zdrowy (odniesienie p95 400 ms), 15 degradacja (p95 900 ms = 2,25 ×), 30 i 60 nasycone. */
function rampaSyn(nazwaKatalogu, { sondaR030 = null } = {}) {
  const k = join(roboczy, nazwaKatalogu);
  mkdirSync(k, { recursive: true });
  zapiszStopien(k, 'r005-p1', seriaSyn({ rps: 5, p95: 400 }), 'SKAZONY');
  zapiszStopien(k, 'r005-p2', seriaSyn({ rps: 5, p95: 400 }));
  zapiszStopien(k, 'r015-p1', seriaSyn({ rps: 15, p95: 900 }));
  const start = '2026-09-29T10:10:00.000Z';
  const probnik = [];
  for (let s = 0; s < 120; s++) {
    probnik.push({
      t: new Date(Date.parse(start) + s * 1000).toISOString(), kontener_rdzenie: 0.5, kontener_rss_mb: 600,
      kontener_rss_szczyt_mb: 700, db_polaczenia: 6, db_aktywne: 4, db_czeka_na_blokade: 0, kolejka: 2, najstarsze_zadanie_s: 3, nieudane_zadania: 0,
    });
  }
  zapiszStopien(k, 'r030-p1', { ...NASYCONA(30), start }, 'CZYSTY', probnik);
  zapiszStopien(k, 'r060-p1', NASYCONA(60));
  if (sondaR030) writeFileSync(join(k, 'powrot-r030-p1.json'), JSON.stringify(sondaR030));
  return k;
}

// =============================================================================
// 1. Klasyfikacja stopnia — każde kryterium OSOBNO
// =============================================================================

{
  const odn = { rps: 5, p95_ms: 400, seria: 'r005-p2' };
  const oc = (seria, werdykt = 'CZYSTY') => N.ocenStopien(seria, { werdykt }, odn);

  assert.equal(oc(seriaSyn({ rps: 20, p95: 500 })).stan, 'ZDROWY');
  assert.equal(oc(seriaSyn({ rps: 20, p95: 800 })).stan, 'DEGRADACJA', 'p95 = 2 × odniesienia to degradacja');
  assert.equal(oc(seriaSyn({ rps: 20, p95: 1999 })).stan, 'DEGRADACJA');
  assert.equal(oc(seriaSyn({ rps: 20, p95: 2000 })).stan, 'NASYCONY', 'p95 = 5 × odniesienia to nasycenie');
  const blad = oc(seriaSyn({ rps: 20, blad: 1.5, p95: 400 }));
  assert.equal(blad.stan, 'NASYCONY', 'sam błąd > 1 % wystarcza, choć p95 jest zdrowe');
  assert.match(blad.powody.join(' '), /błąd 1\.5 %/);
  assert.equal(oc(seriaSyn({ rps: 20, blad: 1, p95: 400 })).stan, 'ZDROWY', 'błąd równy 1 % nie jest jeszcze nasyceniem');
  const przep = oc(seriaSyn({ rps: 20, przep: 17, p95: 400 }));
  assert.equal(przep.stan, 'NASYCONY', 'przepustowość < 90 % zadanego to nasycenie, choć błędów brak');
  assert.match(przep.powody.join(' '), /przepustowość 17 rps/);
  assert.equal(oc(seriaSyn({ rps: 20, przep: 18, p95: 400 })).stan, 'ZDROWY');
  powiedz('kryterium: każdy próg (błąd, przepustowość, p95 ×2, p95 ×5) działa osobno i na granicy');

  // p95 z poprawnych SPADA, gdy najwolniejsze żądania wypadają jako błędy.
  assert.equal(N.p95Efektywne(seriaSyn({ rps: 20, p95: 100, p95b: 20000 })), 20000);
  assert.equal(N.p95Efektywne(seriaSyn({ rps: 20, p95: 900, p95b: 50 })), 900);
  assert.equal(N.p95Efektywne({}), null);
  powiedz('p95 do oceny to większe z p95 poprawnych i p95 z błędami');

  for (const w of ['SKAZONY', 'NIEWYKONANY', 'BRAK']) {
    const o = oc(NASYCONA(30), w);
    assert.equal(o.stan, 'NIEOCENIONY', `stopień ${w} nie może wyznaczać punktu nasycenia`);
  }
  assert.equal(N.ocenStopien(null, { werdykt: 'CZYSTY' }, odn).stan, 'NIEOCENIONY');
  powiedz('stopień skażony, niewykonany albo bez pliku wyniku jest NIEOCENIONY, a nie „nasycony"');

  assert.equal(N.ocenStopien(seriaSyn({ rps: 5, p95: 5000 }), { werdykt: 'CZYSTY' }, null).stan, 'ZDROWY',
    'bez odniesienia działają tylko progi bezwzględne');
  powiedz('bez odniesienia p95 oceniają tylko progi bezwzględne i mówią to wprost');
}

// =============================================================================
// 2. Wybór próby i odniesienie
// =============================================================================

{
  const lista = [
    { nazwa: 'r005-p1', rps: 5, proba: 1, seria: seriaSyn({ rps: 5, p95: 999 }), werdykt: { werdykt: 'SKAZONY' } },
    { nazwa: 'r005-p2', rps: 5, proba: 2, seria: seriaSyn({ rps: 5, p95: 300 }), werdykt: { werdykt: 'CZYSTY' } },
    { nazwa: 'r015-p1', rps: 15, proba: 1, seria: seriaSyn({ rps: 15 }), werdykt: { werdykt: 'SKAZONY' } },
    { nazwa: 'r015-p2', rps: 15, proba: 2, seria: null, werdykt: { werdykt: 'NIEWYKONANY' } },
  ];
  const st = N.wybierzProby(lista);
  assert.deepEqual(st.map((s) => [s.rps, s.wybrana?.nazwa ?? null]), [[5, 'r005-p2'], [15, null]]);
  assert.equal(N.odniesienieP95(st).p95_ms, 300, 'odniesienie bierze CZYSTĄ próbę, nie skażoną (p95 999)');
  assert.equal(N.odniesienieP95([{ rps: 5, wybrana: { nazwa: 'x', seria: seriaSyn({ rps: 5, blad: 4, p95: 50 }) } }]), null,
    'stopień z błędami nie może być odniesieniem');
  assert.equal(N.odniesienieP95([{ rps: 5, wybrana: { nazwa: 'x', seria: seriaSyn({ rps: 5, p95: 20 }) } }]).p95_ms, 100,
    'odniesienie ma podłogę 100 ms, żeby „5 ×" z 5 ms nie było degradacją');
  powiedz('z powtórzonych prób liczy się pierwsza CZYSTA; skażone i z błędami nie są odniesieniem');
}

// =============================================================================
// 3. Charakter degradacji i sygnały zasobów
// =============================================================================

{
  const ch = N.charakterDegradacji(NASYCONA(30));
  assert.match(ch.glowny, /przekroczenia czasu/);
  assert.equal(ch.przekroczenia_czasu, 60);
  const c429 = N.charakterDegradacji(seriaSyn({ rps: 30, powody: { ok: 10 }, statusy: { 200: 10, 429: 50 } }));
  assert.match(c429.glowny, /429/);
  assert.equal(c429.odpowiedzi_429, 50);
  assert.match(N.charakterDegradacji(seriaSyn({ rps: 30, statusy: { 200: 1, 503: 9 } })).glowny, /5xx/);
  assert.equal(N.charakterDegradacji(seriaSyn({ rps: 30 })).glowny, 'brak błędów');
  powiedz('charakter degradacji odróżnia przekroczenia czasu, 5xx, zerwania i limity 429');

  const probka = (o) => ({ kontener_rdzenie: 0.4, kontener_rss_szczyt_mb: 500, db_polaczenia: 5, db_czeka_na_blokade: 0, najstarsze_zadanie_s: 1, ...o });
  assert.deepEqual(N.sygnalyZasobow(Array.from({ length: 100 }, () => probka({}))).sygnaly, []);
  // Jeden pik CPU nie jest wyczerpaniem (średnia stopnia z 20.09 to ok. 0,5 rdzenia, a jedna próbka miała 1,9).
  assert.deepEqual(N.sygnalyZasobow([...Array.from({ length: 99 }, () => probka({})), probka({ kontener_rdzenie: 1.9 })]).sygnaly, []);
  assert.match(N.sygnalyZasobow(Array.from({ length: 100 }, (_, i) => probka({ kontener_rdzenie: i < 30 ? 1.95 : 0.4 }))).sygnaly.join(' '), /CPU kontenera/);
  assert.match(N.sygnalyZasobow([probka({ kontener_rss_szczyt_mb: 1000 })]).sygnaly.join(' '), /pamięć kontenera/);
  assert.match(N.sygnalyZasobow([probka({ db_czeka_na_blokade: 2 })]).sygnaly.join(' '), /blokady/);
  assert.match(N.sygnalyZasobow([probka({ najstarsze_zadanie_s: 90 })]).sygnaly.join(' '), /kolejka zadań/);
  assert.match(N.sygnalyZasobow([probka({ db_polaczenia: 85 })]).sygnaly.join(' '), /połączenia DB/);
  powiedz('sygnały zasobów: CPU liczy się jako przebywanie przy limicie, nie jeden pik; pamięć, blokady, kolejka, połączenia');
}

// =============================================================================
// 4. Analiza całej rampy
// =============================================================================

function sprawdzAnalize(N_) {
  const a = N_.analizuj(N_.wczytajKatalog(rampaSyn('analiza-1')));
  assert.deepEqual(a.stopnie.map((s) => [s.rps, s.stan]), [[5, 'ZDROWY'], [15, 'DEGRADACJA'], [30, 'NASYCONY'], [60, 'NASYCONY']]);
  assert.equal(a.pierwsza_degradacja_rps, 15);
  assert.equal(a.pierwszy_nasycony_rps, 30);
  assert.equal(a.ostatni_zdrowy_rps, 5);
  assert.deepEqual(a.przedzial_nasycenia_rps, [5, 30]);
  assert.match(a.charakter.glowny, /przekroczenia czasu/);
  assert.equal(a.zasoby_przy_nasyceniu.sygnaly.length, 0, 'próbki nie pokazują wyczerpanego zasobu');
  const po = a.stopnie.find((s) => s.rps === 60);
  assert.equal(po.po_nasyceniu, true);
  assert.match(po.ostrzezenie, /zależny od zaległości/, 'stopień po nasyceniu bez zapisanego powrotu musi to mówić');
  assert.ok(a.ostrzezenia.some((o) => /bez zapisanego powrotu/.test(o)));
  return a;
}
sprawdzAnalize(N);
powiedz('analiza rampy: przedział nasycenia (ostatni zdrowy, pierwszy nasycony), charakter, zasoby, ostrzeżenie o zależności po nasyceniu');

{
  const z = N.analizuj(N.wczytajKatalog(rampaSyn('analiza-2', { sondaR030: { wrocil: true, czas_powrotu_s: 42, limit_s: 900 } })));
  assert.equal(z.stopnie.find((s) => s.rps === 60).ostrzezenie, undefined, 'zapisany powrót zdejmuje ostrzeżenie o zależności');
  const nie = N.analizuj(N.wczytajKatalog(rampaSyn('analiza-3', { sondaR030: { wrocil: false, limit_s: 900 } })));
  assert.match(nie.stopnie.find((s) => s.rps === 60).ostrzezenie, /zależny od zaległości/, 'powrót, który się nie udał, niczego nie zdejmuje');
  assert.match(nie.powrot_do_normy.map((p) => p.powod).join(' '), /nie wrócił w 900 s/);
  powiedz('powrót do normy: udany zdejmuje ostrzeżenie o zależności, nieudany je zostawia i jest w raporcie');
}

{
  const k = join(roboczy, 'bez-nasycenia');
  mkdirSync(k);
  zapiszStopien(k, 'r005-p1', seriaSyn({ rps: 5, p95: 300 }));
  zapiszStopien(k, 'r015-p1', seriaSyn({ rps: 15, p95: 350 }));
  const a = N.analizuj(N.wczytajKatalog(k));
  assert.equal(a.pierwszy_nasycony_rps, null);
  assert.equal(a.przedzial_nasycenia_rps, null);
  assert.ok(a.ostrzezenia.some((o) => /nasycenia NIE osiągnięto/.test(o)), 'brak nasycenia musi być powiedziany, żeby nikt nie cytował „wytrzymuje 15 rps"');
  assert.match(N.raportTekst(a), /NASYCENIA NIE OSIĄGNIĘTO/);
  powiedz('gdy nasycenia nie osiągnięto, raport mówi to wprost i nie podaje pojemności');
}

// Ten sam werdykt na PRAWDZIWYCH danych z pomiaru z 20.09.2026 (dowody w repozytorium).
{
  const dowody = join(DOWODY, '2026-09-20-gpt/serie');
  assert.ok(existsSync(dowody), 'Brak dowodów z 20.09.2026 — test nie miałby na czym sprawdzać kryterium.');
  const a = N.analizuj(N.wczytajKatalog(dowody));
  assert.equal(a.odniesienie.seria, 'r005-p2', 'odniesieniem jest pierwszy CZYSTY stopień 5 rps (r005-p1 był skażony)');
  assert.deepEqual(a.stopnie.map((s) => [s.rps, s.stan]), [[5, 'ZDROWY'], [15, 'DEGRADACJA'], [30, 'NASYCONY'], [60, 'NASYCONY'], [120, 'NASYCONY']]);
  assert.equal(a.pierwsza_degradacja_rps, 15);
  assert.deepEqual(a.przedzial_nasycenia_rps, [5, 30]);
  assert.ok(a.powrot_do_normy.some((p) => p.zrodlo === 'r005-powrot' && p.wrocil === false), 'r005-powrot (100 % błędów) to „nie wrócił"');
  assert.deepEqual(a.pominiete_serie.filter((n) => !/jit/.test(n)), [], 'poza parą JIT nic nie powinno zostać pominięte');
  powiedz('na prawdziwych danych z 20.09.2026 kryterium daje to, co raport opisał ręcznie: degradacja od 15, nasycenie między 5 a 30 rps, brak powrotu');
}

// =============================================================================
// 5. CLI: `stopien` (używane przez rampę) i `analiza`
// =============================================================================

function uruchom(skrypt, args, { env = {}, limitMs = 60000, cwd = KORZEN } = {}) {
  return new Promise((resolve) => {
    const proces = spawn(skrypt.endsWith('.sh') ? 'bash' : process.execPath, [skrypt, ...args], {
      cwd, env: { ...process.env, ...env }, stdio: ['ignore', 'pipe', 'pipe'],
    });
    let out = ''; let err = '';
    proces.stdout.on('data', (d) => { out += d; });
    proces.stderr.on('data', (d) => { err += d; });
    const zegar = setTimeout(() => proces.kill('SIGKILL'), limitMs);
    proces.on('exit', (kod) => { clearTimeout(zegar); resolve({ kod, out, err }); });
  });
}

async function sprawdzCli(sciezka) {
  const k = rampaSyn('cli');
  const kody = {};
  for (const n of ['r005-p2', 'r015-p1', 'r030-p1', 'r005-p1']) {
    kody[n] = (await uruchom(sciezka, ['stopien', k, n])).kod;
  }
  assert.deepEqual(kody, { 'r005-p2': 0, 'r015-p1': 0, 'r030-p1': 3, 'r005-p1': 4 }, 'kody wyjścia `stopien`: 0 zdrowy, 3 nasycony, 4 nieoceniony');
  assert.equal((await uruchom(sciezka, ['stopien', k, 'nie-ma'])).kod, 2);
  const a = await uruchom(sciezka, ['analiza', k, '--json']);
  assert.equal(a.kod, 0);
  assert.equal(JSON.parse(a.out).pierwszy_nasycony_rps, 30);
  assert.equal((await uruchom(sciezka, ['analiza', join(roboczy, 'brak-katalogu')])).kod, 2);
}
await sprawdzCli(NASYCENIE);
powiedz('CLI: `stopien` zwraca 0/3/4/2, `analiza --json` daje parsowalny wynik, brak katalogu to błąd');

// =============================================================================
// 6. Generator: druga strona feedu z kursorem, dobór widzów, sonda powrotu
// =============================================================================

const G = await modul(GENERATOR);

{
  // Odnośnik dokładnie taki, jaki renderuje `components/show-more.blade.php` (Blade escapuje `&`).
  const html = `<a href="/tag/rosol?cursor=INNY">tag</a>
    <a class="btn btn-secondary" href="http://localhost/home?zrodlo=obserwowani&amp;cursor=eyJpZCI6MTIzfQ%3D%3D">Następna strona wpisów</a>`;
  assert.equal(G.nastepnaStronaFeedu(html), '/home?zrodlo=obserwowani&cursor=eyJpZCI6MTIzfQ%3D%3D');
  // Wyjście PRAWDZIWEGO komponentu `x-show-more` z CursorPaginator (29.09.2026, `artisan tinker`), bez zmian.
  const zKomponentu = `<div class="pokaz-wiecej text-center mt-6" data-pokaz-wiecej="cursor">
 <p class="m-0">
 <a class="btn btn-secondary" href="/home?zrodlo=obserwowani&amp;cursor=eyJjcmVhdGVkX2F0IjoiMjAyNi0wOS0wMSAwOTowMDowMCIsImlkIjoyLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9">Następna strona wpisów</a>
 </p></div>`;
  assert.equal(G.nastepnaStronaFeedu(zKomponentu),
    '/home?zrodlo=obserwowani&cursor=eyJjcmVhdGVkX2F0IjoiMjAyNi0wOS0wMSAwOTowMDowMCIsImlkIjoyLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9');
  assert.equal(G.nastepnaStronaFeedu('<a href="/tag/x?cursor=1">x</a>'), null, 'kursor cudzej listy (nie /home) nie jest drugą stroną feedu');
  assert.equal(G.nastepnaStronaFeedu('<p>koniec listy</p>'), null);
  assert.equal(G.nastepnaStronaFeedu(undefined), null);
  powiedz('druga strona feedu: kursor i `zrodlo` wyciągnięte z odnośnika „Następna strona"; cudze listy i brak odnośnika dają null');
}

function sprawdzWidzow(G_) {
  // Dokładny rozkład z scripts/dane-obciazenia-605.php ($profilObserwacji × 2).
  const profil = [10, 10, 10, 10, 10, 10, 25, 25, 25, 25, 50, 50, 50, 50, 100, 100, 100, 100, 100, 100, 250, 250, 250, 500, 500, 500, 500, 800, 1200, 1200];
  const wszyscy = [...profil, ...profil].map((obserwuje, i) => ({ email: `b605-widz-${i}@example.test`, obserwuje }));
  const osiem = G_.wybierzWidzow(wszyscy, 8).map((w) => w.obserwuje);
  assert.deepEqual([...osiem].sort((a, b) => a - b), [10, 25, 50, 100, 250, 500, 800, 1200], '8 widzów musi obejmować wszystkie klasy');
  const dwadziescia4 = G_.wybierzWidzow(wszyscy, 24).map((w) => w.obserwuje);
  assert.ok(dwadziescia4.includes(800) && dwadziescia4.includes(1200), '24 widzów (tyle logowano 20.09) musi mieć konta 800 i 1200');
  assert.equal(new Set(G_.wybierzWidzow(wszyscy, 60).map((w) => w.email)).size, 60, 'bez powtórzeń kont');
  assert.deepEqual(G_.wybierzWidzow(wszyscy, 24), G_.wybierzWidzow(wszyscy, 24), 'deterministycznie');
  assert.equal(G_.wybierzWidzow(wszyscy, 500).length, 60, 'nie więcej niż jest kont');
  assert.throws(() => G_.wybierzWidzow([], 5), /Brak widzów/);
  assert.throws(() => G_.wybierzWidzow([{ email: 'a', obserwuje: 'dużo' }], 1), /obserwuje/);
}
sprawdzWidzow(G);
powiedz('dobór widzów: 8 kont pokrywa wszystkie klasy, 24 konta zawierają 800 i 1200 (dawniej: 10–500), deterministycznie');

{
  const dane = JSON.parse(readFileSync(join(DOWODY, 'dane.json'), 'utf8'));
  const ob = G.wybierzWidzow(dane.widzowie, 24).map((w) => w.obserwuje);
  assert.ok(Math.max(...ob) === 1200, 'na prawdziwym dane.json 24 widzów ma konto z 1200 obserwowanymi');
  assert.equal(Math.max(...dane.widzowie.slice(0, 24).map((w) => w.obserwuje)), 500, 'sanity: dawny dobór (0..23) kończył na 500');
  powiedz('na prawdziwym dane.json: dawny dobór (konta 0..23) kończył na 500 obserwowanych, nowy sięga 1200');
}

// --- Sonda powrotu -----------------------------------------------------------

async function sprawdzSonde(G_) {
  const s = await uruchomSerwer();
  sprzatanie.push(() => s.zamknij());
  const cel = { hostname: '127.0.0.1', port: s.port };
  const dobra = await G_.sondaPowrotu({ cel, sciezka: '/pelna', kolejnych: 3, coMs: 10, limitMs: 5000, budzetMs: 2000 });
  assert.equal(dobra.wrocil, true);
  assert.equal(dobra.prob, 3, 'zdrowy serwis: dokładnie trzy próby, passa od razu');
  assert.equal(dobra.czas_powrotu_s, 0);

  const zla = await G_.sondaPowrotu({ cel, sciezka: '/blad', kolejnych: 3, coMs: 20, limitMs: 400, budzetMs: 2000 });
  assert.equal(zla.wrocil, false, 'serwer odpowiadający 500 nie „wrócił"');
  assert.equal(zla.czas_powrotu_s, null);
  assert.ok(zla.prob >= 2);

  const bezOdpowiedzi = await G_.sondaPowrotu({ cel, sciezka: '/brak-odpowiedzi', kolejnych: 1, coMs: 10, limitMs: 300, calkowityMs: 100 });
  assert.equal(bezOdpowiedzi.wrocil, false, 'brak odpowiedzi w limicie żądania to brak powrotu, a sonda się kończy');
  assert.ok(bezOdpowiedzi.proby.every((p) => p.powod === 'deadline' || p.powod === 'bezczynnosc'));
  await s.zamknij();

  // Migotanie: F S F S S S — jedno szczęśliwe żądanie nie jest powrotem, liczy się passa.
  const odpowiedzi = [500, 200, 500, 200, 200, 200];
  let i = 0;
  const migotanie = http.createServer((req, res) => { res.writeHead(odpowiedzi[Math.min(i++, odpowiedzi.length - 1)]); res.end('x'); });
  await new Promise((r) => migotanie.listen(0, '127.0.0.1', r));
  sprzatanie.push(async () => { migotanie.closeAllConnections(); await new Promise((r) => migotanie.close(() => r())); });
  const m = await G_.sondaPowrotu({ cel: { hostname: '127.0.0.1', port: migotanie.address().port }, kolejnych: 3, coMs: 60, limitMs: 5000, budzetMs: 2000 });
  assert.equal(m.wrocil, true);
  assert.equal(m.prob, 6, 'passa trzech po dwóch pojedynczych sukcesach przedzielonych błędem');
  assert.ok(m.czas_powrotu_s >= 0.1, 'czas powrotu liczony od początku passy, nie od pierwszego szczęśliwego żądania');
  migotanie.closeAllConnections();
  await new Promise((r) => migotanie.close(r));
}
await sprawdzSonde(G);
powiedz('sonda powrotu: zdrowy serwis wraca od razu, 500 i brak odpowiedzi nie są powrotem, migotanie wymaga passy');

{
  const s = await uruchomSerwer();
  const wynik = join(roboczy, 'powrot-cli.json');
  const dobry = await uruchom(GENERATOR, ['powrot', '--baza', s.baza, '--sciezka', '/pelna', '--co', '10', '--limit', '5', '--wynik', wynik]);
  assert.equal(dobry.kod, 0, dobry.err);
  assert.equal(JSON.parse(readFileSync(wynik, 'utf8')).wrocil, true);
  const zly = await uruchom(GENERATOR, ['powrot', '--baza', s.baza, '--sciezka', '/blad', '--co', '50', '--limit', '1', '--wynik', wynik]);
  assert.equal(zly.kod, 5, 'CLI: serwis, który nie wrócił, kończy się kodem 5 — na tym opiera się rampa');
  assert.equal(JSON.parse(readFileSync(wynik, 'utf8')).wrocil, false);
  const literowka = await uruchom(GENERATOR, ['powrot', '--baza', s.baza, '--limit', 'dlugo']);
  assert.notEqual(literowka.kod, 0, 'literówka w --limit zatrzymuje sondę, zamiast dać „wrócił" z niczego');
  await s.zamknij();
  powiedz('CLI `powrot`: kod 0 gdy wrócił, 5 gdy nie, literówka w opcji zatrzymuje bieg');
}

// --- zal_feed_str2 idzie z kursorem -----------------------------------------

async function sprawdzFeedStr2(generator) {
  const widziane = [];
  const serwer = http.createServer((req, res) => {
    req.resume();
    widziane.push(req.url);
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end('ok');
  });
  await new Promise((r) => serwer.listen(0, '127.0.0.1', r));
  const dir = mkdtempSync(join(roboczy, 'feed2-'));
  try {
    writeFileSync(join(dir, 'los.cjs'), 'Math.random = () => 0.51;'); // 0.51 × 100 → „zal_feed_str2" (49 < x <= 53)
    writeFileSync(join(dir, 'manifest.json'), JSON.stringify({
      sesje: [
        { ciasteczka: 'a=1', token: 't', home_str2: '/home?zrodlo=obserwowani&cursor=ABC' },
        { ciasteczka: 'b=1', token: 't', home_str2: null },
      ],
      cele: { przepisy: [], wpisy: [], tagi: [], profile: [], media: [] },
    }));
    const wynik = join(dir, 'wynik.json');
    const wlasciwy = await new Promise((resolve) => {
      const p = spawn(process.execPath, ['--require', join(dir, 'los.cjs'), generator, 'seria', '--baza', `http://127.0.0.1:${serwer.address().port}`,
        '--manifest', join(dir, 'manifest.json'), '--rps', '20', '--czas', '2', '--wynik', wynik, '--zdjecia', dir], { stdio: ['ignore', 'ignore', 'pipe'] });
      let err = ''; p.stderr.on('data', (d) => { err += d; });
      const z = setTimeout(() => p.kill('SIGKILL'), 30000);
      p.on('exit', (kod) => { clearTimeout(z); resolve({ kod, err }); });
    });
    assert.equal(wlasciwy.kod, 0, wlasciwy.err);
    const w = JSON.parse(readFileSync(wynik, 'utf8')).endpointy.zal_feed_str2;
    assert.ok(w.zadan > 0, 'scenariusz musiał wysłać żądania');
    assert.ok(widziane.includes('/home?zrodlo=obserwowani&cursor=ABC'), `Druga strona feedu nie poszła z kursorem: ${[...new Set(widziane)].join(', ')}`);
    assert.ok(!widziane.some((u) => /page=2/.test(u)), '`?page=2` jest ignorowany przez /home — to nie jest druga strona');
    assert.ok(w.pominietych_brak_celu > 0, 'widz bez kolejnej porcji feedu daje scenariusz POMINIĘTY i widoczny, nie zmyślone żądanie');
    assert.equal(w.blad_procent, null, 'przy pominiętych jednej uczciwej liczby błędów nie ma');
  } finally {
    serwer.closeAllConnections();
    await new Promise((r) => serwer.close(r));
  }
}
await sprawdzFeedStr2(GENERATOR);
powiedz('seria: zal_feed_str2 wysyła kursor ze strony pierwszej widza, nigdy `?page=2`; brak kursora = pominięty i jawny');

// =============================================================================
// 7. Rampa: staje po nasyceniu, staje po serwisie, który nie wrócił, wymaga korpusu
// =============================================================================

/** Atrapa `seria-obciazenia-605.sh`: od 30 rps stopień jest nasycony. Bez bazy, kontenera i bramki. */
function atrapaSerii() {
  const sciezka = join(roboczy, 'atrapa-serii.sh');
  const js = join(roboczy, 'atrapa-serii.mjs');
  writeFileSync(js, `
    import { writeFileSync } from 'node:fs';
    const [nazwa, rps, , katalog] = process.argv.slice(2);
    const r = Number(rps);
    const nasycony = r >= 30;
    const razem = { zadan_wyslanych: 1000, poprawnych: nasycony ? 300 : 1000, nieudanych: nasycony ? 700 : 0,
      przepustowosc_rps: nasycony ? 3 : r, blad_procent: nasycony ? 70 : 0,
      p50: 50, p95: nasycony ? 20000 : 100 + r, p99: 300, p95_z_bledami: nasycony ? 20001 : 100 + r, p99_z_bledami: 300, porzuconych_przez_limit: 0 };
    writeFileSync(katalog + '/seria-' + nazwa + '.json', JSON.stringify({ seria: nazwa, zadany_rps: r, start: '2026-09-29T10:00:00.000Z', trwanie_s: 1,
      razem, w_locie_szczyt: 10, koszt_generatora: { cpu_rdzenie_srednio: 0.01 },
      endpointy: { strona: { powody: nasycony ? { ok: 300, bezczynnosc: 700 } : { ok: 1000 }, statusy: {} } } }));
    writeFileSync(katalog + '/werdykt-' + nazwa + '.json', JSON.stringify({ seria: nazwa, zadany_rps: r, werdykt: 'CZYSTY',
      obce_obciazenie_rdzenie: { min: 1, mediana: 2, p95: 3, max: 4 } }));
  `);
  writeFileSync(sciezka, `#!/usr/bin/env bash\nexec "${process.execPath}" "${js}" "$@"\n`);
  chmodSync(sciezka, 0o755);
  return sciezka;
}
const ATRAPA = atrapaSerii();

/**
 * Serwis udający aplikację: zdrowy, dopóki w katalogu wyników nie pojawi się
 * seria nasycona; wtedy „choruje" na `chorujeMs` (Infinity = nie wraca).
 */
async function serwisZaleznyOdRampy(katalog, chorujeMs) {
  let chory = false;
  let chorowal = false;
  const serwer = http.createServer((req, res) => {
    req.resume();
    if (chory) { res.writeHead(503); res.end('zaległość'); return; }
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end('ok');
  });
  await new Promise((r) => serwer.listen(0, '127.0.0.1', r));
  const czujka = setInterval(() => {
    if (!chorowal && existsSync(join(katalog, 'seria-r030-p1.json'))) {
      chorowal = true;
      chory = true;
      if (Number.isFinite(chorujeMs)) setTimeout(() => { chory = false; }, chorujeMs);
    }
  }, 5);
  return {
    baza: `http://127.0.0.1:${serwer.address().port}`,
    zamknij: async () => { clearInterval(czujka); serwer.closeAllConnections(); await new Promise((r) => serwer.close(r)); },
  };
}

async function uruchomRampe(rampa, nazwa, { chorujeMs = Infinity, env = {}, stopnie = '5 15 30 60' } = {}) {
  const katalog = join(roboczy, nazwa);
  mkdirSync(katalog, { recursive: true });
  const serwis = await serwisZaleznyOdRampy(katalog, chorujeMs);
  try {
    const bieg = await uruchom(rampa, [katalog, join(roboczy, 'manifest-atrapa.json')], {
      cwd: dirname(dirname(rampa)),
      env: {
        SERIA_605: ATRAPA, STOPNIE: stopnie, CZAS: '1', PRZERWA: '0', MAKS_PROB: '1', KORPUS_ZDJEC: '/atrapa/korpus.json',
        BAZA_APLIKACJI: serwis.baza, MAKS_POWROTU_S: '1', POWROT_CO_MS: '50', BUDZET_POWROTU_MS: '1000', ...env,
      },
      limitMs: 90000,
    });
    const log = existsSync(join(katalog, 'rampa.log')) ? readFileSync(join(katalog, 'rampa.log'), 'utf8') : '';
    return { ...bieg, log, katalog, pliki: readdirSync(katalog) };
  } finally {
    await serwis.zamknij();
  }
}

async function sprawdzRampe(rampa, prefiks) {
  // (a) Nasycenie i powrót zmierzony → rampa staje po stopniu 30, stopnia 60 nie puszcza.
  const a = await uruchomRampe(rampa, `${prefiks}-stop`, { chorujeMs: 300 });
  assert.equal(a.kod, 0, `${a.log}\n${a.err}`);
  assert.ok(a.pliki.includes('seria-r030-p1.json'));
  assert.ok(!a.pliki.includes('seria-r060-p1.json'), 'Rampa poszła za nasycenie mimo PO_NASYCENIU=stop');
  assert.match(a.log, /NASYCENIE: stopień r030/);
  assert.match(a.log, /kończę \(PO_NASYCENIU=stop\)/);
  const sonda = JSON.parse(readFileSync(join(a.katalog, 'powrot-r030-p1.json'), 'utf8'));
  assert.equal(sonda.wrocil, true, 'po chorobie 300 ms serwis wrócił i sonda musi to zapisać');
  assert.ok(a.pliki.includes('nasycenie.txt'), 'na koniec rampa zapisuje analizę nasycenia');
  assert.match(readFileSync(join(a.katalog, 'nasycenie.txt'), 'utf8'), /Punkt nasycenia leży w przedziale \(15, 30\]/);

  // (b) Serwis NIE wraca po nasyceniu → rampa kończy kodem 3, nie mierzy następnego stopnia.
  const b = await uruchomRampe(rampa, `${prefiks}-nie-wrocil`, { chorujeMs: Infinity, env: { PO_NASYCENIU: 'dalej' } });
  assert.equal(b.kod, 3, `Rampa nie przerwała mimo serwisu, który nie wrócił.\n${b.log}\n${b.err}`);
  assert.ok(!b.pliki.includes('seria-r060-p1.json'), 'Stopień po serwisie, który nie wrócił, mierzyłby zaległość poprzednika');
  assert.match(b.log, /SERWIS NIE WRÓCIŁ DO NORMY/);
  assert.equal(JSON.parse(readFileSync(join(b.katalog, 'powrot-r030-p1.json'), 'utf8')).wrocil, false);
  assert.match(b.log, /Nie restartuję niczego/);

  // (c) PO_NASYCENIU=dalej + powrót → mierzy też stopień 60, a analiza nie ostrzega o zależności.
  const c = await uruchomRampe(rampa, `${prefiks}-dalej`, { chorujeMs: 200, env: { PO_NASYCENIU: 'dalej' } });
  assert.equal(c.kod, 0, `${c.log}\n${c.err}`);
  assert.ok(c.pliki.includes('seria-r060-p1.json'));
  assert.ok(!/bez zapisanego powrotu do normy/.test(readFileSync(join(c.katalog, 'nasycenie.txt'), 'utf8')),
    'powrót zmierzony po r030 — stopień 60 nie jest już „zależny od zaległości bez powrotu"');
}

await sprawdzRampe(RAMPA, 'rampa');
powiedz('rampa: staje po nasyceniu z zmierzonym powrotem; kończy kodem 3, gdy serwis nie wrócił; `dalej` mierzy wyższe stopnie');

{
  const bez = await uruchom(RAMPA, [join(roboczy, 'rampa-bez-korpusu'), 'manifest'], { env: { KORPUS_ZDJEC: '', ZDJECIA_SYNTETYCZNE: '', SERIA_605: ATRAPA, STOPNIE: '5' } });
  assert.equal(bez.kod, 2, 'Rampa bez korpusu zdjęć musi odmówić startu');
  assert.match(bez.err, /KORPUS_ZDJEC/);
  assert.match(bez.err, /prawdziwych zdjęć 12\/24\/48 MP/);
  const zle = await uruchom(RAMPA, [join(roboczy, 'rampa-zle-po'), 'manifest'], { env: { KORPUS_ZDJEC: '/x.json', PO_NASYCENIU: 'moze', SERIA_605: ATRAPA } });
  assert.equal(zle.kod, 2, 'Nieznana wartość PO_NASYCENIU zatrzymuje start');
  powiedz('rampa odmawia startu bez korpusu zdjęć (albo jawnego ZDJECIA_SYNTETYCZNE=tak) i przy złym PO_NASYCENIU');
}

// =============================================================================
// 8. Kontrole ujemne — fizyczne mutacje kopii
// =============================================================================

let numerMutacji = 0;
function mutuj(zrodlo, zamiany) {
  numerMutacji += 1;
  const dir = join(roboczy, `mutacja-${numerMutacji}`);
  const skrypty = join(dir, 'scripts');
  mkdirSync(skrypty, { recursive: true });
  // Rampa woła generator i analizator po ścieżce względnej od korzenia — kopiujemy komplet.
  for (const plik of ['generator-obciazenia-605.mjs', 'nasycenie-605.mjs', 'rampa-obciazenia-605.sh', 'serwer-scenariuszy-605.mjs']) {
    copyFileSync(join(KATALOG, plik), join(skrypty, plik));
  }
  const cel = join(skrypty, basename(zrodlo));
  let tekst = readFileSync(cel, 'utf8');
  for (const [z, na] of zamiany) {
    assert.ok(tekst.includes(z), `Kontrola ujemna nie trafiła: nie znaleziono „${z.slice(0, 60)}" — mutacja byłaby pusta.`);
    tekst = tekst.replace(z, na);
  }
  writeFileSync(cel, tekst);
  chmodSync(cel, 0o755);
  return cel;
}

async function musiOblac(nazwa, sprawdzenie) {
  let oblalo = false;
  let powod = '';
  try {
    await sprawdzenie();
  } catch (e) {
    oblalo = true;
    powod = String(e?.message ?? e).split('\n')[0].slice(0, 110);
  }
  assert.ok(oblalo, `KONTROLA UJEMNA NIEUDANA: ${nazwa} — sprawdzenie przeszło na zepsutej kopii, więc niczego nie pilnuje.`);
  powiedz(`kontrola ujemna: ${nazwa} → sprawdzenie OBLEWA (${powod})`);
}

// -- kryterium ----------------------------------------------------------------
await musiOblac('wycięty próg błędu nasycenia', async () => {
  const kopia = mutuj(NASYCENIE, [['blad !== null && blad > kryteria.blad_procent_nasycenie', 'false']]);
  const M = await modul(kopia);
  assert.equal(M.ocenStopien(seriaSyn({ rps: 20, blad: 1.5, p95: 400 }), { werdykt: 'CZYSTY' }, { p95_ms: 400 }).stan, 'NASYCONY');
});
await musiOblac('wycięta przepustowość z kryterium', async () => {
  const kopia = mutuj(NASYCENIE, [['if (zadany && przep !== null && przep < kryteria.przepustowosc_ulamek_nasycenie * zadany) {', 'if (false) {']]);
  const M = await modul(kopia);
  assert.equal(M.ocenStopien(seriaSyn({ rps: 20, przep: 17, p95: 400 }), { werdykt: 'CZYSTY' }, { p95_ms: 400 }).stan, 'NASYCONY');
});
await musiOblac('p95 tylko z poprawnych (bez p95 z błędami)', async () => {
  const kopia = mutuj(NASYCENIE, [['return Math.max(a ?? 0, b ?? 0);', 'return a ?? 0;']]);
  const M = await modul(kopia);
  assert.equal(M.p95Efektywne(seriaSyn({ rps: 20, p95: 100, p95b: 20000 })), 20000);
});
await musiOblac('skażony stopień oceniany jak czysty', async () => {
  const kopia = mutuj(NASYCENIE, [["if (wv !== 'CZYSTY') {", 'if (false) {']]);
  const M = await modul(kopia);
  assert.equal(M.ocenStopien(NASYCONA(30), { werdykt: 'SKAZONY' }, { p95_ms: 400 }).stan, 'NIEOCENIONY');
});
await musiOblac('brak ostrzeżenia o zależności po nasyceniu', async () => {
  const kopia = mutuj(NASYCENIE, [["w.ostrzezenie = 'pomiar po nasyceniu", "void 'pomiar po nasyceniu"]]);
  const M = await modul(kopia);
  sprawdzAnalize(M);
});
await musiOblac('pik CPU liczony jak wyczerpanie', async () => {
  const kopia = mutuj(NASYCENIE, [['if (cpu.length && blisko / cpu.length >= 0.25) {', 'if (blisko > 0) {']]);
  const M = await modul(kopia);
  assert.deepEqual(M.sygnalyZasobow([{ kontener_rdzenie: 0.3 }, { kontener_rdzenie: 0.3 }, { kontener_rdzenie: 1.9 }]).sygnaly, []);
});
await musiOblac('kod wyjścia `stopien` dla nasyconego = 0', async () => {
  const kopia = mutuj(NASYCENIE, [["process.exit(ocena.stan === 'NASYCONY' ? 3 : ocena.stan === 'NIEOCENIONY' ? 4 : 0);", 'process.exit(0);']]);
  await sprawdzCli(kopia);
});

// -- generator ----------------------------------------------------------------
await musiOblac('druga strona feedu znów `?page=2`', async () => {
  const kopia = mutuj(GENERATOR, [["cfg = sesja.home_str2 ? { sciezka: sesja.home_str2, ciasteczka: sesja.ciasteczka } : null;", "cfg = { sciezka: '/home?page=2', ciasteczka: sesja.ciasteczka };"]]);
  await sprawdzFeedStr2(kopia);
});
await musiOblac('kursor bierze pierwszy odnośnik z cursor, także cudzej listy', async () => {
  const kopia = mutuj(GENERATOR, [["if (url.pathname === '/home' && url.searchParams.has('cursor'))", "if (url.searchParams.has('cursor'))"]]);
  const M = await modul(kopia);
  assert.equal(M.nastepnaStronaFeedu('<a href="/tag/x?cursor=1">x</a>'), null);
});
await musiOblac('dobór widzów: kolejne konta zamiast klas obserwowanych', async () => {
  const kopia = mutuj(GENERATOR, [['  const kolejki = [...klasy.entries()].sort((a, b) => a[0] - b[0]).map(([, l]) => l);', '  const kolejki = [widzowie.map((w) => ({ email: w.email, obserwuje: w.obserwuje }))];']]);
  sprawdzWidzow(await modul(kopia));
});
await musiOblac('sonda powrotu uznaje pojedynczy sukces', async () => {
  const kopia = mutuj(GENERATOR, [['passa = dobra ? passa + 1 : 0;', 'passa = dobra ? passa + 1 : passa;']]);
  await sprawdzSonde(await modul(kopia));
});
await musiOblac('sonda powrotu uznaje odpowiedź 500 za dobrą', async () => {
  const kopia = mutuj(GENERATOR, [["const dobra = odp.powod === 'ok' && odp.status === 200 && odp.ms <= budzetMs;", "const dobra = odp.powod === 'ok' && odp.ms <= budzetMs;"]]);
  await sprawdzSonde(await modul(kopia));
});

// -- rampa --------------------------------------------------------------------
await musiOblac('rampa nie przerywa po serwisie, który nie wrócił', async () => {
  const kopia = mutuj(RAMPA, [['      exit 3\n', '      true\n']]);
  await sprawdzRampe(kopia, 'mut-nie-wrocil');
});
await musiOblac('rampa nie staje po nasyceniu', async () => {
  const kopia = mutuj(RAMPA, [['if [ -n "$NASYCONY" ] && [ "$PO_NASYCENIU" = "stop" ]; then', 'if false; then']]);
  await sprawdzRampe(kopia, 'mut-nie-staje');
});
await musiOblac('rampa startuje bez korpusu zdjęć', async () => {
  const kopia = mutuj(RAMPA, [['if [ -z "${KORPUS_ZDJEC:-}" ] && [ "${ZDJECIA_SYNTETYCZNE:-}" != "tak" ]; then', 'if false; then']]);
  const bez = await uruchom(kopia, [join(roboczy, 'mut-bez-korpusu'), 'manifest'], {
    cwd: dirname(dirname(kopia)), env: { KORPUS_ZDJEC: '', ZDJECIA_SYNTETYCZNE: '', SERIA_605: ATRAPA, STOPNIE: '5', MAKS_POWROTU_S: '1', POWROT_CO_MS: '50', BAZA_APLIKACJI: 'http://127.0.0.1:1' },
  });
  assert.equal(bez.kod, 2);
});

// =============================================================================

for (const [sciezka, suma] of Object.entries(MD5_NA_WEJSCIU)) {
  assert.equal(md5(sciezka), suma, `Kontrole ujemne zmieniły oryginał ${sciezka} — to byłby fałszywy wynik.`);
}
powiedz('oryginały (analizator, generator, rampa) mają tę samą sumę MD5 co przed kontrolami ujemnymi');

for (const zamknij of sprzatanie) await zamknij();
for (const m of zaladowane) m.zamknijAgenta?.();
rmSync(roboczy, { recursive: true, force: true });
process.stdout.write(`\nZdane sprawdzenia (nasycenie, powrót, dobór ruchu): ${zdane}\n`);
