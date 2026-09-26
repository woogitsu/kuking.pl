"""Token wydania Livewire i stan zapisu kreatora dla „Ta strona jest nieaktualna” (#977).
Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Token wydania Livewire (#977). Mutacja wraca do stałego 'a' sprzed poprawki:
# karta sprzed wdrożenia znów wysyłałaby migawkę starego kodu bez odmowy.
LIVEWIRE_KONFIG = "config/livewire.php"
LIVEWIRE_TOKEN_TEST = "LivewireReleaseTokenZWydaniaTest"

# Stan zapisu kreatora dla komunikatu „Ta strona jest nieaktualna” (#977).
# Mutacja każe kreatorowi mówić „szkic” przed pierwszym zapisem: komunikat po
# 419 obiecałby, że szkic zostaje, choć w bazie nic nie ma.
KREATOR_WIDOK = "resources/views/components/recipe-wizard.blade.php"
KREATOR_ZAPIS_TEST = "KreatorWystawiaStanZapisuDlaStronyNieaktualnejTest"
KREATOR_ZAPIS = "$recipeId === null ? 'brak' : ($juzOpublikowany ? 'opublikowany' : 'szkic')"


KONTROLE_DODATNIE = [LIVEWIRE_TOKEN_TEST, KREATOR_ZAPIS_TEST]

KONTROLE = [
    Kontrola("Stały token wydania Livewire", LIVEWIRE_KONFIG, LIVEWIRE_TOKEN_TEST,
     lambda s: replace_once(s, "'release_token' => strtolower(trim((string) env('RAILWAY_GIT_COMMIT_SHA'))) ?: 'lokalnie',", "'release_token' => 'a',")),
    Kontrola("Kreator obiecuje szkic przed zapisem", KREATOR_WIDOK, KREATOR_ZAPIS_TEST,
     lambda s: replace_once(s, KREATOR_ZAPIS, "$juzOpublikowany ? 'opublikowany' : 'szkic'")),
]
