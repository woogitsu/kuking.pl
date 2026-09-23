#!/bin/bash
# WYMAGA: WSL, klonu /home/mateusz/flota/push-run, list gałęzi w /home/mateusz/flota/*.txt i zdalnego 'github9'; poza tą maszyną nie zadziała — zapis tego, jak pchano szeregowo.
# Kolejka pchania floty. Czeka na kolejke8, potem pcha galezie z listy.
# Liste MOZNA DOPISYWAC W LOCIE — skrypt czyta ja przed kazda tura.
set -uo pipefail
D=/home/mateusz/flota/push-run
KAN=/mnt/c/Users/matma/Documents/Codex/kuking.pl
LISTA=/home/mateusz/flota/do-pchniecia.txt
ZROBIONE=/home/mateusz/flota/pchniete.txt
NIEUDANE=/home/mateusz/flota/nieudane.txt
LOG=/home/mateusz/flota/kolejka9.log

exec >>"$LOG" 2>&1
echo "########## KOLEJKA 9 start $(date -u +%H:%M:%SZ)"
touch "$ZROBIONE" "$NIEUDANE"

# Czekamy, az stanowisko sie zwolni. Dwa rownolegle przebiegi zderzaja sie
# na bazie i daja falszywe porazki (142 failures, deadlocki) — stad szeregowo.
for i in $(seq 1 960); do
  ps -eo args | grep -q '[k]olejka8.sh' || { echo "=== stanowisko wolne po $i probach"; break; }
  sleep 30
done

cd "$D" || exit 1
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export PGHOST=127.0.0.1 PGPORT=55439 PGUSER=kuking PGPASSWORD=kuking
export DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_push_hook
export DB_USERNAME=kuking DB_PASSWORD=kuking

TOK=/mnt/c/Temp/kuking-gh-token.tmp
install -m 600 /dev/null /tmp/.ghg9; cat "$TOK" | tr -d '\r\n' > /tmp/.ghg9
printf '#!/bin/bash\necho username=x-access-token\necho "password=$(cat /tmp/.ghg9)"\n' > /tmp/credg9.sh
chmod 700 /tmp/credg9.sh
git remote remove github9 2>/dev/null; git remote add github9 https://github.com/woogitsu/kuking.pl.git


# ---------------------------------------------------------------------------
# PROG OBCIAZENIA. Hook pre-push uruchamia pelna bateria na tym samym
# PostgreSQL-u, ktorego uzywaja wszystkie stanowiska. Przy obciazonej maszynie
# daje to deadlocki i 38 falszywych porazek — zmierzone 20.09.2026. Czerwien
# z takiego przebiegu nie mowi nic o kodzie, a kosztuje 7 minut.
# Dlatego czekamy, az load average z ostatniej minuty spadnie ponizej progu.
#
# PROG = 16 przy 24 rdzeniach (nproc=24), czyli ok. 2/3 maszyny zajete, 8 rdzeni
# wolnych dla samej baterii hooka. Poprzednie 8 bylo progiem dla maszyny
# duzo mniejszej: przy 24 rdzeniach oznacza 0,33 obciazenia na rdzen, czyli
# praktycznie bezczynna maszyne. 20.09.2026 load nie spadl ponizej 8 ani razu
# przez ponad godzine (probki 14–32) i kolejka stala od 18:47Z do awaryjnej
# furtki 4h. Mechanizmu NIE kasujemy — powstal, bo przy zatkanej maszynie hook
# dawal deadlocki i 38 falszywych porazek jednego dnia. Zmieniamy tylko wartosc
# na taka, ktora zostawia baterii realny zapas CPU i jest osiagalna.
czekaj_na_spokoj() {
  local prog=16
  for _ in $(seq 1 240); do
    local l
    l=$(awk '{printf "%d", $1}' /proc/loadavg)
    [ "$l" -lt "$prog" ] && return 0
    echo "  ... czekam na spokoj maszyny (load=$l, prog=$prog) $(date -u +%H:%M:%SZ)"
    sleep 60
  done
  echo "  !!! maszyna nie ucichla przez 4h — pchamy mimo to"
  return 0
}

