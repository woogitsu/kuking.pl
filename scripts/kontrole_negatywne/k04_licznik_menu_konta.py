"""Licznik kolejek w widocznym menu konta.

Bez kontroli dodatniej przed mutacją — tak było w jednym pliku i tak zostaje.
Test i tak przechodzi po przywróceniu źródła.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


LAYOUT = "resources/views/components/layout.blade.php"

KONTROLE = [
    Kontrola("Licznik w widocznym menu konta", LAYOUT, "test_wejscie_do_panelu_pokazuje_sume_kolejek",
             lambda s: replace_once(s, """<li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji <x-licznik-kolejki :ile="$czekaWPanelu" /></a></li>""", """<li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji</a></li>"""),
             oczekuj=r"Wejście do panelu nie pokazuje sumy wszystkich kolejek"),
]
