// Bilans zmiennych przed pierwszym `railway config apply` rozbijającym
// produkcję na web / worker / scheduler (#595). Runbook:
// docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md, krok 0.5.
//
// PO CO. Produkcja (stan 29.09.2026) to jeden serwis `kuking.pl` w roli
// `all`, a jego zmienne stoją w panelu jako zmienne SERWISU. Plik po #1459
// (#1013) daje każdej roli tylko jej zmienne, a sekrety czyta z Shared
// Variables (`ctx.shared`). Pierwszy apply zrobi więc z każdą zmienną jedną
// z trzech rzeczy — i każda ma inny sposób, żeby wartość zgubić:
//
//   * USUNIE ją z `kuking.pl`, bo w roli `web` jej nie ma. Poprawne dla
//     zmiennych, które przechodzą do workera albo schedulera (np. klucz
//     moderacji) i dla martwych (`TRUSTED_PROXIES`); każda inna to STOP.
//   * PODMIENI wartość w serwisie na `${{shared.X}}`. Gdy Shared Variable X
//     nie istnieje, wartość z panelu przepada, a funkcja cicho się wyłącza
//     (fail-open) — także w nowym workerze i schedulerze, które poza Shared
//     nie mają skąd jej wziąć.
//   * DODA referencję do Shared, której nikt nie założył — pusto, czyli
//     funkcja wyłączona, dokładnie jak dziś (np. VAPID_*, KUKING_EDGE_*).
//     To informacja, nie błąd.
//
// Skrypt NIE łączy się z Railwayem. Czyta nazwy zmiennych serwisu `kuking.pl`
// (stdin, np. z `railway variables --service kuking.pl --json`) i plik
// z nazwami Shared Variables środowiska (przepisane z panelu, po jednej
// w linii albo JSON), i porównuje je z grafem z `railway.ts`
// (scripts/railway/iac-graf.mjs).
//
// WARTOŚCI NIGDY NIE WYCHODZĄ. Z wejścia bierzemy wyłącznie klucze; wypisujemy
// wyłącznie nazwy zmiennych, serwisów i Shared Variables.
//
//   railway variables --service kuking.pl --json \
//     | KUKING_WAIT_FOR_CI=true node --experimental-strip-types --no-warnings \
//         scripts/railway/bilans-zmiennych-595.mjs production --wspoldzielone ~/shared-nazwy.txt
//
// Kod wyjścia: 0 — bilans się zamyka (wolno iść do planu), 1 — STOP (lista
// wyżej mówi, co poprawić), 2 — złe wywołanie albo wejście.
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

// Railway wstrzykuje je sam (RAILWAY_PUBLIC_DOMAIN, RAILWAY_ENVIRONMENT…).
// Nie ustawia ich człowiek i apply ich nie rusza.
const WSTRZYKIWANE_PRZEZ_RAILWAY = /^RAILWAY_/;

/**
 * Zmienne, które plan może usunąć z `kuking.pl` bez szkody: żaden kod ich
 * nie czyta. Każdy wpis z powodem — dopisanie nazwy tutaj to decyzja, że jej
 * wartość wolno stracić.
 */
export const MARTWE = {
  TRUSTED_PROXIES: "SEC-01: aplikacja nigdy jej nie czytała (komentarz w railway.ts)",
};

/**
 * Zmienne, które plik ZASTĘPUJE inną: `railway.ts` daje każdej roli
 * `DB_URL = ${{Postgres.DATABASE_URL}}`, a `config/database.php` bierze
 * `url` przed `host`/`port`/… (audyt 30.09, §3.1). Usunięcie tych nazw
 * z `kuking.pl` jest oczekiwane — ale tylko wtedy, gdy serwis WWW w grafie
 * naprawdę ma `DB_URL` (sprawdza `bilansZmiennych`).
 */
export const ZASTAPIONE_PRZEZ_DB_URL = ["DB_DATABASE", "DB_HOST", "DB_PASSWORD", "DB_PORT", "DB_USERNAME"];

