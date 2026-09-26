"""D-262 (AGENTS.md §5): zamknięta lista selektorów wyjątku panelu moderacji.
Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


CSS = "resources/css/app.css"


KONTROLE = [
    # D-262 (AGENTS.md §5): piąty selektor nie może powołać się na wyjątek
    # panelu moderacji bez zmiany zamkniętej listy.
    Kontrola("Piąty selektor powołuje się na D-262", CSS, "WyjatekD262ZamknietaListaTest",
     lambda s: replace_once(s, "\n  .badge-cichy {\n", "\n  /* wyjątek D-262 */\n  .badge-cichy {\n")),
]
