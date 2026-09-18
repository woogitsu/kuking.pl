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
#  bramka zgodności wersji `pg_dump`, weryfikacja zrzutu na PRAWDZIWYM
#  archiwum `pg_dump --format=custom`, retencja wobec PRAWDZIWEGO serwera
#  HTTP mówiącego ListObjectsV2, maskowanie hasła w logu.
#
#  CO JEST TU PRAWDZIWE, A CO PODSTAWIONE — i to jest ważne rozróżnienie
#  Prawdziwe: `pg_restore` i archiwum, które czyta (`tests/skrypty/dane/`);
#  `curl` po prawdziwym gnieździe TCP do serwera próbnego z tego pliku
#  (urwane ciało odpowiedzi, `IsTruncated`, listowanie z rozmiarami,
#  pliki `.meta`, żądania `DELETE`); `openssl` i prawdziwa para kluczy.
#  Podstawione: `pg_dump` (nie ma tu serwera, do którego miałby się
#  połączyć) i pojedyncze wywołania `s3_zadanie` tam, gdzie mierzymy
#  REAKCJĘ wołającego na umówiony kod, a nie sam kod.
#
#  NIEsprawdzane, bo wymaga prawdziwych usług: rozmowa z R2, prawdziwy
#  `pg_dump` na PostgreSQL 18, zbudowanie obrazu. To jest wypisane wprost,
#  żeby zielony wynik tego pliku nie był czytany jako „kopia działa".
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
echo "── Retencja: chroni POTWIERDZONE kopie, nie pasujące nazwy (#193) ──"
# =============================================================================
#
#  Bez dolnej granicy wystarczyłaby jedna przerwa w działaniu serwisu dłuższa
#  niż okno retencji, żeby przebieg wznowiony po niej skasował WSZYSTKIE
#  kopie, jakie jeszcze były — bo każda byłaby „za stara". Kasowanie
#  ostatniej kopii to nie porządki, to utrata danych.
#
#  USTERKA ODTWORZONA 18.09.2026 NA PRAWDZIWYM ENDPOINCIE S3 (MinIO)
#  -----------------------------------------------------------------
#  `MINIMUM_KOPII` chroniło LICZBĘ KLUCZY. W buckecie leżała jedna poprawna
#  kopia (najstarsza, 172 162 B) i nad nią dziewięć obiektów ZEROWEJ długości
#  po nieudanych wysyłkach. Retencja naliczyła dziesięć „kopii", uznała, że
#  trzy najstarsze wolno skasować — i skasowała jedyną, która cokolwiek
#  zawierała. W logu stało „retencja: skasowano 3", a w buckecie zostało
#  siedem pustych plików i zero kopii.
#
#  DLACZEGO PRAWDZIWY SERWER, A NIE ATRAPA `s3_lista_kluczy`
#  --------------------------------------------------------
#  Bo poprzednia wersja tego bloku podstawiała `s3_lista_kluczy` funkcją
#  wypisującą same nazwy — czyli fikstura NIE MIAŁA JAK mieć rozmiaru, a
#  rozmiar jest tu całą treścią usterki. Test przechodził przez cały czas
#  trwania błędu i przeszedłby nadal, gdyby nikt go nie przepisał. Poniżej
#  leci prawdziwy `curl` po prawdziwym gnieździe, po prawdziwą odpowiedź
#  ListObjectsV2 z elementami `<Size>` i po prawdziwe pliki `.meta`,
#  a `DELETE`, które retencja wyśle, serwer zapisuje do pliku — więc asercja
#  patrzy na to, co NAPRAWDĘ poszło na drut.

if ! command -v python3 >/dev/null 2>&1; then
  sprawdz "serwer próbny do testów retencji" "python3 jest" "python3 BRAK"
else
  SERWER_RET_PY="$(mktemp)"
  cat >"${SERWER_RET_PY}" <<'PYTON'
import socket, sys, threading
PORT = int(sys.argv[1]); TRYB = sys.argv[2]; DZIENNIK = sys.argv[3]
BLOKADA = threading.Lock()

# (znacznik, rozmiar w buckecie, rozmiar zapisany w .meta albo None = brak .meta)
SCENARIUSZE = {
    # Jedyna niepusta kopia jest NAJSTARSZA, nad nia dziewiec obiektow 0 B.
    'puste-obok': [('20200101-020000Z', 172162, 172162)]
                  + [('202001%02d-020000Z' % d, 0, 0) for d in range(2, 11)],
    # Dziesiec kopii, kazda potwierdzona — kontrola DODATNIA.
    'dziesiec-dobrych': [('202001%02d-020000Z' % d, 5000, 5000) for d in range(1, 11)],
    # Obiekt, ktorego rozmiar kloci sie z jego wlasnym .meta (zostawialo
    # po sobie `potwierdz()` przy kodzie 81).
    'niezgodny': [('20200101-020000Z', 5000, 5000), ('20200102-020000Z', 900, 250000)],
    # Szyfrogram bez zadnych papierow.
    'bez-meta': [('20200101-020000Z', 5000, None), ('20200102-020000Z', 5000, 5000)],
}

def lista():
    w = [b'<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>'
         b'<Name>k</Name><Prefix>baza/</Prefix><MaxKeys>1000</MaxKeys>'
         b'<IsTruncated>false</IsTruncated>']
    for znacznik, rozmiar, meta in SCENARIUSZE[TRYB]:
        w.append(b'<Contents><Key>baza/kuking-%s.dump.cms</Key><Size>%d</Size>'
                 b'<StorageClass>STANDARD</StorageClass></Contents>'
                 % (znacznik.encode(), rozmiar))
        if meta is not None:
            tresc = tresc_meta(znacznik, meta)
            w.append(b'<Contents><Key>baza/kuking-%s.meta</Key><Size>%d</Size>'
                     b'<StorageClass>STANDARD</StorageClass></Contents>'
                     % (znacznik.encode(), len(tresc)))
    w.append(b'</ListBucketResult>')
    return b''.join(w)

def tresc_meta(znacznik, rozmiar):
    return (b'# Kuking.pl - metadane zrzutu bazy. Bez danych osobowych.\n'
            b'znacznik: %s\nrozmiar_szyfrogramu_bajty: %d\n'
            % (znacznik.encode(), rozmiar))

def meta_dla(klucz):
    for znacznik, _rozmiar, meta in SCENARIUSZE[TRYB]:
        if meta is not None and klucz == 'baza/kuking-%s.meta' % znacznik:
            return tresc_meta(znacznik, meta)
    return None

def odpowiedz(c, kod, cialo=b''):
    c.sendall(b'HTTP/1.1 %d X\r\nContent-Length: %d\r\nConnection: close\r\n\r\n'
              % (kod, len(cialo)) + cialo)

def obsluz(c):
    try:
        dane = b''
        while b'\r\n\r\n' not in dane:
            kawalek = c.recv(65536)
            if not kawalek:
                return
            dane += kawalek
        metoda, sciezka = dane.split(b' ')[0].decode(), dane.split(b' ')[1].decode()
        klucz = sciezka.split('?')[0]
        klucz = klucz[3:] if klucz.startswith('/k/') else ''
        if metoda == 'GET' and 'list-type=2' in sciezka:
            odpowiedz(c, 200, lista())
        elif metoda == 'GET' and klucz.endswith('.meta'):
            tresc = meta_dla(klucz)
            odpowiedz(c, 200, tresc) if tresc else odpowiedz(c, 404)
        elif metoda == 'DELETE':
            with BLOKADA:
                with open(DZIENNIK, 'a') as f:
                    f.write(klucz + '\n')
            odpowiedz(c, 204)
        else:
            odpowiedz(c, 404)
    except Exception:
        pass
    finally:
        try: c.close()
        except Exception: pass

s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('127.0.0.1', PORT)); s.listen(16)
print('gotowy', flush=True)
while True:
    k, _ = s.accept()
    threading.Thread(target=obsluz, args=(k,), daemon=True).start()
