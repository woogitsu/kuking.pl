#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — KOPIA BAZY NA WŁASNY DYSK, do zrobienia DZIŚ (issue #9)
# =============================================================================
#
#  PO CO TO JEST, GDY JEST JUŻ `docker/kopia/kopia-bazy.sh`
#  --------------------------------------------------------
#  Tamten skrypt jest lepszą kopią pod każdym względem: chodzi z harmonogramu,
#  szyfruje, wypycha do R2, pilnuje retencji i alarmuje, gdy zawiedzie.
#  Ma jedną wadę: **nie działa, dopóki właściciel nie wykona czterech rzeczy
#  w panelach** (bucket R2, dwa tokeny, para kluczy, serwis w Railwayu —
#  `docs/infra/KOPIE_I_ODTWORZENIE.md` §7.3). Do tego czasu liczba kopii bazy
#  Kukinga wynosi **ZERO**.
#
#  Ten skrypt jest jedną komendą do wykonania zanim tamte cztery rzeczy się
#  wydarzą, i robi dokładnie tyle: **kopię numer jeden, na dysku właściciela,
#  poza Railwayem.** Zastępuje siedem kroków przepisywanych z ręki (procedura
#  §4B) i — inaczej niż wklejanie komend z dokumentu — KOŃCZY SIĘ KODEM
#  RÓŻNYM OD ZERA, gdy kopia nie powstała albo jest pusta.
#
#  To NIE jest druga warstwa kopii ani konkurencja dla serwisu z `docker/kopia`.
#  Gdy tamten zacznie chodzić, ten zostaje jako narzędzie do ćwiczeń i do
#  kopii „na już” przed ryzykowną migracją. Nie ma harmonogramu, bo kopia
#  zależna od tego, że człowiek pamięta, łamie się po trzech tygodniach —
#  i właśnie dlatego nie zamyka issue #9.
#
#  DLACZEGO „poza Railwayem” JEST TU WARUNKIEM, NIE OZDOBĄ
#  Obie warstwy kopii, które obiecuje `INFRA_DECISION.md` §10 (Volume Backups
#  i PITR), są funkcjami planu Pro i na Free/Hobby nie istnieją (D-043).
#  Gdyby istniały, leżałyby w tym samym miejscu, co baza: utrata konta albo
#  pomyłka w panelu zabiera jednocześnie bazę i jej kopie. Plik na dysku
#  właściciela tej wady nie ma.
#
#  CZEGO TEN SKRYPT NIE ZROBI
#  --------------------------
#   * nie zaszyfruje kopii, dopóki nie podasz `--klucz-publiczny`. Zrzut to
#     komplet danych osobowych wszystkich kont — nieszyfrowany plik nadaje się
#     na nośnik zamknięty w szufladzie, nie do katalogu `Pobrane` i nie do
#     chmury. Skrypt mówi o tym przy każdym przebiegu bez klucza;
#   * nie zapisze niczego wewnątrz repozytorium — odmawia (kod 20). Zrzut
#     w katalogu roboczym gita kończy się kiedyś w commicie;
#   * nie odtworzy kopii. To robi `scripts/proba-odtworzenia.sh`, i dopóki go
#     nie uruchomisz, ten plik jest obietnicą, nie kopią.
#
#  URUCHOMIENIE
#  ------------
#    scripts/kopia-lokalna.sh --zrodlo "$DSN" --katalog ~/kopie-kuking
#    scripts/kopia-lokalna.sh --zrodlo "$DSN" --katalog /media/pendrive \
#                             --klucz-publiczny kuking-kopie-publiczny.pem
#
#  DSN bierze się z `railway connect postgres --tunnel-only` (procedura
#  w §8 dokumentu kopii). **Nie podawaj go w wierszu polecenia na wspólnym
#  komputerze** — hasło widać wtedy w `ps` i w historii powłoki; użyj
#  zmiennej `KOPIA_ZRODLO`.
#
#  Hasła NIE MA za to w `ps` samego `pg_dump` (#594): skrypt przekłada je
#  do prywatnego `PGPASSFILE` i woła narzędzia adresem bez hasła. To jest
#  jedyna część, którą skrypt może załatwić za Ciebie — swojego własnego
#  wiersza polecenia nie schowa.
#
#  KODY WYJŚCIA
#  ------------
#     2  błąd użycia
#    10  brak narzędzia (pg_dump / pg_restore / openssl)
#    20  katalog docelowy leży w repozytorium — odmowa
#    21  katalog docelowy nie istnieje albo nie da się w nim zapisać
#    22  klucz publiczny zawiera KLUCZ PRYWATNY — odmowa
#    30  pg_dump zakończył się błędem
#    40  zrzut nie powstał albo jest za mały (PUSTA KOPIA)
#    41  pg_restore nie potrafi odczytać powstałego zrzutu
#    42  w zrzucie jest za mało tabel (zrzut nie tej bazy?)
#    50  szyfrowanie nie udało się
# =============================================================================

