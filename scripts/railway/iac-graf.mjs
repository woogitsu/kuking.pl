// Kompiluje `.railway/railway.ts` do grafu zasobów dla jednego środowiska
// i wypisuje go jako JSON na stdout. NIE łączy się z Railwayem: używa tylko
// lokalnego SDK (`railway/iac`) z node_modules — tego samego programu, który
// `railway config plan` porównuje z żywym środowiskiem.
//
//   node --experimental-strip-types --no-warnings scripts/railway/iac-graf.mjs production
//
//   … production --wspoldzielone
//       tylko posortowane nazwy zmiennych współdzielonych (`${{shared.X}}`),
//       do których odwołuje się graf — lista kontrolna przed `apply` (#595).
//       Same NAZWY; wartości w tym pliku nie istnieją.
//
// Wymaga Node >= 22.6 (usuwanie typów TypeScript) i `npm ci`.
// Czyta go strażnik `scripts/railway/iac.test.mjs` (#595).
import { createRailwayContext, project } from "railway/iac";
import { pathToFileURL } from "node:url";
import { resolve } from "node:path";

const srodowisko = process.argv[2];
if (!srodowisko) {
  console.error("Podaj środowisko, np. production albo staging.");
  process.exit(2);
}

const plik = resolve(import.meta.dirname, "..", "..", ".railway", "railway.ts");
const modul = await import(pathToFileURL(plik).href);
const ctx = createRailwayContext({ environment: srodowisko });
const definicja = await modul.default(ctx, project);

if (process.argv.includes("--wspoldzielone")) {
  const nazwy = new Set();
  for (const zasob of definicja.resources) {
    for (const wartosc of Object.values(zasob.variables ?? {})) {
      if (wartosc?.type === "sharedReference") nazwy.add(wartosc.name);
    }
  }
  process.stdout.write([...nazwy].sort().join("\n") + "\n");
} else {
  process.stdout.write(JSON.stringify(definicja));
}
