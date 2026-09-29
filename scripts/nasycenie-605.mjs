#!/usr/bin/env node
/*
 * =============================================================================
 *  Kryterium nasycenia — #605
 * =============================================================================
 *
 *  PO CO TO JEST
 *  Issue #605 każe „stopniowo zwiększać concurrency/RPS aż do zauważalnego
 *  punktu degradacji" i wskazać BOTTLENECK. Rampa (`rampa-obciazenia-605.sh`)
 *  umiała tylko puszczać kolejne stopnie; to, czy stopień jest jeszcze zdrowy,
 *  czy już nasycony, ktoś oceniał ręcznie po fakcie — a kryterium ustalone po
 *  obejrzeniu wyników jest dobieraniem wyników. Tutaj stoi ono z góry, jawnie,
 *  w jednym miejscu (`KRYTERIA`), razem z testami.
 *
 *  CO TO JEST, A CZEGO NIE JEST
 *  To ocena WYNIKÓW jednej rampy, na tym stanowisku, przy tej mieszance.
 *  Nie jest to „pojemność serwisu" ani liczba użytkowników online. Punkt
 *  nasycenia jest przedziałem (ostatni zdrowy stopień, pierwszy nasycony),
 *  a nie jedną liczbą — stopnie są rzadkie.
 *
 *  KRYTERIA (jedyne arbitralne liczby; zmiana = nowa wersja i powtórzenie oceny
 *  dla WSZYSTKICH stopni, nigdy dla wybranych)
 *    odniesienie   p95 najniższego stopnia CZYSTEGO i bez błędów
 *    ZDROWY        żadne z niżej
 *    DEGRADACJA    p95 >= 2 × odniesienie
 *    NASYCONY      błąd > 1 %  LUB  przepustowość < 90 % zadanego  LUB
 *                  p95 >= 5 × odniesienie
 *    NIEOCENIONY   werdykt bramki SKAŻONY / NIEWYKONANY — stopień nie wchodzi
 *                  do wyznaczania punktu (zostaje na liście z powodem)
 *
 *  p95 to większe z `p95` (odpowiedzi poprawne) i `p95_z_bledami`: sam p95
 *  z poprawnych SPADA, gdy najwolniejsze żądania wypadają jako błędy.
 *
 *  ZALEŻNOŚĆ STOPNI PO NASYCENIU
 *  Zaległość żądań nie znika sama (pomiar 20.09.2026: >11 minut, do restartu).
 *  Każdy stopień po pierwszym nasyconym, bez zapisanego POWROTU DO NORMY,
 *  dostaje ostrzeżenie: to nie jest niezależny pomiar serwera zaczynającego od
 *  zera, tylko pomiar serwera z zaległością poprzednika.
 *
 *  Użycie:
 *      node scripts/nasycenie-605.mjs analiza <katalog-wynikow> [--json]
 *      node scripts/nasycenie-605.mjs stopien <katalog-wynikow> <nazwa-serii>
 *          kod wyjścia: 0 = ZDROWY/DEGRADACJA, 3 = NASYCONY, 4 = NIEOCENIONY
 *  Bez zależności; nie dotyka bazy, aplikacji ani sieci.
 * =============================================================================
 */

import { readFileSync, readdirSync, existsSync, realpathSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const KRYTERIA = Object.freeze({
  wersja: '2026-09-29',
  blad_procent_nasycenie: 1,
  przepustowosc_ulamek_nasycenie: 0.9,
  p95_wzgledne_degradacja: 2,
  p95_wzgledne_nasycenie: 5,
  // Podłoga odniesienia: przy p95 rzędu 5 ms „5 ×" to 25 ms, czyli szum, nie degradacja.
  odniesienie_min_ms: 100,
  // Powrót do normy: błąd <= 1 % i p95 <= 2 × odniesienie (jak próg degradacji).
  powrot_p95_wzgledne: 2,
});

/** Limity stanowiska; domyślnie te z METODA.md §1.1 (kontener 2 CPU / 1 GiB). */
export const LIMITY_STANOWISKA = Object.freeze({
  cpu_rdzenie: 2,
  pamiec_mb: 1024,
  polaczenia_db: 100,
});

const liczbaLubNull = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : null);