set -Eeuo pipefail

MIN_BAJTOW="${KOPIA_MIN_BAJTOW:-20000}"
MIN_TABEL="${KOPIA_MIN_TABEL:-20}"

ZRODLO="${KOPIA_ZRODLO:-}"
KATALOG=''
KLUCZ_PUBLICZNY=''

ZIELONY=$'\033[0;32m'
CZERWONY=$'\033[0;31m'
ZOLTY=$'\033[0;33m'
RESET=$'\033[0m'

log() { printf '[kopia] %s\n' "$*" >&2; }
ok() { printf '[kopia] %s✓%s %s\n' "${ZIELONY}" "${RESET}" "$*" >&2; }

padnij() {
  local kod="$1"
  shift
  printf '[kopia] %sKOPIA NIE POWSTAŁA (kod %s)%s\n' "${CZERWONY}" "${kod}" "${RESET}" >&2
  local linia
  for linia in "$@"; do printf '        %s\n' "${linia}" >&2; done
  exit "${kod}"
}

bez_hasla() { sed -E 's#(://[^:/@]+):[^@]*@#\1:***@#' <<<"$1"; }

# =============================================================================
#  POŚWIADCZENIE POZA LISTĄ PROCESÓW (#594)
#
#  `pg_dump "postgresql://user:HASŁO@host/db"` pokazuje hasło w `ps` KAŻDEMU
#  użytkownikowi maszyny: argumenty procesu są na Linuksie jawne. Zmierzone
#  17.09.2026 na tym skrypcie — `ps -o args=` przez cały czas trwania zrzutu
#  zawierało pełny DSN razem z hasłem produkcyjnej bazy.
#
#  Hasło idzie więc do pliku `PGPASSFILE` (prawa 600, w prywatnym katalogu
#  roboczym, który ginie razem z przebiegiem), a do narzędzi trafia DSN BEZ
#  hasła. libpq czyta plik sam; `--no-password` tego nie wyłącza — ten
#  przełącznik blokuje wyłącznie pytanie na terminalu.
#
#  `~/.pgpass` użytkownika NIE JEST dotykane. Plik żyje tylko przez ten jeden
#  przebieg.
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
schowaj_haslo_z_dsn() {
  local dsn="$1"
  rozdziel_dsn "${dsn}"

  if [[ -n "${HASLO_Z_DSN}" ]]; then
    # Plik powstaje DOPIERO gdy jest co w nim schować. Pusty PGPASSFILE
    # przesłoniłby `~/.pgpass` użytkownika i zerwałby połączenie adresem,
    # który hasła w sobie nie miał.
    if [[ -z "${PGPASSFILE:-}" || "${PGPASSFILE}" != "${SCIEZKA_PGPASS}" ]]; then
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

zaloz_pgpassfile() { SCIEZKA_PGPASS="$1"; }

pomoc() {
  cat <<'POMOC'
Kopia bazy Kuking na własny dysk (poza Railwayem).

  --zrodlo DSN            adres bazy do zrzucenia (albo zmienna KOPIA_ZRODLO)
  --katalog KATALOG       gdzie zapisać kopię; NIE w repozytorium
  --klucz-publiczny PLIK  certyfikat (część PUBLICZNA) — wtedy kopia jest
                          szyfrowana tak samo jak w serwisie kopii, więc
                          odtwarza się ją tą samą komendą (§7.4)
  -h, --help              ta pomoc

Po zrobieniu kopii ODTWÓRZ JĄ:
  scripts/proba-odtworzenia.sh --zrzut <plik> --serwer <DSN serwera próbnego>
POMOC
}

while (($# > 0)); do
  case "$1" in
    --zrodlo)
      ZRODLO="${2:-}"
      shift 2
      ;;
    --katalog)
      KATALOG="${2:-}"
      shift 2
      ;;
    --klucz-publiczny)
      KLUCZ_PUBLICZNY="${2:-}"
      shift 2
      ;;
    -h | --help)
      pomoc
      exit 0
      ;;
    *)
      pomoc >&2
      padnij 2 "Nieznany argument: $1"
      ;;
  esac
