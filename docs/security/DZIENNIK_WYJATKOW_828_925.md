# Dziennik wyjątków moderacji — #828 i #925

## Zakres i rozwiązanie

Baza stanowiska zweryfikowana przed zmianami: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Wspólny `App\Moderacja\ExceptionContext::forStage()` zastępuje surową wiadomość
wyjątku klasą i stałym etapem w czterech miejscach:

| Miejsce | Etap |
|---|---|
| `KlientOpenAI::zapytaj` | `openai_transport` |
| `OcenaModelem::jakoJpeg` | `image_preparation` |
| `PrzeanalizujTresc::handle` | `content_analysis` |
| `PrzeanalizujAwatar::handle` | `avatar_analysis` |

Nie przechowujemy wiadomości, obiektu wyjątku, stosu, poprzedniego wyjątku
ani jego dowolnego kodu. Gałąź odpowiedzi HTTP `failed()` pozostała bez zmian:
liczbowy status i rodzaj ocenianej treści, bez ciała odpowiedzi.
Nie zmieniono pozostałych loggerów repozytorium.

## Pomiary własne — 20 września 2026

Środowisko: WSL Ubuntu, kopia runtime `/home/mateusz/flota/gpt-dziennik-wyjatkow-run`,
PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-dziennik-wyjatkow`,
użytkownik bazy `kuking`. Zależności skopiowane przez wspólny skrypt floty.

1. Przed zmianami kodu: filtr `Moderacja` — **27 testów, 79 asercji, PASS**.
2. Nowy `ModeracjaBezTresciWyjatkowTest` na niezmienionym kodzie aplikacji:
   **4 FAIL, 1 PASS, 26 asercji**. Wszystkie cztery porażki wskazują
   `Not to contain: CONTROLLED_FOREIGN_EXCEPTION`. W przechwyconych
   kontekstach każdego loggera widać również syntetyczny adres, parametr
   sekretu, tekst żądania i znacznik obrazu. Kontrola HTTP 503 przechodzi.
3. Po poprawce: filtr `Moderacja` — **32 testy, 142 asercje, PASS**.
4. Cztery niezależne kontrole `scripts/kontrola-ujemna.sh`: każdorazowo
   przywrócono `getMessage()` w jednym miejscu, dokładnie jedna podmiana;
   **PASS → FAIL na markerze → PASS**. Konsola przyrządu potwierdziła
   przywrócenie MD5 i mtime. Dodatkowo wszystkie sześć plików PHP w runtime
   i stanowisku porównano przez SHA-256: identyczne.
5. `vendor/bin/pint` dla sześciu plików PHP: wykonany; poprawił zakończenia
   linii nowego testu, plik skopiowano z powrotem do stanowiska.

Wyniki kontroli ujemnych: `dowody-828-925/*.json`. Uwaga o samym przyrządzie:
JSON zapisuje pole `przywrocenie` przed wykonaniem końcowego trap, więc pozostaje
w nim „nie wykonane”. Nie traktujemy tego pola jako dowodu przywrócenia;
dowodem jest późniejszy komunikat konsoli o porównaniu MD5 i mtime oraz
osobne porównanie sześciu plików przez SHA-256.

Pierwszy rozruch nowych fixture ujawnił dwie pomyłki testu: atrapę konfiguracji
bez przekazywania pozostałych odczytów i podwójnie założoną atrapę HTTP.
Poprawiono je PRZED miarodajnym czerwonym przebiegiem opisanym w punkcie 2.
Nie zaliczono tych błędów fixture jako dowodu regresji.

## Co dokładnie mierzy test

HTTP rzuca kontrolowany `RuntimeException`; pobranie miniatury rzuca drugi
kontrolowany wyjątek. Logger jest atrapą zbierającą komunikat i kontekst,
więc syntetyczne dane nie trafiają do dziennika aplikacji.

Oba zewnętrzne catch jobów są mierzone oddzielnie: atrapa konfiguracji rzuca
przy odczycie `ocenia_zdjecia` wewnątrz rzeczywistego `OcenaModelem`, poza
wewnętrznym catch klienta i dekodera. To celowy punkt wstrzyknięcia awarii,
nie twierdzenie o rzeczywistym zachowaniu dostawcy. Test uruchamia pełny job
na rzeczywistym wpisie albo przypiętym awatarze; wymaga dokładnie jednego
ostrzeżenia z właściwej granicy. Sprawdza także brak zgłoszeń, decyzji
moderacyjnych i powiadomień oraz zachowanie opublikowanego wpisu i awatara.

Nie przejęto cudzej czerwieni jako własnego pomiaru: wszystkie cztery miejsca
zmierzono samodzielnie. #828 i #925 są źródłem zakresu oraz zaakceptowanej
granicy diagnostyki. Nie badano produkcyjnych logów, prawdziwego OpenAI ani R2.
Test pobrania obrazu mierzy catch wspólny dla storage i dekodera; nie udaje
osobnego pomiaru błędu biblioteki dekodującej.

## Wycofanie i ograniczenia

Nie ma migracji, zmiany schematu ani UI. Wycofanie polega na cofnięciu zmiany
kodu, lecz przywraca możliwość zapisu surowych wiadomości — nie zaleca się
tego jako sposobu odzyskania szczegółowej diagnostyki. Brak nowych decyzji
produktowych wymagających rozstrzygnięcia właściciela.

Nie wykonano push ani PR (zakaz floty). W trakcie pracy zniknęły metadane
wskazanego repozytorium i gałąź; właściciel potwierdził pracę innego modelu.
Nie odtwarzano metadanych Git i nie zmieniano cudzej historii.

## Odbiór końcowy

- Pełny zestaw `php artisan test --filter '^(?!.*ProbaOdtworzeniaTest)'`:
  **4398 PASS, 83 755 asercji, 465,24 s, kod procesu 0**.
- `ProbaOdtworzeniaTest` pominięty jawnie zgodnie z poleceniem właściciela
  (współdzielona baza `kuking_zrodlo_proby_glowny`). Nie zaliczono go jako PASS.
- PHPStan dla pięciu zmienionych plików aplikacji: **bez błędów**.
- Osobne porównanie MD5 po kontrolach: `dowody-828-925/przywrocenie.json`.
- Pełny zapis testów: `dowody-828-925/pelne-testy.txt`.
- Brak pomiarów produkcji, CI, budowania assetów i testów przeglądarkowych:
  zmiana dotyczy wyłącznie diagnostyki PHP, nie UI ani schematu.
- **SHA nowego commita: brak.** Metadane repozytorium zmienia inny model,
  co właściciel potwierdził w trakcie pracy. Nie próbowano ich naprawiać.
  Po odtworzeniu stanowiska pozostaje przegląd różnicy i lokalny commit,
  proponowany tytuł: „Nie zapisuj treści obcych wyjątków w dzienniku moderacji”.

Paczka obok stanowiska `gpt-dziennik-wyjatkow-pakiet.zip` zawiera wyłącznie
sześć plików PHP, uzupełniony dokument sygnałów, ten raport i dowody.
Nie jest kopią całego repozytorium i nie zawiera `.env` ani zależności.
