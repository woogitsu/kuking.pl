"""Oryginał zdjęcia traci XMP (issue #1004).

Test czyta fixture'y zapisane niezależną biblioteką — strażnik widzi odczyt
pliku, więc kontrola dodatnia wyłącza samo czyszczenie XMP i test ma wtedy oblać.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


USUN_GPS = "app/Domain/Media/UsunGps.php"
XMP_TEST = "OryginalTraciGpsZXmpTest"

KONTROLE_DODATNIE = [XMP_TEST]

KONTROLE = [
    Kontrola("Oryginał zdjęcia z nietkniętym XMP", USUN_GPS, XMP_TEST,
             lambda s: replace_once(s, "return self::usunXmp(self::usunGpsZExif($bajty));", "return self::usunGpsZExif($bajty);"),
             oczekuj=r"</x:xmpmeta>' is null\."),
]
