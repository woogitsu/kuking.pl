import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const css = readFileSync(new URL("../resources/css/wartosci-odzywcze.css", import.meta.url), "utf8");

function zachowujeNatywnyWskaznik(arkusz) {
  const reguly = arkusz.match(/\.wartosci-odzywcze-jak summary\s*\{([^}]*)\}/)?.[1] ?? "";

  return /\bdisplay\s*:\s*list-item\s*;/.test(reguly)
    && !/\blist-style(?:-type)?\s*:\s*none\s*;/.test(reguly)
    && !/\.wartosci-odzywcze-jak summary::-webkit-details-marker\s*\{[^}]*display\s*:\s*none\s*;/.test(arkusz);
}

test("arkusz Jak to liczymy nie usuwa natywnego markera summary", () => {
  assert.equal(zachowujeNatywnyWskaznik(css), true);

  // Kontrola ujemna: dawny flex usuwał marker mimo zachowanej semantyki.
  assert.equal(zachowujeNatywnyWskaznik(css.replace("display: list-item;", "display: flex;")), false);
});