/** Większe z p95 poprawnych i p95 z błędami — patrz komentarz nagłówkowy. */
export function p95Efektywne(seria) {
  const r = seria?.razem ?? {};
  const a = liczbaLubNull(r.p95);
  const b = liczbaLubNull(r.p95_z_bledami);
  if (a === null && b === null) return null;
  return Math.max(a ?? 0, b ?? 0);
}

/**
 * Stopnie o tej samej zadanej przepustowości bywają powtarzane (`-p1`, `-p2`).
 * Wybieramy pierwszą próbę CZYSTĄ; brak takiej → stopień nieoceniony.
 */
export function wybierzProby(serie) {
  const poRps = new Map();
  for (const s of serie) {
    const lista = poRps.get(s.rps) ?? [];
    lista.push(s);
    poRps.set(s.rps, lista);
  }
  return [...poRps.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([rps, proby]) => {
      proby.sort((a, b) => a.proba - b.proba);
      const czysta = proby.find((p) => p.werdykt?.werdykt === 'CZYSTY' && p.seria);
      return { rps, wybrana: czysta ?? null, proby };
    });
}

/** Odniesienie: p95 najniższego stopnia czystego, bez błędów (w granicach kryterium). */
export function odniesienieP95(stopnie, kryteria = KRYTERIA) {
  for (const st of stopnie) {
    const s = st.wybrana?.seria;
    if (!s) continue;
    const blad = liczbaLubNull(s.razem?.blad_procent);
    const p95 = p95Efektywne(s);
    if (blad !== null && blad <= kryteria.blad_procent_nasycenie && p95 !== null) {
      return { rps: st.rps, seria: st.wybrana.nazwa, p95_ms: Math.max(p95, kryteria.odniesienie_min_ms), p95_zmierzone_ms: p95 };
    }
  }
  return null;
}

/**
 * Ocena jednego stopnia. `odniesienie` może być null (stopień jest wtedy sam
 * sobie odniesieniem albo go nie ma) — działają wtedy tylko kryteria bezwzględne.
 */
export function ocenStopien(seria, werdykt, odniesienie, kryteria = KRYTERIA) {
  const wv = werdykt?.werdykt ?? 'BRAK';
  if (wv !== 'CZYSTY') {
    return { stan: 'NIEOCENIONY', powody: [`werdykt bramki: ${wv} — stopień nadaje się wyłącznie do powtórzenia`] };
  }
  if (!seria?.razem) {
    return { stan: 'NIEOCENIONY', powody: ['brak pliku wyniku serii'] };
  }
  const r = seria.razem;
  const zadany = liczbaLubNull(seria.zadany_rps);
  const blad = liczbaLubNull(r.blad_procent);
  const przep = liczbaLubNull(r.przepustowosc_rps);
  const p95 = p95Efektywne(seria);
  const nasycenie = [];
  const degradacja = [];

  if (blad !== null && blad > kryteria.blad_procent_nasycenie) {
    nasycenie.push(`błąd ${blad} % > ${kryteria.blad_procent_nasycenie} %`);
  }
  if (zadany && przep !== null && przep < kryteria.przepustowosc_ulamek_nasycenie * zadany) {
    nasycenie.push(`przepustowość ${przep} rps < ${Math.round(kryteria.przepustowosc_ulamek_nasycenie * 100)} % zadanych ${zadany} rps`);
  }
  if (odniesienie && p95 !== null) {
    const krotnosc = p95 / odniesienie.p95_ms;
    if (krotnosc >= kryteria.p95_wzgledne_nasycenie) {
      nasycenie.push(`p95 ${p95} ms = ${krotnosc.toFixed(1)} × odniesienia (${odniesienie.p95_ms} ms) >= ${kryteria.p95_wzgledne_nasycenie} ×`);
    } else if (krotnosc >= kryteria.p95_wzgledne_degradacja) {
      degradacja.push(`p95 ${p95} ms = ${krotnosc.toFixed(1)} × odniesienia (${odniesienie.p95_ms} ms) >= ${kryteria.p95_wzgledne_degradacja} ×`);
    }
  }
  if (nasycenie.length) return { stan: 'NASYCONY', powody: nasycenie.concat(degradacja) };
  if (degradacja.length) return { stan: 'DEGRADACJA', powody: degradacja };
  return {
    stan: 'ZDROWY',
    powody: odniesienie ? [] : ['brak odniesienia p95 — oceniono tylko kryteriami bezwzględnymi (błąd, przepustowość)'],
  };
}

