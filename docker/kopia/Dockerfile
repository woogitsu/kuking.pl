# syntax=docker/dockerfile:1.7
# =============================================================================
#  Kuking.pl — obraz serwisu KOPII BAZY (issue #193, decyzja D-043)
# =============================================================================
#
#  To jest DRUGI, osobny obraz w tym repozytorium. Główny (`/Dockerfile`)
#  uruchamia aplikację; ten robi jedną rzecz: zrzut bazy, szyfruje go
#  i wypycha poza Railwaya. Nie ma w nim PHP, Laravela, Composera ani kodu
#  aplikacji — i to nie jest oszczędność, tylko sens całej decyzji D-043:
#
#    * `docker/php.ini` w obrazie aplikacji wyłącza `proc_open`, bez którego
#      `pg_dump` z PHP nie wystartuje. `AGENTS.md` zabrania to osłabiać.
#      Tutaj nie ma PHP, więc nie ma czego osłabiać.
#    * Kontener, który trzyma w rękach zrzut CAŁEJ bazy, powinien mieć
#      najmniejszą możliwą powierzchnię. Każda paczka to kod z prawem
#      przeczytania tego pliku.
#
#  DLACZEGO OBRAZ BAZOWY `postgres:18`, A NIE DEBIAN + REPOZYTORIUM PGDG
#  --------------------------------------------------------------------
#  `pg_dump` STARSZY od serwera odmawia pracy — nie ostrzega, odmawia:
#
#      pg_dump: error: server version: 18.1; pg_dump version: 16.13
#      pg_dump: error: aborting because of server version mismatch
#
#  Czyli zła wersja klienta to brak kopii. Obraz `postgres:18` niesie
#  `pg_dump` dokładnie w wersji 18 z tego samego źródła, z którego Railway
#  bierze serwer, i nie wymaga dokładania repozytorium APT ani zgadywania,
#  czy dana wersja Debiana ma paczkę `postgresql-client-18`.
#
#  KIEDY TEN TAG TRZEBA ZMIENIĆ: gdy Railway podniesie serwer do 19.
#  Sam skrypt sprawdza zgodność przy KAŻDYM przebiegu i odmawia pracy
#  z alarmem, zamiast po cichu przestać robić kopie.
#
#  CZEGO NIE MA W TYM OBRAZIE ŚWIADOMIE
#  ------------------------------------
#  `aws` CLI (Python + botocore), `rclone`, `jq`, `age`, `gpg`. Podpis AWS
#  SigV4 liczymy w `docker/kopia/s3.sh` nad `openssl` i `curl`, szyfrujemy
#  CMS-em z `openssl`. Uzasadnienie w nagłówku tamtego pliku.
# =============================================================================

FROM postgres:18

LABEL org.opencontainers.image.title="kuking.pl — kopia bazy"
LABEL org.opencontainers.image.source="https://github.com/woogitsu/kuking.pl"
LABEL org.opencontainers.image.description="pg_dump 18 + szyfrowanie kluczem publicznym + wysyłka do S3/R2"

# `openssl` (szyfrowanie i podpis SigV4), `curl` (wysyłka i webhook alarmu),
# `ca-certificates` (weryfikacja TLS wobec R2 i webhooka). Trzy paczki
# z głównego repozytorium Debiana, żadnej z zewnątrz.
#
# `postgres:18` niesie już bibliotekę OpenSSL, ale nie gwarantuje narzędzia
# `openssl` w PATH — a bez niego skrypt nie zaszyfruje ani nie podpisze
# niczego. Instalujemy je więc jawnie, zamiast liczyć na obraz bazowy.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      openssl \
      curl \
      ca-certificates; \
    rm -rf /var/lib/apt/lists/*

# Skrypty. Oba w JEDNYM katalogu, bo `kopia-bazy.sh` wczytuje `s3.sh`
# ze swojego własnego katalogu (`dirname "${BASH_SOURCE[0]}"`) — bez ścieżki
# wpisanej na stałe, żeby ten sam plik dał się uruchomić i przetestować
# wprost z repozytorium.
COPY docker/kopia/s3.sh          /usr/local/lib/kuking/s3.sh
COPY docker/kopia/kopia-bazy.sh  /usr/local/lib/kuking/kopia-bazy.sh
RUN chmod 0555 /usr/local/lib/kuking/s3.sh /usr/local/lib/kuking/kopia-bazy.sh

# NIE JAKO ROOT. Ten kontener nie montuje woluminu i nie potrzebuje niczego
# poza `/tmp`, więc — inaczej niż obraz aplikacji — nie ma tu ANI JEDNEJ
# rzeczy wymagającej roota. Użytkownik `postgres` (uid 999) jest w obrazie
# bazowym.
USER postgres

# Zrzut jawny powstaje w katalogu tymczasowym i ginie natychmiast po
# zaszyfrowaniu (`szyfruj()` w skrypcie). Filesystem kontenera jest ulotny,
# ale to nie jest powód, żeby trzymać komplet danych osobowych dłużej.
ENV KOPIA_KATALOG_ROBOCZY=/tmp \
    KOPIA_S3_REGION=auto \
    KOPIA_PREFIKS=baza/

# Obraz bazowy ma ENTRYPOINT `docker-entrypoint.sh`, który chce inicjować
# klaster bazy danych. Nadpisujemy go w całości — ten kontener nie stawia
# bazy, tylko się do niej łączy.
ENTRYPOINT []
CMD ["/usr/local/lib/kuking/kopia-bazy.sh"]
