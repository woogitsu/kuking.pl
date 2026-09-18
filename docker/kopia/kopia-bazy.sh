#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — zrzut bazy poza Railwaya (issue #193, decyzja D-043)
# =============================================================================
#
#  CO TO JEST I DLACZEGO ISTNIEJE
#  ------------------------------
#  Railway na planie Free/Hobby NIE ROBI ŻADNYCH KOPII bazy. Volume Backups
#  i PITR to funkcje planu Pro — panel na Hobby nawet nie pokazuje tej
#  zakładki. Do napisania tego skryptu liczba kopii bazy Kuking wynosiła
#  ZERO, a nie „dwie warstwy Railwaya plus ta trzecia", jak zakładał
#  `docs/infra/INFRA_DECISION.md` §10. Ten plik jest więc JEDYNĄ kopią,
#  a nie ostatnią linią obrony (sprostowanie w D-043).
#
#  DLACZEGO OSOBNY SERWIS, A NIE HARMONOGRAM APLIKACJI
#  `docker/php.ini` wyłącza `proc_open` (`disable_functions`), a `pg_dump`
#  wołany z PHP wymaga dokładnie tej funkcji (`Symfony\Process`). Osłabienia
#  tego hardeningu zabrania `AGENTS.md`, więc zrzut nie jest do naprawienia
#  w kontenerze aplikacji — jest do przeniesienia. W TYM obrazie nie ma PHP
#  w ogóle, więc nie ma też czego osłabiać.
#
#  DLACZEGO NIE GITHUB ACTIONS: poświadczenie do bazy musiałoby trafić do
#  sekretów GitHuba, czyli powstałaby druga kopia najwrażliwszego klucza,
#  w innym systemie niż baza. Tu zostaje wewnątrz Railwaya (D-043).
#
#  CO SIĘ DZIEJE, PO KOLEI
#  -----------------------
#   1. sprawdzenie zgodności wersji klienta i serwera (niezgodny pg_dump
#      ODMAWIA pracy — to nie jest ostrzeżenie, to jest brak kopii);
#   2. sprawdzenie, czy poprzednia kopia w ogóle jest i czy nie jest za stara
#      (to jest jedyny moment, w którym ten serwis potrafi zauważyć, że
#      ostatni przebieg się nie udał);
#   3. `pg_dump --format=custom` do pliku;
#   4. WERYFIKACJA ZRZUTU: `pg_restore --list` musi go przeczytać i pokazać
#      co najmniej `KOPIA_MIN_TABEL` tabel. To łapie najgorszy możliwy stan:
#      poprawny plik z pustej albo nie tej bazy;
#   5. szyfrowanie KLUCZEM PUBLICZNYM (CMS/PKCS#7, AES-256 + RSA);
#   6. natychmiastowe usunięcie zrzutu jawnego z dysku kontenera;
#   7. wysyłka na R2 razem z plikiem `.meta` (rozmiary, skróty, wersje);
#   8. sprawdzenie po wysyłce, że obiekt NAPRAWDĘ tam jest i ma ten rozmiar;
#   9. retencja — kasowanie starych kopii, ale nigdy poniżej minimum.
#
#  Każdy z tych kroków, gdy się nie uda, kończy się ALARMEM na kanał błędów
#  (ten sam webhook, co błędy 500 — D-041) i NIEZEROWYM kodem wyjścia.
#
#  SZYFROWANIE — GDZIE MIESZKA KLUCZ
#  ---------------------------------
#  Zrzut to komplet danych osobowych wszystkich kont. Szyfrujemy KLUCZEM
#  PUBLICZNYM, nie hasłem, i to jest różnica zasadnicza: w Railwayu leży
#  wyłącznie certyfikat (`KOPIA_KLUCZ_PUBLICZNY`), którym da się zaszyfrować
#  i którym NIE DA SIĘ odszyfrować niczego. Klucz prywatny nie istnieje
#  w żadnym środowisku uruchomieniowym — jego jedyne kopie są w menedżerze
#  haseł właściciela i na nośniku offline w innym miejscu fizycznym.
#
#  Skutek praktyczny: kto przejmie ten serwis, bucket R2 albo konto Railway,
#  dostaje wyłącznie szyfrogram. Kto zgubi klucz prywatny, traci wszystkie
#  kopie — dlatego DWIE kopie klucza w DWÓCH miejscach, i procedura odczytu
#  bez dostępu do aplikacji opisana w `docs/infra/KOPIE_I_ODTWORZENIE.md` §7.
#
#  Uruchomienie: kopia-bazy.sh          (pełny przebieg)
#                kopia-bazy.sh --sprawdz (tylko walidacja środowiska)
#  Testy:        tests/skrypty/kopia-bazy.sh
# =============================================================================

set -Eeuo pipefail

KATALOG_SKRYPTU="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=docker/kopia/s3.sh
. "${KATALOG_SKRYPTU}/s3.sh"

# --- Wartości domyślne -------------------------------------------------------
PREFIKS="${KOPIA_PREFIKS:-baza/}"
RETENCJA_DNI="${KOPIA_RETENCJA_DNI:-30}"
MINIMUM_KOPII="${KOPIA_MINIMUM_KOPII:-7}"
MIN_TABEL="${KOPIA_MIN_TABEL:-20}"
MIN_BAJTOW="${KOPIA_MIN_BAJTOW:-20000}"
MAX_BAJTOW="${KOPIA_MAX_BAJTOW:-5368709120}" # 5 GiB — limit pojedynczego PUT
ALARM_PO_GODZINACH="${KOPIA_ALARM_PO_GODZINACH:-36}"
SRODOWISKO="${KOPIA_SRODOWISKO:-production}"

log() { printf '[kopia] %s\n' "$*" >&2; }