/** Charakter degradacji: czym kończą się nieudane żądania (z `endpointy.*.powody` i `statusy`). */
export function charakterDegradacji(seria) {
  const powody = {};
  let kod429 = 0;
  let kod5xx = 0;
  for (const e of Object.values(seria?.endpointy ?? {})) {
    for (const [k, v] of Object.entries(e.powody ?? {})) powody[k] = (powody[k] ?? 0) + v;
    for (const [k, v] of Object.entries(e.statusy ?? {})) {
      if (k === '429') kod429 += v;
      else if (/^5\d\d$/.test(k)) kod5xx += v;
    }
  }
  const przekroczeniaCzasu = (powody.deadline ?? 0) + (powody.bezczynnosc ?? 0);
  const zerwania = (powody.urwana ?? 0) + (powody.blad ?? 0);
  const anulowane = powody.anulowane ?? 0;
  const porzucone = liczbaLubNull(seria?.razem?.porzuconych_przez_limit) ?? 0;
  const kandydaci = [
    ['przekroczenia czasu (brak odpowiedzi w limicie)', przekroczeniaCzasu],
    ['odpowiedzi 5xx', kod5xx],
    ['zerwane połączenia i błędy gniazda', zerwania],
    ['odmowy limitem 429 (to ograniczenie konfiguracji, nie serwera)', kod429],
  ].sort((a, b) => b[1] - a[1]);
  const glowny = kandydaci[0][1] > 0 ? kandydaci[0][0] : 'brak błędów';
  return {
    glowny,
    przekroczenia_czasu: przekroczeniaCzasu,
    odpowiedzi_5xx: kod5xx,
    odpowiedzi_429: kod429,
    zerwania,
    anulowane_przy_domykaniu: anulowane,
    porzucone_przez_limit_generatora: porzucone,
  };
}

const maks = (probki, pole) => {
  const v = probki.map((p) => p[pole]).filter((x) => typeof x === 'number' && Number.isFinite(x));
  return v.length ? Math.max(...v) : null;
};

/**
 * Które zasoby wyglądają na wyczerpane w oknie stopnia. To są KANDYDACI do
 * sprawdzenia, nie ustalenie przyczyny: brak wyczerpanego zasobu przy
 * zaległości żądań też jest informacją (wskazuje na koszt żądania, nie sprzęt).
 */