PYTON

  # Port z zakresu nieużywanego przez resztę projektu — inny niż w bloku
  # o urwanej odpowiedzi, żeby oba dały się kiedyś puścić równolegle.
  PORT_RETENCJI=59789

  # retencja_na_serwerze <tryb> <minimum> <potwierdzona> — wypisuje
  # „skasowane=<klucze po przecinku>|log=<jedna linia>".
  retencja_na_serwerze() {
    local tryb="$1" minimum="$2" potwierdzona="${3:-1}"
    local dziennik; dziennik="$(mktemp)"
    : >"${dziennik}"

    python3 "${SERWER_RET_PY}" "${PORT_RETENCJI}" "${tryb}" "${dziennik}" >/dev/null 2>&1 &
    local pid=$!
    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
      (exec 3<>"/dev/tcp/127.0.0.1/${PORT_RETENCJI}") 2>/dev/null && break
      sleep 0.2
    done

    local log_retencji
    log_retencji="$(
      wczytaj
      export KOPIA_S3_ENDPOINT="http://127.0.0.1:${PORT_RETENCJI}"
      export KOPIA_S3_BUCKET=k KOPIA_S3_KLUCZ=x KOPIA_S3_SEKRET=y
      export KOPIA_S3_REGION=us-east-1 KOPIA_S3_TIMEOUT=10
      KATALOG_ROBOCZY="$(mktemp -d)"
      PREFIKS='baza/'
      MINIMUM_KOPII="${minimum}"
      RETENCJA_DNI=30
      KOPIA_POTWIERDZONA="${potwierdzona}"
      alarm() { :; }
      retencja 2>&1 >/dev/null
      rm -rf "${KATALOG_ROBOCZY}"
    )"

    kill "${pid}" 2>/dev/null
    wait "${pid}" 2>/dev/null

    printf 'skasowane=%s|log=%s' \
      "$(sort "${dziennik}" | sed 's#baza/kuking-##' | tr '\n' ',' | sed 's/,$//')" \
      "$(printf '%s' "${log_retencji}" | tr '\n' ' ')"
    rm -f "${dziennik}"
  }

  # 1. SEDNO REGRESJI. Dziesięć kluczy, ale kopia jest JEDNA — i jest
  #    najstarsza. Przed poprawką retencja kasowała trzy najstarsze, czyli
  #    ją. Teraz nie kasuje niczego, bo potwierdzona jest jedna, a minimum
  #    wynosi siedem.
  wynik="$(retencja_na_serwerze puste-obok 7)"
  sprawdz "dziewięć obiektów 0 B nie wypiera jedynej potwierdzonej kopii" \
    "skasowane=" "${wynik%%|*}"

  wynik_log="${wynik#*|log=}"
  if [[ "${wynik_log}" == *'kopii POTWIERDZONYCH w buckecie: 1'* ]]; then
    sprawdz "…i log mówi, że potwierdzona jest JEDNA, nie dziesięć" "tak" "tak"
  else
    sprawdz "…i log mówi, że potwierdzona jest JEDNA, nie dziesięć" "tak" "nie: ${wynik_log}"
  fi

  if [[ "${wynik_log}" == *'BEZ POTWIERDZENIA: 9'* && "${wynik_log}" == *'nie kasuję'* ]]; then
    sprawdz "…a o dziewięciu bez potwierdzenia mówi wprost, że ich NIE kasuje" "tak" "tak"
  else
    sprawdz "…a o dziewięciu bez potwierdzenia mówi wprost, że ich NIE kasuje" \
      "tak" "nie: ${wynik_log}"
  fi

  # 2. KONTROLA DODATNIA — bez niej „nigdy nic nie kasuj" przechodziłoby
  #    wszystkie asercje wyżej (pułapka 4 z docs/PULAPKI_TESTOW.md).
  #    Dziesięć POTWIERDZONYCH kopii z 2020 roku, minimum 7 → giną trzy
  #    najstarsze, każda razem ze swoim `.meta`.
  wynik="$(retencja_na_serwerze dziesiec-dobrych 7)"
  sprawdz "dziesięć potwierdzonych kopii przy minimum 7 — giną trzy najstarsze" \
    "skasowane=20200101-020000Z.dump.cms,20200101-020000Z.meta,20200102-020000Z.dump.cms,20200102-020000Z.meta,20200103-020000Z.dump.cms,20200103-020000Z.meta" \
    "${wynik%%|*}"

  # 3. Obiekt, którego rozmiar kłóci się z jego własnym `.meta`, nie jest
  #    kopią — i nie wolno mu wypchnąć tej jednej, która jest.
  wynik="$(retencja_na_serwerze niezgodny 1)"
  sprawdz "obiekt niezgodny z własnym .meta nie liczy się jako kopia" \
    "skasowane=" "${wynik%%|*}"
  wynik_log="${wynik#*|log=}"
  if [[ "${wynik_log}" == *'w buckecie 900 B, .meta mówi 250000 B'* ]]; then
    sprawdz "…a log nazywa dokładnie, co się nie zgadza" "tak" "tak"
  else
    sprawdz "…a log nazywa dokładnie, co się nie zgadza" "tak" "nie: ${wynik_log}"
  fi

  # 4. Szyfrogram bez `.meta` to obiekt bez dowodu — ani dobry, ani do
  #    skasowania. Dotyczy to tak samo obiektów sprzed tej zmiany: nie ma
  #    tu żadnej daty granicznej ani taryfy ulgowej.
  wynik="$(retencja_na_serwerze bez-meta 1)"
  sprawdz "szyfrogram bez .meta nie jest kasowany po cichu" "skasowane=" "${wynik%%|*}"
  wynik_log="${wynik#*|log=}"
  if [[ "${wynik_log}" == *'brak pliku .meta'* ]]; then
    sprawdz "…tylko wypisany jako obiekt bez potwierdzenia" "tak" "tak"
  else
    sprawdz "…tylko wypisany jako obiekt bez potwierdzenia" "tak" "nie: ${wynik_log}"
  fi

  # 5. Retencja bez potwierdzonej kopii z TEGO przebiegu nie rusza w ogóle.
  #    Inaczej nieudany przebieg zabierałby stare kopie, nie dokładając
  #    żadnej nowej.
  wynik="$(retencja_na_serwerze dziesiec-dobrych 7 0)"
  sprawdz "bez potwierdzonej kopii tego przebiegu retencja nie kasuje NICZEGO" \
    "skasowane=" "${wynik%%|*}"

  # 6. Dwa footguny z konfiguracji, oba zmierzone 18.09.2026 na MinIO:
  #    `KOPIA_MINIMUM_KOPII=0` czyściło bucket DO ZERA, a wartość niebędąca
  #    liczbą wywalała przebieg na `unbound variable` PO udanej kopii.
  wynik="$(retencja_na_serwerze dziesiec-dobrych 0)"
  sprawdz "KOPIA_MINIMUM_KOPII=0 nie czyści bucketu" "skasowane=" "${wynik%%|*}"

  wynik="$(retencja_na_serwerze dziesiec-dobrych siedem)"
  sprawdz "KOPIA_MINIMUM_KOPII=„siedem\" nie kasuje niczego" "skasowane=" "${wynik%%|*}"
  wynik_log="${wynik#*|log=}"
  if [[ "${wynik_log}" == *'nie jest liczbą'* && "${wynik_log}" != *'unbound variable'* ]]; then
    sprawdz "…tylko mówi, że to nie jest liczba (a nie „unbound variable\")" "tak" "tak"
  else
    sprawdz "…tylko mówi, że to nie jest liczba (a nie „unbound variable\")" \
      "tak" "nie: ${wynik_log}"
  fi

  rm -f "${SERWER_RET_PY}"
fi