# -----------------------------------------------------------------------------
#  ALARM — to jest najważniejsza funkcja w tym pliku.
#
#  Kopia, która po cichu przestała się robić, jest GORSZA niż jej brak: daje
#  fałszywe poczucie bezpieczeństwa i jednocześnie nie chroni przed niczym.
#  Dlatego każda porażka kończy się tutaj.
#
#  CO WYCHODZI NA WEBHOOK — LISTA ZAMKNIĘTA, nie „wszystko, co mamy":
#    nazwa serwisu, nazwa środowiska, ETAP z listy niżej, kod liczbowy,
#    odcisk (8 znaków z etapu i kodu).
#
#  CZEGO NIE WYCHODZI NIGDY: adresu bazy, hasła, nazwy bucketu, klucza
#  obiektu, treści odpowiedzi S3, wyjścia `pg_dump` ani `psql`. Powód jest
#  ten sam, co w `App\Logging\WebhookBleduHandler` po audycie A6-01: komunikat
#  sterownika bazy potrafi nieść wartości z zapytania (adres e-mail, hash
#  hasła), a ten kanał wychodzi do usługi, nad którą nie mamy kontroli.
#  Szczegóły zostają w logu serwisu w Railwayu i tylko tam.
#
#  ETAPY (jedyne dozwolone wartości pierwszego argumentu):
#    srodowisko  wersja  poprzednia-kopia  zrzut  weryfikacja  szyfrowanie
#    wysylka  potwierdzenie  retencja
# -----------------------------------------------------------------------------
ETAPY='srodowisko wersja poprzednia-kopia zrzut weryfikacja szyfrowanie wysylka potwierdzenie retencja'

alarm() {
  local etap="$1" kod="${2:-0}"

  # Etap poza listą to błąd programisty, nie stan produkcji — ale alarm musi
  # wyjść mimo wszystko, bo cisza jest tu najgorsza. Podmieniamy na „nieznany".
  case " ${ETAPY} " in
    *" ${etap} "*) : ;;
    *) etap='nieznany' ;;
  esac

  # Kod przepuszczamy tylko jako liczbę — cokolwiek innego mogłoby wnieść
  # w treść fragment komunikatu błędu.
  [[ "${kod}" =~ ^[0-9]{1,6}$ ]] || kod='0'

  local odcisk
  odcisk="$(printf '%s|%s' "${etap}" "${kod}" | openssl dgst -sha1 -hex | sed 's/^.*= *//' | cut -c1-8)"

  local tresc="[Kuking/${SRODOWISKO}] kopia-bazy: NIEUDANA
etap: ${etap}
kod: ${kod}
odcisk: ${odcisk}
Szczegóły zostają w logu serwisu — na webhook nie wychodzą."

  log "ALARM etap=${etap} kod=${kod} odcisk=${odcisk}"

  if [[ -z "${KOPIA_WEBHOOK_URL:-}" ]]; then
    # Kanał wyłączony. Mówimy o tym GŁOŚNO w logu, bo to znaczy, że alarm
    # nie doszedł do człowieka — a właśnie o alarm chodzi w tym issue.
    log 'OSTRZEŻENIE: KOPIA_WEBHOOK_URL nie jest ustawione — alarm nie wyszedł nigdzie.'
    return 0
  fi

  # Format zgodny ze Slackiem; Discord przyjmuje go na końcówce `.../slack`
  # (tak samo jak kanał `blad_webhook` aplikacji — docs/infra/MONITORING_BLEDOW.md).
  #
  # JSON budujemy sami, bez `jq`: treść składa się wyłącznie ze stałych
  # z tego pliku, nazwy etapu z listy zamkniętej i cyfr, więc nie ma tu
  # czego uciekać poza znakami nowej linii.
  local json_tresc="${tresc//$'\n'/\\n}"

  curl --silent --show-error --max-time 10 \
    --header 'Content-Type: application/json' \
    --data "{\"text\":\"${json_tresc}\"}" \
    --output /dev/null \
    "${KOPIA_WEBHOOK_URL}" \
    || log 'OSTRZEŻENIE: nie udało się wysłać alarmu na webhook.'
}

# Porażka: alarm i wyjście. Kod wyjścia jest różny dla różnych etapów, żeby
# w panelu Railway dało się odróżnić „nie było jak się połączyć" od „zrzut
# wyszedł podejrzanie mały", bez czytania logu.
padnij() {
  local etap="$1" kod="$2"
  alarm "${etap}" "${kod}"
  exit "${kod}"
}

# --- Adres bazy bez hasła, do logu -------------------------------------------
# Log Railwaya nie jest miejscem na poświadczenie do produkcyjnej bazy.
bez_hasla() {
  # postgresql://user:haslo@host:port/db  →  postgresql://user:***@host:port/db
  sed -E 's#(://[^:/@]+):[^@]*@#\1:***@#' <<<"$1"
}

# =============================================================================
#  POŚWIADCZENIE POZA LISTĄ PROCESÓW (#594)
#
#  `pg_dump "postgresql://user:HASŁO@host/db"` pokazuje hasło w `ps` KAŻDEMU
#  użytkownikowi maszyny: argumenty procesu są na Linuksie jawne. Zmierzone
#  17.09.2026 na bliźniaczym `scripts/kopia-lokalna.sh` — `ps -o args=` przez
#  cały czas trwania zrzutu zawierało pełny DSN razem z hasłem.
#
#  W tym kontenerze chodzi jeden proces, więc ryzyko jest mniejsze niż na
#  cudzym laptopie — ale nie zerowe (obraz da się uruchomić lokalnie, a wyjście
#  `ps` trafia do zrzutów diagnostycznych). Hasło idzie więc do prywatnego
#  `PGPASSFILE` (prawa 600), a do `pg_dump` i `psql` trafia DSN BEZ hasła.
#  `--no-password` tego nie psuje: ten przełącznik blokuje wyłącznie pytanie
#  na terminalu, nie odczyt pliku.
# =============================================================================
odkoduj_procenty() {
  local s="${1//\\/\\\\}"
  printf '%b' "${s//%/\\x}"
}

