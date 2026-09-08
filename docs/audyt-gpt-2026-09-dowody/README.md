# Dowody audytu Kuking.pl

Badany kod aplikacji: 2fe302b6534d4d233a45271769a5d0c62f83bbe7.
Data pomiarow: 2026-09-08.
Raport: docs/AUDYT_GPT_2026-09.md.

## Zawartosc

- baseline/: pelny zestaw 1721 testow / 56687 asercji, logi instalacji,
  migracji i rollbacku, rzeczywisty schemat PostgreSQL 18.6, Pint i PHPStan.
  Run: https://github.com/woogitsu/kuking.pl/actions/runs/34272363472
- probes/: siedem celowanych sond, mutacje, logi przed/po/przywroceniu,
  przebieg przegladarki bez JavaScriptu, wyniki kontroli dostepnosci.
  Run: https://github.com/woogitsu/kuking.pl/actions/runs/34273395722
- final-probes/: domkniety pomiar CSS, powrot autora, kolejka moderacji.
  Run: https://github.com/woogitsu/kuking.pl/actions/runs/34274108261
- SHA256SUMS.txt: sumy kontrolne zalaczonych plikow.

## Interpretacja

Zielony status workflow sond nie oznacza, ze wszystkie sondy przeszly.
Celowo pozostawiono czerwone oczekiwania wskazujace odtworzone usterki.
Wynik nalezy czytac z plikow .exit, .log, JUnit i danych JSON.

Baseline: exit 0. Sondy B1: jedna zdrowa kontrola przeszla,
szesc oczekiwan ujawnilo naruszenia. Paginacja B2: exit 0 i brak duplikatow.
Blad pierwszej sondy CSS byl bledem selektora narzedzia; przebieg B2 go poprawia.
Pomocniczy licznik powiadomienia o podziekowaniu w author-loop.json
uzywa niewlasciwego klucza JSON; zero NIE jest dowodem bledu produktu.
Szczegoly i poprawne dowody opisano w raporcie.

## Odtworzenie

Zrodla sond i workflow znajduja sie w probes/source/ oraz final-probes/source/.
Wymagaja kodu aplikacji z podanego commita, PHP 8.4, PostgreSQL 18,
zaleznosci composer/npm i Chromium Playwright. Pliki sond skopiowac do
odizolowanego checkoutu; nigdy nie uruchamiac migracji/restore na produkcji.
Uzyc osobnej bazy dla procesu. Kroki srodowiskowe i komendy sa we workflow.
Skrypt mutations.py przywraca zmieniony plik w bloku finally i sprawdza
zgodnosc bajtow oraz zielony wynik po przywroceniu.

Paczka nie zawiera vendor/, pelnego repozytorium ani plikow fontow.
Dane kont i tresci zrzutow sa danymi syntetycznymi utworzonymi w CI.