export function sygnalyZasobow(probki, limity = LIMITY_STANOWISKA) {
  const p = probki.filter((x) => x && typeof x === 'object');
  const zmierzone = {
    kontener_rdzenie_max: maks(p, 'kontener_rdzenie'),
    kontener_pamiec_szczyt_mb: maks(p, 'kontener_rss_szczyt_mb'),
    kontener_pamiec_biezaca_max_mb: maks(p, 'kontener_rss_mb'),
    db_polaczenia_max: maks(p, 'db_polaczenia'),
    db_aktywne_max: maks(p, 'db_aktywne'),
    db_lock_waits_max: maks(p, 'db_czeka_na_blokade'),
    kolejka_max: maks(p, 'kolejka'),
    najstarsze_zadanie_max_s: maks(p, 'najstarsze_zadanie_s'),
    nieudane_zadania_max: maks(p, 'nieudane_zadania'),
    probek: p.length,
  };
  const sygnaly = [];
  // CPU: liczy się przebywanie przy limicie, nie pojedynczy pik (średnia stopnia
  // 20.09.2026 to ok. 0,5 rdzenia, choć jedna próbka pokazała 1,9).
  const cpu = p.map((x) => x.kontener_rdzenie).filter((x) => typeof x === 'number' && Number.isFinite(x));
  const blisko = cpu.filter((x) => x >= 0.9 * limity.cpu_rdzenie).length;
  zmierzone.kontener_rdzenie_udzial_probek_przy_limicie = cpu.length ? Math.round((1000 * blisko) / cpu.length) / 10 : null;
  if (cpu.length && blisko / cpu.length >= 0.25) {
    sygnaly.push(`CPU kontenera: ${zmierzone.kontener_rdzenie_udzial_probek_przy_limicie} % próbek przy >= 90 % limitu ${limity.cpu_rdzenie} rdzeni (szczyt ${zmierzone.kontener_rdzenie_max})`);
  }
  if (zmierzone.kontener_pamiec_szczyt_mb !== null && zmierzone.kontener_pamiec_szczyt_mb >= 0.95 * limity.pamiec_mb) {
    sygnaly.push(`pamięć kontenera (cgroup, zawiera cache): szczyt ${zmierzone.kontener_pamiec_szczyt_mb} MB z limitu ${limity.pamiec_mb} MB`);
  }
  if (zmierzone.db_lock_waits_max) {
    sygnaly.push(`oczekiwanie na blokady w bazie: do ${zmierzone.db_lock_waits_max}`);
  }
  if (zmierzone.db_polaczenia_max !== null && zmierzone.db_polaczenia_max >= 0.8 * limity.polaczenia_db) {
    sygnaly.push(`połączenia DB: ${zmierzone.db_polaczenia_max} z ${limity.polaczenia_db}`);
  }
  if (zmierzone.najstarsze_zadanie_max_s !== null && zmierzone.najstarsze_zadanie_max_s >= 60) {
    sygnaly.push(`kolejka zadań: najstarsze zadanie czekało ${zmierzone.najstarsze_zadanie_max_s} s`);
  }
  if (zmierzone.nieudane_zadania_max) {
    sygnaly.push(`nieudane zadania kolejki: ${zmierzone.nieudane_zadania_max}`);
  }
  return { zmierzone, sygnaly };
}

/** Próbki z okna napływu stopnia (bez wybiegu i bramki). Bez znaczników czasu — wszystkie. */
export function oknoStopnia(probki, seria) {
  const od = Date.parse(seria?.start ?? '');
  const dlugosc = liczbaLubNull(seria?.trwanie_s);
  if (!Number.isFinite(od) || dlugosc === null) return probki;
  const doMs = od + (dlugosc + 1) * 1000;
  return probki.filter((p) => {
    const t = Date.parse(p.t ?? '');
    return Number.isFinite(t) && t >= od - 1000 && t <= doMs;
  });
}

/** Powrót do normy z serii „…-powrot” (mały ruch po zdjęciu obciążenia) lub z sondy `powrot`. */
export function ocenPowrot(seria, odniesienie, kryteria = KRYTERIA) {
  if (!seria?.razem) return { wrocil: null, powod: 'brak wyniku' };
  const blad = liczbaLubNull(seria.razem.blad_procent);
  const p95 = p95Efektywne(seria);
  if (blad === null || p95 === null) return { wrocil: false, powod: 'brak poprawnych odpowiedzi' };
  if (blad > kryteria.blad_procent_nasycenie) return { wrocil: false, powod: `błąd ${blad} % po zdjęciu obciążenia` };
  if (odniesienie && p95 > kryteria.powrot_p95_wzgledne * odniesienie.p95_ms) {
    return { wrocil: false, powod: `p95 ${p95} ms > ${kryteria.powrot_p95_wzgledne} × odniesienia (${odniesienie.p95_ms} ms)` };
  }
  return { wrocil: true, powod: `błąd ${blad} %, p95 ${p95} ms` };
}

function czytajJson(sciezka) {
  try {
    return JSON.parse(readFileSync(sciezka, 'utf8'));
  } catch {
    return null;
  }
}

function czytajProbnik(sciezka) {
  if (!existsSync(sciezka)) return [];
  const wynik = [];
  for (const linia of readFileSync(sciezka, 'utf8').split('\n')) {
    if (!linia.trim()) continue;
    try { wynik.push(JSON.parse(linia)); } catch { /* uszkodzona ostatnia linia po Ctrl+C */ }
  }
  return wynik;
}