# Rozkłada DSN na części. Ustawia: DSN_BEZ_HASLA, HASLO_Z_DSN, HOST_Z_DSN,
# PORT_Z_DSN, UZYTKOWNIK_Z_DSN. Adres bez hasła zostawia nietknięty.
rozdziel_dsn() {
  local dsn="$1"
  DSN_BEZ_HASLA="${dsn}"
  HASLO_Z_DSN=''
  HOST_Z_DSN='*'
  PORT_Z_DSN='*'
  UZYTKOWNIK_Z_DSN='*'

  case "${dsn}" in
    postgresql://* | postgres://*) ;;
    *) return 0 ;;
  esac

  local schemat="${dsn%%://*}://" reszta="${dsn#*://}"
  local przed_sciezka="${reszta%%/*}"

  case "${przed_sciezka}" in
    *@*) ;;
    *) return 0 ;;
  esac

  local userinfo="${przed_sciezka%@*}" gospodarz="${przed_sciezka##*@}"
  UZYTKOWNIK_Z_DSN="$(odkoduj_procenty "${userinfo%%:*}")"

  case "${gospodarz}" in
    \[*) ;; # IPv6 — zostawiamy gwiazdkę, dopasowanie po użytkowniku wystarczy
    *:*)
      HOST_Z_DSN="${gospodarz%%:*}"
      PORT_Z_DSN="${gospodarz##*:}"
      ;;
    *) HOST_Z_DSN="${gospodarz}" ;;
  esac

  case "${userinfo}" in
    *:*) ;;
    *) return 0 ;;
  esac

  HASLO_Z_DSN="$(odkoduj_procenty "${userinfo#*:}")"
  DSN_BEZ_HASLA="${schemat}${userinfo%%:*}@${gospodarz}${reszta#"${przed_sciezka}"}"
}

# Dopisuje poświadczenie z DSN-u do prywatnego PGPASSFILE i zostawia adres bez
# hasła w `DSN_BEZ_HASLA`. Wynik JEST W ZMIENNEJ, a nie na wyjściu, i to jest tu
# istotne: `X="$(schowaj_haslo_z_dsn "$X")"` uruchomiłoby tę funkcję
# w podpowłoce, a wtedy `export PGPASSFILE` zginąłby razem z nią. Pierwsza
# wersja tej poprawki miała dokładnie ten błąd i przechodziła tylko dlatego,
# że w środowisku stało `PGPASSWORD` — czyli zielono, bez PGPASSFILE.
#
# Plik powstaje DOPIERO gdy jest co w nim schować — pusty PGPASSFILE
# przesłoniłby `~/.pgpass` i zerwałby połączenie adresem bez hasła.
schowaj_haslo_z_dsn() {
  local dsn="$1"
  rozdziel_dsn "${dsn}"

  if [[ -n "${HASLO_Z_DSN}" ]]; then
    if [[ "${PGPASSFILE:-}" != "${SCIEZKA_PGPASS}" ]]; then
      (
        umask 077
        : >"${SCIEZKA_PGPASS}"
      )
      chmod 600 "${SCIEZKA_PGPASS}"
      export PGPASSFILE="${SCIEZKA_PGPASS}"
    fi

    local pole="${HASLO_Z_DSN//\\/\\\\}"
    pole="${pole//:/\\:}"
    printf '%s:%s:*:%s:%s\n' \
      "${HOST_Z_DSN}" "${PORT_Z_DSN}" "${UZYTKOWNIK_Z_DSN}" "${pole}" >>"${SCIEZKA_PGPASS}"
  fi
}

# =============================================================================
#  KROK 0 — środowisko
# =============================================================================
sprawdz_srodowisko() {
  local brakujace=''
  local zmienna
  for zmienna in DB_URL KOPIA_S3_ENDPOINT KOPIA_S3_BUCKET KOPIA_S3_KLUCZ \
    KOPIA_S3_SEKRET KOPIA_KLUCZ_PUBLICZNY; do
    [[ -n "${!zmienna:-}" ]] || brakujace="${brakujace} ${zmienna}"
  done

  if [[ -n "${brakujace}" ]]; then
    log "BŁĄD: brak zmiennych:${brakujace}"
    padnij srodowisko 10
  fi

  local narzedzie
  for narzedzie in pg_dump pg_restore psql openssl curl; do
    command -v "${narzedzie}" >/dev/null 2>&1 || {
      log "BŁĄD: brak narzędzia ${narzedzie} w obrazie"
      padnij srodowisko 11
    }
  done

  # -------------------------------------------------------------------------
  #  POŁĄCZENIE PO SIECI WEWNĘTRZNEJ, NIE PO PUBLICZNYM ADRESIE (#193).
  #
  #  Railway daje oba: `DATABASE_URL` (host `*.railway.internal`) i
  #  `DATABASE_PUBLIC_URL` (host `*.proxy.rlwy.net`). Podstawienie tego
  #  drugiego „bo działa z laptopa" wypuszcza komplet danych osobowych
  #  przez publiczny internet za każdym przebiegiem — i nic by o tym nie
  #  powiedziało, bo kopia nadal by powstawała.
  # -------------------------------------------------------------------------
  case "${DB_URL}" in
    *proxy.rlwy.net* | *.up.railway.app*)
      if [[ "${KOPIA_POZWOL_PUBLICZNIE:-0}" != '1' ]]; then
        log 'BŁĄD: DB_URL wskazuje publiczny adres bazy, a nie sieć wewnętrzną Railwaya.'
        log '  Użyj referencji do serwisu Postgres (DATABASE_URL, host *.railway.internal).'
        padnij srodowisko 12
      fi
      log 'OSTRZEŻENIE: łączę się publicznym adresem bazy (KOPIA_POZWOL_PUBLICZNIE=1).'
      ;;
  esac

  log "baza: $(bez_hasla "${DB_URL}")"
  log "bucket: ${KOPIA_S3_BUCKET} prefiks: ${PREFIKS}"

  # Od tej linii `DB_URL` nie zawiera już hasła — leży ono w PGPASSFILE
  # w katalogu, który ginie razem z przebiegiem (`sprzataj`).
  KATALOG_POSWIADCZEN="$(mktemp -d "${KOPIA_KATALOG_ROBOCZY:-/tmp}/kopia-pass.XXXXXX")"
  SCIEZKA_PGPASS="${KATALOG_POSWIADCZEN}/pgpass"
  schowaj_haslo_z_dsn "${DB_URL}"
  DB_URL="${DSN_BEZ_HASLA}"
}

