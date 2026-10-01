# Klient PostgreSQL 18 w CI

`scripts/ci/klient-postgresql-18.sh` instaluje klienta tylko wtedy, gdy na
runnerze nie ma już `pg_restore` 18. Job Pint potrzebuje go do uruchomienia
testów skryptów kopii. Nie zastępuj tej kontroli starszym klientem.

## Diagnoza przestoju z 1.10.2026

W jobach Pint [#2482](https://github.com/woogitsu/kuking.pl/actions/runs/36922842410/job/110572985899)
i [#2475](https://github.com/woogitsu/kuking.pl/actions/runs/36922688724/job/110572419203)
ostatni zapis z kroku instalacji potwierdzał odcisk klucza PGDG. Potem nie
było wiadomo, czy trwało przygotowanie repozytorium, `apt-get update`, czy
`apt-get install`; oba joby zakończyły się po około 10 minutach. To nie
jest dowód awarii konkretnego serwera ani pakietu.

Skrypt zapisuje teraz początek, limit i czas pobrania klucza, aktualizacji
indeksu i instalacji. Po przekroczeniu limitu podaje nazwę etapu i kod 124.
`curl` ma limit połączenia i pobrania oraz ponawia tylko błędy uznane przez
siebie za przejściowe. APT ma ograniczone czasy połączeń i dwa ponowienia
pobrania; cała aktualizacja i instalacja także mają osobne limity. Błąd
odcisku klucza pozostaje natychmiastową odmową, bez ponawiania APT.

Jeśli instalacja znów zawiedzie, sprawdź nazwę i czas ostatniego etapu,
komunikat APT oraz dostępność PGDG. Nie zwiększaj czasu całego joba bez
pomiaru. Test `tests/skrypty/klient-postgresql-18.sh` używa atrap sieci i APT;
sprawdza też zawieszone i błędne polecenia oraz fizyczną kontrolę ujemną
po usunięciu timeoutu. Nie instaluje nic w systemie testującego.
