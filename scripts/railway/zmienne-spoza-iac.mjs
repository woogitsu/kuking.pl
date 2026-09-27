// Które zmienne serwisu stoją TYLKO w panelu Railway, a nie w `railway.ts`
// (audyt po fali 25.09, znalezisko 12 / propozycja C).
//
// PO CO TO JEST. `railway config apply` może usuwać zmienne serwisu, których
// plik nie wymienia — tego jeszcze nikt nie zmierzył (runbook: docs/infra/
// ZMIENNE_SPOZA_IAC.md). Zanim ktokolwiek uruchomi apply, właściciel ma
// wiedzieć, KTÓRE zmienne byłyby zagrożone. Ten skrypt odpowiada na to bez
// połączenia z Railwayem: porównuje NAZWY zmiennych z panelu (podane na
// stdin) z grafem skompilowanym z `railway.ts` (scripts/railway/iac-graf.mjs).
//
// WARTOŚCI NIGDY NIE WYCHODZĄ. Wejście może zawierać wartości (tak wypisuje
// je `railway variables --json`), ale skrypt czyta z niego tylko klucze
// i wypisuje tylko nazwy.
//
//   railway variables --service kuking.pl --json \
//     | node --experimental-strip-types --no-warnings \
//         scripts/railway/zmienne-spoza-iac.mjs production kuking.pl
//
// Wejście: obiekt JSON { NAZWA: wartość, … } albo tablica nazw.
// Wyjście: jedna nazwa na linię; kod 0 — nic poza plikiem, kod 1 — są
// zmienne tylko w panelu, kod 2 — złe wywołanie albo wejście.
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

// Railway wstrzykuje je sam (RAILWAY_PUBLIC_DOMAIN, RAILWAY_ENVIRONMENT…).
// Nie ustawia ich człowiek i apply ich nie usunie.
const WSTRZYKIWANE_PRZEZ_RAILWAY = /^RAILWAY_/;

/**
 * Nazwy z panelu, których graf `railway.ts` nie deklaruje dla tego serwisu.
 *
 * @param {string[]} nazwyZPanelu
 * @param {{resources: Array<{type: string, name: string, variables?: object}>}} graf
 * @param {string} serwis
 * @returns {string[]} posortowane nazwy
 */
export function zmienneSpozaIac(nazwyZPanelu, graf, serwis) {
  const zasob = graf.resources.find((r) => r.type === "service" && r.name === serwis);
  if (!zasob) {
    throw new Error(`Graf railway.ts nie ma serwisu „${serwis}”.`);
  }

  const wPliku = new Set(Object.keys(zasob.variables ?? {}));

  return [...new Set(nazwyZPanelu)]
    .filter((nazwa) => !WSTRZYKIWANE_PRZEZ_RAILWAY.test(nazwa))
    .filter((nazwa) => !wPliku.has(nazwa))
    .sort();
}

/** Same klucze — wartości z `railway variables --json` odrzucamy od razu. */
export function nazwyZWejscia(tekst) {
  const dane = JSON.parse(tekst);
  if (Array.isArray(dane)) {
    return dane.map(String);
  }
  if (dane !== null && typeof dane === "object") {
    return Object.keys(dane);
  }
  throw new Error("Wejście ma być obiektem JSON albo tablicą nazw.");
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) {
  const [srodowisko, serwis] = process.argv.slice(2);
  if (!srodowisko || !serwis) {
    console.error("Użycie: … zmienne-spoza-iac.mjs <środowisko> <serwis>  (nazwy zmiennych z panelu na stdin)");
    process.exit(2);
  }

  let nazwy;
  try {
    nazwy = nazwyZWejscia(readFileSync(0, "utf8"));
  } catch (blad) {
    // Bez treści wejścia w komunikacie — mogą w nim być sekrety.
    console.error(`Nie udało się odczytać nazw zmiennych ze stdin: ${blad instanceof SyntaxError ? "to nie jest JSON" : blad.message}`);
    process.exit(2);
  }

  const graf = JSON.parse(
    execFileSync(
      process.execPath,
      ["--experimental-strip-types", "--no-warnings", resolve(import.meta.dirname, "iac-graf.mjs"), srodowisko],
      { encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] },
    ),
  );

  const tylkoWPanelu = zmienneSpozaIac(nazwy, graf, serwis);
  if (tylkoWPanelu.length > 0) {
    process.stdout.write(tylkoWPanelu.join("\n") + "\n");
  }
  process.exit(tylkoWPanelu.length > 0 ? 1 : 0);
}
