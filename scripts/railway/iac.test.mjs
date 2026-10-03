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
import { execFileSync, spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const KORZEN = resolve(import.meta.dirname, "..", "..");

function uruchomGraf(srodowisko, env) {
  return execFileSync(
    process.execPath,
    ["--experimental-strip-types", "--no-warnings", resolve(KORZEN, "scripts/railway/iac-graf.mjs"), srodowisko],
    // Czyste środowisko procesu: KUKING_* z powłoki uruchamiającej test
    // nie może po cichu zmienić grafu, który sprawdzamy.
    { cwd: KORZEN, encoding: "utf8", env: { PATH: process.env.PATH, ...env }, stdio: ["ignore", "pipe", "pipe"] },
  );
}

// railway.ts odmawia bez jawnego KUKING_WAIT_FOR_CI (#1390), więc graf
// liczymy z jawnym "false" — tak jak dziś stoi produkcja bez bramki —
// chyba że przypadek poda własną wartość.
function graf(srodowisko, env = {}) {
  return JSON.parse(uruchomGraf(srodowisko, { KUKING_WAIT_FOR_CI: "false", ...env }));
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
    const komendyPrzedWdrozeniem = s.deploy?.preDeployCommand ?? [];
    const maWspolnaBlokade = komendyPrzedWdrozeniem.some((c) => c.includes("kuking:migruj-pod-blokada"));
    const migruje = komendyPrzedWdrozeniem.some((c) => c.includes("migrate") || c.includes("kuking:migruj-pod-blokada"));
    if (jestWww && !maWspolnaBlokade) b.push(`${s.name}: brak migracji ze wspólną blokadą w preDeployCommand`);
    // #1932 (audyt 28.09.2026): pre-deploy kończy się PRZED seedem-importem
    // i healthcheckiem nowego kontenera. Numer wdrożenia zapisuje dopiero
    // nowy kontener po gotowości (docker/entrypoint.sh), inaczej nieudany
    // rollout zużywa numer i opisuje funkcje jako wydane.
    if (komendyPrzedWdrozeniem.some((c) => c.includes("kuking:zarejestruj-wdrozenie"))) {
      b.push(`${s.name}: rejestracja wdrożenia w preDeployCommand — zapisze numer także przy nieudanym rolloucie`);
    }
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
      const RDZEN = /^(APP_(?!ROLE$)|DB_|AWS_|FILESYSTEM_DISK$|LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK$|KUKING_EXPORT_DISK$|KUKING_MEDIA_DISK$|KUKING_QUESTIONS_ENABLED$|QUEUE_CONNECTION$|CACHE_STORE$|SESSION_|LOG_|MAIL_MAILER$|MAIL_FROM_ADDRESS$)/;
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
  for (const s of aplikacja(graf("production", { KUKING_WAIT_FOR_CI: "false" }))) assert.equal(s.source.checkSuites, false, s.name);
});

test("brak albo zła wartość KUKING_WAIT_FOR_CI zatrzymuje graf zamiast wyłączać bramkę (#1390)", () => {
  // Do 24.09.2026 brak zmiennej dawał po cichu `checkSuites: false`.
  for (const env of [{}, { KUKING_WAIT_FOR_CI: "" }, { KUKING_WAIT_FOR_CI: "1" }, { KUKING_WAIT_FOR_CI: "TRUE" }]) {
    assert.throws(
      () => uruchomGraf("production", env),
      (e) => /KUKING_WAIT_FOR_CI musi być jawnie "true" albo "false"/.test(String(e.stderr)),
      `graf policzony mimo KUKING_WAIT_FOR_CI=${JSON.stringify(env.KUKING_WAIT_FOR_CI)}`,
    );
  }
});

// ---------------------------------------------------------------------------
//  ZMIENNE, KTÓRE DO 25.09.2026 STAŁY TYLKO W PANELU (albo nigdzie)
//
//  `railway config apply` ustawia CAŁY zestaw zmiennych usługi: zmienna
//  z panelu, której nie ma w pliku, znika. Właściciel (25.09.2026) kazał je
//  dopisać — każdą do ról, które ją czytają. Macierz niżej to ta sama
//  decyzja co `JAWNE_Z_PANELU` i `MACIERZ` w ZmienneRailwayaPerRolaTest.php,
//  tylko sprawdzona na grafie, który zobaczy `plan`.
//  TRUSTED_PROXIES jest tu jako MARTWA: nie czyta jej żaden kod (SEC-01),
//  więc nie dostaje jej żadna rola, a plan pokaże jej usunięcie.
// ---------------------------------------------------------------------------
const Z_PANELU = {
  KUKING_QUESTIONS_ENABLED: { role: ["web", "worker", "scheduler"], wartosc: { type: "literal", value: "true" } },
  KUKING_MEDIA_DISK: { role: ["web", "worker", "scheduler"], wartosc: { type: "literal", value: "r2" } },
  KUKING_EDGE_TRYB: { role: ["web"], wartosc: { type: "sharedReference", name: "KUKING_EDGE_TRYB" } },
  KUKING_HTML_EDGE_CACHE_SECONDS: { role: ["web"], wartosc: { type: "sharedReference", name: "KUKING_HTML_EDGE_CACHE_SECONDS" } },
  KUKING_R2_PUBLICZNE_ADRESY: { role: ["web"], wartosc: { type: "sharedReference", name: "KUKING_R2_PUBLICZNE_ADRESY" } },
  TRUSTED_PROXIES: { role: [], wartosc: undefined },
};

function bledyZPanelu(g, { rozbity, nazwaWww }) {
  const b = [];
  const aplikacja = g.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja");
  const rolaUslugi = (s) => (!rozbity ? "all" : s.name === nazwaWww ? "web" : s.name);
  for (const s of aplikacja) {
    const rola = rolaUslugi(s);
    for (const [nazwa, { role, wartosc }] of Object.entries(Z_PANELU)) {
      const ma = s.variables?.[nazwa];
      const powinna = rola === "all" ? role.length > 0 : role.includes(rola);
      if (powinna && JSON.stringify(ma) !== JSON.stringify(wartosc)) {
        b.push(`${s.name} (${rola}): ${nazwa}=${JSON.stringify(ma)}, oczekiwane ${JSON.stringify(wartosc)} — apply usunie zmienną z panelu`);
      }
      if (!powinna && ma !== undefined) b.push(`${s.name} (${rola}): dostaje ${nazwa}, której ta rola nie czyta`);
    }
  }
  return b;
}

for (const [opis, srodowisko, env, rozbity] of PRZYPADKI) {
  test(`graf ${opis}: zmienne z panelu w swoich rolach`, () => {
    assert.deepEqual(bledyZPanelu(graf(srodowisko, env), { ...KONTEKST, rozbity }), []);
  });
}

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

test("instrukcja wdrożenia podaje czasy zamykania z grafu IaC (#2056)", () => {
  const dokument = readFileSync(resolve(KORZEN, "docs/DEPLOYMENT.md"), "utf8");
  const przypadki = [
    ["splitServices=false", "all", usluga(STAGING, KONTEKST.nazwaWww)],
    ["splitServices=true", "web", usluga(PROD, KONTEKST.nazwaWww)],
    ["splitServices=true", "worker", usluga(PROD, "worker")],
    ["splitServices=true", "scheduler", usluga(PROD, "scheduler")],
  ];

  for (const [topologia, rola, serwis] of przypadki) {
    assert.ok(serwis, `brak serwisu ${rola} w grafie IaC`);
    const wiersz = `| \`${topologia}\` | \`${rola}\` | ${serwis.deploy.drainingSeconds} s |`;
    assert.ok(dokument.includes(wiersz), `docs/DEPLOYMENT.md nie zgadza się z IaC: ${wiersz}`);
  }
});

