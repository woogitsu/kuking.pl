/*
 * tekst-widoczny.test.mjs — CodeQL #3 (js/bad-tag-filter), #1910.
 *
 * `sprawdz-paczke.mjs` liczy się jako wykonane od samego importu (skanuje
 * całą paczkę i kończy proces `process.exit`), więc test nie importuje TEGO
 * pliku — importuje `tekst-widoczny.mjs`, jedyną część bez efektów
 * ubocznych, wydzieloną właśnie po to, żeby dało się ją tak sprawdzić.
 *
 * Uruchomienie: node tekst-widoczny.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { tekstZHtml } from './tekst-widoczny.mjs';

test('domyka goły </script> — kontrola dodatnia, bazowy przypadek', () => {
  const html = '<p>widoczne</p><script>const tekst = "zly kod";</script><p>tez widoczne</p>';

  const wynik = tekstZHtml(html);

  assert.match(wynik, /widoczne/);
  assert.match(wynik, /tez widoczne/);
  assert.doesNotMatch(wynik, /zly kod/);
});

test('domyka </script foo=bar> — dokładny wariant z CodeQL #3 i z opisu #1910', () => {
  const html = '<p>widoczne</p><script>const tekst = dowolny kod</script foo=bar><p>tez widoczne</p>';

  const wynik = tekstZHtml(html);

  assert.doesNotMatch(
    wynik,
    /dowolny kod/,
    'Treść skryptu przeciekła do tekstu widocznego — dokładnie ten wariant zamknięcia znalazł CodeQL.',
  );
  assert.match(wynik, /widoczne/);
  assert.match(wynik, /tez widoczne/);
});

test('domyka </script > (spacja przed >) i </script\\n> (biały znak, nie spacja)', () => {
  assert.doesNotMatch(tekstZHtml('<script>x = 1</script >widoczne'), /x = 1/);
  assert.doesNotMatch(tekstZHtml('<script>x = 1</script\n>widoczne'), /x = 1/);
});

test('</scriptx> NIE jest końcówką <script> — \\b odrzuca inną nazwę znacznika', () => {
  // Ten wariant nie ma prawa domykać <script>: to inny znacznik, nie script
  // z literówką w zamknięciu. Regexowi bez granicy słowa "script" wystarczy
  // jako prefiks — ta kontrola łapie taki błąd.
  const html = '<script>x = 1</scriptx>reszta</script>';

  const wynik = tekstZHtml(html);

  assert.doesNotMatch(wynik, /x = 1/);
  assert.doesNotMatch(wynik, /reszta/);
});

test('ten sam wariant zamknięcia z atrybutem działa też dla <style> i <svg>', () => {
  assert.doesNotMatch(tekstZHtml('<style>.a{color:red}</style foo=bar>widoczne'), /color/);
  assert.doesNotMatch(tekstZHtml('<svg><path d="M0 0"/></svg data-x="1">widoczne'), /path/);
});

test('kontrola ujemna: tekst bez żadnego znacznika wraca bez zmian poza spacjami z encji', () => {
  assert.equal(tekstZHtml('zwykly tekst po polsku'), 'zwykly tekst po polsku');
});
