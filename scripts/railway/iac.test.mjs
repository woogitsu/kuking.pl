// Strażnik topologii Railway (#595): web / worker / scheduler.
//
// DLACZEGO GRAF, A NIE TEKST. `.railway/railway.ts` jest programem, nie
// konfiguracją: rola wynika z `splitServices`, zmienne z rozwinięcia
// `...appEnv`, a środowisko z `ctx`. Grep po źródle nie powie, co `railway
// config plan` zobaczy dla `production`. Dlatego kompilujemy plik tym samym
// SDK (`railway/iac`) do grafu zasobów — lokalnie, BEZ połączenia z Railwayem
// (scripts/railway/iac-graf.mjs) — i sprawdzamy graf.
//
// CZEGO TEN TEST NIE DOWODZI: że żywe środowisko ma zasoby o tych nazwach,
// że plan będzie pusty ani że apply się uda. To odbiór właściciela według
// docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md. Dowodzi tylko, że plik, który
// właściciel poda do `plan`, opisuje zamierzoną topologię.
//
// KONTROLE UJEMNE są w tym samym pliku (ostatni blok): każda reguła dostaje
// graf celowo zepsuty i musi zgłosić błąd. Bez tego sprawdzenie, które nic
// nie znajduje, wyglądałoby tak samo jak sprawdzenie, które nic nie sprawdza.
//
//   node --test scripts/railway/iac.test.mjs      # wymaga Node >= 22.6 i npm ci
import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const KORZEN = resolve(import.meta.dirname, "..", "..");

function graf(srodowisko, env = {}) {
  const wyjscie = execFileSync(
    process.execPath,
    ["--experimental-strip-types", "--no-warnings", resolve(KORZEN, "scripts/railway/iac-graf.mjs"), srodowisko],
    // Czyste środowisko procesu: KUKING_* z powłoki uruchamiającej test
    // nie może po cichu zmienić grafu, który sprawdzamy.
    { cwd: KORZEN, encoding: "utf8", env: { PATH: process.env.PATH, ...env } },
  );
  return JSON.parse(wyjscie);
}

