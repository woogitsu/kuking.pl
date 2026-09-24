"""Kompensacja nieudanego wgrania zdjęcia (issue #962).

Pliki idą do storage przed `Media::create()`; gdy wiersz nie powstanie,
`StoreUploadedImage` ma je skasować, bo bez wiersza nie znajdzie ich żadne
sprzątanie. Mutacja wyłącza wywołanie kompensacji — test ma oblać na
oryginale i podglądzie, które zostały w `Storage::fake()`.

Kontrola celuje w JEDNĄ metodę, nie w całą klasę: po tej mutacji druga metoda
(`test_nieudana_kompensacja_zostawia_slad_z_kluczem_i_nie_podmienia_wyjatku`)
pada wyjątkiem Mockery (`InvalidCountException`), a nie asercją, więc werdykt
dla klasy byłby `ZLA_PRZYCZYNA` (#1011). Klasa w całości zostaje kontrolą
dodatnią.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


KOMPENSACJA_UPLOADU = "app/Domain/Media/Actions/StoreUploadedImage.php"
KOMPENSACJA_UPLOADU_TEST = "NieudanyZapisZdjeciaNieZostawiaPlikowTest"
KOMPENSACJA_UPLOADU_METODA = "test_blad_tworzenia_wiersza_kasuje_oryginal_i_podglad_i_oddaje_pierwotny_wyjatek"

KONTROLE_DODATNIE = [KOMPENSACJA_UPLOADU_TEST]

KONTROLE = [
    Kontrola("Nieudane wgranie bez kompensacji plików", KOMPENSACJA_UPLOADU, KOMPENSACJA_UPLOADU_METODA,
             lambda s: replace_once(s, "            $this->posprzatajPoNieudanymZapisie($disk, $objectKey, $dyskWariantow);\n", ""),
             oczekuj=r"Oryginał został w buckecie bez wiersza `media` — nic go już nie skasuje\."),
]
