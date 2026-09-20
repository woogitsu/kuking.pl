import assert from "node:assert/strict";
import { readFile, writeFile, mkdtemp, rm } from "node:fs/promises";
import { fileURLToPath, pathToFileURL } from "node:url";
import { join } from "node:path";
import { test } from "node:test";
import { createRailwayContext } from "railway/iac";

const directory = fileURLToPath(new URL("../.railway/", import.meta.url));
const source = await readFile(join(directory, "railway.ts"), "utf8");

async function topology(environment, overrides = {}) {
  let variant = source;
  for (const [name, value] of Object.entries(overrides)) {
    const pattern = new RegExp(`const ${name} = (?:true|false|[0-9]+);`, "g");
    assert.equal([...variant.matchAll(pattern)].length, 1, `Nie znaleziono jednej stałej ${name}`);
    variant = variant.replace(pattern, `const ${name} = ${value};`);
  }
  const temporary = await mkdtemp(join(directory, ".roles-test-"));
  try {
    const path = join(temporary, "railway.ts");
    // Node 22.18+ czyta TypeScript; prawdziwy SDK, bez atrapy grafu i sieci.
    await writeFile(path, variant);
    const { default: program } = await import(pathToFileURL(path));
    const result = await program(createRailwayContext({ environment }));
    const services = result.resources.filter((node) => node.type === "service" && node.name !== "kopia-bazy");
    assert.ok(services.length > 0, "Nie odczytano usług aplikacji");
    return Object.fromEntries(services.map((node) => [node.name, node]));
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
}

function value(service, name) {
  return service.variables[name]?.value;
}

function roles(services, { split, replicas = 1, media = false, budget = 16 }) {
  assert.deepEqual(Object.keys(services).sort(), (split ? ["web", "worker", "scheduler", ...(media ? ["media"] : [])] : ["web"]).sort());
  assert.equal(services.web.deploy.numReplicas, replicas);
  assert.equal(services.web.deploy.startCommand, `/usr/local/bin/kuking-entrypoint ${split ? "web" : "all"}`);
  assert.equal(value(services.web, "APP_ROLE"), split ? "web" : "all");
  assert.equal(services.web.deploy.healthcheckPath, "/health");
  assert.ok(services.web.deploy.preDeployCommand.length > 0);
  for (const service of Object.values(services)) {
    assert.equal(value(service, "CACHE_STORE"), "database");
    assert.equal(value(service, "SESSION_DRIVER"), "database");
    assert.equal(value(service, "LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK"), "r2");
    assert.equal(value(service, "KUKING_EXPORT_DISK"), "r2_eksporty");
    assert.equal(value(service, "KUKING_POLACZENIA_BUDZET"), String(budget));
    if (service.name === "web") continue;
    assert.equal(service.deploy.numReplicas, 1);
    assert.equal(service.deploy.preDeployCommand, undefined);
    assert.equal(service.deploy.healthcheckPath, undefined);
    assert.equal(service.networking, undefined);
    assert.equal(service.deploy.sleepApplication, false);
  }
  if (split) {
    assert.equal(services.scheduler.deploy.startCommand, "/usr/local/bin/kuking-entrypoint scheduler");
    assert.equal(value(services.scheduler, "APP_ROLE"), "scheduler");
    assert.equal(services.worker.deploy.startCommand, "/usr/local/bin/kuking-entrypoint worker");
    assert.equal(value(services.worker, "QUEUE_NAMES"), media ? "high,default,low" : "high,default,media,low");
  }
  if (media) {
    assert.equal(services.media.deploy.startCommand, "/usr/local/bin/kuking-entrypoint worker");
    assert.equal(value(services.media, "APP_ROLE"), "worker");
    assert.equal(value(services.media, "QUEUE_NAMES"), "media");
  }
}

test("produkcja: przygotowane trzy role, bez aktywacji rozszerzeń #600", async () => {
  roles(await topology("production"), { split: true });
});
test("staging i preview pozostają pojedynczym all", async () => {
  for (const env of ["staging", "pr-595"]) roles(await topology(env), { split: false });
});
test("próba podziału stagingu jest jawna i nie zmienia preview", async () => {
  const overrides = { STAGING_SPLIT_SERVICES: true };
  roles(await topology("staging", overrides), { split: true });
  roles(await topology("pr-595", overrides), { split: false });
});
test("drugi web ma osobny etap i budżet 24", async () => {
  roles(await topology("production", { PRODUCTION_WEB_REPLICAS: 2 }), { split: true, replicas: 2, budget: 24 });
});
test("media opuszczają worker ogólny dopiero wraz z osobną usługą", async () => {
  roles(await topology("production", { PRODUCTION_MEDIA_WORKER: true }), { split: true, media: true, budget: 18 });
  roles(await topology("production", { PRODUCTION_WEB_REPLICAS: 2, PRODUCTION_MEDIA_WORKER: true }), { split: true, replicas: 2, media: true, budget: 26 });
});
test("powrót do all ogranicza web do jednej repliki także po #600", async () => {
  roles(await topology("production", { PRODUCTION_SPLIT_SERVICES: false, PRODUCTION_WEB_REPLICAS: 2, PRODUCTION_MEDIA_WORKER: true }), { split: false });
});

test("staging pozwala odebrać skalowanie przed produkcją", async () => {
  const overrides = { STAGING_SPLIT_SERVICES: true, STAGING_WEB_REPLICAS: 2, STAGING_MEDIA_WORKER: true };
  roles(await topology("staging", overrides), { split: true, replicas: 2, media: true, budget: 26 });
  roles(await topology("production", overrides), { split: true });
  roles(await topology("pr-595", overrides), { split: false });
});
