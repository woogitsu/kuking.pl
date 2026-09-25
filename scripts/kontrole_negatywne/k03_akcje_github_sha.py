"""Akcje GitHuba przypięte do pełnych SHA (#951).

Strażnik parsuje `uses:` w workflowach i akcjach composite; mutacja cofa akcję
PHP do ruchomego tagu i ma go zapalić — dowód, że widzi też
`.github/actions/**`, nie tylko workflowy.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


AKCJA_PHP = ".github/actions/php/action.yml"
AKCJE_SHA_TEST = "AkcjeGithubPrzypieteDoShaTest"


def akcja_php_na_ruchomym_tagu(source):
    """KONTROLA DODATNIA: wróć w akcji composite do gołego tagu `@v2`.

    Workflow z `setup-php@v2` działa dalej zielono — dlatego tylko mutacja
    dowodzi, że `test_kazde_zewnetrzne_uses_ma_pelny_sha_i_dokladna_wersje`
    to zauważy.
    """
    return replace_once(
        source,
        "uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2",
        "uses: shivammathur/setup-php@v2",
    )


KONTROLE_DODATNIE = [AKCJE_SHA_TEST]

KONTROLE = [
    Kontrola("Akcja GitHuba na ruchomym tagu", AKCJA_PHP, AKCJE_SHA_TEST, akcja_php_na_ruchomym_tagu,
             oczekuj=r"Zewnętrzna akcja bez pełnego SHA.*setup-php@v2"),
]