done

[[ -n "${ZRODLO}" ]] || {
  pomoc >&2
  padnij 2 'Brak --zrodlo (ani zmiennej KOPIA_ZRODLO): nie wiem, co zrzucić.'
}
[[ -n "${KATALOG}" ]] || {
  pomoc >&2
  padnij 2 'Brak --katalog: nie wiem, gdzie zapisać kopię.'
}

# =============================================================================
#  KROK 0 — narzędzia i katalog docelowy
# =============================================================================
sprawdz_narzedzia() {
  local narzedzie
  for narzedzie in pg_dump pg_restore; do
    command -v "${narzedzie}" >/dev/null 2>&1 || padnij 10 \
      "Brak narzędzia ${narzedzie}." \
      'Zainstaluj klienta PostgreSQL w wersji NIE STARSZEJ niż serwer —' \
      'starszy pg_dump ODMAWIA pracy, a to jest brak kopii, nie ostrzeżenie.'
  done

  if [[ -n "${KLUCZ_PUBLICZNY}" ]]; then
    command -v openssl >/dev/null 2>&1 || padnij 10 'Brak openssl, a podano --klucz-publiczny.'
  fi
}

sprawdz_katalog() {
  # ZRZUT NIGDY W REPOZYTORIUM. Komplet danych osobowych wszystkich kont
  # w katalogu roboczym gita kończy się kiedyś w commicie — a `git` nie
  # zapomina. Sprawdzamy to na ścieżce ROZWINIĘTEJ, bo `../../repo` i symlink
  # są dokładnie tymi kształtami, które omijają porównanie napisów.
  local katalog_repo
  katalog_repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

  local katalog_pelny
  katalog_pelny="$(cd "${KATALOG}" 2>/dev/null && pwd -P)" || padnij 21 \
    "Katalog ${KATALOG} nie istnieje." \
    'Załóż go świadomie (mkdir -p) — skrypt nie zakłada katalogów sam, żeby' \
    'literówka w ścieżce nie skończyła się kopią w miejscu, o którym nie wiesz.'

  case "${katalog_pelny}/" in
    "${katalog_repo}/"*)
      padnij 20 \
        "Katalog ${katalog_pelny} leży w repozytorium (${katalog_repo})." \
        'Zrzut bazy to komplet danych osobowych wszystkich kont — w katalogu' \
        'roboczym gita kończy się kiedyś w commicie i nie da się tego cofnąć.' \
        'Wybierz katalog poza repozytorium: dysk zewnętrzny, nośnik, katalog domowy.'
      ;;
  esac

  [[ -w "${katalog_pelny}" ]] || padnij 21 \
    "Nie mogę zapisać w ${katalog_pelny} (brak prawa zapisu)."

  KATALOG="${katalog_pelny}"
  ok "katalog docelowy: ${KATALOG}"
}

