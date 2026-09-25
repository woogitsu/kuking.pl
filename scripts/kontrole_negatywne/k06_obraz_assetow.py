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
    """KONTROLA DODATNIA: zabierz etapowi `assets` pliki `node --test` z `scripts/`.

    Etap kopiuje dziś cały katalog (`COPY scripts ./scripts`). Mutacja cofa go
    do wyliczanki z jednym plikiem — samym `kontrast-marki.mjs`, którego
    potrzebuje pierwszy człon `build` — czyli do dokładnie tej regresji, przed
    którą chroni ten test: `package.json` nadal podaje do `node --test` pliki
    `scripts/*.test.mjs`, a Dockerfile przestaje je obiecywać. Test ma to
    zauważyć; Node sam by nie zauważył, bo brakujący plik pomija bez błędu.
    """
    return replace_once(
        source,
        "COPY scripts ./scripts\n",
        "COPY scripts/kontrast-marki.mjs ./scripts/kontrast-marki.mjs\n",
    )


KONTROLE_DODATNIE = [OBRAZ_ASSETOW_TEST]

KONTROLE = [
    Kontrola("Plik z node --test nieskopiowany do etapu assets", OBRAZ_ASSETOW, OBRAZ_ASSETOW_TEST,
             bez_kopii_testu_assetow,
             oczekuj=r"Etap `assets` w Dockerfile nie kopiuje `scripts/.*\.test\.mjs`"),
]