/**
 * Zmienne „tylko w panelu z założenia” (IN-03, #2295): `railway.ts` ich
 * ŚWIADOMIE nie deklaruje (lista `WYJATKI` w
 * `tests/Feature/ZmienneRailwayaPerRolaTest.php` albo para `AWS_LEGACY_*`
 * z `config/filesystems.php`), a pierwszy apply usunie je z `kuking.pl`
 * razem z wartością. To nadal STOP — ale z instrukcją dla tej jednej
 * nazwy, bo „dopisz do railway.ts” jest tu często złą odpowiedzią.
 * Pilnuje `iac.test.mjs`: każdy wyjątek „ustawiany ręcznie / z panelu”
 * musi tu stać.
 */
const LEGACY =
  "Stary bucket zdjęć (#120, docs/infra/STARY_BUCKET_R2_LEGACY.md) — jedyna kopia części najstarszych zdjęć. " +
  "Najpierw `php artisan kuking:zaleznosc-od-starego-bucketu --pliki` (railway ssh, kuking.pl). " +
  "Żaden wiersz nie wskazuje `r2_legacy` → PR: przenieś nazwę do MARTWE z datą pomiaru. " +
  "Wiersze są → PR: AWS_LEGACY_* w appEnv jako ctx.shared.R2_LEGACY_* (usuń z WYJATKI), Shared R2_LEGACY_* z wartościami z panelu.";
export const PANELOWE_Z_ZALOZENIA = {
  AWS_LEGACY_BUCKET: LEGACY,
  AWS_LEGACY_URL: LEGACY,
  AWS_LEGACY_ACCESS_KEY_ID: LEGACY,
  AWS_LEGACY_SECRET_ACCESS_KEY: LEGACY,
  AWS_URL: "Wycofane (W7-02), ale `r2_legacy` bierze z niej adres, gdy nie ma AWS_LEGACY_URL — postępuj jak z AWS_LEGACY_*.",
  KUKING_ZAUFANE_HOSTY:
    "Awaryjny przełącznik hosta healthchecku (config/proxy.php). Stoi = ktoś go potrzebował. " +
    "PR: webEnv jako ctx.shared.KUKING_ZAUFANE_HOSTY (usuń z WYJATKI) + Shared z tą wartością, albo usuń w panelu i sprawdź /health.",
  TURNSTILE_HOSTY_STAGINGU: "Tylko staging (#992); na produkcji ma być pusto — usuń ją w panelu kuking.pl.",
  KUKING_EXPORT_TEMP_DIR: "Ustawiana tylko na workerze (#1455). PR: workerEnv, albo usuń w panelu, jeśli tmp kontenera wystarcza.",
  KUKING_IMPORT_PDF_DYSK: "Inny dysk importu PDF niż Livewire (#28). PR: webEnv i workerEnv, albo usuń w panelu.",
  KUKING_POTWIERDZENIA_RODO_RETENTION_MONTHS:
    "Okres retencji potwierdzeń RODO — decyzja właściciela. PR: schedulerEnv (literal albo ctx.shared), inaczej komenda znów odmówi kasowania.",
  KUKING_TEST_USERNAMES: "Lista kont testowych dla metryki. PR: rola, która liczy metrykę, albo usuń w panelu.",
  DB_JIT: "Opcjonalny przełącznik diagnostyczny PostgreSQL (`config/database.php`); pusto oznacza `jit=off`, a `on` włącza JIT tylko na żądanie.",
};

const NAZWA = /^[A-Za-z_][A-Za-z0-9_]*$/;

