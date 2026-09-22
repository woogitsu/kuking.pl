#!/usr/bin/env node
/*
 * =============================================================================
 *  Serwer scenariuszy HTTP — stanowisko do badania PRZYRZĄDU #605
 * =============================================================================
 *
 *  PO CO TO JEST
 *  `scripts/generator-obciazenia-605.mjs` mierzy czasy odpowiedzi. Zanim wolno
 *  zacytować jakikolwiek jego wynik, trzeba wiedzieć, co robi, gdy odpowiedzi
 *  NIE MA albo jest połamana — bo pod nasyceniem, czyli tam, gdzie ten przyrząd
 *  pracuje, takie odpowiedzi są spodziewane, nie wyjątkowe.
 *
 *  Ten serwer udaje właśnie te złe przypadki. Nie ma nic wspólnego z Kukingiem:
 *  nie dotyka bazy, kolejki, mediów ani aplikacji. Jego liczby NIE SĄ wynikiem
 *  wydajnościowym portalu i nie wolno ich tak przedstawiać.
 *
 *  PORT JEST PRZYDZIELANY DYNAMICZNIE (`listen(0)`). Stanowisko chodzi na
 *  wspólnym hoście CI pięciu projektów — stały port zabrałby go komuś innemu.
 *
 *  SCENARIUSZE (po prefiksie ścieżki, żeby dało się je wpisać do manifestu serii)
 *    /pelna, /przepisy/*, i wszystko nierozpoznane  — 200 z całym body
 *    /przekierowanie, POST na /*komentarz|ugotowalem|dodaj — 302
 *    /brak-odpowiedzi      — połączenie przyjęte, ZERO bajtów, gniazdo żyje
 *    /urwana, /wpisy/*     — 200 + nagłówki + kilka bajtów + `res.destroy()`
 *    /naglowki-bez-konca   — 200 + nagłówki z Content-Length, body nigdy nie leci
 *    /powolne?ile=&co=&bajty=  — skończona seria fragmentów
 *    /powolne-bez-konca, /tag/*  — fragmenty co `co` ms, bez końca
 *    /blad                 — 500
 *
 *  Uruchomiony wprost wypisuje przydzielony port i czeka; Ctrl+C zamyka.
 */

import http from 'node:http';
import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const ZWLOKA_URWANIA_MS = 20;

/*
 * Parametry z query stringa przechodzą przez walidację, bo stanowisko musi być
 * przewidywalne nawet przy błędnym wywołaniu. `Number('abc')` daje NaN, a NaN
 * w `setInterval` Node skraca do 1 ms (zalew danych), zaś `wyslane >= NaN`
 * nigdy nie zachodzi — trasa udokumentowana jako skończona nadawałaby bez końca.
 */
function parametr(url, nazwa, domyslny, { min, max }) {
  const surowy = url.searchParams.get(nazwa);
  if (surowy === null) return domyslny;
  const w = Number(surowy);
  if (!Number.isFinite(w) || w < min || w > max) return domyslny;
  return w;
}

export function uruchomSerwer({ co = 75, bajty = 12 } = {}) {
  const otwarte = new Set();
  const liczniki = { polaczen: 0, zadan: 0 };

  const serwer = http.createServer((req, res) => {
    liczniki.zadan += 1;
    const url = new URL(req.url, 'http://localhost');
    const p = url.pathname;
    // Body żądania trzeba odczytać do końca, inaczej wgranie zdjęcia w serii
    // utknęłoby na buforze, a to byłby artefakt stanowiska, nie przyrządu.
    req.resume();

    const urwana = p === '/urwana' || p.startsWith('/wpisy/');
    const bezKonca = p === '/powolne-bez-konca' || p.startsWith('/tag/');
    const powolne = p === '/powolne' || bezKonca;

    if (p === '/brak-odpowiedzi') return; // ani nagłówków, ani body

    if (urwana) {
      res.writeHead(200, { 'content-type': 'text/plain', 'content-length': '100' });
      res.write('xxxxx');
      // Zegar jest rejestrowany i czyszczony jak każdy inny — inaczej
      // `zamknij()` zostawiałby po sobie jedyny niesprzątnięty uchwyt
      // stanowiska i wołał `destroy()` na zniszczonej już odpowiedzi.
      const zegar = setTimeout(() => res.destroy(), ZWLOKA_URWANIA_MS);
      res.on('close', () => clearTimeout(zegar));
      return;
    }

    if (p === '/naglowki-bez-konca') {
      res.writeHead(200, { 'content-type': 'text/plain', 'content-length': '100' });
      res.flushHeaders();
      return;
    }

    if (powolne) {
      const odstep = parametr(url, 'co', co, { min: 1, max: 60000 });
      const paczka = parametr(url, 'bajty', bajty, { min: 1, max: 1_000_000 });
      const ile = bezKonca ? Infinity : parametr(url, 'ile', 12, { min: 1, max: 1_000_000 });
      res.writeHead(200, { 'content-type': 'text/plain' }); // chunked
      let wyslane = 0;
      const zegar = setInterval(() => {
        if (res.writableEnded || res.destroyed) { clearInterval(zegar); return; }
        res.write('y'.repeat(paczka));
        wyslane += 1;
        if (wyslane >= ile) { clearInterval(zegar); res.end(); }
      }, odstep);
      res.on('close', () => clearInterval(zegar));
      return;
    }

    if (p === '/blad') {
      res.writeHead(500, { 'content-type': 'text/plain' });
      res.end('awaria stanowiska');
      return;
    }

    if (p === '/przekierowanie' || req.method === 'POST') {
      res.writeHead(302, { location: '/', 'content-length': '0' });
      res.end();
      return;
    }

    const body = `<!doctype html><title>stanowisko #605</title><p>${p}</p>`;
    res.writeHead(200, { 'content-type': 'text/html', 'content-length': Buffer.byteLength(body) });
    res.end(body);
  });

  serwer.on('connection', (gniazdo) => {
    liczniki.polaczen += 1;
    otwarte.add(gniazdo);
    gniazdo.on('close', () => otwarte.delete(gniazdo));
  });

  return new Promise((gotowe) => {
    serwer.listen(0, '127.0.0.1', () => {
      gotowe({
        port: serwer.address().port,
        baza: `http://127.0.0.1:${serwer.address().port}`,
        otwartePolaczenia: () => otwarte.size,
        liczniki,
        zamknij: () => new Promise((r) => {
          for (const gniazdo of otwarte) gniazdo.destroy();
          serwer.close(() => r());
        }),
      });
    });
  });
}

const uruchomionyWprost = process.argv[1]
  && realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));

if (uruchomionyWprost) {
  const serwer = await uruchomSerwer();
  console.log(JSON.stringify({ port: serwer.port, baza: serwer.baza }));
  process.on('SIGINT', async () => { await serwer.zamknij(); process.exit(0); });
}
