#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — klient S3 (Cloudflare R2) w powłoce: podpis AWS SigV4 + curl
# =============================================================================
#
#  DLACZEGO WŁASNY PODPIS, A NIE `aws` CLI ALBO `rclone`
#  -----------------------------------------------------
#  Ten kontener trzyma w rękach ZRZUT CAŁEJ BAZY, czyli komplet danych
#  osobowych wszystkich kont. Każda paczka, którą tu dołożymy, to kod
#  z prawem czytania tego pliku i prawem gadania po sieci. `aws` CLI to
#  Python + botocore (kilkadziesiąt megabajtów i własny łańcuch zależności),
#  `rclone` to binarka pobierana z internetu przy budowie obrazu.
#
#  Podpis SigV4 to natomiast sto linii nad `openssl dgst` i `curl` — jedno
#  i drugie jest w Debianie od zawsze, oba i tak są w tym obrazie potrzebne
#  (openssl szyfruje zrzut, curl dzwoni na webhook alarmu). Efekt: obraz
#  bez ani jednej paczki dołożonej WYŁĄCZNIE po to, żeby wysłać plik.
#  Ta sama zasada, z której powstał własny transport poczty (D-047).
#
#  CZEGO TEN KLIENT NIE POTRAFI, ŚWIADOMIE
#  ---------------------------------------
#  Multipart upload. Pojedyncze `PUT` przyjmuje do 5 GiB (limit S3 i R2), więc
#  `kopia-bazy.sh` sprawdza rozmiar PRZED wysyłką i woli alarm od wysłania
#  czegoś, co się nie zmieści. Gdy baza dorośnie do tego rzędu, multipart
#  będzie osobną, świadomą decyzją — nie cichą awarią o trzeciej nad ranem.
#
#  ADRESOWANIE PATH-STYLE
#  R2 wystawia S3 pod `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, gdzie
#  nazwa bucketu jest PIERWSZYM segmentem ścieżki. Nie używamy adresowania
#  przez host (`<bucket>.<konto>.r2...`), bo wymagałoby osobnego DNS-u,
#  a zysku nie daje żadnego.
#
#  Zmienne wejściowe (ustawia je serwis Railway, patrz .railway/railway.ts):
#    KOPIA_S3_ENDPOINT  https://<ACCOUNT_ID>.r2.cloudflarestorage.com
#    KOPIA_S3_BUCKET    nazwa bucketu z kopiami (osobny od bucketów zdjęć!)
#    KOPIA_S3_KLUCZ     Access Key ID tokenu R2 z prawem zapisu do tego bucketu
#    KOPIA_S3_SEKRET    Secret Access Key tego tokenu
#    KOPIA_S3_REGION    dla R2 literalnie „auto" (wartość domyślna)
#
#  Ten plik jest WYŁĄCZNIE biblioteką — nie robi nic po wczytaniu.
#  Testy: tests/skrypty/kopia-bazy.sh
# =============================================================================

# --- prymitywy kryptograficzne ----------------------------------------------
#
# `openssl dgst -hex` wypisuje „SHA2-256(stdin)= <hex>" albo samo „<hex>",
# zależnie od wersji openssl — dlatego ucinamy wszystko do ostatniego „= ".

s3_sha256() { openssl dgst -sha256 -hex | sed 's/^.*= *//'; }

s3_sha256_pliku() { openssl dgst -sha256 -hex "$1" | sed 's/^.*= *//'; }

s3_hmac() { openssl dgst -sha256 -mac HMAC -macopt "hexkey:$1" -hex | sed 's/^.*= *//'; }

# Bajty ze stdin na hex. `od`, nie `xxd`: `xxd` jest w paczce `vim-common`,
# `od` w `coreutils`, czyli w każdym obrazie bez wyjątku.
s3_hex() { od -A n -v -t x1 | tr -d ' \n'; }