/** Same klucze — wartości odrzucamy od razu. Obiekt JSON, tablica albo linie. */
export function nazwyZTekstu(tekst) {
  const przyciety = tekst.trim();
  if (przyciety === "") return [];
  if (przyciety.startsWith("{") || przyciety.startsWith("[")) {
    const dane = JSON.parse(przyciety);
    const nazwy = Array.isArray(dane) ? dane.map(String) : Object.keys(dane ?? {});
    return sprawdzone(nazwy);
  }
  return sprawdzone(
    przyciety
      .split("\n")
      .map((l) => l.trim())
      .filter((l) => l !== "" && !l.startsWith("#")),
  );
}

function sprawdzone(nazwy) {
  const zle = nazwy.filter((n) => !NAZWA.test(n));
  if (zle.length > 0) {
    // Bez treści w komunikacie: w złej linii może stać wklejona wartość.
    throw new Error(`${zle.length} wpis(ów) nie wygląda na nazwę zmiennej — podaj same nazwy, bez wartości`);
  }
  return nazwy;
}

/**
 * @param {string[]} nazwyZPanelu  zmienne serwisu WWW w żywym środowisku
 * @param {string[]} wspoldzielone nazwy Shared Variables środowiska
 * @param {{resources: Array<{type: string, name: string, groupId?: string, variables?: object}>}} graf
 * @param {string} serwisWww
 */
export function bilansZmiennych(nazwyZPanelu, wspoldzielone, graf, serwisWww = "kuking.pl") {
  const uslugi = graf.resources.filter((r) => r.type === "service");
  const www = uslugi.find((s) => s.name === serwisWww);
  if (!www) throw new Error(`Graf railway.ts nie ma serwisu „${serwisWww}”.`);

  const panel = new Set(nazwyZPanelu.filter((n) => !WSTRZYKIWANE_PRZEZ_RAILWAY.test(n)));
  const shared = new Set(wspoldzielone);
  const zmienneWww = www.variables ?? {};

  const przenoszone = [];
  const martwe = [];
  const zastapione = [];
  const tylkoWPanelu = [];
  const nieznane = [];
  const maDbUrl = "DB_URL" in zmienneWww;
  for (const nazwa of [...panel].sort()) {
    if (nazwa in zmienneWww) continue;
    const role = uslugi.filter((s) => s !== www && nazwa in (s.variables ?? {})).map((s) => s.name);
    if (role.length > 0) przenoszone.push({ nazwa, role });
    else if (nazwa in MARTWE) martwe.push(nazwa);
    else if (maDbUrl && ZASTAPIONE_PRZEZ_DB_URL.includes(nazwa)) zastapione.push(nazwa);
    else if (nazwa in PANELOWE_Z_ZALOZENIA) tylkoWPanelu.push(nazwa);
    else nieznane.push(nazwa);
  }

  // Każda referencja `${{shared.X}}` w dowolnym serwisie: kto jej używa.
  const potrzebne = new Map();
  for (const s of uslugi) {
    for (const [zmienna, wartosc] of Object.entries(s.variables ?? {})) {
      if (wartosc?.type !== "sharedReference") continue;
      const wpis = potrzebne.get(wartosc.name) ?? { zmienne: new Set(), uslugi: new Set() };
      wpis.zmienne.add(zmienna);
      wpis.uslugi.add(s.name);
      potrzebne.set(wartosc.name, wpis);
    }
  }

  const doPrzeniesienia = [];
  const pusteReferencje = [];
  for (const [nazwaShared, { zmienne, uslugi: gdzie }] of [...potrzebne].sort(([a], [b]) => a.localeCompare(b))) {
    if (shared.has(nazwaShared)) continue;
    const wpis = { shared: nazwaShared, zmienne: [...zmienne].sort(), uslugi: [...gdzie].sort() };
    // Wartość stoi dziś w serwisie pod nazwą zmiennej, a plik będzie jej
    // szukał w Shared — bez przeniesienia przepada.
    if (wpis.zmienne.some((z) => panel.has(z))) doPrzeniesienia.push({ ...wpis, zPanelu: wpis.zmienne.filter((z) => panel.has(z)) });
    else pusteReferencje.push(wpis);
  }

  return {
    przenoszone,
    martwe,
    zastapione,
    tylkoWPanelu,
    nieznane,
    doPrzeniesienia,
    pusteReferencje,
    zamyka: nieznane.length === 0 && tylkoWPanelu.length === 0 && doPrzeniesienia.length === 0,
  };
}