# Ta sama bramka od strony KROKU 0: zła liczba w panelu ma zatrzymać przebieg
# ZANIM powstanie zrzut, a nie dopiero w retencji.
konfiguracja_wynik() { # konfiguracja_wynik <zmienna> <wartość>
  (
    # Wartości domyślne (`MINIMUM_KOPII="${KOPIA_MINIMUM_KOPII:-7}"`) czyta
    # nagłówek skryptu przy wczytaniu, więc zmienna MUSI stać przed nim.
    export DB_URL='postgresql://u:p@postgres.railway.internal:5432/railway'
    export KOPIA_S3_ENDPOINT=x KOPIA_S3_BUCKET=y KOPIA_S3_KLUCZ=z
    export KOPIA_S3_SEKRET=w KOPIA_KLUCZ_PUBLICZNY=c
    export "$1=$2"
    wczytaj
    alarm() { :; }
    (sprawdz_srodowisko >/dev/null 2>&1)
    printf 'kod=%s' "$?"
  )
}

sprawdz "KOPIA_MINIMUM_KOPII=0 zatrzymuje przebieg na starcie" \
  "kod=13" "$(konfiguracja_wynik KOPIA_MINIMUM_KOPII 0)"
sprawdz "KOPIA_MINIMUM_KOPII=„siedem\" zatrzymuje przebieg na starcie" \
  "kod=13" "$(konfiguracja_wynik KOPIA_MINIMUM_KOPII siedem)"
sprawdz "KOPIA_RETENCJA_DNI=0 zatrzymuje przebieg na starcie" \
  "kod=13" "$(konfiguracja_wynik KOPIA_RETENCJA_DNI 0)"
# Kontrola dodatnia: poprawna wartość ma PRZEJŚĆ tę bramkę. Bez niej
# „odrzucaj wszystko" zdałoby trzy asercje wyżej.
sprawdz "poprawna liczba przechodzi bramkę konfiguracji" \
  "kod=0" "$(konfiguracja_wynik KOPIA_MINIMUM_KOPII 7)"

# =============================================================================
echo "── Kod HTTP z listowania bucketu dożywa do komunikatu (#594) ──"
# =============================================================================
#
#  USTERKA ODTWORZONA 18.09.2026 na PRAWDZIWYM endpoincie S3 (MinIO
#  w kontenerze, ta sama ścieżka co R2). `s3_lista_kluczy` wypisuje klucze
#  na standardowe wyjście, więc oba miejsca wołały ją przez `$( )` — czyli
#  w PODPOWŁOCE. `S3_KOD` ustawia się w tej podpowłoce i ginie razem z nią,
#  więc komunikat „HTTP ${S3_KOD:-brak}" kończył się słowem „brak" ZAWSZE.
#
#  Zmierzone: token bez prawa do bucketu → 403, bucket o złej nazwie → 404.
#  Dwie różne awarie, dwie różne naprawy, jeden nieodróżnialny komunikat
#  i — bo odcisk alarmu liczy się z etapu i kodu wyjścia — jeden
#  nieodróżnialny alarm. Te asercje patrzą na TREŚĆ komunikatu, bo kod
#  wyjścia był poprawny przez cały czas trwania usterki.

# Podstawiamy samo `s3_lista_kluczy` — dokładnie tak, jak zachowuje się
# prawdziwa biblioteka: ustawia `S3_KOD` i zwraca 1.
listowanie_wynik() { # listowanie_wynik <funkcja> <kod HTTP> <kod powrotu>
  local funkcja="$1" kod_http="$2" kod_powrotu="$3"
  (
    wczytaj
    PREFIKS='baza/'
    KATALOG_ROBOCZY="$(mktemp -d)"
    KOPIA_POTWIERDZONA=1
    MINIMUM_KOPII=7
    RETENCJA_DNI=30
    # Dwie funkcje, bo `sprawdz_poprzednia_kopie` pyta o same klucze,
    # a `retencja` o klucze RAZEM z rozmiarami.
    s3_lista_kluczy() {
      S3_KOD="${kod_http}"
      return "${kod_powrotu}"
    }
    s3_lista_obiektow() {
      S3_KOD="${kod_http}"
      return "${kod_powrotu}"
    }
    alarm() { :; }
    ("${funkcja}" 2>&1 >/dev/null)
    rm -rf "${KATALOG_ROBOCZY}"
  )
}

wynik="$(listowanie_wynik sprawdz_poprzednia_kopie 403 1 | grep -c 'HTTP 403')"
sprawdz "brak uprawnień tokenu mówi w logu HTTP 403, nie „brak\"" "1" "${wynik}"

wynik="$(listowanie_wynik sprawdz_poprzednia_kopie 404 1 | grep -c 'HTTP 404')"
sprawdz "nieistniejący bucket mówi w logu HTTP 404, nie „brak\"" "1" "${wynik}"

# Kontrola ujemna wbudowana w zestaw: gdyby ktoś wrócił do `$( )`, ten test
# zobaczyłby słowo „brak" i oblał. Asercja jest napisana wprost na tamten stan.
wynik="$(listowanie_wynik sprawdz_poprzednia_kopie 403 1 | grep -c 'HTTP brak')"
sprawdz "komunikat NIE mówi „HTTP brak\", gdy kod HTTP jest znany" "0" "${wynik}"

wynik="$(listowanie_wynik retencja 403 1 | grep -c 'HTTP 403')"
sprawdz "retencja też podaje kod HTTP listowania" "1" "${wynik}"

# Lista obcięta (`IsTruncated`) to NIE jest błąd HTTP — `s3_lista_kluczy`
# zwraca wtedy 2, `S3_KOD` jest 2xx i mówienie o „HTTP 200" wprowadzałoby
# w błąd. To musi być osobne zdanie, bo i naprawa jest inna.
wynik="$(listowanie_wynik sprawdz_poprzednia_kopie 200 2 | grep -c 'OBCIĘTĄ')"
sprawdz "obcięta lista nazywa się obciętą, a nie błędem HTTP" "1" "${wynik}"

wynik="$(listowanie_wynik retencja 200 2 | grep -c 'nie kasuję niczego')"
sprawdz "retencja przy obciętej liście nie kasuje NICZEGO" "1" "${wynik}"

# Porażka retencji nadal NIE jest porażką kopii — kopia już leży w buckecie.
wynik="$(
  wczytaj
  PREFIKS='baza/'
  KATALOG_ROBOCZY="$(mktemp -d)"
  KOPIA_POTWIERDZONA=1
  MINIMUM_KOPII=7
  RETENCJA_DNI=30
  s3_lista_obiektow() {
    S3_KOD='403'
    return 1
  }
  alarm() { :; }
  retencja >/dev/null 2>&1
  printf 'kod=%s' "$?"
)"
sprawdz "nieudane listowanie w retencji nie przerywa przebiegu" "kod=0" "${wynik}"

# =============================================================================
echo "── Odpowiedź urwana w połowie NIE jest sukcesem (regresja) ──"
# =============================================================================
#
#  USTERKA ODTWORZONA 18.09.2026 wobec PRAWDZIWEGO serwera HTTP.
#
#  `s3_zadanie` decydowała wyłącznie po kodzie HTTP, a kod wyjścia curla
#  wyrzucała przez `|| true`. Status odpowiedzi przychodzi PRZED ciałem, więc
#  zerwane połączenie w połowie ciała daje „200" przy NIEPEŁNYM pliku.
#  Zmierzone: serwer oddał 200 i połowę ListObjectsV2 — `s3_lista_kluczy`
#  zwróciła 0 i CZTERY klucze zamiast dziewięciu, a `sprawdz_poprzednia_kopie`
#  ogłosiła jako najnowszą kopię sprzed czterech dni i zaalarmowała
#  o przestoju, którego nie było.
#
#  `IsTruncated` tego NIE łapie: ten element stoi w odpowiedzi PRZED
#  `<Contents>` (sprawdzone na prawdziwej odpowiedzi serwera S3), więc
#  obcięcie ciała zabiera klucze, a znacznik stronicowania zostawia.
#
#  DLACZEGO PRAWDZIWY SERWER, A NIE ATRAPA `s3_lista_kluczy`
#  Bo atrapa sprawdza reakcję WOŁAJĄCEGO na umówiony kod powrotu i nie dotyka
#  ani jednej linii, która ten kod wylicza. Kontrola ujemna: wycięcie straży
#  `IsTruncated` z `docker/kopia/s3.sh` nie oblewało ani jednego testu, dopóki
#  ten blok nie powstał. Tu leci prawdziwy `curl` po prawdziwym gnieździe.