pusto=0
while [ "$pusto" -lt 20 ]; do
  br=""
  while IFS= read -r kandydat; do
    [ -n "$kandydat" ] || continue
    case "$kandydat" in \#*) continue;; esac
    grep -qxF "$kandydat" "$ZROBIONE" && continue
    br="$kandydat"; break
  done < "$LISTA"

  if [ -z "$br" ]; then
    pusto=$((pusto+1)); sleep 60; continue
  fi
  pusto=0

  echo "########## $br  start $(date -u +%H:%M:%SZ)"

  # FETCH IDZIE PRZED CZEKANIEM NA SPOKOJ. Fetch nie dotyka ani wspolnego
  # PostgreSQL-a, ani drzewa roboczego — czyta tylko repozytorium kanoniczne,
  # wiec nie potrzebuje ani flocka, ani ciszy na maszynie. Wczesniej bylo
  # odwrotnie i pozycja bez pokrycia (galaz przepadla przy awarii stanowisk)
  # najpierw czekala do 4 godzin na spadek obciazenia, a dopiero potem dostawala
  # FETCH FAIL. Przy 44 martwych pozycjach z 54 oznaczalo to paraliz kolejki.
  # Teraz martwa pozycja odpada w sekundy, a czekamy tylko przed realna praca.
  if ! git fetch -q "$KAN" "$br" 2>/dev/null; then
    echo "  FETCH FAIL — brak galezi w repozytorium kanonicznym, pomijam"
    echo "$br" >> "$ZROBIONE"; continue
  fi
  # Zapamietujemy SHA z tego fetcha. Miedzy fetchem a checkoutem uplywa teraz
  # czekanie na spokoj, a regula projektu mowi, ze czerwien liczy sie dopiero
  # po ponowieniu na IDENTYCZNYM SHA — wiec pchamy to, co wlasnie pobralismy.
  pobrany=$(git rev-parse FETCH_HEAD)

  czekaj_na_spokoj
  # flock nadal obejmuje checkout i push — to one ruszaja drzewo robocze
  # i odpalaja bateria hooka na wspoldzielonej bazie.
  exec 9>/home/mateusz/flota/stanowisko.lock
  flock 9
  git checkout -- . 2>/dev/null; git clean -fd -q 2>/dev/null
  if ! git checkout -q -B "$br" "$pobrany"; then
    echo "  CHECKOUT FAIL"; echo "$br" >> "$ZROBIONE"; exec 9>&-; continue
  fi
  echo "  HEAD: $(git rev-parse --short HEAD)"
  git -c credential.helper=/tmp/credg9.sh push github9 "$br"
  kod=$?
  echo "  === push exit=$kod koniec $(date -u +%H:%M:%SZ)"
  if [ "$kod" = 0 ]; then
    echo "$br" >> "$ZROBIONE"
  else
    # NIE oznaczamy jako zrobione. Nieudane pchniecie zostaje w kolejce,
    # zeby dalo sie je ponowic, gdy maszyna bedzie spokojna. Reguła projektu:
    # czerwien liczy sie dopiero po ponowieniu na identycznym SHA.
    # PULAPKA: NIE pisac `grep -c ... || echo 0`. Przy zerze trafien `grep -c`
    # DRUKUJE "0" i JEDNOCZESNIE zwraca kod 1, wiec `|| echo 0` dokladalo drugie
    # zero i zmienna miala wartosc "0\n0". Test ponizej konczyl sie wtedy
    # `[: 0\n0: integer expected`, warunek nigdy nie byl prawdziwy, a furtka
    # "odpada po trzech probach" nie dzialala ani razu. Galaz z prawdziwa
    # czerwienia krazyla w nieskonczonosc i blokowala cala reszte kolejki
    # (20.09.2026, naprawa/klient-pg18-w-ci). Bierzemy sam wydruk grepa i
    # domykamy pusty wynik podstawieniem, bez zadnego `||`.
    proby=$(grep -c "^$br$" "$NIEUDANE" 2>/dev/null)
    proby=${proby:-0}
    echo "$br" >> "$NIEUDANE"
    if [ "$proby" -ge 2 ]; then
      echo "  !!! $br odpada po trzech probach — do recznego obejrzenia"
      echo "$br" >> "$ZROBIONE"
    fi
  fi
  exec 9>&-
done

shred -u /tmp/.ghg9 /tmp/credg9.sh 2>/dev/null
echo "########## KONIEC KOLEJKI 9 $(date -u +%H:%M:%SZ)"
