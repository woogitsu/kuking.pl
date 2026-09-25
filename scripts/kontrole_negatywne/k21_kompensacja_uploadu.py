"""Kompensacja nieudanego wgrania zdjęcia (#962).

Pliki idą do storage przed `Media::create()`; gdy wiersz nie powstanie,
`StoreUploadedImage` ma je skasować, bo bez wiersza nie znajdzie ich żadne
sprzątanie. Mutacja wyłącza wywołanie kompensacji — test ma oblać na
oryginale i podglądzie, które zostały w `Storage::fake()`.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


KOMPENSACJA_UPLOADU = "app/Domain/Media/Actions/StoreUploadedImage.php"
KOMPENSACJA_UPLOADU_TEST = "NieudanyZapisZdjeciaNieZostawiaPlikowTest"

KONTROLE_DODATNIE = [KOMPENSACJA_UPLOADU_TEST]

KONTROLE = [
    Kontrola("Nieudane wgranie bez kompensacji plików", KOMPENSACJA_UPLOADU, KOMPENSACJA_UPLOADU_TEST,
             lambda s: replace_once(s, "            $this->posprzatajPoNieudanymZapisie($disk, $objectKey, $dyskWariantow);\n", "")),
]
