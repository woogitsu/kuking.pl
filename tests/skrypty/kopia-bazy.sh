#!/usr/bin/env bash
# =============================================================================
#  Testy serwisu kopii bazy (issue #193, decyzja D-043)
# =============================================================================
#
#  DLACZEGO TEST W BASHU, A NIE W PHPUNICIE
#  Bo kopia bazy JEST skryptem powłoki, uruchamianym w obrazie bez PHP —
#  taka jest treść decyzji D-043 (`proc_open` wyłączony w kontenerze
#  aplikacji). Test musi mówić tym samym językiem, co naprawa. Ta sama
#  zasada i ten sam kształt, co `tests/skrypty/entrypoint-nadzor.sh`.
#
#  CO TU JEST SPRAWDZANE, A CZEGO SPRAWDZIĆ NIE DA SIĘ
#  Sprawdzane: podpis SigV4 wobec URZĘDOWYCH wektorów AWS, treść alarmu
#  (najważniejsze — alarm wychodzi do usługi, nad którą nie mamy kontroli),
#  bramka zgodności wersji `pg_dump`, arytmetyka retencji, maskowanie hasła
#  w logu.
#
#  NIEsprawdzane, bo wymaga prawdziwych usług: rozmowa z R2, prawdziwy
#  `pg_dump` na PostgreSQL 18 (lokalnie stoi 16), zbudowanie obrazu.
#  To jest wypisane wprost, żeby zielony wynik tego pliku nie był czytany
#  jako „kopia działa".
#
#  Uruchomienie:  bash tests/skrypty/kopia-bazy.sh
# =============================================================================

set -uo pipefail

KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SKRYPT="${KATALOG}/docker/kopia/kopia-bazy.sh"
BIBLIOTEKA_S3="${KATALOG}/docker/kopia/s3.sh"
DOCKERFILE_KOPII="${KATALOG}/docker/kopia/Dockerfile"
PHP_INI="${KATALOG}/docker/php.ini"

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

# Strażnik przed fałszywą zielenią: bez tych plików nie ma czego testować,
# a każde `if` na nieistniejącej funkcji przechodziłoby jako „nie zawiodła".
for plik in "${SKRYPT}" "${BIBLIOTEKA_S3}" "${DOCKERFILE_KOPII}"; do
  if [[ ! -f "${plik}" ]]; then
    printf '  \033[0;31m✗\033[0m brak pliku %s\n' "${plik#"${KATALOG}"/}"
    printf '\033[0;31mNie ma czego testować.\033[0m\n'
    exit 1
  fi
done

if ! (
  # shellcheck disable=SC1090
  . "${SKRYPT}" >/dev/null 2>&1
  declare -f s3_podpis >/dev/null && declare -f alarm >/dev/null && declare -f retencja >/dev/null
); then
  printf '  \033[0;31m✗\033[0m docker/kopia/kopia-bazy.sh nie definiuje s3_podpis/alarm/retencja\n'
  exit 1
fi

wczytaj() {
  # `set +e` PO wczytaniu: skrypt produkcyjny włącza `set -Eeuo pipefail`,
  # a w teście chcemy sprawdzać kody wyjścia, nie wychodzić na pierwszym.
  # shellcheck disable=SC1090
  . "${SKRYPT}"
  set +eo pipefail
}

# =============================================================================
echo "── Podpis AWS SigV4 (docker/kopia/s3.sh) ──"
# =============================================================================
#
#  DLACZEGO WEKTORY, A NIE „porównaj z drugą implementacją"
#  Bo dwie implementacje napisane przez tę samą osobę potwierdzają wspólne
#  nieporozumienie równie chętnie, jak poprawność. Poniżej są liczby
#  OPUBLIKOWANE PRZEZ AWS: klucz podpisu z dokumentacji Signature Version 4
#  i sygnatura przypadku `get-vanilla` z `aws-sig-v4-test-suite`.
#  Błąd w podpisie kończy się HTTP 403 z R2, czyli brakiem kopii.

