#!/usr/bin/env bash
# =============================================================================
#  Test regresyjny awarii z 5–6 września 2026 (3,5 godziny niedostępności).
# =============================================================================
#
#  CO SIĘ DZIAŁO
#  `queue:work --max-time=3600` kończy się CELOWO po godzinie, z kodem 0 —
#  tak Laravel walczy z wyciekami pamięci. Rola `all` czekała przez `wait -n`,
#  czyli „skończył się którykolwiek → zamykam kontener". Godzinę po wdrożeniu
#  worker zrobił to, do czego został zaprogramowany, i zabrał serwer WWW.
#  Kontener wyszedł z kodem 0, więc Railway uznał to za poprawne zakończenie
#  i nie wskrzesił serwisu.
#
#  DLACZEGO TEST W BASHU, A NIE W PHPUNICIE
#  Błąd nie był w kodzie PHP. Był w tym, jak skrypt powłoki rozróżnia
#  „proces się skończył" od „proces padł" — a tego żaden test Laravela
#  nie dotknie. Test musi mówić tym samym językiem, co naprawa.
#
#  Uruchomienie:  bash tests/skrypty/entrypoint-nadzor.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENTRYPOINT="${KATALOG}/docker/entrypoint.sh"
DOCKERFILE="${KATALOG}/Dockerfile"

zdane=0
oblane=0

sprawdz() {
  local opis="$1" oczekiwane="$2" otrzymane="$3"
  if [[ "${oczekiwane}" == "${otrzymane}" ]]; then
    printf '  \033[0;32m✓\033[0m %s\n' "${opis}"
    zdane=$((zdane + 1))
  else
    printf '  \033[0;31m✗\033[0m %s\n     oczekiwano: %s\n     otrzymano:  %s\n' \
      "${opis}" "${oczekiwane}" "${otrzymane}"
    oblane=$((oblane + 1))
  fi
}

# Wyciągamy SAME FUNKCJE z entrypointu, bez uruchamiania go: plik na starcie
# wymaga APP_KEY, /app/artisan i całej reszty kontenera.
wczytaj_funkcje() {
  # shellcheck disable=SC2016
  sed -n '/^nadzoruj() {/,/^}/p' "${ENTRYPOINT}"
  sed -n '/^petla_harmonogramu() {/,/^}/p' "${ENTRYPOINT}"
  echo 'log() { printf "[test] %s\n" "$*" >&2; }'
}

echo "── Nadzorca procesów (docker/entrypoint.sh) ──"

# Strażnik przed fałszywą zielenią. Bez tego test „proces padający natychmiast
# eskaluje" PRZECHODZI na wersji sprzed naprawy — bo funkcji `nadzoruj` tam
# nie ma, wywołanie kończy się błędem powłoki, a `if` interpretuje ten błąd
# jako „eskalował". Test przechodziłby, nie sprawdzając niczego.
if ! eval "$(wczytaj_funkcje)" 2>/dev/null || ! declare -f nadzoruj >/dev/null 2>&1; then
  printf '  \033[0;31m✗\033[0m docker/entrypoint.sh nie definiuje funkcji nadzoruj()\n'
  printf '\033[0;31mNie ma czego testować.\033[0m\n'
  exit 1
fi

# ---------------------------------------------------------------------------
# 1. SEDNO AWARII: proces, który kończy się planowo, ma zostać wskrzeszony,
#    a nie zabić nadzorcy.
# ---------------------------------------------------------------------------
wynik="$(
  eval "$(wczytaj_funkcje)"
  export NADZOR_MIN_CZAS=1 NADZOR_LIMIT=5
  licznik_pliku="$(mktemp)"
  echo 0 > "${licznik_pliku}"

  # Udaje `queue:work --max-time`: pracuje chwilę i kończy się z kodem 0.
  recykling() {
    local n; n=$(( $(cat "${licznik_pliku}") + 1 ))
    echo "${n}" > "${licznik_pliku}"
    sleep 1.2
    return 0
  }

  # Nadzorca ma pętlę nieskończoną — przerywamy go po czasie i patrzymy,
  # ILE RAZY zdążył wskrzesić proces.
  ( nadzoruj "kolejka" recykling ) &
  pid=$!
  sleep 4
  kill -TERM "${pid}" 2>/dev/null
  wait "${pid}" 2>/dev/null

  ile="$(cat "${licznik_pliku}")"
  rm -f "${licznik_pliku}"
  # Bez naprawy proces uruchomiłby się DOKŁADNIE RAZ i wszystko by się skończyło.
  if (( ile >= 2 )); then echo "wskrzeszony"; else echo "uruchomiony ${ile} raz"; fi
)"
sprawdz "planowe zakończenie procesu jest wskrzeszane, a nie eskalowane" "wskrzeszony" "${wynik}"

