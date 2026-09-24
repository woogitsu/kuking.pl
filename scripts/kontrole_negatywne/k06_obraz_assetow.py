"""Etap `assets` obrazu a lista plików podana do `node --test` (regresja #1085).

Ten strażnik pilnuje własnej NIEPUSTOŚCI (`assertNotEmpty`), ale nic w nim
nie dowodzi, że czytnik `COPY` z Dockerfile potrafi powiedzieć „nie
kopiowany". Gdyby parser zaczął zwracać zbiór za szeroki, `czyKopiowany()`
byłoby zawsze prawdziwe, a test świeciłby na zielono nad niczym — dokładnie
ta klasa usterki, dla której powstał mechanizm kontroli dodatnich.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


OBRAZ_ASSETOW = "Dockerfile"
OBRAZ_ASSETOW_TEST = "ObrazAssetowMaPlikiTestowTest"


def bez_kopii_testu_assetow(source):
    """KONTROLA DODATNIA: zabierz etapowi `assets` jeden z plików `node --test`.

    `package.json` nadal podaje `scripts/pwa-install.test.mjs` do `node --test`,
    więc po tej mutacji Dockerfile obiecuje mniej, niż wymaga budowanie. Test
    ma to zauważyć; Node sam by nie zauważył, bo brakujący plik pomija bez błędu.
    """
    return replace_once(
        source,
        "COPY scripts/pwa-install.test.mjs ./scripts/pwa-install.test.mjs\n",
        "",
    )


KONTROLE_DODATNIE = [OBRAZ_ASSETOW_TEST]

KONTROLE = [
    Kontrola("Plik z node --test nieskopiowany do etapu assets", OBRAZ_ASSETOW, OBRAZ_ASSETOW_TEST,
             bez_kopii_testu_assetow),
]