if ! command -v python3 >/dev/null 2>&1; then
  # Testu, którego nie wykonano, nie liczymy jako zdany — to jest cała
  # zasada tego pliku (patrz nagłówek).
  sprawdz "serwer próbny do testu urwanej odpowiedzi" "python3 jest" "python3 BRAK"
else
  SERWER_PY="$(mktemp)"
  cat >"${SERWER_PY}" <<'PYTON'
import socket, sys, threading
PORT = int(sys.argv[1]); TRYB = sys.argv[2]
# Ksztalt i KOLEJNOSC elementow jak w prawdziwej odpowiedzi ListObjectsV2:
# IsTruncated stoi PRZED Contents.
def cialo(obciety_znacznik):
    return (b'<?xml version="1.0" encoding="UTF-8"?>'
            b'<ListBucketResult><Name>k</Name><Prefix>baza/</Prefix>'
            b'<KeyCount>9</KeyCount><MaxKeys>1000</MaxKeys>'
            + (b'<IsTruncated>true</IsTruncated>' if obciety_znacznik
               else b'<IsTruncated>false</IsTruncated>')
            + b''.join(b'<Contents><Key>baza/kuking-2026091%d-020000Z.dump.cms</Key>'
                       b'<Size>172162</Size></Contents>' % i for i in range(9))
            + b'</ListBucketResult>')
def obsluz(c):
    try:
        c.recv(65536)
        if TRYB == 'urwany':
            b = cialo(False)
            c.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: %d\r\n\r\n' % len(b))
            c.sendall(b[: len(b) // 2])          # polowa ciala i rozlaczenie
        elif TRYB == 'istruncated':
            b = cialo(True)
            c.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: %d\r\n\r\n' % len(b) + b)
        else:
            b = cialo(False)
            c.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: %d\r\n\r\n' % len(b) + b)
    except Exception:
        pass
    finally:
        try: c.close()
        except Exception: pass
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('127.0.0.1', PORT)); s.listen(8)
print('gotowy', flush=True)
while True:
    k, _ = s.accept()
    threading.Thread(target=obsluz, args=(k,), daemon=True).start()
PYTON

  # Port z zakresu nieużywanego przez resztę projektu; gdyby był zajęty,
  # `curl` nie dostanie oczekiwanej odpowiedzi i test OBLEJE — nie przejdzie.
  PORT_PROBNY=59788

  z_serwerem() { # z_serwerem <tryb> <polecenia w podpowloce>
    local tryb="$1"; shift
    python3 "${SERWER_PY}" "${PORT_PROBNY}" "${tryb}" >/dev/null 2>&1 &
    local pid=$!
    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
      (exec 3<>"/dev/tcp/127.0.0.1/${PORT_PROBNY}") 2>/dev/null && break
      sleep 0.2
    done
    ( "$@" )
    kill "${pid}" 2>/dev/null
    wait "${pid}" 2>/dev/null
  }

  probuj_liste() { # probuj_liste — wypisuje „rc=<kod> kluczy=<ile>"
    wczytaj
    export KOPIA_S3_ENDPOINT="http://127.0.0.1:${PORT_PROBNY}"
    export KOPIA_S3_BUCKET=k KOPIA_S3_KLUCZ=x KOPIA_S3_SEKRET=y
    export KOPIA_S3_REGION=us-east-1 KOPIA_S3_TIMEOUT=10
    local plik; plik="$(mktemp)"
    local rc=0
    s3_lista_kluczy_do_pliku 'baza/' "${plik}" || rc=$?
    printf 'rc=%s kluczy=%s' "${rc}" "$(grep -c . "${plik}")"
    rm -f "${plik}"
  }

  # 1. Kontrola DODATNIA — pełna odpowiedź musi nadal przechodzić, inaczej
  #    „wszystko odrzucamy" udawałoby poprawność.
  wynik="$(z_serwerem pelny probuj_liste)"
  sprawdz "pełna odpowiedź nadal przechodzi i oddaje wszystkie klucze" \
    "rc=0 kluczy=9" "${wynik}"

  # 2. Sedno regresji: 200 + urwane ciało to PORAŻKA, nie krótsza lista.
  wynik="$(z_serwerem urwany probuj_liste)"
  sprawdz "odpowiedź 200 z urwanym ciałem NIE jest sukcesem" \
    "rc=1 kluczy=0" "${wynik}"

  # 3. Kod HTTP w komunikacie ma powiedzieć, że ciało urwano — „200" samo
  #    w sobie wprowadzałoby w błąd, bo status naprawdę był dwusetką.
  probuj_kod() { # probuj_kod — wypisuje samo S3_KOD po nieudanym listowaniu
    wczytaj
    export KOPIA_S3_ENDPOINT="http://127.0.0.1:${PORT_PROBNY}"
    export KOPIA_S3_BUCKET=k KOPIA_S3_KLUCZ=x KOPIA_S3_SEKRET=y
    export KOPIA_S3_REGION=us-east-1 KOPIA_S3_TIMEOUT=10
    local plik; plik="$(mktemp)"
    s3_lista_kluczy_do_pliku 'baza/' "${plik}" >/dev/null 2>&1
    printf '%s' "${S3_KOD}"
    rm -f "${plik}"
  }

  wynik="$(z_serwerem urwany probuj_kod | grep -c 'urwany')"
  sprawdz "S3_KOD mówi wprost, że ciało urwano (a nie samo „200\")" "1" "${wynik}"

  # 4. Straż `IsTruncated` sprawdzana FIZYCZNIE, na odpowiedzi serwera —
  #    a nie przez podstawienie funkcji, która ten kod wylicza. Bez tego
  #    wycięcie straży z docker/kopia/s3.sh nie oblewało niczego.
  wynik="$(z_serwerem istruncated probuj_liste)"
  sprawdz "pełna odpowiedź z IsTruncated=true daje kod 2, nie krótszą listę" \
    "rc=2 kluczy=0" "${wynik}"

  rm -f "${SERWER_PY}"
fi

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

# -----------------------------------------------------------------------------
#  NIEUDANE POTWIERDZENIE SPRZĄTA PO SOBIE — USTERKA ODTWORZONA 18.09.2026
#  NA PRAWDZIWYM ENDPOINCIE S3 (MinIO).
#
#  Przy niezgodności rozmiaru skrypt alarmował i wychodził, ZOSTAWIAJĄC zły
#  obiekt w buckecie razem z jego `.meta` — czyli razem z papierami mówiącymi,
#  że to porządna kopia. Zmierzone: obiekt 1234 B przy wysłanych 999 999 B
#  zostawał na miejscu, a następny przebieg liczył go jako jedną z kopii
#  chronionych przez `MINIMUM_KOPII`.
#
#  Kod wyjścia 81 był poprawny przez cały czas trwania usterki, więc asercja
#  wyżej niczego by tu nie złapała. Ta patrzy na to, co poszło na drut.
# -----------------------------------------------------------------------------
sprzatanie_po_potwierdzeniu() { # sprzatanie_po_potwierdzeniu <kod HEAD> <rozmiar>
  local kod_head="$1" rozmiar_w_buckecie="$2"
  (
    wczytaj
    katalog="$(mktemp -d)"
    trap 'rm -rf "${katalog}"' EXIT
    KATALOG_ROBOCZY="${katalog}"
    PREFIKS='baza/'
    ZNACZNIK='20260909-021700Z'
    PLIK_SZYFROGRAMU="${katalog}/s"; printf 'x' >"${PLIK_SZYFROGRAMU}"
    PLIK_META="${katalog}/m"; printf 'y' >"${PLIK_META}"
    ROZMIAR_SZYFROGRAMU=1000
    alarm() { :; }
    s3_zadanie() {
      case "$1" in
        PUT) S3_KOD=200; return 0 ;;
        HEAD)
          S3_KOD="${kod_head}"
          printf 'Content-Length: %s\r\n' "${rozmiar_w_buckecie}" >"${5}"
          [[ "${kod_head}" == 2* ]] && return 0
          return 1
          ;;
        DELETE) S3_KOD=204; printf 'DELETE %s\n' "$2" >&3; return 0 ;;
      esac
      return 0
    }
    ( wyslij && potwierdz ) 3>&1 >/dev/null 2>/dev/null | tr '\n' ' '
  )
}