# ---------------------------------------------------------------------------
# 2. DRUGA STRONA: proces padający NATYCHMIAST to awaria, nie recykling.
#    Nadzorca musi się poddać, żeby kontener zgłosił porażkę.
# ---------------------------------------------------------------------------
wynik="$(
  eval "$(wczytaj_funkcje)"
  export NADZOR_MIN_CZAS=5 NADZOR_LIMIT=2
  pada_od_razu() { return 1; }

  if nadzoruj "kolejka" pada_od_razu 2>/dev/null; then
    echo "wrocil-zero"
  else
    echo "eskalowal"
  fi
)"
sprawdz "proces padający natychmiast eskaluje po przekroczeniu limitu" "eskalowal" "${wynik}"

# ---------------------------------------------------------------------------
# 3. Licznik szybkich śmierci ZERUJE SIĘ po udanym przebiegu. Bez tego
#    worker chodzący tygodniami uzbierałby limit z pojedynczych potknięć
#    i sam się wyłączył — po kilku dniach, bez związku z czymkolwiek.
# ---------------------------------------------------------------------------
wynik="$(
  eval "$(wczytaj_funkcje)"
  export NADZOR_MIN_CZAS=1 NADZOR_LIMIT=2
  stan="$(mktemp)"
  echo 0 > "${stan}"

  # padnij, pożyj, padnij, pożyj... — przy zerowaniu licznika to nigdy
  # nie osiągnie limitu 2.
  na_przemian() {
    local n; n=$(( $(cat "${stan}") + 1 ))
    echo "${n}" > "${stan}"
    if (( n % 2 == 1 )); then return 1; fi
    sleep 1.2
    return 0
  }

  ( nadzoruj "kolejka" na_przemian ) &
  pid=$!
  sleep 6
  zyje="nie"
  kill -0 "${pid}" 2>/dev/null && zyje="tak"
  kill -TERM "${pid}" 2>/dev/null; wait "${pid}" 2>/dev/null
  rm -f "${stan}"
  echo "${zyje}"
)"
sprawdz "pojedyncze potknięcia nie sumują się do wyłączenia" "tak" "${wynik}"

# Długi przebieg z błędem NIE jest recyklingiem (#1041). Zegar jest atrapą,
# ale funkcja nadzorcy, kod wyjścia i backoff są rzeczywiste. Limit zewnętrzny
# sprawia, że mutacja przywracająca nieskończone restarty oblewa zamiast wisieć.
wynik="$(timeout 3 bash -c "$(wczytaj_funkcje)
$(cat <<'PROBA'
set -Eeuo pipefail
NADZOR_MIN_CZAS=30 NADZOR_LIMIT=3
czas=0 przebiegi=0
date() { echo "${czas}"; }
sleep() { echo "przerwa=$1"; command sleep 0.01; }
dluga_awaria() { czas=$((czas + 31)); przebiegi=$((przebiegi + 1)); return 23; }
kod=0
nadzoruj kolejka dluga_awaria || kod=$?
echo "wynik=${kod} przebiegi=${przebiegi}"
PROBA
)" 2>&1)"
sprawdz "długie błędy eskalują dokładnie po limicie" "tak" "$(grep -q 'wynik=1 przebiegi=3' <<< "$wynik" && echo tak || echo nie)"
sprawdz "długie błędy zachowują rosnący backoff" $'przerwa=2\nprzerwa=4' "$(grep '^przerwa=' <<< "$wynik")"
sprawdz "log awarii podaje kod, czas i numer próby" "tak" "$(grep -q 'kolejka awaria (kod 23) po 31 s (3/3)' <<< "$wynik" && echo tak || echo nie)"
sprawdz "niezerowy kod nigdy nie jest planowym recyklingiem" "brak" "$(grep -q 'planowy recykling' <<< "$wynik" && echo jest || echo brak)"