# 1. Łańcuch HMAC (derywacja klucza podpisu).
#    Wektor z dokumentacji AWS: secret wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY,
#    20150830 / us-east-1 / iam / aws4_request.
wynik="$(
  wczytaj
  k="$(printf 'AWS4%s' 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY' | s3_hex)"
  for element in 20150830 us-east-1 iam aws4_request; do
    k="$(printf '%s' "${element}" | s3_hmac "${k}")"
  done
  printf '%s' "${k}"
)"
sprawdz "klucz podpisu zgadza się z wektorem AWS" \
  "c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9" "${wynik}"

# 2. sha256 pustego ciała — wartość, którą wysyłamy przy HEAD/DELETE/GET.
wynik="$(wczytaj; printf '' | s3_sha256)"
sprawdz "sha256 pustego ciała" \
  "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" "${wynik}"

# 3. CAŁA sygnatura, przez produkcyjną funkcję `s3_podpis`.
#    Wektor `get-vanilla` z aws-sig-v4-test-suite: GET / na
#    example.amazonaws.com, region us-east-1, usługa „service",
#    dwa podpisane nagłówki.
wynik="$(
  wczytaj
  naglowki="host:example.amazonaws.com
x-amz-date:20150830T123600Z
"
  s3_podpis GET / '' "${naglowki}" 'host;x-amz-date' \
    "$(printf '' | s3_sha256)" \
    20150830T123600Z 20150830 us-east-1 service \
    'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
)"
sprawdz "sygnatura zgadza się z wektorem AWS get-vanilla" \
  "5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31" "${wynik}"

# 4. Regresja na PUSTĄ LINIĘ w żądaniu kanonicznym. To jedyny błąd w tej
#    funkcji, który wygląda niewinnie i kończy się 403 na produkcji —
#    a lokalnie nie objawia się niczym. Nadmiarowa linia MUSI zmienić
#    sygnaturę; test pilnuje, że liczba wyżej nie jest przypadkiem.
wynik="$(
  wczytaj
  naglowki="host:example.amazonaws.com
x-amz-date:20150830T123600Z

"
  s3_podpis GET / '' "${naglowki}" 'host;x-amz-date' \
    "$(printf '' | s3_sha256)" \
    20150830T123600Z 20150830 us-east-1 service \
    'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
)"
if [[ "${wynik}" == "5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31" ]]; then
  sprawdz "nadmiarowa pusta linia zmienia sygnaturę" "zmienia" "nie zmienia"
else
  sprawdz "nadmiarowa pusta linia zmienia sygnaturę" "zmienia" "zmienia"
fi

# =============================================================================
echo "── Alarm: co wychodzi na webhook (audyt A6-01) ──"
# =============================================================================
#
#  To jest najważniejsza część tego pliku. Kanał alarmowy wychodzi do
#  Discorda/Slacka, czyli do usługi, nad którą nie mamy żadnej kontroli.
#  Zrzut bazy to komplet danych osobowych, a poświadczenia do bazy i do
#  bucketu są w tym samym środowisku, co ten skrypt. Wysłanie „szczegółów
#  na wszelki wypadek" byłoby tu najgorszą możliwą pomocą.

# Podstawiamy `curl` funkcją, która zapisuje ciało żądania do pliku.
# Skrypt woła `curl` bez ścieżki, więc funkcja go przesłania.
zloz_alarm() {
  local etap="$1" kod="$2"
  local plik="$3"
  (
    wczytaj
    export KOPIA_WEBHOOK_URL='https://przyklad.invalid/webhook'
    # Sekrety w środowisku — dokładnie tak jak na produkcji. Jeśli
    # którykolwiek wycieknie do treści, testy niżej to pokażą.
    export DB_URL='postgresql://kuking:tajne-haslo-bazy@postgres.railway.internal:5432/railway'
    export KOPIA_S3_SEKRET='sekret-bucketu-r2'
    export KOPIA_S3_BUCKET='kuking-kopie'
    export SRODOWISKO='production'
    curl() {
      local poprzedni=''
      for argument in "$@"; do
        [[ "${poprzedni}" == '--data' ]] && printf '%s' "${argument}" >"${plik}"
        poprzedni="${argument}"
      done
      return 0
    }
    alarm "${etap}" "${kod}" >/dev/null 2>&1
  )
}

