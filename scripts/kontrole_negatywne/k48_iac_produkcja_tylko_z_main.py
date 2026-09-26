"""IaC: plan produkcji tylko dla PR-a do main (#1313). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# IaC: plan produkcji tylko dla PR-a do `main` (#1313). Apply jest ręczny
# (workflow_dispatch z `main`, #595), więc zamki dotyczą joba plan: filtr
# `branches` w `on.pull_request` i `base.ref == 'main'` w jego warunku.
# Mutacje zdejmują po kolei każdy z nich.
IAC_PRODUKCJA = ".github/workflows/railway-iac.yml"
IAC_PRODUKCJA_TEST = "IacProdukcjaTylkoZPrDoMainTest"
IAC_GALAZ_W_WARUNKU = "      github.event.pull_request.base.ref == 'main' &&\n"


KONTROLE_DODATNIE = [IAC_PRODUKCJA_TEST]

KONTROLE = [
    Kontrola("IaC: plan produkcji bez filtra gałęzi docelowej", IAC_PRODUKCJA, IAC_PRODUKCJA_TEST,
     lambda s: replace_once(s, "    branches: [main]\n", "")),
    Kontrola("IaC: plan produkcji bez base.ref == main", IAC_PRODUKCJA, IAC_PRODUKCJA_TEST,
     lambda s: replace_once(s, IAC_GALAZ_W_WARUNKU, "")),
]