/** Wczytuje katalog wyników rampy (`seria-*.json`, `werdykt-*.json`, `probnik-*.jsonl`, `powrot-*.json`). */
export function wczytajKatalog(katalog) {
  const pliki = readdirSync(katalog);
  const serie = [];
  const powroty = [];
  const pominiete = [];
  for (const plik of pliki) {
    const m = plik.match(/^seria-(.+)\.json$/);
    if (!m) continue;
    const nazwa = m[1];
    const seria = czytajJson(join(katalog, plik));
    const werdykt = czytajJson(join(katalog, `werdykt-${nazwa}.json`));
    const probnik = czytajProbnik(join(katalog, `probnik-${nazwa}.jsonl`));
    const rampowa = nazwa.match(/^r(\d+)-p(\d+)$/);
    if (rampowa) {
      serie.push({ nazwa, rps: Number(rampowa[1]), proba: Number(rampowa[2]), seria, werdykt, probnik });
    } else if (/powrot/.test(nazwa)) {
      powroty.push({ nazwa, seria, werdykt, probnik });
    } else {
      pominiete.push(nazwa);
    }
  }
  // Niewykonane stopnie mają sam werdykt, bez pliku serii.
  for (const plik of pliki) {
    const m = plik.match(/^werdykt-(r(\d+)-p(\d+))\.json$/);
    if (!m || serie.some((s) => s.nazwa === m[1])) continue;
    serie.push({ nazwa: m[1], rps: Number(m[2]), proba: Number(m[3]), seria: null, werdykt: czytajJson(join(katalog, plik)), probnik: [] });
  }
  const sondy = pliki
    .filter((p) => /^powrot-.+\.json$/.test(p))
    .map((p) => ({ nazwa: p.replace(/\.json$/, ''), sonda: czytajJson(join(katalog, p)) }));
  return { serie, powroty, sondy, pominiete };
}

