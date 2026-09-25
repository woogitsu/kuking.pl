"""Awaria eksportu danych dociera do kolejki (#822).

Testy łapały kiedyś `\\Throwable`, więc połykały własne `fail()`; job bez
`throw $e` po `markFailed()` przechodził, a kolejka nie wiedziała o porażce.
Mutacja zdejmuje ten rethrow — oba testy mają oblać na braku wyjątku.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


EKSPORT_JOB = "app/Jobs/GenerateUserExport.php"
EKSPORT_PORAZKA_TEST = "test_niepowodzenie_ustawia_status_failed_z_powodem|test_powod_niepowodzenia_eksportu_nigdy"
# Od audytu B5 (2180634c) między `markFailed()` a `throw` stoi sprzątanie
# osieroconej paczki; mutacja zdejmuje sam rethrow, sprzątanie zostaje.
EKSPORT_RETHROW = "            $this->usunOsieroconaPaczke($export);\n\n            throw $e;\n"

KONTROLE_DODATNIE = [EKSPORT_PORAZKA_TEST]

KONTROLE = [
    Kontrola("Awaria eksportu bez przekazania wyjątku kolejce", EKSPORT_JOB, EKSPORT_PORAZKA_TEST,
             lambda s: replace_once(s, EKSPORT_RETHROW, "            $this->usunOsieroconaPaczke($export);\n\n")),
]
