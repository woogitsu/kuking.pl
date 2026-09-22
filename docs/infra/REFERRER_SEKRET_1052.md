# Sekret w adresie a referrer — #1052

## Granica ochrony

Na bazie `65327e69ddc3423279c7324ff20639ead64c6610` wyłączenie beacona na
stronie z tokenem nie chroniło następnego dokumentu. Domyślna polityka
`strict-origin-when-cross-origin` zachowuje pełny adres przy przejściu w obrębie
tego samego origin. Beacon na następnej stronie może zebrać `document.referrer`.
Usunięcie query i fragmentu nie usuwa tokenu w pathname.

`ApplySecurityHeaders` korzysta teraz z istniejącej klasyfikacji
`AnalitykaCloudflare::wolnoNaTejStronie`: obecność `token`, `email`, `hash` lub
`cel` w query albo parametrach rozpoznanej trasy ustawia `no-referrer`.
Nie zależy to od włączenia analityki. Decyzja zapada po `$next()`, ale przed
wczesnym powrotem przy własnym CSP. Dwa przebiegi middleware nie dublują
nagłówka ani nie zmieniają nonce. Zwykłe odpowiedzi zachowują dotychczasową
politykę, w tym własne nagłówki odpowiedzi z własnym CSP.

Nie zmieniamy tras, polityk dostępu, CSRF, kontrolerów, dostawcy analityki,
retencji ani dyrektyw CSP. Nie twierdzimy, że klasyfikacja wykrywa dowolny sekret
pod dowolną nową nazwą: nowy rodzaj poświadczenia wymaga rozszerzenia wspólnej
klasyfikacji. Brak Referer korzysta z dotychczasowej sesyjnej ścieżki powrotu
Laravela; `origin` nie jest równoważnym zamiennikiem, bo przysłania ją adresem `/`.

## Dowody lokalne

- `SekretnyAdresNiePrzechodziDoReferreraTest`: 24 warianty query (cztery nazwy,
  wartość zwykła/pusta/tablica, analityka włączona/wyłączona), rzeczywiste trasy,
  poprawne i wygasłe linki z rozróżnieniem formularza i odmowy, poprawna
  weryfikacja email oraz 403, własny CSP, nowa trasa i podwójne middleware.
- `scripts/referrer-sekret-browser.mjs`: prawdziwy Chromium i kernel HTTP,
  przejście przez kliknięcie pomiędzy dwoma dokumentami. Trzy przypadki:
  sekret pathname, sekret query i zwykła strona jako kontrola dodatnia.
  Czekamy na poprawnie sparsowany payload dokładnie strony docelowej `/login`,
  a wszystkie przechwycone payloady sprawdzamy na obecność syntetycznego znacznika.
- Formularze: błędny reset wraca na właściwy adres i zachowuje email, poprawny
  naprawdę zmienia hash hasła i umożliwia logowanie. Link loguje; jego zużycie
  mierzone jest natychmiast po POST, przed resetem. Zaproszenie trafia do
  rejestracji z właściwym adresem. Nie ustawiamy sztucznego Referer ani `from()`.
- Oddzielnie CSRF: przeglądarka same-origin korzysta z istniejącej ochrony
  Laravel 13. Klient bez Sec-Fetch-Site/Origin/Referer, z tą samą sesją:
  brak tokenu → 419, zły → 419, prawidłowy → 302 z rzeczywistą walidacją
  formularza. Nie osłabiamy ochrony, żeby wymusić oczekiwany wynik testu.

Trwały `scripts/fixtures/referrer-beacon.js` jest naszym minimalnym modelem
zachowania, NIE kopią ani pełnym testem Cloudflare. Niezależny pomiar wykonano
21.09.2026 na publicznym skrypcie deklarującym wersję 2026.9.1, zachowanym tylko
poza repo; SHA256 `08c4fd72f9d96a7aa554510dff2c293973b1b092dff1b9b282bce9111b50ef41`.
W obu wariantach skrypt jest podstawiany lokalnie. Żaden payload nie opuszcza
lokalnego komputera: forwarding tylko do dokładnego lokalnego origin, endpoint
analityki przechwycony, pozostałe obce żądania odmawiane, WebSocket zamknięty,
service worker wyłączony. PHP ma atrapy poczty/kolejki i zakaz obcego HTTP.
Nie jest to pomiar produkcji, automatycznego wstrzykiwania Cloudflare ani
wszystkich przyszłych wersji beacona czy innych silników przeglądarki.