# =============================================================================
#  KROK 1 — zgodność wersji klienta i serwera
#
#  `pg_dump` STARSZY od serwera odmawia pracy:
#     pg_dump: error: server version: 18.1; pg_dump version: 16.13
#     pg_dump: error: aborting because of server version mismatch
#  To nie jest ostrzeżenie — to jest brak kopii. A ponieważ obraz i baza
#  aktualizują się niezależnie, sprawdzamy to PRZY KAŻDYM przebiegu, zamiast
#  wierzyć, że tag obrazu wciąż odpowiada wersji serwera.
# =============================================================================
sprawdz_wersje() {
  local numer_serwera
  numer_serwera="$(psql "${DB_URL}" --no-password --quiet --no-align --tuples-only \
    --command 'SHOW server_version_num' 2>/dev/null | tr -d '[:space:]')" || true

  if [[ ! "${numer_serwera}" =~ ^[0-9]+$ ]]; then
    log 'BŁĄD: nie udało się odczytać wersji serwera (brak połączenia albo złe poświadczenie).'
    padnij wersja 20
  fi

  WERSJA_SERWERA=$((numer_serwera / 10000))

  local wersja_klienta
  wersja_klienta="$(pg_dump --version | sed -E 's/[^0-9]*([0-9]+).*/\1/')"

  if [[ ! "${wersja_klienta}" =~ ^[0-9]+$ ]]; then
    log 'BŁĄD: nie udało się odczytać wersji pg_dump.'
    padnij wersja 21
  fi

  WERSJA_KLIENTA="${wersja_klienta}"
  log "PostgreSQL: serwer ${WERSJA_SERWERA}, pg_dump ${WERSJA_KLIENTA}"

  if ((WERSJA_KLIENTA < WERSJA_SERWERA)); then
    log "BŁĄD: pg_dump ${WERSJA_KLIENTA} jest starszy niż serwer ${WERSJA_SERWERA} — odmówi pracy."
    log '  Podnieś wersję obrazu bazowego w docker/kopia/Dockerfile.'
    padnij wersja 22
  fi

  if ((WERSJA_KLIENTA > WERSJA_SERWERA)); then
    # Nowszy klient poradzi sobie ze starszym serwerem, ale rozjazd znaczy,
    # że obraz i baza żyją własnym życiem — i kiedyś skończy się to odwrotną,
    # śmiertelną kombinacją.
    log "OSTRZEŻENIE: pg_dump ${WERSJA_KLIENTA} jest nowszy niż serwer ${WERSJA_SERWERA}."
  fi
}

# =============================================================================
#  KROK 2 — czy poprzednia kopia istnieje i nie jest za stara
#
#  Ten serwis nie wie, czy poprzedni przebieg się udał. Wie natomiast, co leży
#  w buckecie — i jeśli najnowsza kopia jest starsza niż oczekiwany odstęp,
#  to znaczy, że co najmniej jeden przebieg wypadł. Alarm NIE PRZERYWA pracy:
#  najpilniejszą rzeczą jest zrobić kopię teraz.
#
#  CZEGO TO NIE ŁAPIE: sytuacji, w której serwis nie uruchamia się wcale
#  (skasowany, wyłączony harmonogram, wyczerpany limit). Tego nie jest w stanie
#  zauważyć kod, który wtedy nie chodzi — pilnuje tego z drugiej strony
#  aplikacja (`kuking:sprawdz-kopie`, `routes/console.php`) i cotygodniowy
#  przegląd z `KOPIE_I_ODTWORZENIE.md` §6.
# =============================================================================
sprawdz_poprzednia_kopie() {
  # Klucze do PLIKU, nie przez `$( )`. Powód i pomiar: nagłówek
  # `s3_lista_kluczy_do_pliku` w `docker/kopia/s3.sh`. W skrócie: podstawienie
  # poleceń to podpowłoka, a `S3_KOD` ginie razem z nią — i wtedy „token bez
  # uprawnień" (403) oraz „bucketu nie ma" (404) są w logu nie do odróżnienia.
  local plik_kluczy="${KATALOG_ROBOCZY:-${KOPIA_KATALOG_ROBOCZY:-/tmp}}/klucze-bucketu.txt"
  local kod_listowania=0
  s3_lista_kluczy_do_pliku "${PREFIKS}" "${plik_kluczy}" || kod_listowania=$?

  if ((kod_listowania == 2)); then
    log 'BŁĄD: bucket oddał listę OBCIĘTĄ (IsTruncated) — nie widzę wszystkich kopii.'
    log '  Nie zgaduję, która jest najnowsza: patrz stronicowanie w docker/kopia/s3.sh.'
    padnij poprzednia-kopia 30
  fi

  if ((kod_listowania != 0)); then
    log "BŁĄD: nie udało się wylistować bucketu (HTTP ${S3_KOD:-brak})."
    log '  403 — token nie ma prawa do TEGO bucketu; 404 — bucketu nie ma pod tą nazwą;'
    log '  brak kodu — nie doszło połączenie do endpointu.'
    padnij poprzednia-kopia 30
  fi

  local klucze
  klucze="$(cat "${plik_kluczy}")"

  local najnowszy
  najnowszy="$(grep -E '\.dump\.cms$' <<<"${klucze}" | sort | tail -1 || true)"

  if [[ -z "${najnowszy}" ]]; then
    log 'Brak wcześniejszych kopii w buckecie — zakładam pierwszy przebieg.'
    return 0
  fi

  # Znacznik czasu bierzemy z NASZEJ nazwy pliku, nie z `LastModified`:
  # nazwa mówi, KIEDY ZROBIONO ZRZUT, a `LastModified` — kiedy obiekt
  # trafił do bucketu. Przy ponownej wysyłce te dwie rzeczy się rozjeżdżają,
  # a interesuje nas wiek DANYCH.
  local znacznik
  znacznik="$(sed -E 's/.*kuking-([0-9]{8})-([0-9]{6})Z\.dump\.cms$/\1 \2/' <<<"${najnowszy}")"

  if [[ ! "${znacznik}" =~ ^[0-9]{8}\ [0-9]{6}$ ]]; then
    log "OSTRZEŻENIE: nie umiem odczytać daty z klucza ${najnowszy##*/} — pomijam sprawdzenie wieku."
    return 0
  fi

  local data="${znacznik%% *}" godzina="${znacznik##* }"
  local sekundy_kopii sekundy_teraz wiek_godzin
  sekundy_kopii="$(date -u -d "${data:0:4}-${data:4:2}-${data:6:2} ${godzina:0:2}:${godzina:2:2}:${godzina:4:2}" +%s)"
  sekundy_teraz="$(date -u +%s)"
  wiek_godzin=$(((sekundy_teraz - sekundy_kopii) / 3600))

  log "najnowsza kopia w buckecie: ${najnowszy##*/} (${wiek_godzin} h)"

  if ((wiek_godzin > ALARM_PO_GODZINACH)); then
    log "OSTRZEŻENIE: poprzednia kopia ma ${wiek_godzin} h, próg to ${ALARM_PO_GODZINACH} h."
    alarm poprzednia-kopia "${wiek_godzin}"
  fi
}