PLIK_ALARMU="$(mktemp)"
zloz_alarm zrzut 40 "${PLIK_ALARMU}"
tresc_alarmu="$(cat "${PLIK_ALARMU}")"

if [[ "${tresc_alarmu}" == *'etap: zrzut'* && "${tresc_alarmu}" == *'kod: 40'* ]]; then
  sprawdz "alarm niesie etap i kod" "tak" "tak"
else
  sprawdz "alarm niesie etap i kod" "tak" "nie: ${tresc_alarmu}"
fi

for tajne in 'tajne-haslo-bazy' 'sekret-bucketu-r2' 'kuking-kopie' 'railway.internal'; do
  if [[ "${tresc_alarmu}" == *"${tajne}"* ]]; then
    sprawdz "alarm nie zawiera „${tajne}\"" "brak" "JEST"
  else
    sprawdz "alarm nie zawiera „${tajne}\"" "brak" "brak"
  fi
done

# Etap poza listą zamkniętą nie może przemycić dowolnego tekstu w treści.
zloz_alarm 'wyjatek: duplicate key (email)=(ktos@example.com)' 40 "${PLIK_ALARMU}"
tresc_alarmu="$(cat "${PLIK_ALARMU}")"
if [[ "${tresc_alarmu}" == *'example.com'* ]]; then
  sprawdz "etap poza listą nie przemyca tekstu" "brak" "JEST"
else
  sprawdz "etap poza listą nie przemyca tekstu" "brak" "brak"
fi
if [[ "${tresc_alarmu}" == *'etap: nieznany'* ]]; then
  sprawdz "etap poza listą staje się „nieznany\"" "tak" "tak"
else
  sprawdz "etap poza listą staje się „nieznany\"" "tak" "nie"
fi

# Kod błędu też. `getCode()` w PHP potrafi nieść dowolny łańcuch — tutaj
# to samo ryzyko wchodzi drugim argumentem.
zloz_alarm zrzut 'SQLSTATE[23505] Key (email)=(ktos@example.com)' "${PLIK_ALARMU}"
tresc_alarmu="$(cat "${PLIK_ALARMU}")"
if [[ "${tresc_alarmu}" == *'example.com'* ]]; then
  sprawdz "kod poza kształtem liczby nie przemyca tekstu" "brak" "JEST"
else
  sprawdz "kod poza kształtem liczby nie przemyca tekstu" "brak" "brak"
fi

rm -f "${PLIK_ALARMU}"

# Brak webhooka NIE MOŻE przewrócić przebiegu — kopia jest ważniejsza niż
# powiadomienie o niej. Ale musi być o tym GŁOŚNO w logu.
wynik="$(
  wczytaj
  unset KOPIA_WEBHOOK_URL
  if alarm zrzut 40 2>&1 | grep -q 'alarm nie wyszedł'; then echo 'ostrzega'; else echo 'cicho'; fi
)"
sprawdz "brak webhooka daje ostrzeżenie w logu" "ostrzega" "${wynik}"

# =============================================================================
echo "── Bramka zgodności wersji pg_dump ──"
# =============================================================================
#
#  `pg_dump` starszy niż serwer ODMAWIA pracy. To nie jest ostrzeżenie —
#  to brak kopii. Skrypt musi to zauważyć SAM, przy każdym przebiegu,
#  i skończyć alarmem, a nie ciszą.