Wykonano lokalnie: Pint, parser PyYAML, składnia Bash/Node, PHPStan oraz
74 testy / 511 asercji; oba pomiary przeglądarkowe po końcowych zmianach PASS.
Kontrole `scripts/kontrola-ujemna.sh`: zamiana `no-referrer` na poprzednią
politykę daje PASS–`REFERRER_SECRET_IN_PAYLOAD`–PASS zarówno dla naszego fixture,
jak rzeczywistego skryptu. Zamiana kroku CI daje PASS–brak oczekiwanego
wywołania–PASS. MD5 i mtime potwierdzone osobnym readbackiem po każdym przebiegu.
Znany artefakt harnessu: JSON zapisany przed trapem podaje
`przywrocenie: nie wykonane`; nie jest dowodem nieprzywrócenia i nie naprawiamy
tego niezależnego przyrządu przy okazji. Zachowane wcześniejsze nieudane próby:
brak opcjonalnego parsera Symfony (użyto dostępnego PyYAML), błędna fixture
daty wygaśnięcia (CHECK prawidłowo odmówił), błędne początkowe założenie testu
o CSRF same-origin i selektor input zamiast statycznego email zaproszenia.

## Odtworzenie i bramki

Po `composer install`, `npm ci`, instalacji Chromium oraz przygotowaniu własnej
aplikacji z kluczem i zbudowanymi assetami wybierz jawnie host, port, właściciela
i oddzielną bazę `kuking_port_*`. Zmigruj ją zwykłym `migrate --force`, bez
kasowania danych. Skrypt dopisuje wyłącznie syntetyczne fixture.

```bash
APP_BASE_PATH="$PWD" DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 \
DB_USERNAME=kuking DB_DATABASE=kuking_port_codex1052 \
node scripts/referrer-sekret-browser.mjs
```

Hasło bazy pochodzi z lokalnego środowiska. `REFERRER_REAL_BEACON` może wskazać
lokalny, osobno pozyskany skrypt do niezależnego pomiaru; narzędzie go nie pobiera.
Opcjonalne `scripts/check.sh --referrer` wymaga dodatkowo
`REFERRER_DB_DATABASE` wskazującego wcześniej zmigrowaną bazę; brak zależności
lub błędny pomiar jest błędem, nie cichym pominięciem. Domyślny check nie
uruchamia przeglądarkowego dowodu #1052.

CI: obowiązkowy krok w istniejącym jobie `dostepnosc`, osobna baza
`kuking_port_referrer` i dynamiczny port usługi PG. Wspólny filtr `widok`
obejmuje również middleware, klasyfikator i sam przyrząd. Nie zmieniamy
pozostałych warunków joba. Ten diff obszarowo koliduje z pracą #1039 nad CI;
przy integracji zachować oba zakresy i ponowić test filtra, bez kopiowania go.
Lokalne pomiary nie dowodzą zdalnego zielonego CI. Pełny check bazowy odbiera
koordynator osobno; nie jest zastąpiony celowanymi 74 testami.

Rollback: wycofanie commita, bez migracji, ujawnia ponownie pierwotny wyciek.
Zamiast tego preferować naprawę do przodu; nie uznawać samego ukrycia beacona
na wrażliwym dokumencie za wystarczające zabezpieczenie.

Źródła: [W3C Referrer Policy](https://w3c.github.io/webappsec-referrer-policy/),
[Cloudflare Web Analytics](https://developers.cloudflare.com/web-analytics/faq/),
[RUM beacon](https://developers.cloudflare.com/speed/observatory/rum-beacon/).
