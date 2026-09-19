# UTC po obu stronach połączenia — #693

## Problem i poprawka

Baza źródłowa: `0f30f07b4bb9c481d4a2e6fc0d9d18cec93c8af7`.
Aplikacja liczy w UTC, lecz dotychczas połączenie `pgsql` nie ustawiało
strefy sesji. Laravel przekazuje daty bez offsetu; PostgreSQL interpretuje
je w strefie sesji przy zapisie do `timestamptz`. Nowy klucz
`timezone => UTC` powoduje ustawienie UTC przez istniejący konektor Laravela.
Nie zmieniamy strefy wyświetlania, globalnego PostgreSQL ani dawnych wierszy.

## Dowody lokalne — 18 września 2026

Osobny runtime `/home/mateusz/kuking-693-check`, baza `kuking_693_tests`
na `127.0.0.1:55439`. Regresja ustawia `PGTZ=Europe/Warsaw` przed utworzeniem
połączenia i sprawdza kontrolnym połączeniem bez ochrony, że strefa faktycznie
jest polska. Potem łączy się z konfiguracją aplikacji. Przywraca poprzednie
PGTZ; używa wyłącznie tabel tymczasowych, bez migracji i bez wysyłania poczty.

`StrefaPolaczeniaPostgresTest`: **4 testy / 12 asercji PASS**:

- zapis daty latem i zimą zachowuje znacznik Unix;
- prawdziwy `DatabaseTokenRepository` przyjmuje nowy token i token po
  59 minutach, a odrzuca go po 61 minutach, dla obu dat.

Fizyczne usunięcie klucza UTC z prawdziwego `config/database.php` runtime
dało cztery porażki: data przesunięta latem o 7200 sekund, zimą o 3600;
token letni odrzucony od razu, zimowy odrzucony po 59 minutach.
Przywrócono kopię spoza repo:
`/tmp/kuking693-negative-kgsuyozs/database.php`,
MD5 `b85b85f2dbfad50952450c270895692e`, mtime ns `1789749028633844800`.
Po przywróceniu ponownie 4/12 PASS. Błąd formatowania nowego testu poprawił Pint.

Po uwadze review test korzysta także z parsera `DB_URL` Laravela przed
utworzeniem połączenia. Osobny przebieg z prawidłowym lokalnym URL i celowo
nieprawidłowym `DB_HOST` zakończył się 4/12 PASS: połączenie pochodziło z URL.
Po tej zmianie ponowiono kontrolę ujemną: 4 FAIL, przywrócenie tego samego
MD5 i mtime, ponownie 4/12 PASS. Kopia:
`/tmp/kuking693-negative-ifrf_cj5/database.php`.

## Granice i odbiór

Test obejmuje rzeczywisty PostgreSQL i repozytorium tokenów frameworka;
nie jest odbiorem pełnego formularza resetowania hasła ani dostarczania maila.
Wymuszenie strefy przez klienta libpq odtwarza stan sesji bez zmiany
konfiguracji współdzielonego serwera. Nie dowodzi, że produkcja ma taką strefę.

Niezależne review kodu nie wskazało blokerów; nie powtarzało testów.
Pozostają: wymagane pełne kontrole, zwykły hook, CI i wdrożenie.

Przed wdrożeniem poprawki wykonano odczyt w konsoli produkcyjnej Railway
(18 września, wdrożenie `79060bd2-ced3-48e6-b11c-c64558587556`, Alfa 0.67).
Po uruchomieniu kernela konsolowego aplikacji odczyt `app.timezone` zwrócił
`UTC`, a `DB::selectOne('SHOW timezone')` zwrócił `Etc/UTC`.
Wynik obejrzano w terminalu. Nie odczytywano rekordów użytkowników, nie
ustawiano strefy ani nie zapisywano danych. Bieżąca sesja produkcyjna nie
odtwarza więc nieprawidłowej strefy z lokalnego scenariusza.

Nawet aktualne UTC nie dowodzi poprawności historycznych dat. Bez dodatkowych
dowodów nie wolno masowo przesuwać istniejących znaczników.

Rollback kodu usuwa jawne ustawienie sesji i przywraca zależność od serwera;
nie przepisuje danych. Nie jest zalecanym rozwiązaniem problemu historycznych dat.
