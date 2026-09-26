// Test bramki Lighthouse (#1029) bez przeglądarki — na spreparowanych audytach.
import test from 'node:test';
import assert from 'node:assert/strict';
import { BUDZET_LCP_MS, CEL_LCP_MS, metrykiZAudytow, ocenEkran } from './wydajnosc-progi.mjs';

function audyty(lcpMs, displayValue = 'celowo nie liczba') {
  return {
    'largest-contentful-paint': { numericValue: lcpMs, displayValue, score: 0.5 },
    'total-blocking-time': { numericValue: 0, displayValue: '0 ms', score: 1 },
    'cumulative-layout-shift': { numericValue: 0.001, displayValue: '0.001', score: 1 },
    'first-contentful-paint': { numericValue: 1200, displayValue: '1.2 s', score: 1 },
    'speed-index': { numericValue: 1500, displayValue: '1.5 s', score: 1 },
  };
}

function ekran(lcpMs, zmiany = {}) {
  return { nazwa: 'strona powitalna', wydajnosc: 88, seo: 100, seoLiczone: true, metryki: metrykiZAudytow(audyty(lcpMs)), ...zmiany };
}

test('raport trzyma surowe numericValue obok tekstu dla człowieka', () => {
  const m = metrykiZAudytow(audyty(3100, '3.1 s'));

  for (const klucz of ['lcp', 'tbt', 'cls', 'fcp', 'si']) {
    assert.equal(typeof m[klucz].numericValue, 'number', klucz);
  }
  assert.equal(m.lcp.numericValue, 3100);
  assert.equal(m.lcp.displayValue, '3.1 s');
});

test('kontrola dodatnia: ekran w budżecie zalicza, choć jest poza celem produktu', () => {
  const wynik = ocenEkran(ekran(3100));

  assert.equal(wynik.ok, true);
  assert.deepEqual(wynik.powody, []);
  assert.equal(wynik.lcpCel, false);
});

test('LCP ponad budżetem oblewa mimo wydajności >= 70, a powód nazywa ekran, metrykę, wartość i próg', () => {
  const wynik = ocenEkran(ekran(BUDZET_LCP_MS + 200, { wydajnosc: 88 }));

  assert.equal(wynik.ok, false);
  assert.deepEqual(wynik.powody, [`strona powitalna: LCP ${BUDZET_LCP_MS + 200} ms ponad budżetem ${BUDZET_LCP_MS} ms`]);
});

test('decyzja nie zależy od displayValue', () => {
  const m = metrykiZAudytow(audyty(BUDZET_LCP_MS + 1, '0.1 s'));

  assert.equal(ocenEkran({ ...ekran(0), metryki: m }).ok, false);
});

test('brak numericValue LCP to awaria pomiaru, nie zaliczenie', () => {
  const wynik = ocenEkran(ekran(undefined));

  assert.equal(wynik.ok, false);
  assert.match(wynik.powody[0], /LCP nie został zmierzony/);
});

test('cel produktu 2,5 s raportowany osobno i próg SEO zachowany', () => {
  assert.equal(ocenEkran(ekran(CEL_LCP_MS)).lcpCel, true);
  assert.equal(ocenEkran(ekran(2000, { seo: 90 })).ok, false);
  assert.equal(ocenEkran(ekran(2000, { seo: 58, seoLiczone: false })).ok, true);
});
