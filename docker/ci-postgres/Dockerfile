# =============================================================================
#  Kuking.pl — obraz usługi PostgreSQL w .github/workflows/ci.yml (#2263)
# =============================================================================
#  TEN PLIK NIE JEST BUDOWANY. Siedem jobów CI stawia usługę `postgres` z obrazu
#  `postgres:18-alpine@sha256:...`. Dependabot nie czyta obrazów usług
#  w workflowach. Czyta za to linie FROM w Dockerfile'ach z katalogów wpisanych
#  w .github/dependabot.yml (ekosystem `docker`), więc przez tę linię widzi
#  ten obraz. Gdy pod tagiem pojawi się nowy obraz, otworzy PR z nowym
#  digestem.
#
#  Digest tutaj i we wszystkich `image:` w ci.yml musi być TEN SAM. Pilnuje
#  tego tests/Feature/InstalacjeCiSaPrzypieteTest.php, więc PR Dependabota
#  będzie czerwony, dopóki ktoś nie przepisze digestu do ci.yml (instrukcja
#  w komentarzu nad pierwszą usługą `postgres` w ci.yml).
#
#  Major zostaje 18 (Dependabot ignoruje semver-major): usługa ma odpowiadać
#  serwerowi na Railwayu, jak obraz kopii w docker/kopia/Dockerfile.
#  Sprawdzone 30.09.2026: indeks wieloplatformowy, amd64 ma PG_VERSION=18.6.
# =============================================================================
FROM postgres:18-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873