const MUTACJE = [
  ["dawna nazwa `web` zamiast żywej", PROD, (g) => { usluga(g, "kuking.pl").name = "web"; }],
  ["baza `postgres` małą literą", PROD, (g) => { g.resources.find((r) => r.type === "database").name = "postgres"; }],
  ["worker w roli all", PROD, (g) => { usluga(g, "worker").deploy.startCommand = "/usr/local/bin/kuking-entrypoint all"; }],
  ["APP_ROLE rozjechany z argumentem", PROD, (g) => { usluga(g, "scheduler").variables.APP_ROLE.value = "worker"; }],
  ["dwa harmonogramy", PROD, (g) => { usluga(g, "scheduler").deploy.numReplicas = 2; }],
  ["rejestracja wdrożenia w pre-deploy (przed healthcheckiem)", PROD, (g) => { usluga(g, "kuking.pl").deploy.preDeployCommand.push("php artisan kuking:zarejestruj-wdrozenie --no-interaction"); }],
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

// ---------------------------------------------------------------------------
//  LISTY URODZINOWE (#1755, D-269): wyłącznik wysyłki tylko w roli, która
//  wysyła (scheduler / all) i włączony wyłącznie na produkcji.
// ---------------------------------------------------------------------------
function bledyUrodzin(g, { srodowisko, nazwaWww }) {
  const b = [];
  const aplikacja = g.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja");
  const produkcja = srodowisko === "production";
  for (const s of aplikacja) {
    const rola = ROLA(s);
    const flaga = zmienna(s, "KUKING_URODZINY_MAIL_WLACZONY");
    const wysyla = rola === "scheduler" || rola === "all";
    if (!wysyla && flaga) b.push(`${s.name}: KUKING_URODZINY_MAIL_WLACZONY w roli ${rola}, która list nie kolejkuje`);
    if (wysyla) {
      const oczekiwana = produkcja ? "true" : "false";
      if (flaga?.type !== "literal" || flaga.value !== oczekiwana) {
        b.push(`${s.name}: KUKING_URODZINY_MAIL_WLACZONY=${flaga?.value}, oczekiwane „${oczekiwana}” (${srodowisko})`);
      }
    }
  }
  if (!aplikacja.some((s) => s.name === nazwaWww)) b.push("brak serwisu WWW — nie ma czego sprawdzać");
  return b;
}

for (const [opis, srodowisko] of [["production", "production"], ["staging", "staging"], ["preview pr-123", "pr-123"]]) {
  test(`listy urodzinowe: wyłącznik w grafie ${opis}`, () => {
    assert.deepEqual(bledyUrodzin(graf(srodowisko), { ...KONTEKST, srodowisko }), []);
  });
}

for (const [opis, wzor, zepsuj] of [
  ["wysyłka wyłączona na produkcji", PROD, (g) => { usluga(g, "scheduler").variables.KUKING_URODZINY_MAIL_WLACZONY.value = "false"; }],
  ["wyłącznik w workerze", PROD, (g) => { usluga(g, "worker").variables.KUKING_URODZINY_MAIL_WLACZONY = { type: "literal", value: "true" }; }],
  ["wysyłka włączona na stagingu", STAGING, (g) => { usluga(g, "kuking.pl").variables.KUKING_URODZINY_MAIL_WLACZONY.value = "true"; }],
]) {
  test(`kontrola ujemna listów urodzinowych: ${opis}`, () => {
    const g = structuredClone(wzor);
    const przed = JSON.stringify(g);
    zepsuj(g);
    assert.notEqual(JSON.stringify(g), przed, "mutacja nic nie zmieniła");
    const srodowisko = wzor === PROD ? "production" : "staging";
    assert.notDeepEqual(bledyUrodzin(g, { ...KONTEKST, srodowisko }), [], `strażnik nie zauważył: ${opis}`);
  });
}

const MUTACJE_Z_PANELU = [
  ["flaga pytań zdjęta z harmonogramu", PROD, (g) => { delete usluga(g, "scheduler").variables.KUKING_QUESTIONS_ENABLED; }],
  ["flaga pytań wyłączona", PROD, (g) => { usluga(g, "kuking.pl").variables.KUKING_QUESTIONS_ENABLED.value = "false"; }],
  ["tryb krawędzi zdjęty z WWW", PROD, (g) => { delete usluga(g, "kuking.pl").variables.KUKING_EDGE_TRYB; }],
  ["adresy bramki R2 w workerze", PROD, (g) => { usluga(g, "worker").variables.KUKING_R2_PUBLICZNE_ADRESY = { type: "sharedReference", name: "KUKING_R2_PUBLICZNE_ADRESY" }; }],
  ["martwe TRUSTED_PROXIES wróciło", PROD, (g) => { usluga(g, "kuking.pl").variables.TRUSTED_PROXIES = { type: "literal", value: "*" }; }],
  ["rola all bez cache HTML", STAGING, (g) => { delete usluga(g, "kuking.pl").variables.KUKING_HTML_EDGE_CACHE_SECONDS; }],
];

for (const [opis, wzor, zepsuj] of MUTACJE_Z_PANELU) {
  test(`kontrola ujemna zmiennych z panelu: ${opis}`, () => {
    const g = structuredClone(wzor);
    const przed = JSON.stringify(g);
    zepsuj(g);
    assert.notEqual(JSON.stringify(g), przed, "mutacja nic nie zmieniła");
    assert.notDeepEqual(bledyZPanelu(g, { ...KONTEKST, rozbity: wzor === PROD }), [], `strażnik nie zauważył: ${opis}`);
  });
}

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
//  POLITYKA RESTARTU (audyt 30.09.2026, IN-13, #2302).
//  Po wyczerpaniu prób Railway zostawia usługę wyłączoną do ręcznego
//  restartu. Na produkcji 10 prób (limit planu darmowego) to kwadrans awarii
//  bazy, po którym kolejka stoi do rana. Uzasadnienie liczb jest przy
//  `PROBY_RESTARTU_PRODUKCJA` w railway.ts.
// ---------------------------------------------------------------------------
/** Najwięcej prób, na jakie pozwala plan darmowy (dokumentacja Railway). */
const LIMIT_PROB_PLANU_DARMOWEGO = 10;

function bledyRestartu(g, { srodowisko }) {
  const b = [];
  const produkcja = srodowisko === "production";
  const aplikacja = g.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja");
  if (!aplikacja.length) b.push("brak serwisów aplikacji — nie ma czego sprawdzać");
  for (const s of aplikacja) {
    const rola = ROLA(s);
    const typ = s.deploy?.restartPolicyType;
    const proby = s.deploy?.restartPolicyMaxRetries;
    // Kolejka i harmonogram mają chodzić zawsze: wyjście z kodem 0 przy
    // ON_FAILURE też kończy się martwą usługą.
    if ((rola === "worker" || rola === "scheduler") && typ !== "ALWAYS") {
      b.push(`${s.name}: restartPolicyType=${typ}, rola ${rola} wymaga ALWAYS`);
    }
    if (rola !== "worker" && rola !== "scheduler" && typ !== "ON_FAILURE") {
      b.push(`${s.name}: restartPolicyType=${typ}, rola ${rola} ma ON_FAILURE`);
    }
    if (!Number.isInteger(proby) || proby < 1) b.push(`${s.name}: restartPolicyMaxRetries=${proby}, oczekiwana liczba prób`);
    else if (produkcja && proby <= LIMIT_PROB_PLANU_DARMOWEGO) {
      b.push(`${s.name}: ${proby} prób restartu na produkcji — po krótkiej awarii bazy usługa zostaje wyłączona`);
    } else if (!produkcja && proby > LIMIT_PROB_PLANU_DARMOWEGO) {
      b.push(`${s.name}: ${proby} prób restartu poza produkcją — więcej niż ${LIMIT_PROB_PLANU_DARMOWEGO}`);
    }
  }
  return b;
}

for (const [opis, srodowisko, env] of PRZYPADKI) {
  test(`graf ${opis}: polityka restartu`, () => {
    assert.deepEqual(bledyRestartu(graf(srodowisko, env), { srodowisko }), []);
  });
}

for (const [opis, wzor, zepsuj] of [
  ["10 prób workera na produkcji (stan sprzed IN-13)", PROD, (g) => { usluga(g, "worker").deploy.restartPolicyMaxRetries = 10; }],
  ["10 prób serwisu WWW na produkcji", PROD, (g) => { usluga(g, "kuking.pl").deploy.restartPolicyMaxRetries = 10; }],
  ["worker z ON_FAILURE", PROD, (g) => { usluga(g, "worker").deploy.restartPolicyType = "ON_FAILURE"; }],
  ["scheduler z ON_FAILURE", PROD, (g) => { usluga(g, "scheduler").deploy.restartPolicyType = "ON_FAILURE"; }],
  ["serwis WWW bez restartu", PROD, (g) => { usluga(g, "kuking.pl").deploy.restartPolicyType = "NEVER"; }],
  ["brak limitu prób", PROD, (g) => { delete usluga(g, "worker").deploy.restartPolicyMaxRetries; }],
  ["limit produkcji na stagingu", STAGING, (g) => { usluga(g, "kuking.pl").deploy.restartPolicyMaxRetries = 1000; }],
]) {
  test(`kontrola ujemna polityki restartu: ${opis}`, () => {
    const g = structuredClone(wzor);
    const przed = JSON.stringify(g);
    zepsuj(g);
    assert.notEqual(JSON.stringify(g), przed, "mutacja nic nie zmieniła");
    const srodowisko = wzor === PROD ? "production" : "staging";
    assert.notDeepEqual(bledyRestartu(g, { srodowisko }), [], `strażnik nie zauważył: ${opis}`);
  });
}

// ---------------------------------------------------------------------------
//  ZADANIA DŁUŻSZE NIŻ OKNO ZAMKNIĘCIA (audyt 30.09.2026, IN-14, #2302).
//  Wcześniej test znał tylko `ProcessUploadedImage`. Zadanie z `$timeout`
//  dłuższym niż `drainingSeconds` workera (minus zapas na zamknięcie) jest
//  zabijane przy wdrożeniu i wraca dopiero po `retry_after`. Każde takie
//  zadanie ma tu wpis z powodem. Nowe długie zadanie bez wpisu oblewa test.
//  Wpis, który przestał być potrzebny, też oblewa: lista nie może gnić.
// ---------------------------------------------------------------------------
const DLUZSZE_NIZ_OKNO = {
  // D-333 „Okno zamknięcia workera”: eksport przerwany przy wdrożeniu wraca
  // po `retry_after`, paczka i tak idzie e-mailem.
  GenerateUserExport: "D-333: przerwany eksport ponawia kolejka",
  // IN-14: `tries = 1`, zlecenie domyka `failed()` albo `kuking:odzyskaj-importy`.
  // Import jest wyłączony na produkcji do podpisania DPA (D-333, #2214).
  ImportujPrzepisZAdresu: "IN-14: import z adresu, zlecenie domyka odzyskiwanie",
  ImportujPrzepisZPdf: "IN-14: import z PDF, zlecenie domyka odzyskiwanie",
  // #2535: podgląd stron PDF, `tries = 1`; przerwany przy wdrożeniu kończy
  // `failed()` zdaniem „wyślij plik jeszcze raz”, a porzuconą poczekalnię
  // sprząta `kuking:odzyskaj-importy` (najpóźniej po 2 godzinach).
  PrzygotujPodgladPdf: "#2535: podgląd stron PDF, poczekalnię domyka odzyskiwanie",
};

function limityZadan() {
  const katalog = resolve(KORZEN, "app/Jobs");
  return Object.fromEntries(
    readdirSync(katalog)
      .filter((plik) => plik.endsWith(".php"))
      .map((plik) => {
        const trafienie = readFileSync(join(katalog, plik), "utf8").match(/public (?:int )?\$timeout = (\d+);/);
        return [plik.replace(/\.php$/, ""), trafienie ? Number(trafienie[1]) : null];
      })
      .filter(([, limit]) => limit !== null),
  );
}

function bledyOkna(limity, okno, wyjatki) {
  const b = [];
  const zaDlugie = Object.entries(limity).filter(([, limit]) => limit + ZAPAS_ZAMKNIECIA_S > okno);
  for (const [zadanie, limit] of zaDlugie) {
    if (!(zadanie in wyjatki)) {
      b.push(`${zadanie}: $timeout=${limit} s + ${ZAPAS_ZAMKNIECIA_S} s zapasu > okno zamknięcia workera ${okno} s i brak wpisu w DLUZSZE_NIZ_OKNO`);
    }
  }
  for (const zadanie of Object.keys(wyjatki)) {
    if (!(zadanie in limity)) b.push(`DLUZSZE_NIZ_OKNO: ${zadanie} nie istnieje albo nie ma $timeout`);
    else if (!zaDlugie.some(([z]) => z === zadanie)) b.push(`DLUZSZE_NIZ_OKNO: ${zadanie} mieści się już w oknie, usuń wpis`);
  }
  return b;
}

const LIMITY_ZADAN = limityZadan();
const OKNO_WORKERA = usluga(PROD, "worker").deploy.drainingSeconds;

test("każde zadanie dłuższe niż okno zamknięcia workera jest świadomym wyjątkiem (IN-14)", () => {
  // Kontrola, że czytanie kodu zadań w ogóle coś znalazło.
  assert.equal(LIMITY_ZADAN.ProcessUploadedImage, KONTEKST.limitZdjec);
  assert.deepEqual(bledyOkna(LIMITY_ZADAN, OKNO_WORKERA, DLUZSZE_NIZ_OKNO), []);
});

for (const [opis, zepsuj] of [
  ["nowe zadanie 300 s bez wpisu", (l, w) => { l.NoweDlugieZadanie = 300; }],
  ["import z PDF zdjęty z listy wyjątków", (l, w) => { delete w.ImportujPrzepisZPdf; }],
  ["zadanie ze zdjęciami wydłużone do 125 s", (l, w) => { l.ProcessUploadedImage = 125; }],
  ["wpis dla zadania, które już się mieści", (l, w) => { l.ImportujPrzepisZAdresu = 60; }],
  ["wpis dla zadania, którego nie ma", (l, w) => { w.UsunieteZadanie = "?"; }],
]) {
  test(`kontrola ujemna okna zamknięcia: ${opis}`, () => {
    const limity = { ...LIMITY_ZADAN };
    const wyjatki = { ...DLUZSZE_NIZ_OKNO };
    zepsuj(limity, wyjatki);
    assert.notDeepEqual(bledyOkna(limity, OKNO_WORKERA, wyjatki), [], `strażnik nie zauważył: ${opis}`);
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

// ---------------------------------------------------------------------------
//  watchPatterns OBEJMUJE WSZYSTKO, CO WCHODZI DO OBRAZU (audyt B10-05)
//
//  `Dockerfile` robi `COPY . .`, więc do obrazu wchodzi każdy śledzony
//  katalog i plik z korzenia, którego nie wycina `.dockerignore`. Do
//  25.09.2026 `watchPatterns` pomijał `lang/**`: PR zmieniający same
//  komunikaty (błędy walidacji, hasła) nie uruchamiał wdrożenia i wisiał
//  zielony na `main` do następnego commita w innym katalogu.
//
//  Porównanie idzie na poziomie korzenia repozytorium. Wpis z obrazu, który
//  NIE wpływa na działanie aplikacji, musi stać w rejestrze niżej z powodem.
// ---------------------------------------------------------------------------
const W_OBRAZIE_BEZ_WPLYWU = {
  scripts: "narzędzia i testy JS; etap `assets` tylko je uruchamia, obraz końcowy bierze z niego samo public/build, a runtime ich nie woła",
  storage: "same .gitignore — katalogi robocze zakłada entrypoint",
  "README.md": "dokumentacja",
  LICENSE: "dokumentacja",
  ".env.example": "wzór zmiennych; obraz nie czyta .env",
  "pint.json": "konfiguracja formatera, nieużywana w runtime",
  "phpstan.neon": "konfiguracja analizy statycznej",
  "phpstan-bootstrap.php": "konfiguracja analizy statycznej",
  ".windsurfrules": "wskaźnik instrukcji dla agentów",
  ".codex": "instrukcje dla agentów (porządki: audyt A4)",
  evidence: "pliki robocze audytów (porządki: audyt A4 1.x)",
  object_key: "plik roboczy (A5-17)",
};

function wpisyKorzenia() {
  const pliki = execFileSync("git", ["ls-files", "-z"], { cwd: KORZEN, encoding: "utf8" }).split("\0").filter(Boolean);
  return [...new Set(pliki.map((p) => p.split("/")[0]))].sort();
}

/** Minimalny odczyt `.dockerignore` dla wpisów z korzenia: ostatnia pasująca reguła wygrywa. */
function wycinaDockerignore(nazwa, dockerignore) {
  let wyciety = false;
  for (const surowa of dockerignore.split("\n")) {
    const linia = surowa.trim();
    if (!linia || linia.startsWith("#")) continue;
    const negacja = linia.startsWith("!");
    const wzor = (negacja ? linia.slice(1) : linia).replace(/\/(\*\*)?$/, "");
    if (wzor.includes("/")) continue; // reguła dotyczy wnętrza katalogu, nie całego wpisu
    const regex = new RegExp(`^${wzor.replace(/[.+^${}()|[\]\\]/g, "\\$&").replace(/\*/g, "[^/]*").replace(/\?/g, "[^/]")}$`);
    if (regex.test(nazwa)) wyciety = !negacja;
  }
  return wyciety;
}

function nieobserwowaneWObrazie(wpisy, dockerignore, wzorce, rejestr) {
  return wpisy
    .filter((w) => !wycinaDockerignore(w, dockerignore))
    .filter((w) => !wzorce.includes(w) && !wzorce.includes(`${w}/**`))
    .filter((w) => !(w in rejestr));
}

const DOCKERIGNORE = readFileSync(resolve(KORZEN, ".dockerignore"), "utf8");
const WZORCE_WWW = usluga(PROD, "kuking.pl").build.watchPatterns;

test("watchPatterns serwisu WWW obejmuje każdy wpis korzenia, który wchodzi do obrazu", () => {
  assert.deepEqual(nieobserwowaneWObrazie(wpisyKorzenia(), DOCKERIGNORE, WZORCE_WWW, W_OBRAZIE_BEZ_WPLYWU), []);
});

test("watchPatterns jest ten sam we wszystkich serwisach aplikacji i środowiskach", () => {
  for (const g of [PROD, STAGING, graf("pr-123")]) {
    for (const s of g.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja")) {
      assert.deepEqual(s.build.watchPatterns, WZORCE_WWW, s.name);
    }
  }
});

test("rejestr wpisów bez wpływu nie zasłania niczego, co obserwujemy albo co wycina .dockerignore", () => {
  for (const w of Object.keys(W_OBRAZIE_BEZ_WPLYWU)) {
    assert.ok(!WZORCE_WWW.includes(w) && !WZORCE_WWW.includes(`${w}/**`), `${w} jest w watchPatterns — zbędny wpis w rejestrze`);
    assert.ok(!wycinaDockerignore(w, DOCKERIGNORE), `${w} wycina .dockerignore — zbędny wpis w rejestrze`);
  }
});

const MUTACJE_OBRAZU = [
  ["watchPatterns bez lang/**", () => nieobserwowaneWObrazie(wpisyKorzenia(), DOCKERIGNORE, WZORCE_WWW.filter((w) => w !== "lang/**"), W_OBRAZIE_BEZ_WPLYWU)],
  ["nowy katalog w obrazie", () => nieobserwowaneWObrazie([...wpisyKorzenia(), "nowy-katalog"], DOCKERIGNORE, WZORCE_WWW, W_OBRAZIE_BEZ_WPLYWU)],
  ["docs zdjęte z .dockerignore", () => nieobserwowaneWObrazie(wpisyKorzenia(), DOCKERIGNORE.replace(/^docs$/m, ""), WZORCE_WWW, W_OBRAZIE_BEZ_WPLYWU)],
];

for (const [opis, policz] of MUTACJE_OBRAZU) {
  test(`kontrola ujemna obrazu: ${opis}`, () => {
    assert.notDeepEqual(policz(), [], `strażnik nie zauważył: ${opis}`);
  });
}

// ---------------------------------------------------------------------------
// Zmienne tylko w panelu (audyt po fali 25.09, znalezisko 12 / propozycja C).
// Skrypt dla właściciela: docs/infra/ZMIENNE_SPOZA_IAC.md. Tu pilnujemy, że
// wskazuje zmienne spoza grafu, nie wskazuje zadeklarowanych ani
// wstrzykiwanych przez Railway i że nie wypisuje WARTOŚCI.
// ---------------------------------------------------------------------------
const { zmienneSpozaIac } = await import(resolve(KORZEN, "scripts/railway/zmienne-spoza-iac.mjs"));
// Trzy zmienne z audytu (KUKING_EDGE_TRYB, KUKING_HTML_EDGE_CACHE_SECONDS,
// KUKING_TAG_TYGODNIA) są od #1883 w grafie — test nie może opierać się na
// tym, czego railway.ts akurat nie ma. Nazwy syntetyczne: na pewno spoza grafu.
const TYLKO_W_PANELU = ["KUKING_TYLKO_W_PANELU_A", "KUKING_TYLKO_W_PANELU_B"];

test("zmienne-spoza-iac wskazuje zmienne z panelu, których railway.ts nie deklaruje", () => {
  assert.deepEqual(zmienneSpozaIac([...TYLKO_W_PANELU, "APP_NAME", "RAILWAY_PUBLIC_DOMAIN"], PROD, "kuking.pl"), TYLKO_W_PANELU);
});

test("zmienne-spoza-iac: trzy zmienne z audytu są już w grafie (#1883)", () => {
  const zAudytu = ["KUKING_EDGE_TRYB", "KUKING_HTML_EDGE_CACHE_SECONDS", "KUKING_TAG_TYGODNIA"];
  assert.deepEqual(zmienneSpozaIac(zAudytu, PROD, "kuking.pl"), []);
});

test("kontrola dodatnia zmienne-spoza-iac: zmienna zadeklarowana w grafie nie jest zgłaszana", () => {
  const zadeklarowane = Object.keys(usluga(PROD, "kuking.pl").variables);
  assert.ok(zadeklarowane.length > 10, "graf serwisu WWW nie ma zmiennych — test niczego by nie sprawdzał");
  assert.deepEqual(zmienneSpozaIac(zadeklarowane, PROD, "kuking.pl"), []);
});

test("kontrola ujemna zmienne-spoza-iac: zmienna usunięta z grafu zaczyna być zgłaszana", () => {
  const zepsuty = structuredClone(PROD);
  delete usluga(zepsuty, "kuking.pl").variables.APP_NAME;
  assert.deepEqual(zmienneSpozaIac(["APP_NAME"], zepsuty, "kuking.pl"), ["APP_NAME"]);
});

test("zmienne-spoza-iac z wiersza poleceń wypisuje same nazwy, nigdy wartości", () => {
  const sekret = "wartosc-ktora-nie-moze-wyjsc-12C";
  let wynik;
  try {
    execFileSync(
      process.execPath,
      ["--experimental-strip-types", "--no-warnings", resolve(KORZEN, "scripts/railway/zmienne-spoza-iac.mjs"), "production", "kuking.pl"],
      { cwd: KORZEN, encoding: "utf8", input: JSON.stringify({ KUKING_TYLKO_W_PANELU_A: sekret, APP_NAME: sekret }), env: { PATH: process.env.PATH, KUKING_WAIT_FOR_CI: "false" } },
    );
    assert.fail("kod 0 mimo zmiennej tylko w panelu");
  } catch (blad) {
    if (blad.code === "ERR_ASSERTION") throw blad;
    wynik = blad;
  }
  assert.equal(wynik.status, 1);
  assert.equal(wynik.stdout, "KUKING_TYLKO_W_PANELU_A\n");
  assert.ok(!String(wynik.stdout).includes(sekret) && !String(wynik.stderr).includes(sekret), "wartość zmiennej wyszła na ekran");
});

// ---------------------------------------------------------------------------
// Bilans zmiennych przed pierwszym apply #595 (scripts/railway/bilans-zmiennych-595.mjs,
// runbook PRZELACZENIE_NA_3_SERWISY_595.md, krok 0.5). Od #1459 role mają
// różne zestawy, więc plan USUWA z `kuking.pl` zmienne workera i schedulera —
// to jest oczekiwane, ale tylko wtedy, gdy ich wartość czeka w Shared Variables.
// ---------------------------------------------------------------------------
const { bilansZmiennych, nazwyZTekstu, MARTWE, PANELOWE_Z_ZALOZENIA, ZASTAPIONE_PRZEZ_DB_URL } = await import(resolve(KORZEN, "scripts/railway/bilans-zmiennych-595.mjs"));

const APLIKACJA_PROD = PROD.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja");
const zmienneUslugi = (g, nazwa) => usluga(g, nazwa)?.variables ?? {};

/** Zmienne workera i schedulera, których serwis WWW po rozbiciu nie ma — z grafu. */
function zmiennePozaWww(g) {
  const www = zmienneUslugi(g, KONTEKST.nazwaWww);
  const wynik = new Map();
  for (const nazwaUslugi of ["worker", "scheduler"]) {
    for (const [k, v] of Object.entries(zmienneUslugi(g, nazwaUslugi))) {
      if (k in www || k === "APP_ROLE") continue;
      if (wynik.has(k)) assert.deepEqual(wynik.get(k), v, `${k}: worker i scheduler mają różne wartości`);
      wynik.set(k, v);
    }
  }
  return wynik;
}

/** Linie `NAZWA=wartość` do wklejenia w panelu przy wycofaniu A — z grafu. */
function liniePowrotuDoAll(g) {
  return [...zmiennePozaWww(g)]
    .map(([k, v]) => {
      if (v.type === "sharedReference") return `${k}=\${{shared.${v.name}}}`;
      if (v.type === "literal") return `${k}=${v.value}`;
      throw new Error(`${k}: nieobsłużony typ wartości ${v.type}`);
    })
    .sort();
}

/**
 * Stan produkcji z 29.09.2026, same NAZWY (koordynator, bez wartości):
 * serwis `kuking.pl` nie ma VAPID_*, KUKING_EDGE_*, OPENAI_IMPORT_KEY,
 * AWS_ZDJECIA_KOPIA_*, KUKING_HTML_EDGE_CACHE_SECONDS ani KUKING_TAG_TYGODNIA;
 * ma RAILWAY_PUBLIC_DOMAIN. Resztę listy test układa z grafu (dzisiejsza rola
 * `all` = suma ról), bo pełnej listy nazw z panelu repozytorium nie zna.
 */
const BRAK_NA_PRODUKCJI_2909 = /^(VAPID_|KUKING_EDGE_|OPENAI_IMPORT_KEY$|AWS_ZDJECIA_KOPIA_|KUKING_HTML_EDGE_CACHE_SECONDS$|KUKING_TAG_TYGODNIA$)/;
function panelZ2909() {
  const nazwy = new Set(["RAILWAY_PUBLIC_DOMAIN", "TRUSTED_PROXIES"]);
  for (const s of APLIKACJA_PROD) for (const k of Object.keys(s.variables ?? {})) if (!BRAK_NA_PRODUKCJI_2909.test(k)) nazwy.add(k);
  return [...nazwy];
}
/** Shared Variables założone zgodnie z runbookiem: wszystko, czego plik szuka dla zmiennych z panelu, plus kopia-bazy. */
function sharedWgRunbooka(panel) {
  const nazwy = new Set();
  for (const s of PROD.resources.filter((r) => r.type === "service")) {
    for (const [k, v] of Object.entries(s.variables ?? {})) {
      if (v?.type === "sharedReference" && (panel.includes(k) || s.groupId !== "Aplikacja")) nazwy.add(v.name);
    }
  }
  return [...nazwy];
}

test("bilans #595: przy stanie z 29.09 i Shared założonych wg runbooka bilans się zamyka", () => {
  const panel = panelZ2909();
  const b = bilansZmiennych(panel, sharedWgRunbooka(panel), PROD);
  assert.deepEqual(b.nieznane, []);
  assert.deepEqual(b.doPrzeniesienia, []);
  assert.equal(b.zamyka, true);
  assert.deepEqual(b.martwe, ["TRUSTED_PROXIES"]);
  // Klucz moderacji, puls i odczyt kopii bazy wychodzą z `kuking.pl` do swoich ról.
  const przenoszone = Object.fromEntries(b.przenoszone.map((p) => [p.nazwa, p.role]));
  assert.deepEqual(przenoszone.OPENAI_MODERATION_KEY, ["worker"]);
  assert.deepEqual(przenoszone.KUKING_PULS_HARMONOGRAMU_URL, ["scheduler"]);
  assert.deepEqual(przenoszone.AWS_KOPIE_ACCESS_KEY_ID, ["scheduler"]);
  // Funkcji, których produkcja nie ma, apply nie włączy: puste referencje.
  const puste = b.pusteReferencje.map((p) => p.shared);
  for (const n of ["VAPID_PUBLIC_KEY", "VAPID_PRIVATE_KEY", "KUKING_EDGE_TOKEN", "KUKING_EDGE_TRYB", "OPENAI_IMPORT_KEY", "R2_ZDJECIA_KOPIA_BUCKET", "KUKING_HTML_EDGE_CACHE_SECONDS", "KUKING_TAG_TYGODNIA"]) {
    assert.ok(puste.includes(n), `${n} powinna być pustą referencją`);
  }
  assert.ok(!puste.includes("R2_ACCESS_KEY_ID"), "klucz R2 nie może zostać pusty");
});

test("bilans #595: zbiór przenoszonych = zmienne workera i schedulera, których nie ma web", () => {
  const panel = panelZ2909();
  const b = bilansZmiennych(panel, sharedWgRunbooka(panel), PROD);
  const oczekiwane = [...zmiennePozaWww(PROD).keys()].filter((k) => panel.includes(k)).sort();
  assert.ok(oczekiwane.length >= 5, "graf nie ma zmiennych poza web — test niczego by nie sprawdzał");
  assert.deepEqual(b.przenoszone.map((p) => p.nazwa), oczekiwane);
});

test("kontrola ujemna bilansu: zmienna spoza pliku zatrzymuje apply", () => {
  const panel = [...panelZ2909(), "KUKING_TYLKO_W_PANELU_A"];
  const b = bilansZmiennych(panel, sharedWgRunbooka(panel), PROD);
  assert.deepEqual(b.nieznane, ["KUKING_TYLKO_W_PANELU_A"]);
  assert.equal(b.zamyka, false);
});

test("kontrola ujemna bilansu: klucz moderacji bez Shared Variable zatrzymuje apply", () => {
  const panel = panelZ2909();
  const shared = sharedWgRunbooka(panel).filter((n) => n !== "OPENAI_MODERATION_KEY");
  const b = bilansZmiennych(panel, shared, PROD);
  assert.deepEqual(b.doPrzeniesienia.map((d) => [d.shared, d.uslugi]), [["OPENAI_MODERATION_KEY", ["worker"]]]);
  assert.equal(b.zamyka, false);
});

test("kontrola ujemna bilansu: klucz R2 pod inną nazwą w Shared (AWS_* ← R2_*) też jest wykrywany", () => {
  const panel = panelZ2909();
  const shared = sharedWgRunbooka(panel).filter((n) => n !== "R2_ACCESS_KEY_ID");
  const b = bilansZmiennych(panel, shared, PROD);
  const wpis = b.doPrzeniesienia.find((d) => d.shared === "R2_ACCESS_KEY_ID");
  assert.ok(wpis, "brak R2_ACCESS_KEY_ID w liście do przeniesienia");
  assert.deepEqual(wpis.zPanelu, ["AWS_ACCESS_KEY_ID"]);
  assert.deepEqual(wpis.uslugi, ["kuking.pl", "scheduler", "worker"]);
});

test("kontrola ujemna bilansu: zmienna dopisana do workera bez web jest przenoszona, nie „nieznana”", () => {
  const zepsuty = structuredClone(PROD);
  usluga(zepsuty, "worker").variables.KUKING_TYLKO_W_PANELU_A = { type: "literal", value: "1" };
  const b = bilansZmiennych(["KUKING_TYLKO_W_PANELU_A"], [], zepsuty);
  assert.deepEqual(b.przenoszone, [{ nazwa: "KUKING_TYLKO_W_PANELU_A", role: ["worker"] }]);
  assert.deepEqual(b.nieznane, []);
});

test("bilans: zmienne wstrzykiwane przez Railway i martwe nie zatrzymują apply", () => {
  const b = bilansZmiennych(["RAILWAY_PUBLIC_DOMAIN", "RAILWAY_ENVIRONMENT", ...Object.keys(MARTWE)], [], PROD);
  assert.deepEqual(b.nieznane, []);
  assert.deepEqual(b.martwe, Object.keys(MARTWE));
});

// IN-02 (#2294): w serwisie stoją DB_HOST/DB_PASSWORD…, a plik daje tylko
// DB_URL. Ich usunięcie jest oczekiwane — o ile WWW naprawdę ma DB_URL.
test("bilans: DB_* spoza DB_URL to oczekiwane usunięcie, nie STOP", () => {
  const panel = [...panelZ2909(), ...ZASTAPIONE_PRZEZ_DB_URL];
  const b = bilansZmiennych(panel, sharedWgRunbooka(panel), PROD);
  assert.deepEqual(b.zastapione, [...ZASTAPIONE_PRZEZ_DB_URL].sort());
  assert.deepEqual(b.nieznane, []);
  assert.equal(b.zamyka, true);
});

test("kontrola ujemna bilansu: bez DB_URL w WWW usunięcie DB_HOST znowu zatrzymuje apply", () => {
  const zepsuty = structuredClone(PROD);
  delete usluga(zepsuty, KONTEKST.nazwaWww).variables.DB_URL;
  const b = bilansZmiennych(["DB_HOST"], [], zepsuty);
  assert.deepEqual(b.zastapione, []);
  assert.deepEqual(b.nieznane, ["DB_HOST"]);
  assert.equal(b.zamyka, false);
});

// IN-03 (#2295): zmienne „tylko w panelu z założenia” plik świadomie pomija,
// więc apply je usunie. AWS_LEGACY_* to jedyna droga do najstarszych zdjęć.
test("bilans: AWS_LEGACY_* w panelu zatrzymuje apply z instrukcją, nie jako „nieznana”", () => {
  const legacy = ["AWS_LEGACY_ACCESS_KEY_ID", "AWS_LEGACY_BUCKET", "AWS_LEGACY_SECRET_ACCESS_KEY", "AWS_LEGACY_URL"];
  const panel = [...panelZ2909(), ...legacy];
  const b = bilansZmiennych(panel, sharedWgRunbooka(panel), PROD);
  assert.deepEqual(b.tylkoWPanelu, legacy);
  assert.deepEqual(b.nieznane, []);
  assert.equal(b.zamyka, false, "stary bucket w panelu nie może przejść bilansu po cichu");
  for (const n of legacy) assert.match(PANELOWE_Z_ZALOZENIA[n], /zaleznosc-od-starego-bucketu --pliki/);
});

/**
 * Nazwy z `WYJATKI` w `ZmienneRailwayaPerRolaTest.php`, których powód mówi,
 * że ktoś je ustawia ręcznie / w panelu / decyzją właściciela — czyli te,
 * które mogą dziś stać w `kuking.pl`, a apply by je usunął.
 */
function panelowePrzyczynyWyjatkow(zrodloPhp) {
  const blok = zrodloPhp.match(/private const WYJATKI = \[([\s\S]*?)\n {4}\];/);
  assert.ok(blok, "nie znaleziono listy WYJATKI w teście PHP");
  const wpisy = [...blok[1].matchAll(/\n {8}'([A-Z0-9_]+)' => ([\s\S]*?)(?=\n {8}'[A-Z0-9_]+' =>|\n {8}\/\/|$)/g)];
  assert.ok(wpisy.length >= 20, "parser WYJATKI nic nie znalazł — test niczego by nie sprawdzał");
  return wpisy.filter(([, , powod]) => /ręczn|panel|właściciel/i.test(powod)).map(([, nazwa]) => nazwa);
}

const ZRODLO_WYJATKOW = readFileSync(resolve(KORZEN, "tests/Feature/ZmienneRailwayaPerRolaTest.php"), "utf8");

/** Nazwy „tylko z panelu”, dla których bilans nie ma instrukcji. */
function bezInstrukcji(zrodloPhp) {
  return panelowePrzyczynyWyjatkow(zrodloPhp).filter((n) => !(n in PANELOWE_Z_ZALOZENIA));
}

/** Instrukcje w bilansie, których nazwy nie stoją już w `WYJATKI` (poza parą AWS_LEGACY_* z filesystems.php). */
function instrukcjeBezWyjatku(zrodloPhp) {
  const wszystkie = new Set([...zrodloPhp.matchAll(/\n {8}'([A-Z0-9_]+)' =>/g)].map((m) => m[1]));
  return Object.keys(PANELOWE_Z_ZALOZENIA).filter((n) => !wszystkie.has(n) && !n.startsWith("AWS_LEGACY_"));
}

test("każdy wyjątek „ustawiany ręcznie / z panelu” ma w bilansie instrukcję (IN-03)", () => {
  const panelowe = panelowePrzyczynyWyjatkow(ZRODLO_WYJATKOW);
  for (const n of ["AWS_LEGACY_BUCKET", "KUKING_ZAUFANE_HOSTY", "TURNSTILE_HOSTY_STAGINGU", "KUKING_EXPORT_TEMP_DIR"]) {
    assert.ok(panelowe.includes(n), `${n}: parser WYJATKI zgubił wpis`);
  }
  assert.deepEqual(bezInstrukcji(ZRODLO_WYJATKOW), [], "dopisz instrukcję do PANELOWE_Z_ZALOZENIA w bilans-zmiennych-595.mjs");
  // Runbook wymienia sekcję i najgroźniejszą nazwę.
  const runbook = readFileSync(resolve(KORZEN, "docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md"), "utf8");
  assert.match(runbook, /tylko z panelu \(WYJATKI, #2295\)/);
  assert.match(runbook, /AWS_LEGACY_\*/);
});

test("instrukcje bilansu nie wskazują nazw, których nie ma już w WYJATKI (martwe wpisy)", () => {
  assert.deepEqual(instrukcjeBezWyjatku(ZRODLO_WYJATKOW), [], "usuń martwy wpis z PANELOWE_Z_ZALOZENIA albo przywróć wyjątek");
});

test("pliki infra nie twierdzą, że apply nie dotknie zmiennych z WYJATKI (IN-03)", () => {
  const spoza = readFileSync(resolve(KORZEN, "docs/infra/ZMIENNE_SPOZA_IAC.md"), "utf8");
  assert.match(spoza, /apply je usunie/, "ZMIENNE_SPOZA_IAC.md musi mówić, że apply usuwa zmienne z WYJATKI");
  const stary = readFileSync(resolve(KORZEN, "docs/infra/STARY_BUCKET_R2_LEGACY.md"), "utf8");
  assert.match(stary, /railway config apply/, "STARY_BUCKET_R2_LEGACY.md musi ostrzegać o apply");
  assert.match(stary, /AWS_LEGACY_\*/);
});

test("kontrola ujemna: nowy wyjątek „ustawiany ręcznie” bez instrukcji w bilansie jest wykrywany", () => {
  const zepsute = ZRODLO_WYJATKOW.replace(
    "private const WYJATKI = [",
    "private const WYJATKI = [\n        'KUKING_NOWY_PRZELACZNIK_Z_PANELU' => 'Ustawiany ręcznie w panelu.',",
  );
  assert.notEqual(zepsute, ZRODLO_WYJATKOW);
  assert.deepEqual(bezInstrukcji(ZRODLO_WYJATKOW), [], "kontrola dodatnia: stan repozytorium jest czysty");
  assert.deepEqual(bezInstrukcji(zepsute), ["KUKING_NOWY_PRZELACZNIK_Z_PANELU"]);
});

test("kontrola ujemna: wyjątek usunięty z WYJATKI zostawia martwą instrukcję w bilansie", () => {
  const zepsute = ZRODLO_WYJATKOW.replace(/\n {8}'TURNSTILE_HOSTY_STAGINGU' =>/, "\n        'TURNSTILE_HOSTY_STAGINGU_X' =>");
  assert.notEqual(zepsute, ZRODLO_WYJATKOW);
  assert.deepEqual(instrukcjeBezWyjatku(zepsute), ["TURNSTILE_HOSTY_STAGINGU"]);
});

test("bilans: nazwy z pliku Shared — linie, JSON; wpis z wartością odrzucony bez jej powtórzenia", () => {
  assert.deepEqual(nazwyZTekstu("APP_KEY\n# komentarz\n\nR2_BUCKET\n"), ["APP_KEY", "R2_BUCKET"]);
  assert.deepEqual(nazwyZTekstu('{"APP_KEY":"x"}'), ["APP_KEY"]);
  assert.deepEqual(nazwyZTekstu('["APP_KEY"]'), ["APP_KEY"]);
  assert.throws(() => nazwyZTekstu("APP_KEY=base64:tajne"), /nie wygląda na nazwę/);
  assert.throws(() => nazwyZTekstu("APP_KEY=base64:tajne"), (e) => !String(e.message).includes("tajne"));
});

function uruchomBilans(stdin, shared) {
  const katalog = mkdtempSync(join(tmpdir(), "bilans-595-"));
  const plik = join(katalog, "shared.txt");
  writeFileSync(plik, shared);
  try {
    return spawnSync(
      process.execPath,
      ["--experimental-strip-types", "--no-warnings", resolve(KORZEN, "scripts/railway/bilans-zmiennych-595.mjs"), "production", "--wspoldzielone", plik],
      { cwd: KORZEN, encoding: "utf8", input: stdin, env: { PATH: process.env.PATH, KUKING_WAIT_FOR_CI: "false" } },
    );
  } finally {
    rmSync(katalog, { recursive: true, force: true });
  }
}

test("bilans z wiersza poleceń: same nazwy, kody 0 / 1 / 2, wartości nigdy na ekranie", () => {
  const sekret = "wartosc-ktora-nie-moze-wyjsc-595";
  const panel = panelZ2909();
  const zPanelu = Object.fromEntries(panel.map((n) => [n, sekret]));
  const shared = sharedWgRunbooka(panel).join("\n");

  const zamyka = uruchomBilans(JSON.stringify(zPanelu), shared);
  assert.equal(zamyka.status, 0, zamyka.stderr);
  assert.match(zamyka.stdout, /Bilans się zamyka/);
  assert.match(zamyka.stdout, /OPENAI_MODERATION_KEY {2}→ worker/);

  const stop = uruchomBilans(JSON.stringify({ ...zPanelu, KUKING_TYLKO_W_PANELU_A: sekret }), shared);
  assert.equal(stop.status, 1);
  assert.match(stop.stdout, /KUKING_TYLKO_W_PANELU_A/);

  const zleShared = uruchomBilans(JSON.stringify(zPanelu), `APP_KEY=${sekret}\n`);
  assert.equal(zleShared.status, 2);

  for (const w of [zamyka, stop, zleShared]) {
    assert.ok(!w.stdout.includes(sekret) && !w.stderr.includes(sekret), "wartość zmiennej wyszła na ekran");
  }
});

// Wycofanie A w runbooku przestawia `kuking.pl` z powrotem na `all` w panelu.
// `kuking.pl` w roli `web` nie ma wtedy zmiennych workera i schedulera —
// bez ich dopisania kontener `all` chodziłby bez klucza moderacji, pulsu
// i odczytu kopii. Runbook podaje je w bloku między znacznikami; tu
// pilnujemy, że blok jest dokładnie tym, co wynika z grafu.
const RUNBOOK_595 = readFileSync(resolve(KORZEN, "docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md"), "utf8");
function blokPowrotu(tekst) {
  const m = tekst.match(/<!-- wycofanie-zmienne:start -->\n```text\n([\s\S]*?)```\n<!-- wycofanie-zmienne:end -->/);
  assert.ok(m, "runbook nie ma bloku wycofanie-zmienne");
  return m[1].trim().split("\n").sort();
}

test("runbook #595: wycofanie A dopisuje do kuking.pl dokładnie zmienne workera i schedulera", () => {
  const oczekiwane = liniePowrotuDoAll(PROD);
  assert.ok(oczekiwane.length >= 5, "graf nie ma zmiennych poza web — test niczego by nie sprawdzał");
  assert.deepEqual(blokPowrotu(RUNBOOK_595), oczekiwane);
});

test("kontrola ujemna runbooka #595: nowa zmienna tylko w workerze rozjeżdża blok wycofania", () => {
  const zepsuty = structuredClone(PROD);
  usluga(zepsuty, "worker").variables.KUKING_TYLKO_W_PANELU_A = { type: "sharedReference", name: "KUKING_TYLKO_W_PANELU_A" };
  assert.notDeepEqual(blokPowrotu(RUNBOOK_595), liniePowrotuDoAll(zepsuty));
});

test("kontrola ujemna runbooka #595: brak linii w bloku wycofania jest wykrywany", () => {
  const zepsuty = RUNBOOK_595.replace(/^OPENAI_MODERATION_KEY=.*\n/m, "");
  assert.notEqual(zepsuty, RUNBOOK_595, "mutacja nic nie zmieniła");
  assert.notDeepEqual(blokPowrotu(zepsuty), liniePowrotuDoAll(PROD));
});

// ---------------------------------------------------------------------------
//  WARTOŚCI DOSŁOWNE RÓŻNE OD DOMYŚLNYCH W config/ (IN-04, #2296).
//
//  `railway.ts` nie obowiązuje na produkcji, dopóki nikt nie zrobi `railway
//  config apply` (#595). Do tego czasu o zachowaniu decyduje panel, a gdy
//  zmiennej tam nie ma — wartość domyślna z `config/*.php`. Tak D-269
//  („życzenia mailem włączone na produkcji”) nie działało: w pliku `"true"`,
//  w `config/kuking.php` `false`, w panelu nic.
//
//  Ten blok wylicza z grafu produkcji każdą wartość dosłowną, która RÓŻNI się
//  od wszystkich jawnych wartości domyślnych w `config/`, i wymaga wpisu w
//  `ROZNE_OD_DOMYSLNYCH` z opisem skutku, gdy panel tej zmiennej nie ma. To
//  lista do porównania przez właściciela w kroku 0.5 runbooka
//  (docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md), nie dowód stanu panelu:
//  repozytorium panelu nie zna.
//
//  Czego to nie łapie: domyślnych będących wyrażeniem (`env('A') === …`,
//  trójka) — te są pomijane, bo nie da się ich policzyć bez PHP.
// ---------------------------------------------------------------------------
const ROZNE_OD_DOMYSLNYCH = {
  APP_FAKER_LOCALE: "tylko dane demonstracyjne; bez wpływu na ludzi",
  APP_URL: "adresy w mailach i mapie strony; bez zmiennej byłby `http://localhost`",
  DB_CONNECTION: "bez zmiennej aplikacja szukałaby SQLite — produkcja bez tego nie wstaje",
  FILESYSTEM_DISK: "bez zmiennej zdjęcia szłyby na dysk lokalny kontenera, czyli znikałyby przy wdrożeniu",
  KUKING_QUESTIONS_ENABLED: "włącza dział pytań „Poradźcie” (D-333: właściciel włącza sam po wdrożeniu)",
  KUKING_URODZINY_MAIL_WLACZONY: "włącza wysyłkę życzeń urodzinowych mailem (D-269, krok W12)",
  LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK: "bez zmiennej pliki tymczasowe kreatora szłyby na dysk lokalny kontenera (przy kilku usługach worker ich nie zobaczy)",
  LOG_CHANNEL: "bez zmiennej logi trafiałyby do pliku w kontenerze, a nie na stderr",
  LOG_STDERR_FORMATTER: "bez zmiennej logi na stderr byłyby tekstem, nie JSON-em (gorzej czytelne dla alarmów i wyszukiwania)",
  LOG_LEVEL: "poziom `warning` zamiast `debug`; bez zmiennej dziennik rośnie od szczegółów",
  MAIL_SCHEME: "schemat połączenia SMTP z EmailLabs; bez zmiennej nie jest ustawiony jawnie (sprawdź, że poczta wychodzi)",
  MAIL_MAILER: "bez zmiennej listy trafiałyby do dziennika (`log`), nie do ludzi",
  SESSION_ENCRYPT: "szyfrowanie sesji; zmiana unieważnia istniejące sesje (ludzie logują się od nowa)",
  SESSION_LIFETIME: "43200 minut zamiast 120; bez zmiennej sesje wygasają po dwóch godzinach",
};

/** Domyślne z config/*.php: tekst literału, `null` dla `env('X')` bez drugiego argumentu, `undefined` dla wyrażenia. */
function domyslneZConfig() {
  const dir = resolve(KORZEN, "config");
  const wynik = new Map();
  for (const plik of readdirSync(dir).filter((f) => f.endsWith(".php"))) {
    const zrodlo = readFileSync(join(dir, plik), "utf8");
    for (const m of zrodlo.matchAll(/env\(\s*'([A-Z0-9_]+)'\s*(?:,\s*((?:[^()]|\([^()]*\))*?))?\)/g)) {
      const surowa = m[2]?.trim();
      let wartosc;
      if (surowa === undefined) wartosc = null;
      else if (/^'[^']*'$/.test(surowa)) wartosc = surowa.slice(1, -1);
      else if (/^(true|false|\d+)$/.test(surowa)) wartosc = surowa;
      else wartosc = undefined;
      if (!wynik.has(m[1])) wynik.set(m[1], []);
      wynik.get(m[1]).push(wartosc);
    }
  }
  return wynik;
}

/** Nazwy wartości dosłownych (w dowolnej usłudze aplikacji), których wartość nie występuje wśród domyślnych z config/. */
function literalyRozneOdDomyslnych(g, domyslne) {
  const nazwy = new Set();
  for (const s of g.resources.filter((r) => r.type === "service" && r.groupId === "Aplikacja")) {
    for (const [k, v] of Object.entries(s.variables ?? {})) {
      if (v.type !== "literal") continue;
      const lista = domyslne.get(k);
      if (!lista || lista.includes(undefined)) continue; // nie czytana w config/ albo wyrażenie
      if (!lista.includes(String(v.value))) nazwy.add(k);
    }
  }
  return [...nazwy].sort();
}

function bledyRoznychOdDomyslnych(g, domyslne, rejestr) {
  const b = [];
  const rozne = literalyRozneOdDomyslnych(g, domyslne);
  for (const n of rozne) {
    if (!(n in rejestr)) b.push(`${n}: wartość dosłowna w railway.ts różni się od domyślnej w config/ — dopisz do ROZNE_OD_DOMYSLNYCH, co zmieni apply`);
  }
  for (const n of Object.keys(rejestr)) {
    if (!rozne.includes(n)) b.push(`${n}: wpis w ROZNE_OD_DOMYSLNYCH nie ma pokrycia (wartość zrównana z domyślną albo zmienna usunięta) — usuń wpis`);
  }
  return b;
}

const DOMYSLNE_CONFIG = domyslneZConfig();

test("wartości dosłowne różne od domyślnych w config/ są wyliczone w ROZNE_OD_DOMYSLNYCH (IN-04)", () => {
  assert.ok(DOMYSLNE_CONFIG.size > 100, "parser config/ nic nie znalazł — test niczego by nie sprawdzał");
  assert.deepEqual(bledyRoznychOdDomyslnych(PROD, DOMYSLNE_CONFIG, ROZNE_OD_DOMYSLNYCH), []);
});

test("runbook #595 odsyła do ROZNE_OD_DOMYSLNYCH przy porównaniu z panelem (IN-04)", () => {
  assert.match(RUNBOOK_595, /ROZNE_OD_DOMYSLNYCH/);
});

function prodZLiteralem(mutacja) {
  const g = structuredClone(PROD);
  mutacja(g);
  return g;
}
const bezWpisu = (nazwa) => Object.fromEntries(Object.entries(ROZNE_OD_DOMYSLNYCH).filter(([k]) => k !== nazwa));

for (const [opis, g, rejestr] of [
  ["nowy wyłącznik włączony dosłownie, domyślnie wyłączony w config", prodZLiteralem((x) => {
    usluga(x, "scheduler").variables.KUKING_DIGEST_WLACZONY = { type: "literal", value: "true" };
  }), ROZNE_OD_DOMYSLNYCH],
  ["wpis rejestru bez pokrycia w grafie", PROD, { ...ROZNE_OD_DOMYSLNYCH, KUKING_NIE_MA_TAKIEJ: "x" }],
  ["wyłącznik życzeń usunięty z rejestru", PROD, bezWpisu("KUKING_URODZINY_MAIL_WLACZONY")],
  ["wartość zrównana z domyślną, wpis w rejestrze zostaje", prodZLiteralem((x) => {
    for (const s of x.resources.filter((r) => r.type === "service")) {
      if (s.variables?.SESSION_LIFETIME) s.variables.SESSION_LIFETIME.value = "120";
    }
  }), ROZNE_OD_DOMYSLNYCH],
]) {
  test(`kontrola ujemna różnic od domyślnych: ${opis}`, () => {
    assert.deepEqual(bledyRoznychOdDomyslnych(PROD, DOMYSLNE_CONFIG, ROZNE_OD_DOMYSLNYCH), [], "kontrola dodatnia: stan repozytorium jest czysty");
    assert.notDeepEqual(bledyRoznychOdDomyslnych(g, DOMYSLNE_CONFIG, rejestr), [], `strażnik nie zauważył: ${opis}`);
  });
}