sprawdz "zły rozmiar w buckecie — obiekt TEGO przebiegu znika razem z .meta" \
  "DELETE baza/kuking-20260909-021700Z.dump.cms DELETE baza/kuking-20260909-021700Z.meta " \
  "$(sprzatanie_po_potwierdzeniu 200 512)"

# Kontrola dodatnia do tej samej rzeczy: UDANE potwierdzenie nie kasuje nic.
sprawdz "udane potwierdzenie nie kasuje niczego" \
  "" "$(sprzatanie_po_potwierdzeniu 200 1000)"

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

# --- HASŁO POZA LISTĄ ARGUMENTÓW (#594) --------------------------------------
#
#  Odtworzone 17.09.2026 na bliźniaczym `scripts/kopia-lokalna.sh` prawdziwym
#  `ps`: przez cały czas trwania zrzutu wiersz procesu `pg_dump` zawierał pełny
#  DSN razem z hasłem. Argumenty procesu są na Linuksie jawne, a tym DSN-em
#  jest poświadczenie do produkcyjnej bazy.
#
#  Tu sprawdzamy wynik `sprawdz_srodowisko()`: adres, który pójdzie do
#  `pg_dump` i `psql`, ma być BEZ hasła, a samo hasło ma leżeć w prywatnym
#  `PGPASSFILE` z prawami 600. Bez drugiej połowy tej asercji „adres bez
#  hasła" znaczyłoby tylko tyle, że kopia przestała się łączyć.
poswiadczenie_wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT
  export KOPIA_KATALOG_ROBOCZY="${katalog}"
  export DB_URL='postgresql://kuking:bardzo-tajne@postgres.railway.internal:5432/railway'
  export KOPIA_S3_ENDPOINT='https://konto.r2.cloudflarestorage.com'
  export KOPIA_S3_BUCKET='b' KOPIA_S3_KLUCZ='k' KOPIA_S3_SEKRET='s'
  export KOPIA_KLUCZ_PUBLICZNY='x'
  sprawdz_srodowisko >/dev/null 2>&1
  printf 'dsn=%s pgpass=%s prawa=%s' \
    "${DB_URL}" \
    "$(grep -qF 'bardzo-tajne' "${PGPASSFILE}" && echo 'ma hasło' || echo 'PUSTY')" \
    "$(stat -c %a "${PGPASSFILE}")"
)"
sprawdz "hasło bazy nie trafia do argumentów pg_dump, tylko do PGPASSFILE" \
  "dsn=postgresql://kuking@postgres.railway.internal:5432/railway pgpass=ma hasło prawa=600" \
  "${poswiadczenie_wynik}"

# Adres BEZ hasła ma przejść przez ten sam kod nietknięty — inaczej skrypt
# psułby konfiguracje, w których poświadczenie przychodzi spoza DSN-u.
sprawdz "adres bez hasła zostaje nietknięty" \
  "postgresql://kuking@postgres.railway.internal:5432/railway" \
  "$(
    wczytaj
    SCIEZKA_PGPASS="$(mktemp -u)"
    schowaj_haslo_z_dsn 'postgresql://kuking@postgres.railway.internal:5432/railway'
    printf '%s' "${DSN_BEZ_HASLA}"
  )"

# Hasło z bajtami, które rozbiłyby i adres, i plik `.pgpass`: `@`, `:`, `/`
# oraz odwrotny ukośnik. `%XX` rozkodowujemy, bo libpq robi to samo.
sprawdz "hasło z %XX, dwukropkiem i ukośnikiem trafia do pliku DOSŁOWNIE" \
  'ha:sl\o@1' \
  "$(
    wczytaj
    plik="$(mktemp)"
    SCIEZKA_PGPASS="${plik}"
    schowaj_haslo_z_dsn 'postgresql://kuking:ha%3Asl%5Co%401@host:5432/db'
    sed -E 's/^[^:]*:[^:]*:[^:]*:[^:]*://' "${plik}" | sed -E 's/\\(.)/\1/g'
    rm -f "${plik}"
  )"

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
echo "── Zrzut i jego WERYFIKACJA: pg_dump z kodem 0 to jeszcze nie kopia ──"
# =============================================================================
#
#  TO JEST PUŁAPKA 5 z `docs/PULAPKI_TESTOW.md`, wprost i dosłownie:
#  „narzędzie może zameldować sukces, nie robiąc nic". W tym repozytorium
#  kosztowała już 249 przebiegów testu dymnego, które kończyły się jako
#  `success`, mając sam krok testu `skipped`.
#
#  Przy kopii bazy ta klasa usterki wygląda tak: `pg_dump` kończy się kodem 0,
#  bo nic nie wybuchło — a plik jest zrzutem PUSTEJ bazy (DB_URL wskazał
#  świeży serwis Postgresa) albo archiwum OBCIĘTYM (padło łącze na 80%,
#  co też potrafi dać kod 0). Do bucketu leci wtedy poprawnie zaszyfrowany
#  plik, którego nikt nie odtworzy, alarm nie wychodzi, a czujka w aplikacji
#  widzi świeżą kopię i milczy. Wszystko jest zielone i nie ma kopii.
#
#  Obrona jest w `weryfikuj_zrzut()`: minimalny rozmiar ORAZ odczytanie zrzutu
#  z powrotem przez `pg_restore --list` z minimalną liczbą tabel. Do tej pory
#  ta funkcja nie miała ANI JEDNEGO testu — czyli jedyny mechanizm, który
#  odróżnia „mam kopię" od „mam plik", był niesprawdzony.

# Podstawiamy `pg_dump` i `pg_restore` funkcjami, bo prawdziwego serwera 18
# tu nie ma (nagłówek tego pliku mówi o tym wprost). Sprawdzamy REAKCJĘ
# skryptu na każdy z czterech możliwych wyników, nie samego Postgresa.
zrzut_wynik() {
  local zachowanie_dump="$1" zachowanie_restore="$2"
  (
    wczytaj
    katalog="$(mktemp -d)"
    trap 'rm -rf "${katalog}"' EXIT
    KATALOG_ROBOCZY="${katalog}"
    PLIK_ZRZUTU="${katalog}/zrzut.dump"
    PLIK_BLEDU="${katalog}/blad.txt"
    MIN_BAJTOW=20000
    MIN_TABEL=20
    export DB_URL='postgresql://u:p@postgres.railway.internal:5432/railway'
    alarm() { printf 'ALARM %s %s\n' "$1" "$2" >&2; }

    case "${zachowanie_dump}" in
      # Padnięcie pg_dumpa: niezerowy kod i komunikat na stderr.
      padnij) pg_dump() { printf 'pg_dump: error: connection to server failed\n' >&2; return 1; } ;;
      # NAJGROŹNIEJSZY PRZYPADEK: kod 0 i plik, który wygląda poprawnie,
      # a jest zrzutem pustej bazy — kilka kilobajtów nagłówków.
      pusty) pg_dump() { head -c 3000 /dev/zero >"${PLIK_ZRZUTU}"; return 0; } ;;
      *) pg_dump() { head -c 200000 /dev/zero >"${PLIK_ZRZUTU}"; return 0; } ;;
    esac

    case "${zachowanie_restore}" in
      # Archiwum obcięte: pg_restore nie rozpoznaje własnego formatu.
      padnij) pg_restore() { printf 'pg_restore: error: did not find magic string\n' >&2; return 1; } ;;
      # Plik czyta się, ale to nie ta baza — trzy tabele zamiast dwudziestu pięciu.
      malo) pg_restore() { local i; for i in 1 2 3; do printf '%d; 0 0 TABLE DATA public t%d kuking\n' "${i}" "${i}"; done; return 0; } ;;
      *) pg_restore() { local i; for i in $(seq 1 25); do printf '%d; 0 0 TABLE DATA public t%d kuking\n' "${i}" "${i}"; done; return 0; } ;;
    esac

    # Osobna podpowłoka, bo `padnij` kończy się `exit` — bez niej `printf`
    # niżej nigdy by się nie wykonał i test porównywałby pustkę z pustką.
    ( zrzut >/dev/null 2>&1 && weryfikuj_zrzut >/dev/null 2>&1 )
    printf 'kod=%s' "$?"
  )
}