# -----------------------------------------------------------------------------
#  s3_podpis — sygnatura AWS SigV4.
#
#  Blok nagłówków kanonicznych i lista podpisanych nagłówków są ARGUMENTAMI,
#  a nie wartościami zaszytymi w środku — i to nie jest ogólność na zapas.
#  Dzięki temu ta sama funkcja, której używa produkcja, daje się sprawdzić
#  wprost wobec URZĘDOWEGO wektora AWS `get-vanilla` (dwa nagłówki), a nie
#  tylko wobec własnego, trzynagłówkowego przypadku. Test, który sprawdza
#  kod jedynie wobec siebie samego, potwierdza tylko własne nieporozumienie.
#  Wektory: tests/skrypty/kopia-bazy.sh.
#
#  Argumenty: metoda ścieżka zapytanie nagłówki_kanoniczne podpisane
#             hash_ciała amz_data dzień region usługa sekret
#
#  `nagłówki_kanoniczne` — posortowane, małymi literami, KAŻDY zakończony \n.
#  `podpisane`           — te same nazwy, po średniku, w tej samej kolejności.
# -----------------------------------------------------------------------------
s3_podpis() {
  local metoda="$1" sciezka="$2" zapytanie="$3" naglowki="$4" podpisane="$5"
  local hash_ciala="$6" amz_data="$7" dzien="$8" region="$9" usluga="${10}"
  local sekret="${11}"

  # UWAGA NA PUSTĄ LINIĘ: między blokiem nagłówków a listą podpisanych
  # nagłówków MUSI stać dokładnie jedna. `${naglowki}` kończy się już
  # znakiem nowej linii, więc daje ją przejście do następnego wiersza
  # w tym literale. Nadmiarowa albo brakująca linia zmienia skrót
  # i kończy się HTTP 403 „SignatureDoesNotMatch".
  local kanoniczne="${metoda}
${sciezka}
${zapytanie}
${naglowki}
${podpisane}
${hash_ciala}"

  local hash_kanonicznego
  hash_kanonicznego="$(printf '%s' "${kanoniczne}" | s3_sha256)"

  local do_podpisu="AWS4-HMAC-SHA256
${amz_data}
${dzien}/${region}/${usluga}/aws4_request
${hash_kanonicznego}"

  local klucz
  klucz="$(printf 'AWS4%s' "${sekret}" | s3_hex)"
  klucz="$(printf '%s' "${dzien}" | s3_hmac "${klucz}")"
  klucz="$(printf '%s' "${region}" | s3_hmac "${klucz}")"
  klucz="$(printf '%s' "${usluga}" | s3_hmac "${klucz}")"
  klucz="$(printf '%s' 'aws4_request' | s3_hmac "${klucz}")"

  printf '%s' "${do_podpisu}" | s3_hmac "${klucz}"
}

# -----------------------------------------------------------------------------
#  s3_zadanie — jedno żądanie do S3/R2.
#
#    $1 metoda      GET | PUT | HEAD | DELETE
#    $2 klucz       klucz obiektu (bez bucketu, bez wiodącego „/"); pusty
#                   dla operacji na całym buckecie (listowanie)
#    $3 zapytanie   kanoniczny query string, już posortowany i zakodowany
#                   (np. „list-type=2&prefix=baza%2F") albo pusty
#    $4 plik_ciala  plik wysyłany jako ciało (PUT) albo pusty
#    $5 plik_wyniku gdzie zapisać odpowiedź albo pusty (do /dev/null)
#
#  Zwraca 0 przy 2xx. Przy innym kodzie zwraca 1 i USTAWIA `S3_KOD`.
#
#  CO NIE WYCHODZI Z TEJ FUNKCJI: treść odpowiedzi błędu ani adres żądania.
#  Wyżej trafia sam kod HTTP — bo w komunikacie błędu S3 potrafi wylądować
#  klucz obiektu, a alarm idzie do usługi, nad którą nie mamy kontroli
#  (ta sama zasada, co w App\Logging\WebhookBleduHandler po audycie A6-01).
# -----------------------------------------------------------------------------
S3_KOD=''