# =============================================================================
#  KROK 1 — zrzut
#
#  `pg_dump` NIE ZAPISUJE NICZEGO w bazie źródłowej: otwiera migawkę
#  REPEATABLE READ i czyta. Nie blokuje zapisów aplikacji (poza DDL-em),
#  więc wolno go uruchomić na żywej produkcji — i o to chodzi, bo kopia
#  wymagająca przestoju nie zostanie zrobiona nigdy.
# =============================================================================
zrzut() {
  ZNACZNIK="$(date -u +%Y%m%d-%H%M%S)Z"
  PLIK="${KATALOG}/kuking-${ZNACZNIK}.dump"

  log "pg_dump z $(bez_hasla "${ZRODLO}") …"

  local start koniec
  start="$(date +%s)"

  if ! pg_dump "${ZRODLO}" --no-password --format=custom --no-owner --no-privileges \
    --file="${PLIK}" 2>"${PLIK_BLEDU}"; then
    sed 's/^/  pg_dump: /' "${PLIK_BLEDU}" >&2
    rm -f "${PLIK}"
    padnij 30 \
      'pg_dump zakończył się błędem — KOPII NIE MA.' \
      'Wyjście jest wyżej. Najczęstsze przyczyny: tunel do bazy zamknął się' \
      '(railway connect musi zostać otwarty w drugim oknie), złe hasło albo' \
      'pg_dump starszy niż serwer.'
  fi

  koniec="$(date +%s)"
  CZAS_ZRZUTU=$((koniec - start))

  # Plik, który nie powstał, i plik zerobajtowy to dwa RÓŻNE stany i oba
  # kończą się tu. Drugi jest groźniejszy: `ls` go pokazuje, data się zgadza
  # i wygląda jak kopia.
  [[ -f "${PLIK}" ]] || padnij 40 \
    'pg_dump zakończył się zerowym kodem, a pliku NIE MA.' \
    'To znaczy, że zapisywał gdzie indziej, niż myślisz — sprawdź ścieżkę.'

  ROZMIAR="$(stat -c %s "${PLIK}")"

  if ((ROZMIAR < MIN_BAJTOW)); then
    log "zrzut ma ${ROZMIAR} B — kasuję go, żeby nikogo nie uśpił"
    rm -f "${PLIK}"
    padnij 40 \
      "Zrzut miał ${ROZMIAR} B, a minimum to ${MIN_BAJTOW} B." \
      'Kopia tej wielkości to nie baza Kukinga: albo zrzut pustej bazy (DSN' \
      'wskazał świeży serwis?), albo urwany zapis. Plik został SKASOWANY —' \
      'kopia, która cicho zapisuje zero bajtów, jest gorsza od braku kopii,' \
      'bo usypia.'
  fi

  ok "zrzut: ${PLIK##*/}, ${ROZMIAR} B, ${CZAS_ZRZUTU} s"
}

# =============================================================================
#  KROK 2 — weryfikacja, czyli odróżnienie „mam kopię” od „mam plik”
#
#  Zerowy kod `pg_dump` znaczy „nic nie wybuchło”, nie „mam kopię bazy
#  Kukinga”. Dwa stany przechodzą przez zerowy kod i są najgorszym możliwym
#  wynikiem tej pracy: poprawny zrzut PUSTEJ albo NIE TEJ bazy oraz archiwum
#  obcięte. Oba łapie odczytanie zrzutu z powrotem.
# =============================================================================
weryfikuj() {
  local spis
  if ! spis="$(pg_restore --list "${PLIK}" 2>"${PLIK_BLEDU}")"; then
    sed 's/^/  pg_restore: /' "${PLIK_BLEDU}" >&2
    padnij 41 \
      'pg_restore nie potrafi odczytać zrzutu, który właśnie powstał.' \
      'Archiwum jest uszkodzone — nie zostawiaj go jako kopii.'
  fi

  LICZBA_TABEL="$(grep -c 'TABLE DATA' <<<"${spis}" || true)"

  if ((LICZBA_TABEL < MIN_TABEL)); then
    padnij 42 \
      "W zrzucie jest ${LICZBA_TABEL} tabel z danymi, a minimum to ${MIN_TABEL}." \
      'Najczęstsza przyczyna: --zrodlo wskazuje inną bazę niż produkcyjna.'
  fi

  ok "w zrzucie ${LICZBA_TABEL} tabel z danymi"
}