sprawdz "zrzut i weryfikacja przechodzą przy zdrowej bazie" "kod=0" "$(zrzut_wynik ok ok)"
sprawdz "padnięcie pg_dumpa przerywa przebieg" "kod=40" "$(zrzut_wynik padnij ok)"
sprawdz "zrzut PUSTEJ bazy z kodem 0 zostaje odrzucony" "kod=50" "$(zrzut_wynik pusty ok)"
sprawdz "archiwum, którego pg_restore nie czyta, zostaje odrzucone" "kod=51" "$(zrzut_wynik ok padnij)"
sprawdz "zrzut z za małą liczbą tabel zostaje odrzucony" "kod=52" "$(zrzut_wynik ok malo)"

# Odrzucenie to połowa roboty — druga połowa to POWIEDZENIE O TYM. Cichy
# nieudany cron potrafi wisieć w panelu Railway tygodniami.
alarm_etapu() {
  local zachowanie_dump="$1" zachowanie_restore="$2"
  (
    wczytaj
    katalog="$(mktemp -d)"
    trap 'rm -rf "${katalog}"' EXIT
    KATALOG_ROBOCZY="${katalog}"
    PLIK_ZRZUTU="${katalog}/zrzut.dump"
    PLIK_BLEDU="${katalog}/blad.txt"
    MIN_BAJTOW=20000
    MIN_TABEL=20
    export DB_URL='postgresql://u:p@postgres.railway.internal:5432/railway'
    alarm() { printf 'ALARM-%s\n' "$1"; }

    case "${zachowanie_dump}" in
      padnij) pg_dump() { return 1; } ;;
      pusty) pg_dump() { head -c 3000 /dev/zero >"${PLIK_ZRZUTU}"; return 0; } ;;
      *) pg_dump() { head -c 200000 /dev/zero >"${PLIK_ZRZUTU}"; return 0; } ;;
    esac
    case "${zachowanie_restore}" in
      malo) pg_restore() { printf '1; 0 0 TABLE DATA public t1 kuking\n'; return 0; } ;;
      *) pg_restore() { local i; for i in $(seq 1 25); do printf '%d; 0 0 TABLE DATA public t%d kuking\n' "${i}" "${i}"; done; return 0; } ;;
    esac

    ( zrzut 2>/dev/null; weryfikuj_zrzut 2>/dev/null ) | grep '^ALARM-' | head -1
  )
}

sprawdz "padnięcie pg_dumpa alarmuje etapem „zrzut\"" "ALARM-zrzut" "$(alarm_etapu padnij ok)"
sprawdz "odrzucony zrzut alarmuje etapem „weryfikacja\"" "ALARM-weryfikacja" "$(alarm_etapu pusty ok)"
sprawdz "za mało tabel alarmuje etapem „weryfikacja\"" "ALARM-weryfikacja" "$(alarm_etapu ok malo)"

# -----------------------------------------------------------------------------
#  `pipefail` — regresja na ryzyko, którego w tym skrypcie ŚWIADOMIE NIE MA.
#
#  Gdyby zrzut jechał potokiem (`pg_dump | openssl | curl`), porażka
#  `pg_dump` ginęłaby za sukcesem ostatniego członu i do bucketu leciałby
#  poprawnie zaszyfrowany plik PUSTY albo OBCIĘTY. Ten skrypt tego nie robi
#  — zrzut idzie do `--file=`, szyfrowanie z pliku do pliku, wysyłka z pliku
#  — ale `pipefail` jest i tak włączony, bo potoków pomocniczych jest tu
#  kilkanaście (`psql | tr`, `openssl | sed`, `grep | sort | tail`).
#
#  Sprawdzamy ZACHOWANIE, nie obecność napisu: po wczytaniu skryptu opcja
#  musi być NAPRAWDĘ włączona w powłoce.
#
#  UWAGA, TA PUŁAPKA ZŁAPAŁA TEN TEST W PIERWSZEJ WERSJI. Ten plik sam ma
#  w nagłówku `set -uo pipefail`, a podpowłoka dziedziczy opcje powłoki —
#  więc `pipefail` był tu włączony ZAWSZE, niezależnie od tego, co robi
#  skrypt produkcyjny. Kontrola ujemna (usunięcie `pipefail` z `set -Eeuo
#  pipefail`) nie oblała testu ani razu, bo test mierzył WŁASNY nagłówek.
#  Dlatego gasimy opcję jawnie i sprawdzamy OBA stany: że przed wczytaniem
#  jest zgaszona, i że po wczytaniu jest zapalona. Para „przed i po" jest
#  tu jedynym dowodem, że zapalił ją mierzony skrypt.
# -----------------------------------------------------------------------------
wynik="$(
  # Bez `wczytaj`, bo ono celowo gasi `pipefail` na potrzeby testów.
  set +o pipefail
  if set -o | grep -qE '^pipefail[[:space:]]+on$'; then echo 'przed:on'; else echo 'przed:off'; fi
  # shellcheck disable=SC1090
  . "${SKRYPT}" >/dev/null 2>&1
  if set -o | grep -qE '^pipefail[[:space:]]+on$'; then echo 'po:on'; else echo 'po:off'; fi
)"
sprawdz "wczytanie skryptu włącza pipefail" "przed:off
po:on" "${wynik}"

# I strona strukturalna tej samej rzeczy: w ciele `zrzut()` nie ma potoku,
# a zrzut ląduje w pliku. Test na pusty zbiór przechodziłby tu na zawsze,
# więc wymagamy TRAFIENIA (pułapka 2), nie jego braku.
cialo_zrzutu="$(bez_komentarzy "${SKRYPT}" | awk '/^zrzut\(\) \{/,/^\}/')"
if [[ -z "${cialo_zrzutu}" ]]; then
  sprawdz "umiem znaleźć ciało funkcji zrzut()" "znalazłem" "nie znalazłem"
elif grep -q -- '--file="${PLIK_ZRZUTU}"' <<<"${cialo_zrzutu}" \
  && ! grep -q '|' <<<"${cialo_zrzutu}"; then
  sprawdz "zrzut idzie do pliku, nie potokiem" "tak" "tak"
else
  sprawdz "zrzut idzie do pliku, nie potokiem" "tak" "nie"
fi