# =============================================================================
#  KROK 3 — zrzut
# =============================================================================
zrzut() {
  log 'pg_dump…'

  # --format=custom  → pg_restore z wyborem tabel i równoległością; zawiera
  #                    już kompresję zlib, więc nie pakujemy drugi raz.
  # --no-owner       → restore do świeżego Postgresa nie wywali się na braku
  #                    roli `postgres` z tamtego projektu.
  # --no-privileges  → to samo dla GRANT-ów.
  #
  # PG_DUMP NIE ZAPISUJE NICZEGO W BAZIE. Otwiera migawkę REPEATABLE READ
  # i czyta; nie blokuje zapisów aplikacji (poza DDL-em).
  if ! pg_dump "${DB_URL}" \
    --no-password \
    --format=custom \
    --no-owner \
    --no-privileges \
    --file="${PLIK_ZRZUTU}" 2>"${PLIK_BLEDU}"; then
    log 'BŁĄD: pg_dump zakończył się niepowodzeniem. Wyjście (zostaje w logu serwisu):'
    sed 's/^/  pg_dump: /' "${PLIK_BLEDU}" >&2
    padnij zrzut 40
  fi

  ROZMIAR_JAWNY="$(stat -c %s "${PLIK_ZRZUTU}")"
  log "zrzut: ${ROZMIAR_JAWNY} B"
}

# =============================================================================
#  KROK 4 — WERYFIKACJA ZRZUTU
#
#  `pg_dump` z kodem 0 nie znaczy „mam kopię bazy Kuking". Znaczy „nic nie
#  wybuchło". Dwa stany, które przechodzą przez zerowy kod wyjścia i są
#  jednocześnie najgorszym możliwym wynikiem tej pracy:
#
#    * zrzut PUSTEJ bazy (DB_URL wskazał świeży, nie ten serwis) — plik jest,
#      waży kilka kilobajtów, wygląda poprawnie i nie ma w nim niczyich danych;
#    * archiwum OBCIĘTE (padło łącze na 80%) — czasem daje kod 0.
#
#  Dlatego czytamy zrzut z powrotem `pg_restore --list`. To jest jedyna
#  weryfikacja możliwa w tym kontenerze: klucza prywatnego tu nie ma, więc
#  szyfrogramu odszyfrować nie potrafimy. Prawdziwe odtworzenie jest
#  ćwiczeniem człowieka — `KOPIE_I_ODTWORZENIE.md` §4.
# =============================================================================
weryfikuj_zrzut() {
  if ((ROZMIAR_JAWNY < MIN_BAJTOW)); then
    log "BŁĄD: zrzut ma ${ROZMIAR_JAWNY} B, minimum to ${MIN_BAJTOW} B."
    padnij weryfikacja 50
  fi

  local spis
  if ! spis="$(pg_restore --list "${PLIK_ZRZUTU}" 2>"${PLIK_BLEDU}")"; then
    log 'BŁĄD: pg_restore nie potrafi odczytać własnego zrzutu — archiwum jest uszkodzone.'
    sed 's/^/  pg_restore: /' "${PLIK_BLEDU}" >&2
    padnij weryfikacja 51
  fi

  LICZBA_TABEL="$(grep -c 'TABLE DATA' <<<"${spis}" || true)"
  log "tabel z danymi w zrzucie: ${LICZBA_TABEL}"

  if ((LICZBA_TABEL < MIN_TABEL)); then
    log "BŁĄD: w zrzucie jest ${LICZBA_TABEL} tabel, oczekiwane minimum ${MIN_TABEL}."
    log '  Najczęstsza przyczyna: DB_URL wskazuje inną bazę niż produkcyjna.'
    padnij weryfikacja 52
  fi
}