# =============================================================================
#  KROK 3 — szyfrowanie (gdy podano klucz publiczny)
#
#  Ta sama komenda i ten sam format, co w `docker/kopia/kopia-bazy.sh`:
#  CMS/PKCS#7, AES-256 na plik, klucz sesji zamknięty RSA. Dzięki temu kopia
#  z tego skryptu odtwarza się DOKŁADNIE tą samą drogą (§7.4) — jedna
#  procedura odtworzenia, nie dwie.
# =============================================================================
#  KLUCZ SPRAWDZAMY PRZED ZRZUTEM, NIE PRZY SZYFROWANIU.
#
#  Pierwsza wersja tego skryptu sprawdzała klucz dopiero tutaj — czyli po
#  zrobieniu zrzutu. Przy sklejonej parze kluczy kończyła się wtedy
#  komunikatem „KOPIA NIE POWSTAŁA (kod 22)”, a w katalogu docelowym
#  zostawał JAWNY zrzut całej bazy, o którym nikt nie wiedział. Zmierzone
#  przy kontroli ujemnej 11.09.2026. Odmowa ma nastąpić, DOPÓKI nie ma
#  jeszcze czego ujawnić.
sprawdz_klucz() {
  [[ -n "${KLUCZ_PUBLICZNY}" ]] || return 0

  [[ -r "${KLUCZ_PUBLICZNY}" ]] || padnij 2 "Nie mogę odczytać ${KLUCZ_PUBLICZNY}."

  # TA SAMA STRAŻ, CO W SERWISIE KOPII, I Z TEGO SAMEGO POWODU.
  # `cat kuking-kopie-*.pem` skleja część publiczną z prywatną, a wynik
  # szyfruje bez najmniejszego problemu — tylko że klucz do odczytu kopii
  # leży od tej pory obok niej. Odmawiamy, a nie ostrzegamy: kopia, której
  # nie ma, jest widoczna; kopia zaszyfrowana kluczem leżącym obok nie jest.
  if grep -q 'PRIVATE KEY' "${KLUCZ_PUBLICZNY}"; then
    padnij 22 \
      "Plik ${KLUCZ_PUBLICZNY} zawiera KLUCZ PRYWATNY." \
      'Podaj TYLKO część publiczną (zaczyna się od BEGIN CERTIFICATE).' \
      'Klucz prywatny mieszka w menedżerze haseł i na nośniku offline —' \
      'docs/infra/KOPIE_I_ODTWORZENIE.md §7.1.' \
      'Zrzutu jeszcze NIE MA — nic się nie ujawniło.'
  fi

  openssl x509 -in "${KLUCZ_PUBLICZNY}" -noout >/dev/null 2>&1 || padnij 2 \
    "Plik ${KLUCZ_PUBLICZNY} nie jest certyfikatem X.509."

  ok "klucz publiczny w porządku (bez części prywatnej)"
}

szyfruj() {
  if [[ -z "${KLUCZ_PUBLICZNY}" ]]; then
    printf '[kopia] %sUWAGA: kopia NIE JEST zaszyfrowana.%s\n' "${ZOLTY}" "${RESET}" >&2
    log '  W tym pliku są adresy e-mail, hashe haseł i cała treść wszystkich kont.'
    log '  Trzymaj go na nośniku, który zamykasz, nie w chmurze i nie w Pobranych.'
    log '  Szyfrowanie: --klucz-publiczny (para kluczy — KOPIE_I_ODTWORZENIE.md §7.1).'
    return 0
  fi

  local szyfrogram="${PLIK}.cms"
  log 'szyfruję (openssl cms)…'

  if ! openssl cms -encrypt -binary -aes-256-cbc -stream \
    -in "${PLIK}" -outform DER -out "${szyfrogram}" "${KLUCZ_PUBLICZNY}" 2>"${PLIK_BLEDU}"; then
    sed 's/^/  openssl: /' "${PLIK_BLEDU}" >&2
    rm -f "${szyfrogram}"
    padnij 50 'Szyfrowanie nie udało się — zostawiam zrzut JAWNY i mówię o tym wprost.'
  fi

  ODCISK="$(openssl x509 -in "${KLUCZ_PUBLICZNY}" -noout -fingerprint -sha256 \
    | sed 's/^.*=//' | tr -d ':')"
  SKROT_JAWNEGO="$(sha256sum "${PLIK}" | cut -d' ' -f1)"

  # Zrzut jawny ginie TERAZ. Od tej linii na dysku nie ma pliku, który czyta
  # się bez klucza prywatnego.
  rm -f "${PLIK}"
  PLIK="${szyfrogram}"
  ROZMIAR="$(stat -c %s "${PLIK}")"

  ok "zaszyfrowane: ${PLIK##*/}, ${ROZMIAR} B (odcisk klucza ${ODCISK:0:16}…)"
}