# =============================================================================
echo "── Archiwum OBCIĘTE: na PRAWDZIWYM pliku i PRAWDZIWYM pg_restore ──"
# =============================================================================
#
#  USTERKA ODTWORZONA 18.09.2026 NA PRAWDZIWYM ZRZUCIE PRAWDZIWEJ BAZY
#  -------------------------------------------------------------------
#  `pg_restore --list` czyta z archiwum WYŁĄCZNIE spis treści, a ten leży na
#  jego POCZĄTKU — bloków danych nie dotyka w ogóle. Zmierzone na zrzucie
#  bazy Kukinga (PostgreSQL 18.6, 331 974 B, 50 tabel) obcinanym do 99, 98,
#  95, 92, 90 i 80 procent ORAZ o jeden bajt: `--list` za każdym razem
#  kończył się kodem 0 i pięćdziesięcioma tabelami, a prawdziwe
#  `pg_restore -d` — komunikatem „could not read from input file: end of
#  file". Taki plik przechodził `MIN_BAJTOW`, był szyfrowany, wysyłany,
#  potwierdzany i meldowany jako GOTOWE; pełny przebieg na zrzucie obciętym
#  do 95% odtworzył się potem do bazy, w której `recipes` miało 41 wierszy,
#  a `users` ZERO.
#
#  DLACZEGO PRAWDZIWY PLIK, A NIE PODSTAWIONY `pg_restore`
#  ------------------------------------------------------
#  Bo cała usterka siedzi w tym, CO ROBI prawdziwy `pg_restore` z prawdziwym
#  archiwum. Podstawiona funkcja dowiodłaby wyłącznie, że test umie
#  podstawić funkcję: blok wyżej („pg_dump z kodem 0 to jeszcze nie kopia")
#  robi dokładnie to i przechodził przez cały czas trwania błędu.
#  Archiwum leży w `tests/skrypty/dane/archiwum-pg18.dump.base64` — zrobił
#  je prawdziwy `pg_dump --format=custom` z jednorazowej bazy; jak je
#  odtworzyć, pisze nagłówek tamtego pliku. Tu tylko odkodowujemy je na dysk
#  i podajemy PRODUKCYJNEJ funkcji `weryfikuj_zrzut`.

ARCHIWUM_B64="${KATALOG}/tests/skrypty/dane/archiwum-pg18.dump.base64"

if [[ ! -f "${ARCHIWUM_B64}" ]]; then
  sprawdz "fikstura prawdziwego archiwum" "jest" "BRAK ${ARCHIWUM_B64#"${KATALOG}"/}"
else
  KATALOG_ARCHIWUM="$(mktemp -d)"
  ARCHIWUM="${KATALOG_ARCHIWUM}/pelne.dump"
  grep -v '^#' "${ARCHIWUM_B64}" | base64 -d >"${ARCHIWUM}" 2>/dev/null

  # weryfikacja_wynik <plik> — wypisuje „kod=<n>" z PRODUKCYJNEJ funkcji.
  # Progi rozmiaru i liczby tabel schodzą tu w dół, bo fikstura jest maleńka
  # i ma jedną tabelę; pilnują one czego innego (pusta baza, zła baza)
  # i mają własne testy w bloku wyżej.
  weryfikacja_wynik() {
    (
      wczytaj
      PLIK_ZRZUTU="$1"
      PLIK_BLEDU="${KATALOG_ARCHIWUM}/blad.txt"
      ROZMIAR_JAWNY="$(stat -c %s "${PLIK_ZRZUTU}")"
      MIN_BAJTOW=100
      MIN_TABEL=1
      alarm() { :; }
      (weryfikuj_zrzut >/dev/null 2>&1)
      printf 'kod=%s' "$?"
    )
  }

  # Ile wynikowi wierzyć: najpierw sprawdzamy, że fikstura NIE JEST pusta
  # i że czyta ją tutejszy `pg_restore`. Bez tego cały blok niżej mierzyłby
  # pustkę (pułapka 2 z docs/PULAPKI_TESTOW.md).
  if [[ ! -s "${ARCHIWUM}" ]]; then
    sprawdz "fikstura odkodowuje się do niepustego pliku" "tak" "nie"
  else
    sprawdz "fikstura odkodowuje się do niepustego pliku" "tak" "tak"
  fi

  # KONTROLA DODATNIA. Gdyby `weryfikuj_zrzut` odrzucało wszystko, asercje
  # niżej byłyby zielone przy zepsutym kodzie (pułapka 4).
  sprawdz "pełne, zdrowe archiwum PRZECHODZI weryfikację" \
    "kod=0" "$(weryfikacja_wynik "${ARCHIWUM}")"

  PELNE_BAJTY="$(stat -c %s "${ARCHIWUM}")"

  # SEDNO REGRESJI. Każda z tych pozycji obcięcia przechodziła
  # `pg_restore --list` z kodem 0 — sprawdzone na tej samej fiksturze.
  for PROCENT in 99 95 90 80 60; do
    OBCIETE="${KATALOG_ARCHIWUM}/obciete-${PROCENT}.dump"
    head -c "$((PELNE_BAJTY * PROCENT / 100))" "${ARCHIWUM}" >"${OBCIETE}"
    sprawdz "archiwum obcięte do ${PROCENT}% zostaje ODRZUCONE" \
      "kod=53" "$(weryfikacja_wynik "${OBCIETE}")"
  done

  # Najtrudniejszy przypadek obcięcia: brakuje JEDNEGO bajtu.
  head -c "$((PELNE_BAJTY - 1))" "${ARCHIWUM}" >"${KATALOG_ARCHIWUM}/bez-bajtu.dump"
  sprawdz "archiwum krótsze o JEDEN bajt zostaje odrzucone" \
    "kod=53" "$(weryfikacja_wynik "${KATALOG_ARCHIWUM}/bez-bajtu.dump")"

  # I to, czego samo obcięcie nie obejmuje: uszkodzenie danych W ŚRODKU.
  # Plik ma pełną długość i poprawny spis treści; psuje się blok danych.
  cp "${ARCHIWUM}" "${KATALOG_ARCHIWUM}/zepsute-w-srodku.dump"
  printf '\xff\xff\xff\xff' | dd of="${KATALOG_ARCHIWUM}/zepsute-w-srodku.dump" \
    bs=1 seek="$((PELNE_BAJTY * 75 / 100))" count=4 conv=notrunc status=none
  sprawdz "archiwum pełnej długości, uszkodzone w środku, zostaje odrzucone" \
    "kod=53" "$(weryfikacja_wynik "${KATALOG_ARCHIWUM}/zepsute-w-srodku.dump")"

  # KONTROLA UJEMNA WBUDOWANA W ZESTAW: gdyby ktoś wyjął z `weryfikuj_zrzut`
  # pełne odczytanie i zostawił sam `--list`, przypadki wyżej kończyłyby się
  # kodem 0. Ta asercja mówi wprost, że sam `--list` tego NIE łapie — czyli
  # dlaczego tamta linia musi tam być.
  wynik="$(pg_restore --list "${KATALOG_ARCHIWUM}/obciete-80.dump" >/dev/null 2>&1; printf '%s' "$?")"
  sprawdz "sam pg_restore --list PRZEPUSZCZA obcięte archiwum (po to jest pełny odczyt)" \
    "0" "${wynik}"

  # I strona kosztu: pełny odczyt NIE zapisuje SQL-a na dysk. Gdyby
  # `--file` wskazywało plik roboczy, w kontenerze lądowałby jawny SQL
  # z kompletem danych osobowych — dokładnie to, czego `szyfruj()` pozbywa
  # się linijkę dalej.
  cialo_weryfikacji="$(bez_komentarzy "${SKRYPT}" | awk '/^weryfikuj_zrzut\(\) \{/,/^\}/')"
  if [[ -z "${cialo_weryfikacji}" ]]; then
    sprawdz "umiem znaleźć ciało funkcji weryfikuj_zrzut()" "znalazłem" "nie znalazłem"
  elif grep -q -- '--file=/dev/null' <<<"${cialo_weryfikacji}"; then
    sprawdz "pełny odczyt nie zostawia jawnego SQL-a na dysku" "tak" "tak"
  else
    sprawdz "pełny odczyt nie zostawia jawnego SQL-a na dysku" "tak" "nie"
  fi

  rm -rf "${KATALOG_ARCHIWUM}"
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

