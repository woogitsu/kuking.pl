# Próg 25 Mpx a synchroniczny podgląd z #430 — sprawdzenie celowe

To NIE jest pomiar wydajności. Wynikiem jest obecność albo brak wariantu
i obecność albo brak adresu zdjęcia w HTML-u — hałas na maszynie tego nie zmienia.

## 1. Poziom danych: które wgrania dostały synchroniczny `podglad`

Zapytanie:

```sql
select
  width || 'x' || height as wymiary,
  round((width::numeric * height) / 1000000, 1) as mpx,
  count(*) as sztuk,
  count(*) filter (where metadata -> 'variants' ? 'podglad')  as ma_podglad,
  count(*) filter (where metadata -> 'variants' ? 'thumb')    as ma_thumb,
  count(*) filter (where metadata -> 'variants' ? 'feed')     as ma_feed,
  count(*) filter (where metadata -> 'variants' ? 'large')    as ma_large
from media
group by 1, 2
order by 2;
```

Wynik:

```
  wymiary  | mpx  | sztuk | ma_podglad | ma_thumb | ma_feed | ma_large 
-----------+------+-------+------------+----------+---------+----------
 4000x3000 | 12.0 |    10 |         10 |       10 |      10 |       10
 5657x4243 | 24.0 |    11 |         11 |       11 |      11 |       11
 8000x6000 | 48.0 |    11 |          0 |       11 |      11 |       11
(3 rows)

```

Ani jedno zdjęcie 48 Mpx nie ma podglądu; komplet przy 12 i 24 Mpx.
Zgodne z `kuking.media.podglad.max_megapixels` = 25.

## 2. Poziom strony: co widzi autor zaraz po opublikowaniu

```
=== 24mpx — PIERWSZY render, zaraz po wgraniu (/wpisy/01a0b3fb-b541-7143-abbf-afcc2e4a69d8), świeże media: 01a0b3fb-b526-706f-9746-bed4636093c9
    świeże zdjęcie JEST na stronie jako: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/podglad 
    wszystkie warianty na stronie: /zdjecia/01a0b3fb-5098-7113-b453-7cdc8eb12d8d/thumb
    wszystkie warianty na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/podglad
--- 24mpx — DRUGI render, po opróżnieniu kolejki
    wariant na stronie: /zdjecia/01a0b3fb-5098-7113-b453-7cdc8eb12d8d/thumb
    wariant na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/feed
    wariant na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/large
    wariant na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/podglad
    wariant na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/thumb
=== 48mpx — PIERWSZY render, zaraz po wgraniu (/wpisy/01a0b3fb-befc-7137-9e74-36f87aeb0d23), świeże media: 01a0b3fb-bef2-7139-aaa9-40a9b1965b85
    świeżego zdjęcia NIE MA na stronie (widok pomija je warunkiem isReady())
    wszystkie warianty na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/thumb
--- 48mpx — DRUGI render, po opróżnieniu kolejki
    wariant na stronie: /zdjecia/01a0b3fb-b526-706f-9746-bed4636093c9/thumb
    wariant na stronie: /zdjecia/01a0b3fb-bef2-7139-aaa9-40a9b1965b85/feed
    wariant na stronie: /zdjecia/01a0b3fb-bef2-7139-aaa9-40a9b1965b85/large
    wariant na stronie: /zdjecia/01a0b3fb-bef2-7139-aaa9-40a9b1965b85/thumb
nieudane zadania: 0
```

Metoda: wgranie jednego zdjęcia i natychmiastowe otwarcie świeżej strony
wpisu, zanim kolejka zrobi warianty; potem to samo po opróżnieniu kolejki.
Sprawdzeniem jest OBECNOŚĆ identyfikatora świeżego wiersza `media` w HTML-u,
a nie obecność pliku `kuking-mark.svg` — ten jest znakiem marki w nagłówku
każdej strony i niczego by nie dowodził.

### Skrypt sprawdzenia (do powtórzenia)