# UWAGA NA ZASIĘG DYNAMICZNY W BASHU — pierwsza wersja tego testu miała tu
# błąd, który dawał FAŁSZYWĄ CZERWIEŃ, i warto go tu nazwać, bo wróci.
# Zmienne podstawionego `psql` nie mogą nazywać się tak jak zmienne lokalne
# testowanej funkcji: `sprawdz_wersje` deklaruje `local numer_serwera` PRZED
# wywołaniem `psql`, więc podstawiony `psql` czytał tamten, jeszcze pusty
# lokalny, a nie wartość z testu. Stąd wielkie litery i przedrostek.
wersja_wynik() {
  local TESTOWY_NUMER_SERWERA="$1" TESTOWA_WERSJA_KLIENTA="$2"
  (
    wczytaj
    export DB_URL='postgresql://u:p@postgres.railway.internal:5432/railway'
    psql() { printf '%s\n' "${TESTOWY_NUMER_SERWERA}"; }
    pg_dump() { printf 'pg_dump (PostgreSQL) %s\n' "${TESTOWA_WERSJA_KLIENTA}"; }
    alarm() { printf 'ALARM %s %s\n' "$1" "$2"; }
    # Wywołanie w OSOBNEJ podpowłoce: `padnij` kończy się `exit`, więc bez
    # tego zagnieżdżenia `printf` niżej nigdy by się nie wykonał, a test
    # porównywałby pusty łańcuch z pustym łańcuchem — czyli nic.
    ( sprawdz_wersje >/dev/null 2>/dev/null )
    printf 'kod=%s' "$?"
  )
}

sprawdz "klient 16 wobec serwera 18 przerywa przebieg" "kod=22" "$(wersja_wynik 180001 16.13)"
sprawdz "klient 18 wobec serwera 18 przechodzi" "kod=0" "$(wersja_wynik 180001 18.1)"
sprawdz "klient 19 wobec serwera 18 przechodzi (z ostrzeżeniem)" "kod=0" "$(wersja_wynik 180001 19.0)"
sprawdz "brak odpowiedzi z bazy przerywa przebieg" "kod=20" "$(wersja_wynik '' 18.1)"

# Niezgodność wersji musi ALARMOWAĆ, nie tylko wyjść niezerowo — w panelu
# Railway nieudany cron potrafi wisieć niezauważony tygodniami.
wynik="$(
  wczytaj
  export DB_URL='postgresql://u:p@postgres.railway.internal:5432/railway'
  psql() { printf '180001\n'; }
  pg_dump() { printf 'pg_dump (PostgreSQL) 16.13\n'; }
  alarm() { printf 'ALARM-%s\n' "$1"; }
  sprawdz_wersje 2>/dev/null | grep '^ALARM-'
)"
sprawdz "niezgodność wersji wysyła alarm etapu „wersja\"" "ALARM-wersja" "${wynik}"

# =============================================================================
echo "── Retencja: nigdy poniżej minimum ──"
# =============================================================================
#
#  Bez dolnej granicy wystarczyłaby jedna przerwa w działaniu serwisu dłuższa
#  niż okno retencji, żeby przebieg wznowiony po niej skasował WSZYSTKIE
#  kopie, jakie jeszcze były — bo każda byłaby „za stara". Kasowanie
#  ostatniej kopii to nie porządki, to utrata danych.

retencja_wynik() {
  local dni_wstecz_najstarszej="$1" ile_kopii="$2" minimum="$3" retencja_dni="$4"
  (
    wczytaj
    export KOPIA_MINIMUM_KOPII="${minimum}" KOPIA_RETENCJA_DNI="${retencja_dni}"
    MINIMUM_KOPII="${minimum}"
    RETENCJA_DNI="${retencja_dni}"
    PREFIKS='baza/'

    # Klucze udające zawartość bucketu: co dobę jedna kopia, najstarsza
    # `dni_wstecz_najstarszej` dni temu.
    s3_lista_kluczy() {
      local i
      for ((i = 0; i < ile_kopii; i++)); do
        printf 'baza/kuking-%sZ.dump.cms\n' \
          "$(date -u -d "$((dni_wstecz_najstarszej - i)) days ago" +%Y%m%d-%H%M%S)"
      done
    }
    s3_zadanie() {
      [[ "$1" == 'DELETE' ]] && printf 'DELETE %s\n' "$2"
      return 0
    }
    retencja 2>/dev/null | grep -c '^DELETE .*\.dump\.cms$'
  )
}