# -----------------------------------------------------------------------------
#  PLIK `.meta` I UDOKUMENTOWANA DROGA ODTWORZENIA (§7.4)
#
#  `zbuduj_meta()` nie miała do tej pory żadnego testu, a jest jedynym
#  miejscem, z którego człowiek w dniu awarii dowie się, KTÓRYM kluczem
#  odszyfrować dany zrzut i jaki skrót ma z tego wyjść. Dokument §7.4 obiecuje
#  przy tym dwie konkretne rzeczy: że certyfikat da się wyciąć z `.meta` jedną
#  komendą `sed`, i że `openssl cms -decrypt … -recip cert.pem` tym wyciętym
#  plikiem zadziała. Obietnica w dokumencie awaryjnym bez testu jest życzeniem.
#
#  Sprawdzamy też, czego w `.meta` BYĆ NIE MOŻE: klucza prywatnego (plik leży
#  w buckecie jawnie, obok szyfrogramu) i hasła do bazy.
# -----------------------------------------------------------------------------
wynik="$(
  wczytaj
  katalog="$(mktemp -d)"
  trap 'rm -rf "${katalog}"' EXIT

  openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes \
    -keyout "${katalog}/prywatny.pem" -out "${katalog}/publiczny.pem" \
    -subj '/CN=Kuking test kopii' >/dev/null 2>&1

  head -c 50000 /dev/urandom >"${katalog}/zrzut.dump"
  KATALOG_ROBOCZY="${katalog}"
  PLIK_ZRZUTU="${katalog}/zrzut.dump"
  PLIK_SZYFROGRAMU="${katalog}/zrzut.dump.cms"
  PLIK_META="${katalog}/zrzut.meta"
  PLIK_BLEDU="${katalog}/blad.txt"
  ROZMIAR_JAWNY="$(stat -c %s "${PLIK_ZRZUTU}")"
  KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/publiczny.pem")"
  ZNACZNIK='20260909-021700Z'
  SRODOWISKO='production'
  WERSJA_SERWERA=18
  WERSJA_KLIENTA=18
  LICZBA_TABEL=25

  szyfruj >/dev/null 2>&1 || { printf 'szyfrowanie-padlo'; exit 0; }
  zbuduj_meta || { printf 'meta-padlo'; exit 0; }

  # Dokładnie ta komenda, co w §7.4 dokumentu kopii.
  sed -n '/-----BEGIN CERTIFICATE-----/,/-----END CERTIFICATE-----/p' \
    "${PLIK_META}" >"${katalog}/cert.pem"

  openssl x509 -in "${katalog}/cert.pem" -noout >/dev/null 2>&1 \
    || { printf 'wyciety-cert-nie-jest-certyfikatem'; exit 0; }

  openssl cms -decrypt -binary -inform DER -in "${PLIK_SZYFROGRAMU}" \
    -inkey "${katalog}/prywatny.pem" -recip "${katalog}/cert.pem" \
    -out "${katalog}/odtworzony.dump" 2>/dev/null \
    || { printf 'odszyfrowanie-z-recip-padlo'; exit 0; }

  # Skrót z `.meta` musi zgadzać się z tym, co wyszło z odszyfrowania —
  # to jest krok 3 procedury §7.4 i cały jej sens.
  z_meta="$(sed -n 's/^sha256_jawnego: //p' "${PLIK_META}")"
  if [[ "$(s3_sha256_pliku "${katalog}/odtworzony.dump")" == "${z_meta}" && -n "${z_meta}" ]]; then
    printf 'zgadza-sie'
  else
    printf 'skrot-sie-rozjechal'
  fi
)"
sprawdz "droga z §7.4 działa: cert wycięty z .meta odszyfrowuje zrzut, skrót się zgadza" \
  "zgadza-sie" "${wynik}"

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
  PLIK_META="${katalog}/zrzut.meta"
  PLIK_BLEDU="${katalog}/blad.txt"
  ROZMIAR_JAWNY=5000
  KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/publiczny.pem")"
  ZNACZNIK='20260909-021700Z'; SRODOWISKO='production'
  WERSJA_SERWERA=18; WERSJA_KLIENTA=18; LICZBA_TABEL=25
  export DB_URL='postgresql://kuking:tajne-haslo-bazy@postgres.railway.internal:5432/railway'
  szyfruj >/dev/null 2>&1
  zbuduj_meta
  if grep -q 'PRIVATE KEY' "${PLIK_META}" || grep -q 'tajne-haslo-bazy' "${PLIK_META}"; then
    printf 'wyniosl'
  else
    printf 'czysty'
  fi
)"
sprawdz "w .meta nie ma klucza prywatnego ani hasła do bazy" "czysty" "${wynik}"

# -----------------------------------------------------------------------------
#  KLUCZ PRYWATNY W ZMIENNEJ „KLUCZ PUBLICZNY" — pomyłka, która NIC nie psuje
#  widocznie i cofa całą własność, o którą chodziło w D-049 punkt 1.
#
#  `cat kuking-kopie-*.pem` skleja obie połowy pary, wynik ma poprawny nagłówek
#  `BEGIN CERTIFICATE`, przechodzi `openssl x509` i szyfruje bez najmniejszego
#  problemu. Kopie powstają dalej — tylko klucz do ich odczytu leży od tej pory
#  w tym samym Railwayu, co baza i co bucket. Przejęcie konta daje wtedy
#  jednocześnie bazę, kopie i klucz do kopii, czyli kopia chroni przed awarią
#  dysku i przed niczym więcej.
#
#  Dlatego skrypt ODMAWIA pracy (kod 64), a nie ostrzega: brak kopii jest
#  widoczny w panelu, a kopia z kluczem leżącym obok jest niewidoczna.
# -----------------------------------------------------------------------------
klucz_prywatny_wynik() {
  local jak="$1"
  (
    wczytaj
    katalog="$(mktemp -d)"
    trap 'rm -rf "${katalog}"' EXIT
    openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes \
      -keyout "${katalog}/prywatny.pem" -out "${katalog}/publiczny.pem" \
      -subj '/CN=Kuking test kopii' >/dev/null 2>&1
    head -c 100 /dev/urandom >"${katalog}/zrzut.dump"
    KATALOG_ROBOCZY="${katalog}"
    PLIK_ZRZUTU="${katalog}/zrzut.dump"
    PLIK_SZYFROGRAMU="${katalog}/zrzut.dump.cms"
    PLIK_BLEDU="${katalog}/blad.txt"
    ROZMIAR_JAWNY=100
    alarm() { :; }

    case "${jak}" in
      # Tak wygląda `cat publiczny.pem prywatny.pem` wklejone do panelu.
      sklejone) KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/publiczny.pem" "${katalog}/prywatny.pem")" ;;
      # To samo, ale przepuszczone przez base64 — panele lubią tę drogę,
      # a ona ukrywa nagłówki przed czytającym człowiekiem.
      sklejone_base64) KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/publiczny.pem" "${katalog}/prywatny.pem" | base64 -w0)" ;;
      # Sama część prywatna, czyli pomyłka o jedną literę w nazwie pliku (§7.1).
      sam_prywatny) KOPIA_KLUCZ_PUBLICZNY="$(cat "${katalog}/prywatny.pem")" ;;
    esac

    ( szyfruj >/dev/null 2>&1 )
    printf 'kod=%s' "$?"
  )
}

sprawdz "certyfikat sklejony z kluczem prywatnym przerywa przebieg" "kod=64" \
  "$(klucz_prywatny_wynik sklejone)"
sprawdz "to samo w base64 też przerywa przebieg" "kod=64" \
  "$(klucz_prywatny_wynik sklejone_base64)"
sprawdz "sam klucz prywatny nie udaje certyfikatu" "kod=64" \
  "$(klucz_prywatny_wynik sam_prywatny)"

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