/** Pełna analiza rampy → obiekt gotowy do raportu. */
export function analizuj(wczytane, { kryteria = KRYTERIA, limity = LIMITY_STANOWISKA } = {}) {
  const stopnie = wybierzProby(wczytane.serie);
  const odn = odniesienieP95(stopnie, kryteria);
  const wiersze = [];
  let pierwszyNasycony = null;
  const ostrzezenia = [];

  for (const st of stopnie) {
    const w = st.wybrana ?? st.proby[st.proby.length - 1];
    const ocena = ocenStopien(w?.seria ?? null, w?.werdykt ?? null, odn, kryteria);
    const razem = w?.seria?.razem ?? null;
    const wiersz = {
      rps: st.rps,
      seria: w?.nazwa ?? null,
      prob: st.proby.length,
      stan: ocena.stan,
      powody: ocena.powody,
      blad_procent: razem?.blad_procent ?? null,
      przepustowosc_rps: razem?.przepustowosc_rps ?? null,
      p95_ms: w?.seria ? p95Efektywne(w.seria) : null,
      w_locie_szczyt: w?.seria?.w_locie_szczyt ?? null,
      po_nasyceniu: false,
    };
    if (ocena.stan === 'NASYCONY') {
      wiersz.charakter = charakterDegradacji(w.seria);
      wiersz.zasoby = sygnalyZasobow(oknoStopnia(w.probnik, w.seria), limity);
      if (!pierwszyNasycony) pierwszyNasycony = wiersz;
    } else if (pierwszyNasycony) {
      wiersz.po_nasyceniu = true;
    }
    wiersze.push(wiersz);
  }

  // Stopnie po pierwszym nasyconym: zależne od zaległości, chyba że powrót zapisano.
  let wrocilPo = null;
  if (pierwszyNasycony) {
    const sondaZapisana = wczytane.sondy.find((s) => s.sonda?.wrocil === true && s.nazwa.includes(String(pierwszyNasycony.seria)));
    if (sondaZapisana) wrocilPo = sondaZapisana.sonda;
    for (const w of wiersze) {
      if (w.rps > pierwszyNasycony.rps) {
        w.po_nasyceniu = true;
        if (!wrocilPo) {
          w.ostrzezenie = 'pomiar po nasyceniu bez zapisanego powrotu do normy — zależny od zaległości poprzednika, nie niezależna pojemność';
        }
      }
    }
    if (!wrocilPo && wiersze.some((w) => w.rps > pierwszyNasycony.rps)) {
      ostrzezenia.push('Są stopnie po pierwszym nasyconym bez zapisanego powrotu do normy; nie czytać ich jako niezależnych pomiarów.');
    }
  }

  const zdrowe = wiersze.filter((w) => w.stan === 'ZDROWY');
  const ostatniZdrowy = pierwszyNasycony
    ? [...zdrowe].filter((w) => w.rps < pierwszyNasycony.rps).pop() ?? null
    : zdrowe[zdrowe.length - 1] ?? null;
  const pierwszaDegradacja = wiersze.find((w) => w.stan === 'DEGRADACJA' || w.stan === 'NASYCONY') ?? null;

  const powroty = [
    ...wczytane.powroty.map((p) => ({ zrodlo: p.nazwa, ...ocenPowrot(p.seria, odn, kryteria) })),
    ...wczytane.sondy.map((s) => ({
      zrodlo: s.nazwa,
      wrocil: s.sonda?.wrocil ?? null,
      powod: s.sonda?.wrocil ? `po ${s.sonda.czas_powrotu_s} s` : `nie wrócił w ${s.sonda?.limit_s ?? '?'} s`,
    })),
  ];

  if (!odn) ostrzezenia.push('Brak stopnia czystego bez błędów — nie ma odniesienia p95; ocena tylko kryteriami bezwzględnymi.');
  if (!pierwszyNasycony) ostrzezenia.push('Żaden stopień nie jest NASYCONY: nasycenia NIE osiągnięto. Nie wolno pisać „wytrzymuje X rps" — tylko „do X rps nie zaobserwowano nasycenia".');
  const nieocenione = wiersze.filter((w) => w.stan === 'NIEOCENIONY').map((w) => w.rps);
  if (nieocenione.length) ostrzezenia.push(`Stopnie nieocenione (skażone albo niewykonane): ${nieocenione.join(', ')} rps.`);
  if (pierwszyNasycony && wiersze.some((w) => w.rps > pierwszyNasycony.rps && w.stan === 'ZDROWY')) {
    ostrzezenia.push('Stopień wyższy od nasyconego wyszedł ZDROWY — niemonotoniczne; sprawdzić skażenie hosta i zaległość, nie uśredniać.');
  }

  return {
    kryteria,
    odniesienie: odn,
    stopnie: wiersze,
    ostatni_zdrowy_rps: ostatniZdrowy?.rps ?? null,
    pierwsza_degradacja_rps: pierwszaDegradacja?.rps ?? null,
    pierwszy_nasycony_rps: pierwszyNasycony?.rps ?? null,
    przedzial_nasycenia_rps: pierwszyNasycony ? [ostatniZdrowy?.rps ?? null, pierwszyNasycony.rps] : null,
    charakter: pierwszyNasycony?.charakter ?? null,
    zasoby_przy_nasyceniu: pierwszyNasycony?.zasoby ?? null,
    powrot_do_normy: powroty,
    pominiete_serie: wczytane.pominiete,
    ostrzezenia,
  };
}

const pole = (v, jednostka = '') => (v === null || v === undefined ? '—' : `${v}${jednostka}`);

