"""Kolejność w `down()` migracji 2FA (D-238, DB-01).

Strażnik czyta źródło migracji i porównuje położenie sprawdzenia liczby kont
z położeniem zdjęcia CHECK-a i `dropColumn`. W działaniu różnicy nie widać —
wyjątek leci w obu wersjach — więc tylko mutacja dowodzi, że asercja
o kolejności naprawdę pada.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


MIGRACJA_2FA = "database/migrations/2026_09_06_120000_add_two_factor_to_users_table.php"
MIGRACJA_2FA_TEST = "CofniecieMigracji2faOdmawiaTest"


def zdjecie_checku_przed_straznikiem_2fa(source):
    """KONTROLA DODATNIA: zdejmij CHECK 2FA, ZANIM strażnik policzy konta.

    Dokładnie ten błąd kolejności, którego pilnuje
    `test_straznik_stoi_przed_kazda_operacja_niszczaca`: odmowa nadal leci,
    ale schemat przestał już pilnować niezmiennika. Blok `isPostgres()`
    wędruje z miejsca po strażniku na początek `down()`.
    """
    zdjecie = (
        "        if ($this->isPostgres()) {\n"
        "            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS "
        "users_two_factor_confirmed_requires_secret_check');\n"
        "        }\n\n"
    )
    straznik = "        $zPotwierdzonym = DB::table('users')->whereNotNull('two_factor_confirmed_at')->count();\n"

    bez_zdjecia = replace_once(source, zdjecie, "")

    return replace_once(bez_zdjecia, straznik, zdjecie + straznik)


KONTROLE_DODATNIE = [MIGRACJA_2FA_TEST]

KONTROLE = [
    Kontrola("Zdjęcie CHECK-a 2FA przed strażnikiem cofnięcia", MIGRACJA_2FA, MIGRACJA_2FA_TEST,
             zdjecie_checku_przed_straznikiem_2fa,
             oczekuj=r"Sprawdzenie stoi PO zdjęciu CHECK-a"),
]
