# Sonda cache CSS i JS — #809

## Zmiana

Blok cache w `scripts/sprawdz-wdrozenie.sh` wywołuje
`scripts/sprawdz-cache-assetow.sh`. Pobiera aktualną stronę, wybiera własne
hashowane adresy `/build/assets/*.css` i `*.js`, usuwa powtórzenia i pobiera
każdy znaleziony zasób metodą GET. Sukces wymaga udanego transportu, HTTP 200,
niepustej treści, właściwego Content-Type oraz rocznego cache immutable,
bez sprzecznych dyrektyw private/no-store/no-cache. Musi znaleźć przynajmniej
jeden CSS i JS. Każdy zbadany zasób jest nazwany w raporcie. Nie ma
przekierowań ani żądań do obcych źródeł; adresy nie są wykonywane jako polecenia.

Manifest nie jest dowodem dostępności buildu. Niehashowany
`/build/manifest.json` dostaje w Caddy `no-cache`. Roczny matcher jest zawężony
do `/build/assets/*`, zgodnie z domyślnym wyjściem Vite tego projektu.
Zmieniono także instrukcję reguły Cloudflare w runbooku.

## Własne pomiary — 20.09.2026

Na bazowym bloku sondy z `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`
regresje wykonawcze były czerwone: 404 i 500 z immutable zgłaszały sukces,
a timeout był wyłącznie ostrzeżeniem. Nie sprawdzano CSS ani JS.

`tests/skrypty/cache-assetow.sh` wykonuje rzeczywisty blok produkcyjnego
skryptu z atrapą transportu curl. Dwanaście scenariuszy po poprawce przechodzi:
poprawny CSS/JS; 404; 500; timeout; brak JS przy dostępnym manifeście;
HTML zamiast JS; pusty plik; zła polityka cache; private bez spacji po dwukropku; obce źródło; spreparowane
ścieżki; pozorne odnośniki poza link/script lub w data-src. Test sprawdza też kod porażki bloku oraz zapytania o JS.
Przebieg wchodzi do `scripts/check.sh` i do PHPUnit (`SondaCacheAssetowTest`).
Nie jest to pomiar produkcji ani rzeczywistego timeoutu sieci.

Kontrole przez `scripts/kontrola-ujemna.sh`:

- usunięcie kontroli statusu HTTP daje fałszywy sukces 404 i oblewa test;
- przywrócenie matchera `/build/*` oblewa strażnika konfiguracji;
- oba przebiegi PASS → FAIL → PASS, MD5 i mtime sprawdzone po przywróceniu.

Osobno uruchomiono istniejący lokalny obraz
`kuking:ci-534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24` z aktualnym Caddyfile,
bez sieci zewnętrznej (`--network none`), bez bazy i z własnymi plikami fixture.
Walidacja FrankenPHP: `Valid configuration`. Rzeczywiste odpowiedzi HTTP:

| Zasób | Status | Cache-Control | Typ |
|---|---|---|---|
| CSS | 200 | public, max-age=31536000, immutable | text/css |
| JS | 200 | public, max-age=31536000, immutable | text/javascript |
| manifest | 200 | no-cache | application/json |
| brakujący JS | 404 | public, max-age=31536000, immutable | brak |

Ostatni wiersz potwierdza, dlaczego sam nagłówek nie jest dowodem. Matcher
pozostaje ścieżkowy; sonda wymaga również statusu 200. Kontener i fixture
usunięto po pomiarze.

## Granice, wdrożenie i wycofanie

Sprawdzane są tylko zasoby wskazane w HTML, nie wszystkie fragmenty dynamicznych
importów ani cały manifest. Sonda jest przeznaczona dla własnego źródła Vite
z konfiguracji projektu. Nie analizuje semantycznie kodu CSS/JS: poprawny
Content-Type i niepusta treść nie dowodzą, że kod wykona się w przeglądarce.

Nie wykonano pomiaru produkcji, wdrożenia, push ani zmian w panelach.
Przy wdrożeniu trzeba sprawdzić istniejącą regułę Cloudflare: jeśli obejmuje
`/build/*`, zawęzić ją do `/build/assets/*` i usunąć starą kopię manifestu
z cache. Zmiana pliku Caddy nie przestawia panelu Cloudflare.

Wycofanie: odwrócić commit sondy i konfiguracji, bez migracji danych.
Przywróci to dawną, niewiarygodną kontrolę manifestu. Reguły zewnętrzne
zmienione podczas wdrożenia wymagają osobnej obsługi przez operatora.

## Kontrola końcowa pakietu #835 / #809

Własny szeroki przebieg: **4396 testów, 84 582 asercje, 342,39 s**, bez porażek.
Pominięto wyłącznie `ProbaOdtworzeniaTest`, zgodnie z jawną instrukcją floty:
używa wspólnej bazy `kuking_zrodlo_proby_glowny`. Nie uruchamiano go na tej bazie.
Po tym przebiegu adapter na chwilę dostał osobną, angielską klasę; w przeglądzie
scalono go z istniejącym już na `main` `App\Support\Harmonogram` — w repo jest
jeden adapter, a komunikat wyjątku to „Komenda harmonogramu '…' zakończyła się
niepowodzeniem (kod wyjścia: N).” (nadal bez parametrów). Ponadto
oraz zawężono odczyt odnośników sondy do link/script z prawdziwym src/href.
Dodatkowy czerwony test wykrył private bez spacji po dwukropku nagłówka;
po normalizacji dyrektyw ten scenariusz również przechodzi.
Na ostatecznym kodzie: filtr `Harmonogram` — **18 testów / 1134 asercje**,
`SondaCacheAssetow` — **2 testy / 7 asercji**, obejmujące **12 scenariuszy powłoki**.
Ponownie przeszły wszystkie cztery kontrole ujemne z przywróceniem MD5 i mtime.
Pint przeszedł dla wszystkich zmienionych plików PHP. Nie uruchamiano całego
`scripts/check.sh` ani zdalnego CI (zakaz push); osobno sprawdzono składnię Bash
zmienionych skryptów. Nie ma migracji ani zmian UI.

Wszystkie wyniki w tym raporcie są własnymi pomiarami tej sesji; opis
częściowego niepowodzenia eksportów jest jawnie oznaczonym odczytem kodu.