# Wykonujemy prawdziwy blok all, nie jego odpis. Atrapy nie uruchamiają PHP,
# WWW ani bazy. Prawdziwy shutdown ma zatrzymać pozostałe procesy. Trzy
# scenariusze sprawdzają każdy obserwowany PID i set -e przy niezerowym wait.
for cel in kolejka www harmonogram recykling; do
  wynik="$(timeout 5 bash -c "$(wczytaj_funkcje)
$(sed -n '/^czekaj_na_uslugi() {/,/^}/p' "$ENTRYPOINT")
$(sed -n '/^shutdown() {/,/^}/p' "$ENTRYPOINT")
$(cat <<'PROBA'
set -Eeuo pipefail
ROLE=all PORT=8080 CHILD_PIDS=()
NADZOR_MIN_CZAS=30 NADZOR_LIMIT=2
STAN="$(mktemp)"
echo 0 > "$STAN"
trap 'rm -f "$STAN"' EXIT
sleep() { command sleep 0.05; }
trwaj() { while true; do command sleep 0.05; done; }
# Funkcja zastępuje jedynie właściwą pracę, NIE nadzorcę.
jeden_przebieg_kolejki() {
  case "$CEL" in
    kolejka) return 23 ;;
    recykling) echo "$(( $(cat "$STAN") + 1 ))" > "$STAN"; sleep 1; return 0 ;;
    *) trwaj ;;
  esac
}
harmonogram_raz() { if [[ "$CEL" == harmonogram ]]; then return 24; else trwaj; fi; }
frankenphp() {
  case "$CEL" in
    www) return 25 ;;
    recykling)
      local przebiegi
      while true; do
        przebiegi="$(cat "$STAN")"
        if [[ "$przebiegi" =~ ^[0-9]+$ ]] && (( przebiegi >= 3 )); then break; fi
        sleep 1
      done
      echo 'WWW przeżył trzy przebiegi kolejki'
      return 25 ;;
    *) trwaj ;;
  esac
}
PROBA
)
CEL=$cel
[[ \"\${CEL}\" != recykling ]] || NADZOR_MIN_CZAS=0
case \"\${ROLE}\" in
$(sed -n '/^  all)/,/^    ;;/p' "$ENTRYPOINT")
esac" 2>&1)"
  kod=$?
  sprawdz "all eskaluje zakończenie usługi: ${cel}" "1" "$kod"
  sprawdz "all sprząta pozostałe usługi: ${cel}" "tak" "$(grep -q 'zamknięte (kod 1)' <<< "$wynik" && echo tak || echo nie)"
  if [[ "$cel" == recykling ]]; then
    sprawdz "all pozostaje czynne przy planowym recyklingu" "tak" "$(grep -q 'WWW przeżył trzy przebiegi kolejki' <<< "$wynik" && echo tak || echo nie)"
  fi
done

# ---------------------------------------------------------------------------
# 3b. PĘTLA HARMONOGRAMU NIE DRYFUJE (#1355). `schedule:run` patrzy na minutę,
#     w której wystartował. Stara pętla „przebieg + sleep 60” przesuwała start
#     o czas przebiegu, aż przeskoczyła całą minutę — i zadanie dzienne z tej
#     minuty nie wykonało się wcale. Zegar jest atrapą, pętla jest prawdziwa.
# ---------------------------------------------------------------------------
#
#     Atrapa zegara odpowiada na `date +%s` i `date +%S`, atrapa `sleep` przesuwa
#     zegar, atrapa `schedule:run` (harmonogram_raz) „trwa” tyle sekund, ile
#     podaje lista. Wynik to godziny startów (GG:MM:SS) i ostrzeżenia z logu.
# ---------------------------------------------------------------------------
# $1 — definicja pętli (kod basha), $2 — start (sekunda doby), $3.. — czasy
# kolejnych przebiegów; po ostatnim pętla jest przerywana.
proba_harmonogramu() {
  local petla="$1" poczatek="$2"
  shift 2
  timeout 5 bash -c "log() { printf 'LOG %s\n' \"\$*\"; }
${petla}
czas=${poczatek} obrot=0 czasy=( $* )
date() {
  case \"\${1:-}\" in
    +%s) echo \"\${czas}\" ;;
    +%S) printf '%02d\n' \$(( czas % 60 )) ;;
  esac
}
sleep() { czas=\$(( czas + \$1 )); }
harmonogram_raz() {
  (( obrot < \${#czasy[@]} )) || exit 0
  printf 'START %02d:%02d:%02d\n' \$(( czas / 3600 )) \$(( czas / 60 % 60 )) \$(( czas % 60 ))
  czas=\$(( czas + czasy[obrot] ))
  obrot=\$(( obrot + 1 ))
}
petla_harmonogramu" 2>&1
}

# Werdykt dla serii krótkich przebiegów: każdy start po pierwszym na :00
# i w KOLEJNEJ minucie — bez przeskoków i bez dwóch startów w jednej minucie.
ocen_krotkie() {
  awk '/^START/ { split($2, t, ":"); m = t[1] * 60 + t[2]; n++
         if (n > 1) { if (t[3] != "00") zle++; if (m != p + 1) skok++ }
         p = m }
       END { printf "starty=%d poza_granica=%d przeskoki=%d\n", n, zle + 0, skok + 0 }'
}

# Cały blok pętli razem z funkcjami pomocniczymi — od nagłówka #1355 do
# nagłówka o `schedule:work`. Bez pomocników próba oblewałaby na „command not
# found”, a nie na zachowaniu pętli.
PETLA_MAIN="$(sed -n '/PĘTLA WYRÓWNANA DO PEŁNEJ MINUTY/,/DLACZEGO PĘTLA, A NIE/p' "${ENTRYPOINT}")"
# Kontrole dodatnie: stara pętla sprzed #1433 oraz pętla z #1433, która po
# przebiegu dłuższym niż minuta spała do kolejnej granicy.
PETLA_SLEEP60='petla_harmonogramu() { while true; do harmonogram_raz; sleep 60; done; }'
PETLA_1433='petla_harmonogramu() { while true; do harmonogram_raz; sleep "$(( 60 - 10#$(date +%S) ))"; done; }'

# 90 krótkich przebiegów po 7 s, start 03:00:13: stara pętla zgubiłaby 10 minut.
KROTKIE="$(printf '7 %.0s' {1..90})"
sprawdz "krótkie przebiegi startują na każdej pełnej minucie, bez przeskoków" \
  "starty=90 poza_granica=0 przeskoki=0" \
  "$(proba_harmonogramu "${PETLA_MAIN}" $(( 3 * 3600 + 13 )) ${KROTKIE} | ocen_krotkie)"
wynik="$(proba_harmonogramu "${PETLA_SLEEP60}" $(( 3 * 3600 + 13 )) ${KROTKIE} | ocen_krotkie)"
if [[ "${wynik}" == "starty=90 poza_granica=0 przeskoki=0" ]]; then
  sprawdz "kontrola dodatnia: pętla 'sleep 60' oblewa próbę krótkich przebiegów" "oblewa" "przechodzi"
else
  sprawdz "kontrola dodatnia: pętla 'sleep 60' oblewa próbę krótkich przebiegów" "oblewa" "oblewa"
fi

# Przebieg dłuższy niż minuta, zadanie dzienne o 03:40. Start 03:39:00 trwa
# 65 s → minuta 03:40 nie była sprawdzona, więc pętla rusza od razu (03:40:05)
# i zadanie dzienne się wykona. Potem 2 s i sen do 03:41:00. Przebieg 150 s
# od 03:41:00 przesypia całą minutę 03:42 — ta jest stracona, ale w logu
# stoi ostrzeżenie; start od razu 03:43:30 łapie minutę 03:43.
DLUGIE_OCZEKIWANE="START 03:39:00
START 03:40:05
START 03:41:00
LOG OSTRZEŻENIE: przebieg harmonogramu trwał 150 s — pominięte minuty: 1. Zadania z tych minut nie wykonały się; sprawdź, które zadanie jest za długie.
START 03:43:30
START 03:44:00"
sprawdz "przebieg dłuższy niż minuta: bez nakładania, minuta 03:40 złapana, strata minuty w logu" \
  "${DLUGIE_OCZEKIWANE}" \
  "$(proba_harmonogramu "${PETLA_MAIN}" $(( 3 * 3600 + 39 * 60 )) 65 2 150 1 1)"
for kontrola in PETLA_SLEEP60 PETLA_1433; do
  if proba_harmonogramu "${!kontrola}" $(( 3 * 3600 + 39 * 60 )) 65 2 | grep -q '^START 03:40:'; then
    sprawdz "kontrola dodatnia: ${kontrola} gubi zadanie dzienne 03:40 po długim przebiegu" "gubi" "łapie"
  else
    sprawdz "kontrola dodatnia: ${kontrola} gubi zadanie dzienne 03:40 po długim przebiegu" "gubi" "gubi"
  fi
done

# Przebieg kończący się dokładnie na granicy (:00) nowej minuty: ta minuta nie
# była sprawdzona, więc start od razu, a nie po 60 s.
sprawdz "przebieg kończący się o :00 nowej minuty nie przesypia jej" \
  "START 03:39:30
START 03:40:00
START 03:41:00" \
  "$(proba_harmonogramu "${PETLA_MAIN}" $(( 3 * 3600 + 39 * 60 + 30 )) 30 1 1)"

# ---------------------------------------------------------------------------
# 4. Kod wyjścia. Railway restartuje kontener po KODZIE NIEZEROWYM; przy
#    zerze uznaje, że praca się skończyła. Awaria musi więc wychodzić 1.
# ---------------------------------------------------------------------------
if grep -q 'shutdown 1' "${ENTRYPOINT}"; then
  sprawdz "śmierć serwera WWW zamyka kontener kodem niezerowym" "tak" "tak"
else
  sprawdz "śmierć serwera WWW zamyka kontener kodem niezerowym" "tak" "nie"
fi

# ---------------------------------------------------------------------------
# 5. `wait -n` NIE MOŻE WRÓCIĆ do roli `all`. To jest ta jedna konstrukcja,
#    która spowodowała awarię: nie odróżnia procesu, który ma prawo się
#    skończyć, od tego, który go nie ma.
# ---------------------------------------------------------------------------
# Komentarze w tym bloku CELOWO wspominają `wait -n` — opisują, co poszło
# nie tak. Sprawdzamy więc kod, a nie prozę: linie zaczynające się od `#`
# lecą do kosza przed dopasowaniem. Pierwsza wersja tego testu tego nie
# robiła i oblewała na własnym komentarzu z naprawy.
if sed -n '/^  all)/,/^    ;;/p' "${ENTRYPOINT}" | sed 's/[[:space:]]*#.*$//' | grep 'wait -n' >/dev/null; then
  sprawdz "rola 'all' nie czeka przez 'wait -n'" "brak" "jest"
else
  sprawdz "rola 'all' nie czeka przez 'wait -n'" "brak" "brak"
fi

# ---------------------------------------------------------------------------
# 6. WOLUMIN NA ZDJĘCIA NIE MOŻE ZABIĆ CAŁEJ STRONY.
#
#    Railway montuje świeży wolumin jako root:root. Kontener startujący od
#    razu jako www-data nie ma prawa założyć w nim katalogu: `mkdir` pada,
#    `set -e` zabija start, kontener wpada w pętlę i znika CAŁA STRONA —
#    mimo że problem dotyczy wyłącznie zdjęć. Tak poszła produkcja w 404.
#
#    Dwie rzeczy muszą być prawdą naraz: entrypoint schodzi z roota SAM
#    (a nie przez `USER` w Dockerfile, bo wtedy nie ma czym zrobić chown),
#    a `mkdir` na katalogu ze zdjęciami nie jest śmiertelny.
# ---------------------------------------------------------------------------
# UWAGA NA `grep -q` PO DRUGIEJ STRONIE RURY.
# `grep -q` wychodzi przy pierwszym trafieniu, `sed` dostaje wtedy SIGPIPE,
# a `set -o pipefail` uznaje CAŁĄ rurę za nieudaną — mimo że wzorzec został
# znaleziony. Wynik zależy od tego, czy `sed` zdążył dopisać do bufora,
# więc ten sam test raz przechodzi, a raz nie. Kosztowało to pół godziny
# szukania nieistniejącej regresji w entrypoincie, w trakcie awarii
# produkcji. Dlatego niżej jest `grep ... >/dev/null`, a nie `grep -q`.
#
# Ta sama pulapka zlapala pozniej przyrzad kontroli ujemnej, bo lekcja siedziala
# wylacznie tutaj. Pelny opis i kierunek bledu: docs/PULAPKI_TESTOW.md 5c.
bez_komentarzy() { sed 's/[[:space:]]*#.*$//' "$1"; }

if bez_komentarzy "${ENTRYPOINT}" | grep 'exec setpriv --reuid=www-data' >/dev/null; then
  sprawdz "entrypoint sam schodzi z uprawnień roota" "tak" "tak"
else
  sprawdz "entrypoint sam schodzi z uprawnień roota" "tak" "nie"
fi

# Kolejność, nie samo istnienie: chown musi być PRZED zejściem z uprawnień,
# inaczej robi go ktoś, kto już nie ma do tego prawa.
linia_chown="$(bez_komentarzy "${ENTRYPOINT}" | grep -n 'chown www-data:www-data' | head -1 | cut -d: -f1)"
linia_setpriv="$(bez_komentarzy "${ENTRYPOINT}" | grep -n 'exec setpriv' | head -1 | cut -d: -f1)"
if [[ -n "${linia_chown}" && -n "${linia_setpriv}" ]] && (( linia_chown < linia_setpriv )); then
  sprawdz "chown woluminu poprzedza zejście z uprawnień" "tak" "tak"
else
  sprawdz "chown woluminu poprzedza zejście z uprawnień" "tak" "nie"
fi

if bez_komentarzy "${DOCKERFILE}" | grep -E '^USER[[:space:]]' >/dev/null; then
  sprawdz "Dockerfile nie ustawia USER przed entrypointem" "brak" "jest"
else
  sprawdz "Dockerfile nie ustawia USER przed entrypointem" "brak" "brak"
fi

# `storage:link` zakłada symlink w /app/public. Katalog przychodzi z obrazu
# jako root:root, więc bez tego chown link nie powstaje — a wtedy KAŻDE
# zdjęcie zwraca 404 przy stronie oddającej HTTP 200. Awaria niewidoczna
# dla monitoringu, widoczna dla człowieka jako ikona zepsutego obrazka.
if bez_komentarzy "${ENTRYPOINT}" | grep 'chown www-data:www-data /app/public' >/dev/null; then
  sprawdz "katalog public dostaje właściciela przed storage:link" "tak" "tak"
else
  sprawdz "katalog public dostaje właściciela przed storage:link" "tak" "nie"
fi

# `mkdir` na katalogu ze zdjęciami wykonywany JAKO www-data (druga sekcja,
# po zejściu z uprawnień) musi być nieśmiertelny.
if bez_komentarzy "${ENTRYPOINT}" \
  | grep 'mkdir -p /app/storage/app/public /app/storage/app/private 2>/dev/null || true' >/dev/null; then
  sprawdz "brak prawa zapisu do zdjęć nie zabija startu" "tak" "tak"
else
  sprawdz "brak prawa zapisu do zdjęć nie zabija startu" "tak" "nie"
fi

echo
if (( oblane > 0 )); then
  printf '\033[0;31mOblane: %d, zdane: %d\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d testów nadzorcy przechodzi.\033[0m\n' "${zdane}"
