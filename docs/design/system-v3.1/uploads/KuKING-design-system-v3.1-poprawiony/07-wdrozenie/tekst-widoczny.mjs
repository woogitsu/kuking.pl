/*
 * tekst-widoczny.mjs — wyciąga z HTML-a tekst widoczny dla człowieka.
 *
 * Wydzielone z `sprawdz-paczke.mjs` (CodeQL #3, js/bad-tag-filter, #1910):
 * ten sam plik od razu wykonuje pełny skan paczki i kończy proces
 * (`process.exit`), więc funkcji stąd nie dało się bezpiecznie zaimportować
 * w teście bez uruchomienia całego automatu. Ten moduł nie ma efektów
 * ubocznych — sam import niczego nie skanuje ani nie kończy procesu.
 *
 * BŁĄD, KTÓRY TU BYŁ: `<script[\s\S]*?<\/script>/gi` rozpoznawał WYŁĄCZNIE
 * dokładny ciąg `</script>`. HTML pozwala domknąć element atrybutami
 * w znaczniku końcowym — `</script foo="bar">` jest dla parsera taką samą
 * końcówką `<script>` jak goły `</script>` (WHATWG HTML §13.2.5.5, stan
 * „end tag open"). Wyrażenie, które tego nie widziało, zostawiało treść
 * skryptu w puli „tekstu widocznego" — dokładnie ten wariant złapał
 * CodeQL jako `js/bad-tag-filter`.
 *
 * POPRAWKA: `<\/script\b[^>]*>` — `\b` po nazwie znacznika odrzuca
 * `</scriptx>` (to NIE jest końcówka `<script>`, tylko inna nazwa), a
 * `[^>]*` przepuszcza dowolne atrybuty i białe znaki przed `>`. To NIE jest
 * pełny parser HTML (CodeQL sugeruje to jako kierunek docelowy) — to
 * najwęższa poprawka usuwająca lukę, którą znalazł skaner, bez przepisywania
 * całego narzędzia na parser.
 *
 * Ten sam błąd, ta sama poprawka: `<style>` i `<svg>` niżej — obie funkcje
 * w oryginalnym pliku miały dokładnie ten sam kształt wyrażenia.
 */

const ZNACZNIK_KONCOWY = (nazwa) => new RegExp(`<${nazwa}\\b[^>]*>[\\s\\S]*?<\\/${nazwa}\\b[^>]*>`, 'gi');

export const tekstZHtml = (html) =>
  html
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(ZNACZNIK_KONCOWY('style'), ' ')
    .replace(ZNACZNIK_KONCOWY('script'), ' ')
    .replace(ZNACZNIK_KONCOWY('svg'), ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/gi, ' ');