# 10 kopii, wszystkie starsze niż 30 dni, minimum 7 → kasujemy 3, nie 10.
sprawdz "10 starych kopii przy minimum 7 kasuje 3" "3" "$(retencja_wynik 60 10 7 30)"
# 5 kopii, wszystkie stare, minimum 7 → nie kasujemy nic.
sprawdz "5 starych kopii przy minimum 7 nie kasuje nic" "0" "$(retencja_wynik 60 5 7 30)"
# 20 kopii z ostatnich 20 dni, retencja 30 dni → nic nie jest za stare.
sprawdz "kopie młodsze od progu zostają" "0" "$(retencja_wynik 19 20 7 30)"
# 40 kopii dziennych, najstarsza 39 dni temu. Starszych NIŻ 30 dni jest
# dziewięć (39…31 dni temu; kopia dokładnie 30-dniowa jeszcze zostaje),
# minimum 7 nie jest zagrożone → kasujemy dziewięć.
sprawdz "kasujemy dokładnie to, co przekroczyło retencję" "9" "$(retencja_wynik 39 40 7 30)"

# Skasowanie szyfrogramu bez jego `.meta` zostawiałoby w buckecie sieroty,
# które z czasem przestają dać się z czymkolwiek powiązać.
wynik="$(
  wczytaj
  MINIMUM_KOPII=0
  RETENCJA_DNI=1
  PREFIKS='baza/'
  s3_lista_kluczy() { printf 'baza/kuking-20200101-000000Z.dump.cms\n'; }
  s3_zadanie() { [[ "$1" == 'DELETE' ]] && printf '%s\n' "$2"; return 0; }
  retencja 2>/dev/null | grep -c '\.meta$'
)"
sprawdz "razem z kopią ginie jej plik .meta" "1" "${wynik}"

# =============================================================================
echo "── Wysyłka i POTWIERDZENIE, że obiekt naprawdę tam jest ──"
# =============================================================================
#
#  „PUT zwrócił 200" i „w buckecie leży plik tego rozmiaru" to dwie różne
#  rzeczy. Różnica między nimi to ta sama klasa usterki, która kosztowała ten
#  projekt cały dzień przy poczcie i zielony krok `redeploy` w workflow
#  (`OperacjeWdrozeniaCelujaWIstniejacySerwisTest`): narzędzie mówi
#  „zrobione", a nie zrobiło nic. Przy kopii bazy oznaczałoby to fałszywy
#  spokój do dnia awarii.

wysylka_wynik() {
  local kod_put="$1" kod_head="$2" rozmiar_w_buckecie="$3"
  (
    wczytaj
    katalog="$(mktemp -d)"
    trap 'rm -rf "${katalog}"' EXIT
    KATALOG_ROBOCZY="${katalog}"
    PREFIKS='baza/'
    ZNACZNIK='20260909-021700Z'
    PLIK_SZYFROGRAMU="${katalog}/szyfrogram"
    PLIK_META="${katalog}/meta"
    printf 'x' >"${PLIK_SZYFROGRAMU}"
    printf 'y' >"${PLIK_META}"
    ROZMIAR_SZYFROGRAMU=1000
    alarm() { printf 'ALARM %s %s\n' "$1" "$2" >&2; }

    s3_zadanie() {
      case "$1" in
        PUT)
          S3_KOD="${kod_put}"
          [[ "${kod_put}" == 2* ]] && return 0
          return 1
          ;;
        HEAD)
          S3_KOD="${kod_head}"
          printf 'Content-Length: %s\r\n' "${rozmiar_w_buckecie}" >"${5}"
          [[ "${kod_head}" == 2* ]] && return 0
          return 1
          ;;
      esac
      return 0
    }

    ( wyslij >/dev/null 2>&1 && potwierdz >/dev/null 2>&1 )
    printf 'kod=%s' "$?"
  )
}

sprawdz "udana wysyłka z potwierdzonym rozmiarem przechodzi" "kod=0" "$(wysylka_wynik 200 200 1000)"
sprawdz "odrzucona wysyłka przerywa przebieg" "kod=70" "$(wysylka_wynik 403 200 1000)"
sprawdz "brak obiektu po wysyłce przerywa przebieg" "kod=80" "$(wysylka_wynik 200 404 0)"
sprawdz "inny rozmiar w buckecie niż wysłany przerywa przebieg" "kod=81" "$(wysylka_wynik 200 200 512)"