# =============================================================================
#  KROK 4 — plik `.meta`, w tym samym kształcie co w buckecie (§7.2)
# =============================================================================
zapisz_meta() {
  local meta="${KATALOG}/kuking-${ZNACZNIK}.meta"

  {
    printf '# Kuking.pl — metadane kopii lokalnej. Bez danych osobowych.\n'
    printf 'znacznik: %s\n' "${ZNACZNIK}"
    printf 'zrodlo_kopii: scripts/kopia-lokalna.sh\n'
    printf 'pg_dump: %s\n' "$(pg_dump --version | sed -E 's/[^0-9]*([0-9]+).*/\1/')"
    printf 'tabel_z_danymi: %s\n' "${LICZBA_TABEL}"
    printf 'rozmiar_bajty: %s\n' "${ROZMIAR}"
    if [[ -n "${KLUCZ_PUBLICZNY}" ]]; then
      printf 'szyfrowanie: CMS/PKCS7 EnvelopedData, AES-256-CBC + RSA\n'
      printf 'sha256_jawnego: %s\n' "${SKROT_JAWNEGO}"
      printf 'odcisk_certyfikatu_sha256: %s\n' "${ODCISK}"
      printf '# Certyfikat (klucz PUBLICZNY) do -recip przy odczycie.\n'
      cat "${KLUCZ_PUBLICZNY}"
    else
      printf 'szyfrowanie: BRAK\n'
      printf 'sha256_pliku: %s\n' "$(sha256sum "${PLIK}" | cut -d' ' -f1)"
    fi
    printf 'odtworzenie: scripts/proba-odtworzenia.sh; docs/infra/KOPIE_I_ODTWORZENIE.md §7.4 i §8\n'
  } >"${meta}"

  ok "metadane: ${meta##*/}"
}

# =============================================================================
#  PRZEBIEG
# =============================================================================
KATALOG_ROBOCZY=''
PLIK=''
ZNACZNIK=''
ROZMIAR=0
LICZBA_TABEL=0
CZAS_ZRZUTU=0
ODCISK=''
SKROT_JAWNEGO=''
SCIEZKA_PGPASS=''
DSN_BEZ_HASLA=''
HASLO_Z_DSN=''
HOST_Z_DSN='*'
PORT_Z_DSN='*'
UZYTKOWNIK_Z_DSN='*'

sprzataj() {
  local kod=$?
  if [[ -n "${KATALOG_ROBOCZY}" && -d "${KATALOG_ROBOCZY}" ]]; then
    rm -rf "${KATALOG_ROBOCZY}"
  fi
  return "${kod}"
}

main() {
  sprawdz_narzedzia
  sprawdz_katalog
  sprawdz_klucz

  KATALOG_ROBOCZY="$(mktemp -d "${TMPDIR:-/tmp}/kopia-lokalna.XXXXXX")"
  trap sprzataj EXIT
  PLIK_BLEDU="${KATALOG_ROBOCZY}/blad.txt"

  # Od tej linii `ZRODLO` nie zawiera już hasła — leży ono w PGPASSFILE.
  zaloz_pgpassfile "${KATALOG_ROBOCZY}/pgpass"
  schowaj_haslo_z_dsn "${ZRODLO}"
  ZRODLO="${DSN_BEZ_HASLA}"

  zrzut
  weryfikuj
  szyfruj
  zapisz_meta

  printf '\n%sKOPIA POWSTAŁA.%s  %s\n' "${ZIELONY}" "${RESET}" "${PLIK}" >&2
  printf '\n%sI DOPÓKI JEJ NIE ODTWORZYSZ, JEST OBIETNICĄ, NIE KOPIĄ.%s\n' "${ZOLTY}" "${RESET}" >&2
  printf 'Następny krok, teraz, nie kiedyś:\n' >&2
  printf '  scripts/proba-odtworzenia.sh --zrzut %s \\\n' "${PLIK}" >&2
  printf '    --serwer "<DSN serwera, na którym wolno założyć bazę próbną>"' >&2
  if [[ -n "${KLUCZ_PUBLICZNY}" ]]; then
    printf ' \\\n    --klucz <klucz PRYWATNY>' >&2
  fi
  printf '\n' >&2
  printf '\nĆwiczysz lokalnie? Całą pętlę (kopia → odtworzenie → porównanie każdej\n' >&2
  printf 'tabeli → migrate:status) robi jedna komenda:\n' >&2
  printf '  scripts/proba-odtworzenia.sh --petla-lokalna\n' >&2
  printf '\nProcedura krok po kroku: docs/infra/KOPIE_I_ODTWORZENIE.md §8\n' >&2
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  main "$@"
fi
