"""Wiersz `media` i zadanie przetwarzania w jednej transakcji (issue #1456).

Test wymusza fizyczną odmowę INSERT-u do `jobs`; mutacja wynosi dispatch
z powrotem ZA granicę transakcji i kompensacji — test ma wtedy oblać, bo
zostaje wiersz `pending` bez zadania i pliki w buckecie.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


STORE_UPLOADED_IMAGE = "app/Domain/Media/Actions/StoreUploadedImage.php"
ZLECENIE_ZDJECIA_TEST = "ZlecenieZdjeciaWTransakcjiTest"


def dispatch_zdjecia_poza_transakcja(source):
    source = replace_once(source, "ProcessUploadedImage::dispatch($media->getKey());", "null;")
    return replace_once(
        source,
        "            throw $e;\n        }\n\n        return $media;",
        "            throw $e;\n        }\n\n        ProcessUploadedImage::dispatch($media->getKey());\n\n        return $media;",
    )


KONTROLE_DODATNIE = [ZLECENIE_ZDJECIA_TEST]

KONTROLE = [
    Kontrola("Zlecenie zdjęcia poza transakcją wiersza", STORE_UPLOADED_IMAGE, ZLECENIE_ZDJECIA_TEST,
             dispatch_zdjecia_poza_transakcja),
]