# =============================================================================
#  KROK 5 — szyfrowanie kluczem publicznym
#
#  CMS (PKCS#7) `EnvelopedData`: losowy klucz AES-256 na ten jeden plik,
#  zaszyfrowany kluczem publicznym RSA z certyfikatu. Dwie rzeczy, dla
#  których wybraliśmy właśnie to:
#
#   1. `openssl` jest wszędzie. Odtworzenie zrzutu nie wymaga instalowania
#      niczego (`age`, `gpg`, klienta chmury) w dniu, w którym i tak wszystko
#      się wali. Jedna komenda i klucz prywatny — patrz §7 dokumentu kopii.
#   2. Ten kontener NIE POTRAFI odszyfrować tego, co zapisał. Przejęcie
#      serwisu, bucketu albo konta Railway daje szyfrogram i nic więcej.
#
#  Certyfikat przyjmujemy w PEM wprost albo w base64 — zmienne wieloliniowe
#  w panelach bywają kłopotliwe, a base64 zawsze przejdzie.
# =============================================================================
# Jedna odmowa dla obu dróg (wartość surowa i odkodowana z base64) — treść
# komunikatu ma się nie rozjechać między nimi, bo to jest ten komunikat,
# który człowiek przeczyta w najgorszym momencie.
odmow_klucza_prywatnego() {
  log 'BŁĄD: KOPIA_KLUCZ_PUBLICZNY zawiera KLUCZ PRYWATNY.'
  log '  Wstaw TYLKO plik kuking-kopie-publiczny.pem (zaczyna się od BEGIN CERTIFICATE).'
  log '  Klucz prywatny mieszka w menedżerze haseł i na nośniku offline — patrz'
  log '  docs/infra/KOPIE_I_ODTWORZENIE.md §7.1. Nie robię kopii, dopóki tu leży.'
  padnij szyfrowanie 64
}

szyfruj() {
  local plik_certu="${KATALOG_ROBOCZY}/klucz-publiczny.pem"

  # Straż na wartości SUROWEJ, przed próbą dekodowania. Sam klucz prywatny
  # nie jest ani PEM-em z certyfikatem, ani base64, więc bez tego warunku
  # kończyłby się kodem 60 i komunikatem „to nie jest PEM" — prawdziwym,
  # ale mówiącym człowiekowi coś zupełnie innego niż to, co się stało.
  if [[ "${KOPIA_KLUCZ_PUBLICZNY}" == *'PRIVATE KEY'* ]]; then
    odmow_klucza_prywatnego
  fi

  if [[ "${KOPIA_KLUCZ_PUBLICZNY}" == *'BEGIN CERTIFICATE'* ]]; then
    printf '%s\n' "${KOPIA_KLUCZ_PUBLICZNY}" >"${plik_certu}"
  else
    printf '%s' "${KOPIA_KLUCZ_PUBLICZNY}" | base64 -d >"${plik_certu}" 2>/dev/null || {
      log 'BŁĄD: KOPIA_KLUCZ_PUBLICZNY nie jest ani PEM-em, ani base64 PEM-a.'
      padnij szyfrowanie 60
    }
  fi

  if ! openssl x509 -in "${plik_certu}" -noout >/dev/null 2>&1; then
    log 'BŁĄD: KOPIA_KLUCZ_PUBLICZNY nie jest certyfikatem X.509.'
    log '  Wygeneruj parę wg KOPIE_I_ODTWORZENIE.md §7 i wstaw TYLKO część publiczną.'
    padnij szyfrowanie 61
  fi

  # -------------------------------------------------------------------------
  #  KLUCZ PRYWATNY W TEJ ZMIENNEJ UNIEWAŻNIA CAŁĄ TĘ WARSTWĘ.
  #
  #  Powyższe `openssl x509` przechodzi także wtedy, gdy wartość zawiera
  #  certyfikat ORAZ klucz prywatny — a to nie jest przypadek teoretyczny,
  #  tylko jedna z dwóch najprawdopodobniejszych pomyłek przy wklejaniu do
  #  panelu: `cat kuking-kopie-*.pem` skleja oba pliki, a wynik wygląda
  #  poprawnie i szyfruje bez najmniejszego problemu. Druga to pomylenie
  #  plików o jedną literę w nazwie (§7.1 ostrzega o niej wprost).
  #
  #  Skutek byłby dokładnie odwrotny do sensu D-049 punkt 1: klucz prywatny
  #  leżałby w tym samym miejscu, co baza i bucket, więc przejęcie konta
  #  Railway dawałoby jednocześnie bazę, kopie i klucz do kopii. Kopia
  #  chroniłaby wtedy przed awarią dysku i przed niczym więcej — i NIC by
  #  o tym nie powiedziało, bo kopie nadal by powstawały.
  #
  #  Dlatego odmawiamy pracy, a nie ostrzegamy. Zrzut, którego nie ma, jest
  #  widoczny; zrzut zaszyfrowany kluczem leżącym obok jest niewidoczny.
  # -------------------------------------------------------------------------
  if grep -q 'PRIVATE KEY' "${plik_certu}"; then
    odmow_klucza_prywatnego
  fi

  ODCISK_KLUCZA="$(openssl x509 -in "${plik_certu}" -noout -fingerprint -sha256 \
    | sed 's/^.*=//' | tr -d ':')"
  CERT_PEM="$(cat "${plik_certu}")"

  log "szyfruję (odcisk klucza SHA-256: ${ODCISK_KLUCZA:0:16}…)"

  # `-stream` — wynik DER liczony strumieniowo, bez wciągania całego zrzutu
  # do pamięci kontenera (limit 512 MB, a baza kiedyś przekroczy tę wartość).
  if ! openssl cms -encrypt -binary -aes-256-cbc -stream \
    -in "${PLIK_ZRZUTU}" -outform DER -out "${PLIK_SZYFROGRAMU}" \
    "${plik_certu}" 2>"${PLIK_BLEDU}"; then
    log 'BŁĄD: szyfrowanie nie udało się.'
    sed 's/^/  openssl: /' "${PLIK_BLEDU}" >&2
    padnij szyfrowanie 62
  fi

  SKROT_JAWNEGO="$(s3_sha256_pliku "${PLIK_ZRZUTU}")"

  # ZRZUT JAWNY GINIE TERAZ, nie „na końcu przy sprzątaniu". Od tej linii
  # w kontenerze nie ma już pliku, który czyta się bez klucza prywatnego.
  # Filesystem Railwaya jest ulotny, ale to nie jest powód, żeby trzymać
  # komplet danych osobowych minutę dłużej, niż trzeba.
  rm -f "${PLIK_ZRZUTU}"

  ROZMIAR_SZYFROGRAMU="$(stat -c %s "${PLIK_SZYFROGRAMU}")"
  SKROT_SZYFROGRAMU="$(s3_sha256_pliku "${PLIK_SZYFROGRAMU}")"
  log "szyfrogram: ${ROZMIAR_SZYFROGRAMU} B"

  if ((ROZMIAR_SZYFROGRAMU > MAX_BAJTOW)); then
    log "BŁĄD: szyfrogram ${ROZMIAR_SZYFROGRAMU} B przekracza limit pojedynczego PUT (${MAX_BAJTOW} B)."
    log '  Trzeba dołożyć multipart upload — patrz nagłówek docker/kopia/s3.sh.'
    padnij szyfrowanie 63
  fi
}