```bash
#!/usr/bin/env bash
# Celowe sprawdzenie hipotezy o progu 25 Mpx i podglądzie z #430.
#
# NIE jest to pomiar wydajności — wynikiem jest TREŚĆ strony, nie czas,
# więc hałas na maszynie niczego tu nie zmienia.
#
# Metoda: wgrywamy jedno zdjęcie i natychmiast otwieramy świeżą stronę wpisu,
# zanim kolejka zdąży zrobić warianty (zadanie dla dużego zdjęcia trwa sekundy,
# okno jest szerokie). Potem czekamy na opróżnienie kolejki i otwieramy tę samą
# stronę drugi raz. Porównujemy 24 Mpx (poniżej progu podglądu) z 48 Mpx (powyżej).
#
# Świadomie NIE zatrzymujemy workera sygnałem: chodzi jako www-data w kontenerze,
# a wybijanie cudzych procesów po wzorcu jest w tym środowisku zakazane.
set -eu
cd /home/mateusz/kuking-B-obciazenie
M=/home/mateusz/kuking-b605-run/manifest.json
CIASTKO=$(node -e "const m=require('$M');console.log(m.sesje[0].ciasteczka)")

for R in 24mpx 48mpx; do
  node scripts/generator-obciazenia-605.mjs media --manifest "$M" --rozmiar "$R" --ile 1 \
    > "/home/mateusz/kuking-b605-run/430-$R.json" 2>/dev/null
  CEL=$(node -e "const d=require('/home/mateusz/kuking-b605-run/430-$R.json');console.log(new URL(d.wgrania[0].cel).pathname)")
  HTML=$(curl -s -b "$CIASTKO" "http://127.0.0.1:8605$CEL")
  # Świadomie NIE szukamy tu `kuking-mark.svg`: ten plik jest znakiem marki
  # w nagłówku KAŻDEJ strony, więc jego obecność niczego nie dowodzi.
  # Dowodem jest to, czy adres ŚWIEŻO wgranego zdjęcia w ogóle jest w HTML-u.
  NOWE=$(PGPASSWORD=kuking psql -h 127.0.0.1 -p 55439 -U kuking -d kuking_b605_obciazenie -Atc \
    'select id::text from media order by created_at desc limit 1')
  echo "=== $R — PIERWSZY render, zaraz po wgraniu ($CEL), świeże media: $NOWE"
  if echo "$HTML" | grep -qF "/zdjecia/$NOWE/"; then
    echo "    świeże zdjęcie JEST na stronie jako: $(echo "$HTML" | grep -oE "/zdjecia/$NOWE/[a-z]+" | sort -u | tr '\n' ' ')"
  else
    echo '    świeżego zdjęcia NIE MA na stronie (widok pomija je warunkiem isReady())'
  fi
  echo "$HTML" | grep -oE '/zdjecia/[0-9a-f-]{36}/[a-z]+' | sort -u | sed 's/^/    wszystkie warianty na stronie: /'

  for i in $(seq 1 120); do
    N=$(PGPASSWORD=kuking psql -h 127.0.0.1 -p 55439 -U kuking -d kuking_b605_obciazenie -Atc 'select count(*) from jobs')
    [ "$N" = "0" ] && break
    sleep 1
  done
  HTML2=$(curl -s -b "$CIASTKO" "http://127.0.0.1:8605$CEL")
  echo "--- $R — DRUGI render, po opróżnieniu kolejki"
  echo "$HTML2" | grep -oE '/zdjecia/[0-9a-f-]{36}/[a-z]+' | sort -u | sed 's/^/    wariant na stronie: /'
done

PGPASSWORD=kuking psql -h 127.0.0.1 -p 55439 -U kuking -d kuking_b605_obciazenie -Atc 'select count(*) from failed_jobs' | sed 's/^/nieudane zadania: /'
```

## 3. Czego to NIE mówi

* Nie sprawdzono, ile sekund trwa to okno pod obciążeniem — to należy do serii.
* W dzienniku aplikacji NIE ma ostrzeżenia „Zdjęcie bez wygenerowanych wariantów”,
  więc `Media::url()` nie był w ogóle wołany: widok pomija zdjęcie warunkiem
  `isReady()` (komentarz AUDYT A3 w `resources/views/components/layout.blade.php`).
  Autor nie dostaje więc zastępczego znaku marki — po prostu nie widzi swojego zdjęcia.
* Nie zgłoszono tego jeszcze do #430 ani #605: to rzecz zauważona przy okazji
  rozgrzewki mediów, do rozstrzygnięcia razem z pomiarem opóźnienia kolejki.
