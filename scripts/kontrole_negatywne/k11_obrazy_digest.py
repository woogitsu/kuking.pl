"""Obrazy bazowe przypięte do digestów (#952).

Strażnik parsuje linie FROM w Dockerfile'ach; mutacja zdejmuje digest z obrazu
kopii i ma go zapalić — dowód, że parser widzi też drugi Dockerfile, a nie
tylko główny.
"""

from kontrole_negatywne._narzedzia import Kontrola


OBRAZ_KOPII = "docker/kopia/Dockerfile"
OBRAZY_DIGEST_TEST = "ObrazyBazowePrzypieteDoDigestowTest"


def bez_digestu_obrazu_kopii(source):
    """KONTROLA DODATNIA: wróć w obrazie kopii do gołego, ruchomego tagu.

    `FROM postgres:18` buduje się dalej zielono — dlatego tylko mutacja
    dowodzi, że `test_kazdy_from_w_kazdym_dockerfile_ma_digest` to zauważy.
    """
    start = source.index("FROM postgres:18@sha256:")
    end = source.index("\n", start)

    return source[:start] + "FROM postgres:18" + source[end:]


KONTROLE_DODATNIE = [OBRAZY_DIGEST_TEST]

KONTROLE = [
    Kontrola("Obraz bazowy bez digestu", OBRAZ_KOPII, OBRAZY_DIGEST_TEST,
             bez_digestu_obrazu_kopii,
             oczekuj=r"Obraz bazowy bez digestu.*docker/kopia/Dockerfile:\d+ — postgres:18\b"),
]
