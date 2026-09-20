# Odtworzenie stanowiska #926 — 20 września 2026

Wykonano instrukcję `_prompty/00-NAPRAWA-STANOWISKA.txt`.
Katalog `gpt-zawieszone-konto` zabezpieczono przez zmianę nazwy na
`gpt-zawieszone-konto-PLIKI`. Kopia pozostaje zachowana.

## Gałąź i historia

Po `git fetch origin --prune` gałęzi `origin/gpt/zawieszone-konto` nie było.
Utworzono ponownie `gpt/zawieszone-konto` na `origin/main`:
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
To ten sam commit bazowy, na którym powstała poprawka.

Kod zapisano lokalnie w commicie
`2c561ce051f8bb8f77e5af0e9c0691318fc714ce`
„Dopuść prywatne czynności zawieszonego konta”.
Nie wykonano pushu ani PR-a.

## Przeniesione pliki i ochrona cudzych zmian

Przy kopiowaniu pominięto dane techniczne, `.env` oraz stary plik `.git`,
aby nie nadpisać nowego powiązania worktree. Po przeniesieniu każdy
z 23 plików poprawki porównano z wcześniejszym manifestem SHA-256:
wszystkie były identyczne. `git diff --check` przeszedł.

**Pliki przywrócone jako „nie moje”: brak.** Nie wystąpiło cofnięcie cudzych
zmian, ponieważ aktualna baza i baza poprzedniego stanowiska są identyczne.
Lokalne artefakty `.playwright-cli` odsunięto od kodu; nie włączono ich
do commita.

## Powtórzony własny pomiar

Po commicie przygotowano runtime od nowa na własnej bazie
`kuking_flota_gpt-zawieszone-konto`, PostgreSQL `127.0.0.1:55439`.
Powtórzono:

- `ZawieszoneKontoPrywatneCzynnosciTest`;
- `FollowListsTest`;
- `KazdaTrasaZIdentyfikatoremPodPolicyTest`.

**39 testów przeszło, 1142 asercje, 9,62 s.**
Testy obejmują formularze komentarzy i obserwowania, rzeczywiste zapisy
i usuwanie z zeszytów, prywatność zeszytów, odhaczanie, reset,
widoczność treści i zamknięte konta.

Po odtworzeniu powtórzono także **15 kontroli ujemnych**: każdy z ośmiu
wyjątków middleware, brak powiadomienia, blokadę publicznego zeszytu,
reset, komentarze, profil, listy relacji oraz tablicę osób. Wszystkie
ukończyły PASS → FAIL z właściwego powodu → PASS, z przywróceniem
i porównaniem MD5 oraz mtime źródła. Jest to nowy dowód czerwieni,
zebrany na odtworzonym stanowisku. Ponowny `vendor/bin/pint --test`
również przeszedł.

Logi tego pomiaru oraz 15 wyników JSON zachowano w kopii bezpieczeństwa:
`gpt-zawieszone-konto-PLIKI/output/naprawa-926/`.

Wynik pełnego zestawu sprzed naprawy: 4409 testów, 83 823 asercje.
To pomiar wcześniejszy, nie ponowne uruchomienie pełnego zestawu po naprawie.
Pominięto wtedy `ProbaOdtworzeniaTest` zgodnie z instrukcją zadania.

## Utrata danych

**Nie stwierdzono bezpowrotnej utraty własnej pracy.** Przed awarią nie
utworzono jeszcze żadnego commita tego zadania, więc nie ma utraconych
własnych commitów do wyliczenia. Przepadła rejestracja lokalnej gałęzi
i worktree; zostały odtworzone. Kod, testy, dokumentacja oraz wcześniejsze
pomiary i zrzuty przetrwały w katalogu `gpt-zawieszone-konto-PLIKI`.