/** Raport dla człowieka. Tylko nazwy. */
export function raport(b, serwisWww = "kuking.pl") {
  const l = [];
  const sekcja = (tytul, wiersze) => {
    l.push(`${tytul} (${wiersze.length})`);
    for (const w of wiersze) l.push(`  ${w}`);
    if (wiersze.length === 0) l.push("  —");
    l.push("");
  };
  sekcja(
    `STOP — ${serwisWww} straci zmienną, której plik nie przenosi nigdzie`,
    b.nieznane,
  );
  sekcja(
    `STOP — zmienna tylko z panelu (WYJATKI, #2295): apply usunie ją z ${serwisWww} razem z wartością`,
    b.tylkoWPanelu.map((n) => `${n}  — ${PANELOWE_Z_ZALOZENIA[n]}`),
  );
  sekcja(
    "STOP — wartość z panelu przepadnie: załóż Shared Variable z tą samą wartością",
    b.doPrzeniesienia.map((d) => `${d.shared}  ← dziś w ${serwisWww} jako ${d.zPanelu.join(", ")}; czytają: ${d.uslugi.join(", ")}`),
  );
  sekcja(
    `Oczekiwane usunięcie z ${serwisWww}: zmienna przechodzi do innej roli`,
    b.przenoszone.map((p) => `${p.nazwa}  → ${p.role.join(", ")}`),
  );
  sekcja(`Oczekiwane usunięcie z ${serwisWww}: zmienna martwa`, b.martwe.map((n) => `${n}  (${MARTWE[n]})`));
  sekcja(
    `Oczekiwane usunięcie z ${serwisWww}: połączenie z bazą idzie przez DB_URL`,
    b.zastapione,
  );
  sekcja(
    "Informacja — referencja bez Shared Variable: po apply pusto, funkcja wyłączona jak dziś",
    b.pusteReferencje.map((p) => `${p.shared}  (${p.uslugi.join(", ")})`),
  );
  l.push(b.zamyka ? "Bilans się zamyka — można liczyć plan (krok 2)." : "Bilans się NIE zamyka — nie uruchamiaj apply.");
  return l.join("\n") + "\n";
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) {
  const argumenty = process.argv.slice(2);
  const srodowisko = argumenty[0];
  const i = argumenty.indexOf("--wspoldzielone");
  const plikShared = i >= 0 ? argumenty[i + 1] : undefined;
  if (!srodowisko || srodowisko.startsWith("--") || !plikShared) {
    console.error(
      "Użycie: … bilans-zmiennych-595.mjs <środowisko> --wspoldzielone <plik z nazwami Shared Variables>  (nazwy zmiennych kuking.pl na stdin)",
    );
    process.exit(2);
  }

  let panel;
  let shared;
  try {
    panel = nazwyZTekstu(readFileSync(0, "utf8"));
    shared = nazwyZTekstu(readFileSync(plikShared, "utf8"));
  } catch (blad) {
    // Bez treści wejścia w komunikacie — mogą w nim być sekrety.
    console.error(`Nie udało się odczytać nazw: ${blad instanceof SyntaxError ? "to nie jest poprawny JSON" : blad.message}`);
    process.exit(2);
  }

  const graf = JSON.parse(
    execFileSync(
      process.execPath,
      ["--experimental-strip-types", "--no-warnings", resolve(import.meta.dirname, "iac-graf.mjs"), srodowisko],
      { encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] },
    ),
  );

  const b = bilansZmiennych(panel, shared, graf);
  process.stdout.write(raport(b));
  process.exit(b.zamyka ? 0 : 1);
}