# =============================================================================
#  KROK 6 — plik towarzyszący `.meta`
#
#  Bez danych osobowych: rozmiary, skróty, wersje, certyfikat (część
#  PUBLICZNA). Certyfikat jest tu celowo — dzięki niemu do odszyfrowania
#  wystarcza klucz prywatny, bez szukania, który to był certyfikat.
#  `openssl cms -decrypt` przyjmuje `-recip`, a ten plik go dostarcza.
# =============================================================================
zbuduj_meta() {
  cat >"${PLIK_META}" <<META
# Kuking.pl — metadane zrzutu bazy. Bez danych osobowych.
znacznik: ${ZNACZNIK}
srodowisko: ${SRODOWISKO}
serwer_postgresql: ${WERSJA_SERWERA}
pg_dump: ${WERSJA_KLIENTA}
tabel_z_danymi: ${LICZBA_TABEL}
rozmiar_jawny_bajty: ${ROZMIAR_JAWNY}
rozmiar_szyfrogramu_bajty: ${ROZMIAR_SZYFROGRAMU}
sha256_jawnego: ${SKROT_JAWNEGO}
sha256_szyfrogramu: ${SKROT_SZYFROGRAMU}
szyfrowanie: CMS/PKCS7 EnvelopedData, AES-256-CBC + RSA
odcisk_certyfikatu_sha256: ${ODCISK_KLUCZA}
odtworzenie: docs/infra/KOPIE_I_ODTWORZENIE.md sekcja 7
# Certyfikat (klucz PUBLICZNY) użyty do zaszyfrowania — do -recip przy odczycie.
${CERT_PEM}
META
}

# =============================================================================
#  KROK 7 i 8 — wysyłka i POTWIERDZENIE, że obiekt tam jest
#
#  Osobny krok, bo „PUT zwrócił 200" i „w buckecie leży plik tego rozmiaru"
#  to dwie różne rzeczy — a różnica między nimi to dokładnie ta klasa usterki,
#  która kosztowała ten projekt dzień przy poczcie: narzędzie mówi „zrobione",
#  a nie zrobiło nic.
# =============================================================================
wyslij() {
  KLUCZ_OBIEKTU="${PREFIKS}kuking-${ZNACZNIK}.dump.cms"
  local klucz_meta="${PREFIKS}kuking-${ZNACZNIK}.meta"

  log "wysyłam ${KLUCZ_OBIEKTU}"
  if ! s3_zadanie PUT "${KLUCZ_OBIEKTU}" '' "${PLIK_SZYFROGRAMU}"; then
    log "BŁĄD: wysyłka szyfrogramu nie udała się (HTTP ${S3_KOD:-brak})."
    padnij wysylka 70
  fi

  if ! s3_zadanie PUT "${klucz_meta}" '' "${PLIK_META}"; then
    log "BŁĄD: wysyłka pliku .meta nie udała się (HTTP ${S3_KOD:-brak})."
    padnij wysylka 71
  fi
}

potwierdz() {
  local naglowki
  naglowki="$(mktemp -p "${KATALOG_ROBOCZY}")"

  if ! s3_zadanie HEAD "${KLUCZ_OBIEKTU}" '' '' "${naglowki}"; then
    log "BŁĄD: obiektu nie ma w buckecie po wysyłce (HTTP ${S3_KOD:-brak})."
    padnij potwierdzenie 80
  fi

  local rozmiar_w_buckecie
  rozmiar_w_buckecie="$(grep -i '^content-length:' "${naglowki}" \
    | tail -1 | tr -cd '0-9')"

  if [[ "${rozmiar_w_buckecie}" != "${ROZMIAR_SZYFROGRAMU}" ]]; then
    log "BŁĄD: w buckecie leży ${rozmiar_w_buckecie:-?} B, wysłano ${ROZMIAR_SZYFROGRAMU} B."
    padnij potwierdzenie 81
  fi

  log "potwierdzone: ${rozmiar_w_buckecie} B w buckecie"
}

