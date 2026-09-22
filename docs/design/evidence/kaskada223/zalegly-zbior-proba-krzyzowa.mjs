/* Próba kontrolna do raportu D-223 (stanowisko kaskada223).
   Strażnik wydaje werdykt TYLKO tam, gdzie przesiew znalazł przykrywacz.
   Ta próba pyta o to samo na KAŻDEJ mierzonej stronie, bez przesiewu:
   czy zdjęcie deklaracji zmienia `getComputedStyle` któregokolwiek nosiciela. */
import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { readFileSync } from 'node:fs';

const SCIEZKI = ['/', '/odkryj', '/login', '/tagi', '/szukaj?sekcja=przepisy', '/przepisy/rosol-babci-zofii'];
const PYTANIA = JSON.parse(readFileSync('/mnt/c/Users/matma/Documents/kuking-flota/_scratch-kaskada223-pytania.json','utf8'));

const wolnyPort = () => new Promise((res) => { const g = createServer(); g.listen(0, '127.0.0.1', () => { const { port } = g.address(); g.close(() => res(port)); }); });

const SONDA = (pytania) => {
  const out = [];
  const reguly = [];
  const chodz = (lista, warstwa) => {
    for (const r of lista) {
      const moja = r.name !== undefined && r.cssRules ? [...warstwa, r.name] : warstwa;
      if (r.selectorText) reguly.push({ r, warstwa: moja.join('.') });
      if (r.cssRules) chodz(r.cssRules, moja);
    }
  };
  for (const a of document.styleSheets) { try { chodz(a.cssRules, []); } catch { /* CORS */ } }
  const wszystkie = (() => { const s = getComputedStyle(document.body); return [...s]; })();
  for (const [warstwa, selektor, wlasnosc] of pytania) {
    const trafione = reguly.filter((x) => x.warstwa === warstwa && x.r.selectorText.trim() === selektor.trim() && x.r.style.getPropertyValue(wlasnosc) !== '');
    if (!trafione.length) { out.push({ selektor, wlasnosc, stan: 'brak reguły w arkuszu' }); continue; }
    let el = [];
    try { el = [...document.querySelectorAll(selektor.replace(/::[a-zA-Z-]+(\([^)]*\))?/g, ''))]; } catch { /* zły selektor */ }
    if (!el.length) { out.push({ selektor, wlasnosc, stan: 'brak nosiciela' }); continue; }
    const przed = el.map((e) => { const s = getComputedStyle(e); return wszystkie.map((p) => s.getPropertyValue(p)).join('|'); });
    const zapisy = trafione.map((x) => x.r.style.cssText);
    for (const x of trafione) x.r.style.removeProperty(wlasnosc);
    const po = el.map((e) => { const s = getComputedStyle(e); return wszystkie.map((p) => s.getPropertyValue(p)).join('|'); });
    trafione.forEach((x, i) => { x.r.style.cssText = zapisy[i]; });
    const ile = przed.filter((v, i) => v !== po[i]).length;
    out.push({ selektor, wlasnosc, stan: ile ? `ŻYWA na ${ile}/${el.length} nosicielach` : `bez zmiany (${el.length} nosicieli)` });
  }
  return out;
};

const port = await wolnyPort();
const adres = `http://127.0.0.1:${port}`;
const proces = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], { stdio: 'ignore' });
for (let i = 0; i < 60; i++) { try { const o = await fetch(`${adres}/health`); if (o.ok) break; } catch { /* wstaje */ } await new Promise((r) => setTimeout(r, 500)); }
const br = await chromium.launch();
const wynik = new Map();
for (const szer of [320, 1280]) {
  const ctx = await br.newContext({ viewport: { width: szer, height: 900 } });
  for (const sc of SCIEZKI) {
    const s = await ctx.newPage();
    await s.goto(adres + sc, { waitUntil: 'networkidle' });
    for (const w of await s.evaluate(SONDA, PYTANIA)) {
      const k = `${w.selektor} | ${w.wlasnosc}`;
      if (!wynik.has(k)) wynik.set(k, []);
      wynik.get(k).push(`${sc}@${szer}: ${w.stan}`);
    }
    await s.close();
  }
  await ctx.close();
}
await br.close(); proces.kill('SIGTERM');
for (const [k, v] of wynik) {
  const zywe = v.filter((x) => x.includes('ŻYWA'));
  console.log(`\n${k}\n  WERDYKT: ${zywe.length ? 'ŻYWA gdzie indziej — FAŁSZYWE TRAFIENIE' : 'nigdzie nic nie zmienia'}`);
  for (const x of v) console.log(`    ${x}`);
}