export function raportTekst(a) {
  const l = [];
  l.push(`Kryterium nasycenia #605, wersja ${a.kryteria.wersja}`);
  l.push(a.odniesienie
    ? `Odniesienie p95: ${a.odniesienie.p95_ms} ms (stopień ${a.odniesienie.rps} rps, seria ${a.odniesienie.seria})`
    : 'Odniesienie p95: BRAK');
  l.push('');
  l.push('rps  | stan        | błąd %  | przep. rps | p95 ms   | w locie | uwagi');
  for (const w of a.stopnie) {
    l.push([
      String(w.rps).padEnd(4), w.stan.padEnd(11), pole(w.blad_procent).padEnd(7), pole(w.przepustowosc_rps).padEnd(10),
      pole(w.p95_ms).padEnd(8), pole(w.w_locie_szczyt).padEnd(7),
      [...w.powody, w.ostrzezenie].filter(Boolean).join('; '),
    ].join(' | '));
  }
  l.push('');
  if (a.pierwszy_nasycony_rps !== null) {
    l.push(`Punkt nasycenia leży w przedziale (${pole(a.ostatni_zdrowy_rps)}, ${a.pierwszy_nasycony_rps}] rps — to przedział, nie pojemność.`);
    l.push(`Pierwsza degradacja (p95 rośnie, błędów jeszcze brak lub już są): ${pole(a.pierwsza_degradacja_rps)} rps.`);
    l.push(`Charakter degradacji: ${a.charakter.glowny} (czas: ${a.charakter.przekroczenia_czasu}, 5xx: ${a.charakter.odpowiedzi_5xx}, 429: ${a.charakter.odpowiedzi_429}, zerwania: ${a.charakter.zerwania}).`);
    const z = a.zasoby_przy_nasyceniu;
    l.push(z.sygnaly.length
      ? `Zasoby wyczerpane w oknie stopnia (kandydaci, nie dowód przyczyny): ${z.sygnaly.join('; ')}.`
      : 'Żaden zmierzony zasób (CPU, pamięć, połączenia DB, blokady, kolejka) nie wygląda na wyczerpany — zaległość żądań przy niewyczerpanych zasobach MOŻE wskazywać na koszt samego żądania (zapytania, JIT, liczba wątków), a nie na brak sprzętu. To hipoteza do rozstrzygnięcia planami zapytań, nie wynik.');
  } else {
    l.push('NASYCENIA NIE OSIĄGNIĘTO.');
  }
  if (a.powrot_do_normy.length) {
    l.push('');
    l.push('Powrót do normy:');
    for (const p of a.powrot_do_normy) l.push(`  ${p.zrodlo}: ${p.wrocil === true ? 'WRÓCIŁ' : p.wrocil === false ? 'NIE WRÓCIŁ' : 'brak danych'} (${p.powod})`);
  } else if (a.pierwszy_nasycony_rps !== null) {
    l.push('');
    l.push('Powrót do normy: NIE ZMIERZONO.');
  }
  for (const o of a.ostrzezenia) l.push(`UWAGA: ${o}`);
  return l.join('\n');
}

const uruchomionyWprost = (() => {
  if (!process.argv[1]) return false;
  try {
    return realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
})();

if (uruchomionyWprost) {
  const [komenda, katalog, nazwa] = process.argv.slice(2);
  if (!['analiza', 'stopien'].includes(komenda) || !katalog || (komenda === 'stopien' && !nazwa)) {
    console.error('Użycie: nasycenie-605.mjs analiza <katalog> [--json] | stopien <katalog> <nazwa-serii>');
    process.exit(2);
  }
  if (!existsSync(katalog)) {
    console.error(`Brak katalogu wyników: ${katalog}`);
    process.exit(2);
  }
  const wczytane = wczytajKatalog(katalog);
  if (komenda === 'analiza') {
    const a = analizuj(wczytane);
    console.log(process.argv.includes('--json') ? JSON.stringify(a, null, 1) : raportTekst(a));
    process.exit(0);
  }
  // stopien: ocena jednej serii względem odniesienia z tego samego katalogu.
  const cel = wczytane.serie.find((s) => s.nazwa === nazwa);
  if (!cel) {
    console.error(`Brak serii ${nazwa} w ${katalog}`);
    process.exit(2);
  }
  const odn = odniesienieP95(wybierzProby(wczytane.serie));
  const ocena = ocenStopien(cel.seria, cel.werdykt, odn);
  console.log(JSON.stringify({ seria: nazwa, ...ocena }));
  process.exit(ocena.stan === 'NASYCONY' ? 3 : ocena.stan === 'NIEOCENIONY' ? 4 : 0);
}