// Nazwa serwisu, na który celuje job `operate` w deploy.yml. Serwis WWW
// w IaC musi nosić DOKŁADNIE tę nazwę — inaczej plan tworzy drugi serwis
// i proponuje usunięcie istniejącego (patrz NAZWA_SERWISU_WWW w railway.ts).
function nazwaZWorkflow() {
  const workflow = readFileSync(resolve(KORZEN, ".github/workflows/deploy.yml"), "utf8");
  const trafienie = workflow.match(/^\s*APP_SERVICE:\s*([^\s#]+)\s*$/m);
  assert.ok(trafienie, "Brak APP_SERVICE w .github/workflows/deploy.yml");
  return trafienie[1];
}

// Najdłuższe zadanie kolejki `media` — worker musi mieć czas je dokończyć
// po SIGTERM, zanim Railway dobije kontener.
function limitZadaniaZdjec() {
  const zrodlo = readFileSync(resolve(KORZEN, "app/Jobs/ProcessUploadedImage.php"), "utf8");
  const trafienie = zrodlo.match(/public int \$timeout = (\d+);/);
  assert.ok(trafienie, "Brak `public int $timeout` w ProcessUploadedImage");
  return Number(trafienie[1]);
}

/** Sekundy na zamknięcie procesu po dokończeniu zadania (audyt B8-03). */
const ZAPAS_ZAMKNIECIA_S = 10;

const ROLA = (s) => s.deploy?.startCommand?.split(" ").at(-1);
const zmienna = (s, k) => s.variables?.[k];

/**
 * Wszystkie reguły naraz; zwraca listę błędów (pusta = zgodnie z zamiarem).
 * Czysta funkcja, żeby kontrole ujemne mogły ją karmić zepsutym grafem.
 */
function bledy(g, { srodowisko, rozbity, nazwaWww, limitZdjec }) {
  const b = [];
  const zasoby = g.resources;
  const uslugi = zasoby.filter((r) => r.type === "service");
  const bazy = zasoby.filter((r) => r.type === "database");
  const aplikacja = uslugi.filter((s) => s.groupId === "Aplikacja");
  const www = aplikacja.find((s) => s.name === nazwaWww);
  const produkcja = srodowisko === "production";

  if (!www) b.push(`brak serwisu WWW o nazwie „${nazwaWww}” (APP_SERVICE z deploy.yml)`);
  if (bazy.length !== 1 || bazy[0].name !== "Postgres") {
    b.push(`baza ma się nazywać „Postgres” jak żywa; jest: ${bazy.map((d) => d.name).join(", ") || "brak"}`);
  }

  const oczekiwane = rozbity
    ? { [nazwaWww]: "web", worker: "worker", scheduler: "scheduler" }
    : { [nazwaWww]: "all" };
  const nazwy = aplikacja.map((s) => s.name).sort();
  if (JSON.stringify(nazwy) !== JSON.stringify(Object.keys(oczekiwane).sort())) {
    b.push(`${srodowisko}: serwisy aplikacji ${nazwy.join(", ")}, oczekiwane ${Object.keys(oczekiwane).join(", ")}`);
  }

  for (const s of aplikacja) {
    const rola = oczekiwane[s.name];
    if (!rola) continue;
    if (ROLA(s) !== rola) b.push(`${s.name}: startCommand z rolą „${ROLA(s)}”, oczekiwana „${rola}”`);
    // Entrypoint: argument wygrywa z APP_ROLE, ale czujki i logi czytają
    // APP_ROLE — rozjazd daje kontener, który robi co innego, niż mówi.
    const appRole = zmienna(s, "APP_ROLE");
    if (appRole?.type !== "literal" || appRole.value !== rola) {
      b.push(`${s.name}: APP_ROLE=${appRole?.value} nie zgadza się z rolą „${rola}”`);
    }
    const jestWww = s.name === nazwaWww;
    const domeny = Object.keys(s.networking?.customDomains ?? {});
    if (!jestWww && domeny.length) b.push(`${s.name}: domena publiczna poza serwisem WWW (${domeny.join(", ")})`);
    if (jestWww && produkcja && JSON.stringify(domeny.sort()) !== JSON.stringify(["kuking.pl", "www.kuking.pl"])) {
      b.push(`${s.name}: domeny produkcji ${domeny.join(", ")}, oczekiwane kuking.pl i www.kuking.pl`);
    }
    const migruje = (s.deploy?.preDeployCommand ?? []).some((c) => c.includes("migrate"));
    if (jestWww && !migruje) b.push(`${s.name}: brak migracji w preDeployCommand`);
    // Migracje raz na wdrożenie, w jednym serwisie. Trzy serwisy z tym
    // samym preDeploy to trzy równoległe `migrate` na jednej bazie.
    if (!jestWww && migruje) b.push(`${s.name}: preDeployCommand z migracją poza serwisem WWW`);
    if (jestWww && s.deploy?.healthcheckPath !== "/health") b.push(`${s.name}: healthcheck inny niż /health`);
    if (!jestWww && s.deploy?.healthcheckPath) {
      b.push(`${s.name}: healthcheck HTTP w serwisie bez serwera HTTP — wdrożenie nigdy nie zda`);
    }
    if (produkcja && s.deploy?.sleepApplication !== false) b.push(`${s.name}: usypianie na produkcji`);
    if (!(s.deploy?.limitOverride?.containers?.memoryBytes > 0)) b.push(`${s.name}: brak limitu pamięci`);
    if (!(s.deploy?.drainingSeconds > 0)) b.push(`${s.name}: brak drainingSeconds — SIGKILL od razu po SIGTERM`);
    // Rola z `queue:work` (worker albo all) musi dać dokończyć zdjęcie z zapasem
    // na zamknięcie procesu — inaczej ubite zadanie wisi do `retry_after` (B8-03).
    if ((rola === "worker" || rola === "all") && !(s.deploy?.drainingSeconds >= limitZdjec + ZAPAS_ZAMKNIECIA_S)) {
      b.push(`${s.name}: rola ${rola}, drainingSeconds=${s.deploy?.drainingSeconds} krótszy niż limit zadania zdjęć ${limitZdjec} s + ${ZAPAS_ZAMKNIECIA_S} s zapasu`);
    }
    if (s.variables?.DB_URL?.resource !== "database.Postgres") b.push(`${s.name}: DB_URL nie wskazuje database.Postgres`);
  }

  if (rozbity) {
    const scheduler = aplikacja.find((s) => s.name === "scheduler");
    // Dwie repliki harmonogramu = każdy digest i każde sprzątanie dwa razy.
    // `onOneServer()` w routes/console.php to druga linia obrony, nie pierwsza.
    if (scheduler && scheduler.deploy?.numReplicas !== 1) b.push(`scheduler: numReplicas=${scheduler.deploy?.numReplicas}, musi być 1`);
    const worker = aplikacja.find((s) => s.name === "worker");
    if (worker && !(worker.deploy?.drainingSeconds >= limitZdjec)) {
      b.push(`worker: drainingSeconds=${worker.deploy?.drainingSeconds} krótszy niż limit zadania zdjęć ${limitZdjec} s`);
    }
    // Od #1459 (#1013) role NIE mają identycznych zmiennych: każda dostaje
    // rdzeń + tylko swoje sekrety (web: logowanie/Turnstile, worker: klucz
    // modelu, scheduler: odczyt kopii). Pilnujemy więc dwóch rzeczy:
    //  1. rdzeń jest w KAŻDEJ roli — zmienna rdzenia dopisana tylko do web
    //     (np. klucz R2) daje workera, który pada na pierwszym zdjęciu;
    //  2. zmienna obecna w dwóch rolach ma w obu tę samą wartość.
    // Pełny podział na role pilnuje tests/Feature/ZmienneRailwayaPerRolaTest.php.
    if (www) {
      const RDZEN = /^(APP_(?!ROLE$)|DB_|AWS_|FILESYSTEM_DISK$|LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK$|KUKING_EXPORT_DISK$|QUEUE_CONNECTION$|CACHE_STORE$|SESSION_|LOG_|MAIL_MAILER$|MAIL_FROM_ADDRESS$)/;
      const zm = (s) => s.variables ?? {};
      for (const s of aplikacja) {
        if (s === www) continue;
        const brak = Object.keys(zm(www)).filter((k) => RDZEN.test(k) && !(k in zm(s)));
        if (brak.length) b.push(`${s.name}: brak zmiennych rdzenia obecnych w serwisie WWW (${brak.sort().join(", ")})`);
        const rozne = Object.keys(zm(s)).filter((k) => k !== "APP_ROLE" && k in zm(www) && JSON.stringify(zm(s)[k]) !== JSON.stringify(zm(www)[k]));
        if (rozne.length) b.push(`${s.name}: inna wartość niż w serwisie WWW (${rozne.sort().join(", ")})`);
        if (s !== www && JSON.stringify([s.source, s.build]) !== JSON.stringify([www.source, www.build])) {
          b.push(`${s.name}: inne źródło albo build niż serwis WWW — role mają chodzić na jednym obrazie`);
        }
      }
    }
  }

  const regiony = new Set([
    ...uslugi.map((s) => s.deploy?.region),
    ...bazy.flatMap((d) => Object.keys(d.deploy?.multiRegionConfig ?? {})),
  ]);
  if (regiony.size !== 1) b.push(`różne regiony: ${[...regiony].join(", ")}`);

  return b;
}

const KONTEKST = { nazwaWww: nazwaZWorkflow(), limitZdjec: limitZadaniaZdjec() };

const PRZYPADKI = [
  // [opis, środowisko, zmienne procesu, czy trzy serwisy]
  ["production", "production", {}, true],
  ["staging", "staging", {}, false],
  ["staging z KUKING_IAC_STAGING_ROZBITY=true (próba #595)", "staging", { KUKING_IAC_STAGING_ROZBITY: "true" }, true],
  ["preview pr-123", "pr-123", {}, false],
  // Zmienna próby działa WYŁĄCZNIE na stagingu — w preview nie rozbija.
  ["preview pr-123 z KUKING_IAC_STAGING_ROZBITY=true", "pr-123", { KUKING_IAC_STAGING_ROZBITY: "true" }, false],
];

for (const [opis, srodowisko, env, rozbity] of PRZYPADKI) {
  test(`graf ${opis} opisuje zamierzoną topologię`, () => {
    assert.deepEqual(bledy(graf(srodowisko, env), { ...KONTEKST, srodowisko, rozbity }), []);
  });
}

test("KUKING_WAIT_FOR_CI przełącza „Wait for CI” w każdym serwisie aplikacji", () => {
  // Produkcja ma dziś włączone czekanie na CI (#595, komentarz 17.09:
  // „WAITING FOR CI”). Plan bez tej zmiennej je WYŁĄCZA — runbook każe ją
  // ustawić; tu pilnujemy, że zmienna naprawdę działa.
  const aplikacja = (g) => g.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja");
  for (const s of aplikacja(graf("production", { KUKING_WAIT_FOR_CI: "true" }))) assert.equal(s.source.checkSuites, true, s.name);
  for (const s of aplikacja(graf("production"))) assert.equal(s.source.checkSuites, false, s.name);
});

test("serwis WWW nazywa się jak żywy serwis i jak APP_SERVICE w deploy.yml", () => {
  // Pomiar 9.09.2026 (OperacjeWdrozeniaCelujaWIstniejacySerwisTest): kuking.pl.
  assert.equal(KONTEKST.nazwaWww, "kuking.pl");
});

// ---------------------------------------------------------------------------
//  KONTROLE UJEMNE — każda mutacja odpowiada jednej realnej pomyłce.
// ---------------------------------------------------------------------------
const PROD = graf("production");
const STAGING = graf("staging");
const usluga = (g, nazwa) => g.resources.find((r) => r.name === nazwa);

const MUTACJE = [
  ["dawna nazwa `web` zamiast żywej", PROD, (g) => { usluga(g, "kuking.pl").name = "web"; }],
  ["baza `postgres` małą literą", PROD, (g) => { g.resources.find((r) => r.type === "database").name = "postgres"; }],
  ["worker w roli all", PROD, (g) => { usluga(g, "worker").deploy.startCommand = "/usr/local/bin/kuking-entrypoint all"; }],
  ["APP_ROLE rozjechany z argumentem", PROD, (g) => { usluga(g, "scheduler").variables.APP_ROLE.value = "worker"; }],
  ["dwa harmonogramy", PROD, (g) => { usluga(g, "scheduler").deploy.numReplicas = 2; }],
  ["migracja w workerze", PROD, (g) => { usluga(g, "worker").deploy.preDeployCommand = ["php artisan migrate --force"]; }],
  ["domena na workerze", PROD, (g) => { usluga(g, "worker").networking = { customDomains: { "kuking.pl": { port: 8080 } } }; }],
  ["healthcheck HTTP na harmonogramie", PROD, (g) => { usluga(g, "scheduler").deploy.healthcheckPath = "/health"; }],
  ["zmienna tylko w web", PROD, (g) => { delete usluga(g, "worker").variables.AWS_BUCKET; }],
  ["inna wartość zmiennej rdzenia w harmonogramie", PROD, (g) => { usluga(g, "scheduler").variables.AWS_BUCKET = { value: "inny-bucket" }; }],
  ["worker bez czasu na zdjęcie", PROD, (g) => { usluga(g, "worker").deploy.drainingSeconds = 30; }],
  ["worker bez zapasu po zadaniu zdjęć", PROD, (g) => { usluga(g, "worker").deploy.drainingSeconds = 120; }],
  ["rola all z oknem serwisu WWW", STAGING, (g) => { usluga(g, "kuking.pl").deploy.drainingSeconds = 30; }],
  ["usypianie workera", PROD, (g) => { usluga(g, "worker").deploy.sleepApplication = true; }],
  ["worker w innym regionie", PROD, (g) => { usluga(g, "worker").deploy.region = "us-west2"; }],
  ["worker z innej gałęzi", PROD, (g) => { usluga(g, "worker").source = { ...usluga(g, "worker").source, branch: "staging" }; }],
  ["brak serwisu scheduler", PROD, (g) => { g.resources = g.resources.filter((r) => r.name !== "scheduler"); }],
  ["worker na stagingu", STAGING, (g) => { g.resources.push({ ...structuredClone(usluga(g, "kuking.pl")), name: "worker" }); }],
  ["staging w roli web", STAGING, (g) => {
    const s = usluga(g, "kuking.pl");
    s.deploy.startCommand = "/usr/local/bin/kuking-entrypoint web";
    s.variables.APP_ROLE.value = "web";
  }],
];

for (const [opis, wzor, zepsuj] of MUTACJE) {
  test(`kontrola ujemna: ${opis}`, () => {
    const g = structuredClone(wzor);
    const przed = JSON.stringify(g);
    zepsuj(g);
    assert.notEqual(JSON.stringify(g), przed, "mutacja nic nie zmieniła");
    const srodowisko = wzor === PROD ? "production" : "staging";
    assert.notDeepEqual(bledy(g, { ...KONTEKST, srodowisko, rozbity: wzor === PROD }), [], `strażnik nie zauważył: ${opis}`);
  });
}

// ---------------------------------------------------------------------------
//  WORKFLOW `railway-iac.yml`: apply tylko ręcznie (#595).
//  Czytamy tekst bez komentarzy — parsera YAML nie ma w zależnościach,
//  a trzy reguły są liniowe. Kontrole ujemne niżej pilnują, że zapalają.
// ---------------------------------------------------------------------------
function bledyWorkflow(tekst) {
  const b = [];
  const kod = tekst.split("\n").filter((l) => !l.trimStart().startsWith("#")).join("\n");
  const typy = kod.match(/^\s*types:\s*\[([^\]]*)\]/m)?.[1] ?? "";
  if (/\bclosed\b/.test(typy)) b.push("pull_request reaguje na `closed` — apply po scaleniu wraca");
  if (/^\s*confirm-destructive:\s*true\s*$/m.test(kod)) b.push("stałe confirm-destructive: true");
  const apply = kod.split(/^ {2}apply:\s*$/m)[1]?.split(/^ {2}\S/m)[0] ?? "";
  if (!apply) b.push("brak joba apply — reguła nie ma czego sprawdzać");
  if (!apply.includes("github.event_name == 'workflow_dispatch'")) b.push("apply nie jest ograniczony do workflow_dispatch");
  if (!apply.includes("github.ref == 'refs/heads/main'")) b.push("apply nie jest ograniczony do main");
  if (!/inputs\.potwierdzenie == '[^']+'/.test(apply)) b.push("apply bez wpisanego potwierdzenia");
  if (/merged|merge_commit_sha/.test(apply)) b.push("apply nadal zależy od scalenia PR-a");
  return b;
}

const WORKFLOW_IAC = readFileSync(resolve(KORZEN, ".github/workflows/railway-iac.yml"), "utf8");

test("railway-iac.yml: apply wyłącznie ręcznie, bez automatycznych usunięć", () => {
  assert.deepEqual(bledyWorkflow(WORKFLOW_IAC), []);
});

const MUTACJE_WORKFLOW = [
  ["powrót `closed`", (t) => t.replace("types: [opened, synchronize, reopened]", "types: [opened, synchronize, reopened, closed]")],
  ["stałe confirm-destructive", (t) => t.replace(/confirm-destructive: \$\{\{.*\}\}/, "confirm-destructive: true")],
  ["apply po scaleniu", (t) => t.replace("github.event_name == 'workflow_dispatch' &&", "github.event.pull_request.merged &&")],
  ["apply bez potwierdzenia", (t) => t.replace(/ &&\n\s*inputs\.potwierdzenie == '[^']+'/, "")],
  ["apply z dowolnej gałęzi", (t) => t.replace(/github\.ref == 'refs\/heads\/main' &&\n\s*/, "")],
];

for (const [opis, zepsuj] of MUTACJE_WORKFLOW) {
  test(`kontrola ujemna workflow: ${opis}`, () => {
    const zepsuty = zepsuj(WORKFLOW_IAC);
    assert.notEqual(zepsuty, WORKFLOW_IAC, "mutacja nic nie zmieniła");
    assert.notDeepEqual(bledyWorkflow(zepsuty), [], `strażnik nie zauważył: ${opis}`);
  });
}
