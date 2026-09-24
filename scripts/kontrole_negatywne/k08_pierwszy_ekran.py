"""Pierwszy ekran strony powitalnej: blok reguł w warstwie `marka` rusza tylko odstępy.

Najtańsza „naprawa" przycisku pod zgięciem to mniejsze pismo — i właśnie tego
strażnik pilnuje, czytając arkusz.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


PIERWSZY_EKRAN_CSS = "resources/css/marka-ekrany.css"
PIERWSZY_EKRAN_TEST = "PierwszyEkranMiesciPrzyciskTest"


def mniejsze_pismo_na_pierwszym_ekranie(source):
    """KONTROLA DODATNIA: zmieść przycisk pod zgięciem mniejszym pismem.

    Blok pierwszego ekranu dostaje `font-size` przy akapicie hasła — skrót,
    którego poprawka świadomie nie zrobiła (progi pisma z docs/UX_50_PLUS.md).
    `test_skrocenie_nie_zostalo_oplacone_pismem_ani_celem_dotkniecia` ma zapalić.
    """
    return replace_once(
        source,
        "    .hero .hero-lead {\n      margin-bottom: var(--spacing-4);\n",
        "    .hero .hero-lead {\n      font-size: 1rem;\n      margin-bottom: var(--spacing-4);\n",
    )


KONTROLE_DODATNIE = [PIERWSZY_EKRAN_TEST]

KONTROLE = [
    Kontrola("Pierwszy ekran opłacony mniejszym pismem", PIERWSZY_EKRAN_CSS, PIERWSZY_EKRAN_TEST,
             mniejsze_pismo_na_pierwszym_ekranie),
]
