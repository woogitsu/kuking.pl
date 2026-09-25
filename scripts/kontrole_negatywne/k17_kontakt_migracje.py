"""Cofnięcie migracji kontaktu odmawia, gdy dane by na tym ucierpiały (#844, #1081).

Oba strażniki wczytują migrację przez `database_path(...)`, więc tylko mutacja
dowodzi, że odmowa w `down()` jest pilnowana:

- CHECK `contact_messages_handled_complete`: bez odmowy cofnięcie przy
  wiadomościach po usuniętym operatorze wywraca się dopiero na CHECK-u,
  innym wyjątkiem i bez zdania, co zrobić;
- znaczniki odpowiedzi (`reply_key`, `sending_started_at`) chronią przed drugą
  wysyłką tego samego listu; bez odmowy `down()` przechodzi po pierwszym
  formularzu.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


KONTAKT_MIGRACJA = "database/migrations/2026_09_24_100000_allow_null_handled_by_on_contact_messages.php"
KONTAKT_MIGRACJA_TEST = "UsuniecieOperatoraNiePsujeWiadomosciTest"
KONTAKT_ZNACZNIKI = "database/migrations/2026_09_24_120000_add_contact_reply_delivery_markers.php"
KONTAKT_ZNACZNIKI_TEST = "AwarieOdpowiedziKontaktuTest"

KONTROLE_DODATNIE = [KONTAKT_MIGRACJA_TEST, KONTAKT_ZNACZNIKI_TEST]

KONTROLE = [
    Kontrola("Cofnięcie CHECK-a kontaktu bez odmowy przy sierotach", KONTAKT_MIGRACJA, KONTAKT_MIGRACJA_TEST,
             lambda s: replace_once(s, "        if ($istniejaSieroty) {\n", "        if (false && $istniejaSieroty) {\n"),
             oczekuj=r"exception message .* contains 'Nie można cofnąć migracji'"),
    Kontrola("Cofnięcie znaczników odpowiedzi bez odmowy", KONTAKT_ZNACZNIKI, KONTAKT_ZNACZNIKI_TEST,
             lambda s: replace_once(s, "        if (DB::table('contact_message_replies')->whereNotNull('reply_key')->exists()) {\n", "        if (false) {\n"),
             oczekuj=r'Failed asserting that exception of type "RuntimeException" is thrown\.'),
]