s3_zadanie() {
  local metoda="$1" klucz="$2" zapytanie="${3:-}" plik_ciala="${4:-}" plik_wyniku="${5:-}"

  local endpoint="${KOPIA_S3_ENDPOINT:?brak KOPIA_S3_ENDPOINT}"
  local bucket="${KOPIA_S3_BUCKET:?brak KOPIA_S3_BUCKET}"
  local region="${KOPIA_S3_REGION:-auto}"

  local host="${endpoint#https://}"
  host="${host#http://}"
  host="${host%%/*}"

  local sciezka="/${bucket}"
  [[ -n "${klucz}" ]] && sciezka="/${bucket}/${klucz}"

  local hash_ciala
  if [[ -n "${plik_ciala}" ]]; then
    hash_ciala="$(s3_sha256_pliku "${plik_ciala}")"
  else
    # sha256 pustego ciągu — wartość stała, ale liczymy ją, żeby nie wklejać
    # do kodu magicznego hexa, którego nikt nigdy nie zweryfikuje.
    hash_ciala="$(printf '' | s3_sha256)"
  fi

  local amz_data dzien
  amz_data="$(date -u +%Y%m%dT%H%M%SZ)"
  dzien="${amz_data%%T*}"

  # Nagłówki kanoniczne: posortowane, małymi literami, każdy zakończony \n.
  local naglowki="host:${host}
x-amz-content-sha256:${hash_ciala}
x-amz-date:${amz_data}
"
  local podpisane='host;x-amz-content-sha256;x-amz-date'

  local sygnatura
  sygnatura="$(s3_podpis "${metoda}" "${sciezka}" "${zapytanie}" \
    "${naglowki}" "${podpisane}" \
    "${hash_ciala}" "${amz_data}" "${dzien}" "${region}" 's3' \
    "${KOPIA_S3_SEKRET:?brak KOPIA_S3_SEKRET}")"

  local autoryzacja="AWS4-HMAC-SHA256 Credential=${KOPIA_S3_KLUCZ:?brak KOPIA_S3_KLUCZ}/${dzien}/${region}/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=${sygnatura}"

  local adres="${endpoint%/}${sciezka}"
  [[ -n "${zapytanie}" ]] && adres="${adres}?${zapytanie}"

  local -a polecenie=(
    curl --silent --show-error --fail-with-body
    --max-time "${KOPIA_S3_TIMEOUT:-600}"
    --retry 2 --retry-delay 5
    --output "${plik_wyniku:-/dev/null}"
    --write-out '%{http_code}'
    --request "${metoda}"
    --header "Host: ${host}"
    --header "x-amz-content-sha256: ${hash_ciala}"
    --header "x-amz-date: ${amz_data}"
    --header "Authorization: ${autoryzacja}"
  )

  # `HEAD` bez `--head` zawiesiłby curla w oczekiwaniu na ciało, którego
  # serwer nie wyśle.
  [[ "${metoda}" == 'HEAD' ]] && polecenie+=(--head)
  [[ -n "${plik_ciala}" ]] && polecenie+=(--upload-file "${plik_ciala}")

  # Kod HTTP zbieramy ZAWSZE, także gdy curl zwróci błąd — inaczej
  # rozróżnienie „403 zły podpis" od „brak sieci" znika. Ale kod WYJŚCIA
  # curla zbieramy OSOBNO, zamiast wyrzucać go przez `|| true` — powód niżej.
  local kod_curla=0
  S3_KOD="$("${polecenie[@]}" "${adres}" 2>/dev/null)" || kod_curla=$?

  # -------------------------------------------------------------------------
  #  SAM KOD HTTP NIE WYSTARCZY — USTERKA ODTWORZONA 18.09.2026
  #
  #  Status odpowiedzi przychodzi PRZED ciałem. Gdy połączenie urwie się
  #  w połowie ciała albo wyczerpie `--max-time`, curl trzyma już w ręku
  #  „200", a plik jest NIEPEŁNY. Samo `case 2*` uznawało to za sukces.
  #
  #  Zmierzone wobec serwera oddającego 200 i połowę ciała ListObjectsV2:
  #  `s3_lista_kluczy` zwracała 0 i CZTERY klucze zamiast dziewięciu, więc
  #  `sprawdz_poprzednia_kopie` brała za najnowszą kopię sprzed czterech dni
  #  i alarmowała o przestoju, którego nie było.
  #
  #  `IsTruncated` tego nie łapie: ten element stoi w odpowiedzi PRZED
  #  `<Contents>` (sprawdzone na prawdziwej odpowiedzi), więc obcięcie ciała
  #  zabiera klucze, a znacznik stronicowania zostawia nietknięty.
  #  `--fail-with-body` też nie — on patrzy wyłącznie na status HTTP.
  #  Łapie to dopiero kod wyjścia curla: 18 (ciało krótsze, niż obiecał
  #  `Content-Length`), 28 (limit czasu), 55/56 (zerwany zapis/odczyt).
  # -------------------------------------------------------------------------
  if ((kod_curla != 0)); then
    case "${S3_KOD}" in
      2*)
        # 2xx RAZEM z błędem curla znaczy dokładnie jedno: nagłówek doszedł,
        # ciało nie. Mówimy to wprost, zamiast meldować sukces.
        S3_KOD="${S3_KOD}/urwany-curl-${kod_curla}"
        return 1
        ;;
    esac
  fi

  case "${S3_KOD}" in
    2*) return 0 ;;
    *) return 1 ;;
  esac
}

