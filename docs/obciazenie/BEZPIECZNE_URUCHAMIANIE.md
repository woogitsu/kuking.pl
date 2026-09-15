# Bezpieczne uruchamianie harnessu — uzupełnienie PR #582

Poprawka względem `491837d`: nie wykonywano ponownie benchmarku ani żadnej
operacji na PostgreSQL. Dotyczy bezpieczeństwa narzędzi i granic wniosków.

Wszystkie klienty wymagają jawnych `PGHOST`, `PGPORT`, `PGUSER` i nazwy
`kuking_bench_` z niepustym sufiksem `[a-z0-9_]` (maksymalnie 40 znaków).
Host musi być lokalny, port inny niż 5432. Hasło pochodzi z istniejącego
mechanizmu klienta (`PGPASSWORD`/pgpass), nie z pliku źródłowego. Nie używaj
alternatywnych `PGHOSTADDR`, `PGSERVICE` ani `PGSERVICEFILE` — są odrzucane.

`build_scale.sh` bierze nazwę jako pierwszy argument, PHP i Go jako
`BENCH_DB`. Narzędzie zakłada bazę z właścicielem podanym w `PGUSER`, używając
bazy technicznej `postgres`; nie potrzebuje istniejącej aplikacyjnej `kuking`.
Istniejąca baza powoduje odmowę. Reset wymaga oddzielnego argumentu
`--reset=DOKLADNA_NAZWA`, zgodnego z pierwszym argumentem. Nie ma `FORCE`:
aktywne połączenia uniemożliwią skasowanie bazy. Polecenia wykorzystują
parametry psql i `format('%I', ...)`, nie sklejenie nazwy z SQL.

Przykład tworzenia **wyłącznie lokalnych danych syntetycznych**:

```bash
export PGHOST=127.0.0.1 PGPORT=55439 PGUSER=kuking
bash docs/obciazenie/build_scale.sh kuking_bench_proba 100 1000 300
export BENCH_DB=kuking_bench_proba
php docs/obciazenie/feed_php.php
```

Te polecenia nie zostały wykonane podczas poprawki. Prefiks nazwy i port
chronią przed częstą pomyłką, ale nie zastępują osobnego klastra i roli:
operator odpowiada za to, by wskazana baza zawierała tylko dane benchmarku.

## Regresje bez bazy

- `PHP_BIN=php python3 docs/obciazenie/test_connection.py`: 7 testów
  przeszło. Stub psql/createdb/dropdb zapisuje tylko argumenty i SQL; nie
  otwiera połączeń. Sprawdzono odmowę nazwy produkcyjnej i znaków SQL,
  portu 5432, niejawnego połączenia, istniejącej bazy i resetu innej nazwy;
  poprawne przekazywanie portu 55439 oraz cytowanie nazwy/właściciela.
- Test walidacji Go `connection_test.go` przeszedł bez PostgreSQL; sprawdza
  propagację parametrów, bezpieczne kodowanie hasła i odmowy nazwy/portu.
  Przeszedł też `go test ./...` całego modułu harnessu (Go 1.25.0).
- Cztery fizyczne negatywy: zgoda resetu, przekazywanie wybranego portu,
  zakaz 5432 w PHP i w Go. Każdy: PASS → FAIL → PASS, z kopią źródła poza
  repo oraz zgodnością MD5/mtime po przywróceniu. Dowody:
  [`evidence/connection-negatives.json`](evidence/connection-negatives.json).

Negatywy wykonano na identycznych źródłach w osobnej kopii WSL ext4.
Wstępna próba na zamontowanym NTFS wykazała utratę części ułamkowej mtime
przy `copy2`; bajty zostały przywrócone, ale nie zaliczono tej próby jako
pełnego dowodu. Całą serię powtórzono na ext4, gdzie weryfikacja jest ścisła.

§8 raportu nie nazywa już różnicy HTTP–harness czystym narzutem Laravel.
Taka różnica obejmuje również różną pracę aplikacji; nie dowodzi zysku
z przepisania jej na inny język.
