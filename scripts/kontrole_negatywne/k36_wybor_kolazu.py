"""Wybór kolażu należy do wpisu (#955).

Strażnik ładuje migrację przez `base_path(...)` i sprawdza definicję złożonego
FK w `pg_constraint`. Mutacja zdejmuje z migracji `ON DELETE CASCADE` — FK
nadal istnieje, więc sama obecność constraintu przeszłaby zielono; test ma
zapalić na definicji.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


MIGRACJA_HERO_PICKS = "database/migrations/2026_09_24_100000_powiaz_hero_picks_z_post_media.php"
HERO_PICKS_TEST = "test_schemat_wymusza_pare_wpisu_i_zdjecia_z_kaskada"

KONTROLE_DODATNIE = [HERO_PICKS_TEST]

KONTROLE = [
    Kontrola("Wybór kolażu bez kaskady przy odpięciu zdjęcia", MIGRACJA_HERO_PICKS, HERO_PICKS_TEST,
             lambda s: replace_once(s, "\n            .'ON DELETE CASCADE',", "")),
]