# Nazwa obiektu musi nieść znacznik czasu w formacie SORTOWALNYM — na tym
# stoi i wybór najnowszej kopii, i cała arytmetyka retencji, i czujka
# w aplikacji (`App\Domain\Kopie\StanKopiiBazy`).
wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT
  KATALOG_ROBOCZY="${katalog}"
  PREFIKS='baza/'
  ZNACZNIK='20260909-021700Z'
  PLIK_SZYFROGRAMU="${katalog}/s"; printf 'x' >"${PLIK_SZYFROGRAMU}"
  PLIK_META="${katalog}/m"; printf 'y' >"${PLIK_META}"
  s3_zadanie() { [[ "$1" == 'PUT' ]] && printf '%s\n' "$2"; S3_KOD=200; return 0; }
  wyslij 2>/dev/null | tr '\n' ' '
)"
sprawdz "obiekt i jego .meta lądują pod sortowalnymi nazwami" \
  "baza/kuking-20260909-021700Z.dump.cms baza/kuking-20260909-021700Z.meta " "${wynik}"

# =============================================================================
echo "── Poświadczenia i dane osobowe ──"
# =============================================================================

wynik="$(wczytaj; bez_hasla 'postgresql://kuking:bardzo-tajne@postgres.railway.internal:5432/railway')"
sprawdz "hasło bazy nie trafia do logu" \
  "postgresql://kuking:***@postgres.railway.internal:5432/railway" "${wynik}"

# Publiczny adres bazy = komplet danych osobowych przez publiczny internet
# przy każdym przebiegu, i nic by o tym nie powiedziało (#193: „po sieci
# wewnętrznej Railwaya, nie po publicznym adresie").
publiczny_wynik() {
  (
    wczytaj
    export DB_URL="$1"
    export KOPIA_S3_ENDPOINT='https://konto.r2.cloudflarestorage.com'
    export KOPIA_S3_BUCKET='b' KOPIA_S3_KLUCZ='k' KOPIA_S3_SEKRET='s'
    export KOPIA_KLUCZ_PUBLICZNY='x'
    alarm() { :; }
    ( sprawdz_srodowisko >/dev/null 2>&1 )
    printf 'kod=%s' "$?"
  )
}
sprawdz "publiczny adres bazy przerywa przebieg" "kod=12" \
  "$(publiczny_wynik 'postgresql://u:p@monorail.proxy.rlwy.net:34567/railway')"
sprawdz "adres w sieci wewnętrznej przechodzi" "kod=0" \
  "$(publiczny_wynik 'postgresql://u:p@postgres.railway.internal:5432/railway')"

# Zrzut jawny musi zginąć z dysku PRZED wysyłką, a nie „przy sprzątaniu".
bez_komentarzy() { sed 's/[[:space:]]*#.*$//' "$1"; }
linia_rm="$(bez_komentarzy "${SKRYPT}" | grep -n 'rm -f "${PLIK_ZRZUTU}"' | head -1 | cut -d: -f1)"
linia_wyslij="$(bez_komentarzy "${SKRYPT}" | grep -n '^wyslij() {' | head -1 | cut -d: -f1)"
if [[ -n "${linia_rm}" && -n "${linia_wyslij}" ]] && ((linia_rm < linia_wyslij)); then
  sprawdz "zrzut jawny ginie przed wysyłką" "tak" "tak"
else
  sprawdz "zrzut jawny ginie przed wysyłką" "tak" "nie"
fi

# =============================================================================
echo "── Szyfrowanie: pełna droga tam i z powrotem ──"
# =============================================================================
#
#  NAJWAŻNIEJSZA OBIETNICA CAŁEJ TEJ WARSTWY: „ten plik da się odczytać
#  z samym kluczem prywatnym i samym `openssl`, bez dostępu do aplikacji,
#  bez Railwaya i bez niczego, co trzeba doinstalować".
#
#  Obietnica bez testu jest obietnicą. Poniżej: para kluczy powstaje tu
#  i teraz, `szyfruj()` — funkcja produkcyjna, nie jej kopia — szyfruje plik
#  KLUCZEM PUBLICZNYM, a potem odszyfrowujemy go WYŁĄCZNIE kluczem prywatnym
#  i porównujemy bajty. Jeśli kiedyś ktoś zamieni CMS na cokolwiek innego,
#  ten test powie, że procedura z §7 dokumentu kopii przestała być prawdą.

wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT

  openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes \
    -keyout "${katalog}/prywatny.pem" -out "${katalog}/publiczny.pem" \
    -subj '/CN=Kuking test kopii' >/dev/null 2>&1

  # „Zrzut" — byle jakie bajty, ale dokładnie te same mają wrócić.
  head -c 200000 /dev/urandom >"${katalog}/zrzut.dump"
  wzorzec="$(s3_sha256_pliku "${katalog}/zrzut.dump")"

  KATALOG_ROBOCZY="${katalog}"
  PLIK_ZRZUTU="${katalog}/zrzut.dump"
  PLIK_SZYFROGRAMU="${katalog}/zrzut.dump.cms"
  PLIK_BLEDU="${katalog}/blad.txt"
  ROZMIAR_JAWNY="$(stat -c %s "${PLIK_ZRZUTU}")"
  KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/publiczny.pem")"

  szyfruj >/dev/null 2>&1 || { printf 'szyfrowanie-padlo'; exit 0; }

  # Zrzut jawny MUSI już nie istnieć — to sprawdza osobny warunek niżej,
  # tu tylko potwierdzamy, że odczyt idzie z szyfrogramu.
  openssl cms -decrypt -binary -inform DER -in "${PLIK_SZYFROGRAMU}" \
    -inkey "${katalog}/prywatny.pem" -out "${katalog}/odtworzony.dump" 2>/dev/null \
    || { printf 'odszyfrowanie-padlo'; exit 0; }

  if [[ "$(s3_sha256_pliku "${katalog}/odtworzony.dump")" == "${wzorzec}" ]]; then
    printf 'bajt-w-bajt'
  else
    printf 'rozjazd'
  fi
)"
sprawdz "zaszyfrowany zrzut wraca bajt w bajt po odszyfrowaniu kluczem prywatnym" \
  "bajt-w-bajt" "${wynik}"

# Certyfikat, którym da się TYLKO zaszyfrować, nie może dać się użyć jako
# klucz do odczytu — inaczej „klucz publiczny w Railwayu" nie znaczyłoby nic.
wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT
  openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes \
    -keyout "${katalog}/prywatny.pem" -out "${katalog}/publiczny.pem" \
    -subj '/CN=Kuking test kopii' >/dev/null 2>&1
  head -c 5000 /dev/urandom >"${katalog}/zrzut.dump"
  KATALOG_ROBOCZY="${katalog}"
  PLIK_ZRZUTU="${katalog}/zrzut.dump"
  PLIK_SZYFROGRAMU="${katalog}/zrzut.dump.cms"
  PLIK_BLEDU="${katalog}/blad.txt"
  ROZMIAR_JAWNY="$(stat -c %s "${PLIK_ZRZUTU}")"
  KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/publiczny.pem")"
  szyfruj >/dev/null 2>&1
  if openssl cms -decrypt -binary -inform DER -in "${PLIK_SZYFROGRAMU}" \
    -inkey "${katalog}/publiczny.pem" -out /dev/null 2>/dev/null; then
    printf 'odszyfrowal'
  else
    printf 'odmowil'
  fi
)"
sprawdz "sam certyfikat nie odszyfrowuje niczego" "odmowil" "${wynik}"