# =============================================================================
#  KROK 9 — retencja
#
#  Dwa warunki naraz, i drugi jest ważniejszy od pierwszego:
#    * kasujemy kopie starsze niż `KOPIA_RETENCJA_DNI`,
#    * ale NIGDY nie zostawiamy mniej niż `KOPIA_MINIMUM_KOPII`.
#
#  Bez tego drugiego warunku wystarczyłby jeden zły przebieg z przestawionym
#  zegarem albo trzytygodniowa przerwa w działaniu serwisu, żeby retencja
#  skasowała WSZYSTKO, co jeszcze było. Kasowanie ostatniej kopii to nie
#  porządki, to utrata danych — dlatego minimum wygrywa z wiekiem.
#
#  Porażka retencji NIE jest porażką kopii: kopia już leży w buckecie.
#  Dlatego alarm tak, ale bez `exit` — inaczej Railway pokazałby nieudany
#  przebieg mimo udanej kopii, a to uczy ignorowania czerwonego.
# =============================================================================
retencja() {
  # Jak w `sprawdz_poprzednia_kopie`: przez plik, żeby kod HTTP nie zginął
  # w podpowłoce. Tu ma to dodatkową wagę — retencja KASUJE, więc „nie wiem,
  # co jest w buckecie" musi być powiedziane dokładnie.
  local plik_kluczy="${KATALOG_ROBOCZY:-${KOPIA_KATALOG_ROBOCZY:-/tmp}}/klucze-retencji.txt"
  local kod_listowania=0
  s3_lista_kluczy_do_pliku "${PREFIKS}" "${plik_kluczy}" || kod_listowania=$?

  if ((kod_listowania == 2)); then
    log 'OSTRZEŻENIE: bucket oddał listę OBCIĘTĄ (IsTruncated) — nie kasuję niczego.'
    alarm retencja 90
    return 0
  fi

  if ((kod_listowania != 0)); then
    log "OSTRZEŻENIE: nie udało się wylistować bucketu do retencji (HTTP ${S3_KOD:-brak})."
    alarm retencja 90
    return 0
  fi

  local klucze
  klucze="$(cat "${plik_kluczy}")"

  # Same szyfrogramy, posortowane po nazwie — nasza nazwa zawiera znacznik
  # czasu w formacie sortowalnym leksykograficznie, więc `sort` = po dacie.
  local -a szyfrogramy=()
  local linia
  while IFS= read -r linia; do
    [[ -n "${linia}" ]] && szyfrogramy+=("${linia}")
  done < <(grep -E '\.dump\.cms$' <<<"${klucze}" | sort)

  local ile="${#szyfrogramy[@]}"
  log "kopii w buckecie: ${ile}"

  if ((ile <= MINIMUM_KOPII)); then
    log "retencja pominięta: ${ile} <= minimum ${MINIMUM_KOPII}"
    return 0
  fi

  local prog
  prog="$(date -u -d "${RETENCJA_DNI} days ago" +%Y%m%d)"
  local do_skasowania=$((ile - MINIMUM_KOPII))
  local skasowane=0

  local klucz
  for klucz in "${szyfrogramy[@]}"; do
    ((do_skasowania > 0)) || break

    local data
    data="$(sed -E 's/.*kuking-([0-9]{8})-[0-9]{6}Z\.dump\.cms$/\1/' <<<"${klucz}")"
    [[ "${data}" =~ ^[0-9]{8}$ ]] || continue
    ((data < prog)) || break # lista jest posortowana — dalej są tylko młodsze

    if s3_zadanie DELETE "${klucz}"; then
      s3_zadanie DELETE "${klucz%.dump.cms}.meta" || true
      skasowane=$((skasowane + 1))
      do_skasowania=$((do_skasowania - 1))
      log "skasowana stara kopia: ${klucz##*/}"
    else
      log "OSTRZEŻENIE: nie udało się skasować ${klucz##*/} (HTTP ${S3_KOD:-brak})."
      alarm retencja 91
      return 0
    fi
  done

  log "retencja: skasowano ${skasowane}, zostaje $((ile - skasowane))"
}

# =============================================================================
#  PRZEBIEG
# =============================================================================
KATALOG_ROBOCZY=''
KATALOG_POSWIADCZEN=''
SCIEZKA_PGPASS=''
DSN_BEZ_HASLA=''
HASLO_Z_DSN=''
HOST_Z_DSN='*'
PORT_Z_DSN='*'
UZYTKOWNIK_Z_DSN='*'

sprzataj() {
  # Zrzut jawny ginie już w `szyfruj()`; ta pętla to druga linia obrony
  # na wypadek przerwania przed tamtą linią.
  [[ -n "${KATALOG_ROBOCZY}" && -d "${KATALOG_ROBOCZY}" ]] && rm -rf "${KATALOG_ROBOCZY}"
  # Hasło do bazy ginie razem ze swoim katalogiem, także po przerwaniu.
  [[ -n "${KATALOG_POSWIADCZEN}" && -d "${KATALOG_POSWIADCZEN}" ]] && rm -rf "${KATALOG_POSWIADCZEN}"
  return 0
}

main() {
  trap sprzataj EXIT

  if [[ "${1:-}" == '--sprawdz' ]]; then
    sprawdz_srodowisko
    sprawdz_wersje
    log 'Środowisko w porządku — nie robię zrzutu (--sprawdz).'
    return 0
  fi

  sprawdz_srodowisko

  KATALOG_ROBOCZY="$(mktemp -d "${KOPIA_KATALOG_ROBOCZY:-/tmp}/kopia.XXXXXX")"

  ZNACZNIK="$(date -u +%Y%m%d-%H%M%S)Z"
  PLIK_ZRZUTU="${KATALOG_ROBOCZY}/kuking-${ZNACZNIK}.dump"
  PLIK_SZYFROGRAMU="${PLIK_ZRZUTU}.cms"
  PLIK_META="${KATALOG_ROBOCZY}/kuking-${ZNACZNIK}.meta"
  PLIK_BLEDU="${KATALOG_ROBOCZY}/blad.txt"

  log "start, znacznik ${ZNACZNIK}"

  sprawdz_wersje
  sprawdz_poprzednia_kopie
  zrzut
  weryfikuj_zrzut
  szyfruj
  zbuduj_meta
  wyslij
  potwierdz
  retencja

  log "GOTOWE: ${KLUCZ_OBIEKTU} (${ROZMIAR_SZYFROGRAMU} B)"
}

# Nie uruchamiamy się, gdy plik jest tylko wczytany (tak robią testy).
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  main "$@"
fi
