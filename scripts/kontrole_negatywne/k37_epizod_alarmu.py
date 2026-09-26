"""Wspólna maszyna epizodu alarmu (#972).

Cisza ma być kupowana WYŁĄCZNIE przyjętym dzwonkiem: nieudana próba daje tylko
krótkie ponowienie. Mutacja wyjmuje ustawienie `cisza_do` spod `if ($przyjeto)`
— wtedy odrzucony webhook wycisza epizod na godziny. Jeden punkt mutacji
w komponencie ma zapalić i test samej maszyny, i test obu czujek, które
z niej korzystają.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


EPIZOD_ALARMU = "app/Domain/Monitoring/EpizodAlarmu.php"
EPIZOD_ALARMU_TEST = "EpizodAlarmuTest|NieudanyDzwonekNieKupujeCiszyTest"
CISZA_TYLKO_PO_PRZYJECIU = (
    "            $pamiec['cisza_do'] = $this->teraz() + $ciszaGodzin * 3600;\n"
    "        }\n"
)
CISZA_BEZ_WARUNKU = (
    "        }\n"
    "        $pamiec['cisza_do'] = $this->teraz() + $ciszaGodzin * 3600;\n"
)

KONTROLE_DODATNIE = [EPIZOD_ALARMU_TEST]

KONTROLE = [
    Kontrola("Nieudany dzwonek kupuje ciszę epizodu", EPIZOD_ALARMU, EPIZOD_ALARMU_TEST,
             lambda s: replace_once(s, CISZA_TYLKO_PO_PRZYJECIU, CISZA_BEZ_WARUNKU)),
]