# Klucz publiczny przyjmowany też w base64 — panele zmiennych środowiskowych
# bywają nieprzewidywalne przy wartościach wieloliniowych.
wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT
  openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes \
    -keyout "${katalog}/prywatny.pem" -out "${katalog}/publiczny.pem" \
    -subj '/CN=Kuking test kopii' >/dev/null 2>&1
  head -c 5000 /dev/urandom >"${katalog}/zrzut.dump"
  KATALOG_ROBOCZY="${katalog}"
  PLIK_ZRZUTU="${katalog}/zrzut.dump"
  PLIK_SZYFROGRAMU="${katalog}/zrzut.dump.cms"
  PLIK_BLEDU="${katalog}/blad.txt"
  ROZMIAR_JAWNY="$(stat -c %s "${PLIK_ZRZUTU}")"
  KOPIA_KLUCZ_PUBLICZNY="$(base64 -w0 <"${katalog}/publiczny.pem")"
  if szyfruj >/dev/null 2>&1; then printf 'przyjal'; else printf 'odrzucil'; fi
)"
sprawdz "klucz publiczny w base64 też jest przyjmowany" "przyjal" "${wynik}"

# Wartość, która nie jest certyfikatem, MUSI zatrzymać przebieg PRZED
# wysyłką — inaczej w buckecie wylądowałby plik, którego nikt nie odczyta.
wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT
  head -c 100 /dev/urandom >"${katalog}/zrzut.dump"
  KATALOG_ROBOCZY="${katalog}"
  PLIK_ZRZUTU="${katalog}/zrzut.dump"
  PLIK_SZYFROGRAMU="${katalog}/zrzut.dump.cms"
  PLIK_BLEDU="${katalog}/blad.txt"
  ROZMIAR_JAWNY=100
  KOPIA_KLUCZ_PUBLICZNY='to-nie-jest-certyfikat'
  alarm() { :; }
  ( szyfruj >/dev/null 2>&1 )
  printf 'kod=%s' "$?"
)"
sprawdz "wartość, która nie jest certyfikatem, przerywa przebieg" "kod=60" "${wynik}"

# =============================================================================
echo "── Obraz kopii (docker/kopia/Dockerfile) ──"
# =============================================================================

if bez_komentarzy "${DOCKERFILE_KOPII}" | grep -E '^FROM postgres:18($|[[:space:]])' >/dev/null; then
  sprawdz "obraz bazowy niesie pg_dump 18 (zgodny z serwerem)" "tak" "tak"
else
  sprawdz "obraz bazowy niesie pg_dump 18 (zgodny z serwerem)" "tak" "nie"
fi

# Obraz kopii NIE MOŻE być obrazem aplikacji: cały sens D-043 polega na tym,
# że tu nie ma PHP, więc nie ma hardeningu do osłabienia.
if bez_komentarzy "${DOCKERFILE_KOPII}" | grep -Ei 'php|frankenphp|composer' >/dev/null; then
  sprawdz "w obrazie kopii nie ma PHP" "brak" "JEST"
else
  sprawdz "w obrazie kopii nie ma PHP" "brak" "brak"
fi

if bez_komentarzy "${DOCKERFILE_KOPII}" | grep -E '^USER postgres' >/dev/null; then
  sprawdz "kontener kopii nie chodzi jako root" "tak" "tak"
else
  sprawdz "kontener kopii nie chodzi jako root" "tak" "nie"
fi

# ODWROTNA STRONA TEJ SAMEJ DECYZJI: skoro zrzut przeniesiono do osobnego
# obrazu, to hardening w obrazie aplikacji nie ma już ŻADNEGO powodu, żeby
# zniknąć. Ten test pilnuje, żeby przy okazji „porządków" nie wrócił
# `proc_open` do php.ini — bo wtedy ktoś przeniesie zrzut z powrotem.
if grep -E '^disable_functions=.*proc_open' "${PHP_INI}" >/dev/null; then
  sprawdz "aplikacja nadal ma wyłączone proc_open" "tak" "tak"
else
  sprawdz "aplikacja nadal ma wyłączone proc_open" "tak" "nie"
fi

# =============================================================================
echo
if ((oblane > 0)); then
  printf '\033[0;31mOblane: %d, zdane: %d\033[0m\n' "${oblane}" "${zdane}"
  exit 1
fi
printf '\033[0;32mWszystkie %d testów kopii bazy przechodzi.\033[0m\n' "${zdane}"
printf 'Uwaga: to NIE dowodzi, że kopia działa — nie ma tu ani R2, ani serwera 18.\n'