# -----------------------------------------------------------------------------
#  s3_lista_kluczy — klucze obiektów z danym prefiksem, po jednym na linię.
#
#  Parsujemy XML `grep`em, i to jest tu poprawne, a nie skrótem: odpowiedź
#  ListObjectsV2 ma jeden kształt, a nasze klucze same generujemy (bez znaków
#  wymagających encji XML). Alternatywą byłoby doniesienie parsera XML do
#  obrazu, który trzyma komplet danych osobowych — patrz nagłówek pliku.
#
#  Stronicowanie: R2 oddaje do 1000 kluczy na stronę. Przy retencji liczonej
#  w tygodniach nigdy się do tego nie zbliżymy, ale gdyby ktoś zmienił
#  retencję na „trzymaj wszystko", cisza byłaby najgorszym wyjściem —
#  dlatego niedokończone listowanie kończy się błędem, nie obcięciem.
# -----------------------------------------------------------------------------
s3_lista_kluczy() {
  local prefiks="$1"
  local plik
  plik="$(mktemp)"

  # Prefiks w query stringu musi być zakodowany procentowo — „/" to %2F.
  # Kanoniczne zapytanie i adres muszą być IDENTYCZNE, dlatego kodujemy raz.
  local prefiks_url="${prefiks//\//%2F}"

  if ! s3_zadanie GET '' "list-type=2&prefix=${prefiks_url}" '' "${plik}"; then
    rm -f "${plik}"
    return 1
  fi

  if grep -q '<IsTruncated>true</IsTruncated>' "${plik}"; then
    rm -f "${plik}"
    return 2
  fi

  tr '<' '\n' <"${plik}" | sed -n 's/^Key>//p'
  rm -f "${plik}"
}

# -----------------------------------------------------------------------------
#  s3_lista_kluczy_do_pliku — to samo listowanie, ale klucze lądują w PLIKU.
#
#    $1 prefiks   jak wyżej
#    $2 plik      gdzie zapisać klucze (po jednym na linię)
#
#  Kod powrotu jak w `s3_lista_kluczy`: 0, 1 (błąd HTTP), 2 (lista obcięta).
#
#  PO CO TO ISTNIEJE — USTERKA ODTWORZONA 18.09.2026
#  `s3_lista_kluczy` wypisuje klucze na standardowe wyjście, więc naturalne
#  wywołanie brzmi `klucze="$(s3_lista_kluczy baza/)"`. I to jest pułapka:
#  podstawienie poleceń uruchamia funkcję w PODPOWŁOCE, a `S3_KOD` ustawia
#  się właśnie w niej — i ginie razem z nią. Wołający dostawał kod powrotu 1
#  i PUSTĄ zmienną, więc komunikat „HTTP ${S3_KOD:-brak}" zawsze kończył się
#  słowem „brak" — mimo że ta biblioteka obiecuje w nagłówku `s3_zadanie`,
#  że przy błędzie kod HTTP USTAWIA.
#
#  Dlaczego to nie jest kosmetyka. Zmierzone wobec prawdziwego endpointu S3
#  (MinIO w kontenerze, ta sama ścieżka co R2): token bez prawa do bucketu
#  daje 403, a bucket o złej nazwie 404. To są dwie różne awarie
#  z dwiema różnymi naprawami — jedna to uprawnienia tokenu, druga to
#  literówka w nazwie albo bucket, którego nikt nie założył. W logu wyglądały
#  identycznie, a alarm ma dla obu ten sam odcisk, bo liczy się go z etapu
#  i kodu wyjścia. Człowiek o trzeciej nad ranem nie miał z czego zgadnąć,
#  czego szukać.
#
#  Przekierowanie do pliku podpowłoki NIE TWORZY (w odróżnieniu od `$( )`),
#  więc `S3_KOD` dożywa do komunikatu w `kopia-bazy.sh`.
# -----------------------------------------------------------------------------
s3_lista_kluczy_do_pliku() {
  s3_lista_kluczy "$1" >"$2"
}
