# Odtworzenie korpusu z pomiaru 20.09.2026

Źródła, autorzy, licencje, bezpośrednie adresy pobrania i SHA-256 są
w `korpus-zrodla.json`. Pobierz pięć pozycji mających pole `download`
do osobnego katalogu poza repozytorium, pod nazwami z pola `file`.
Nie pobieraj miniatur. Sprawdź sumy przed użyciem: zmieniony plik źródłowy
nie jest tym samym korpusem. Zachowaj informacje o autorach i licencjach.

Poniższy fragment Pythona odtwarza trzy kontrolowane mutacje bez ponownego
kodowania pikseli i tworzy manifest dla istniejącego generatora. Wymaga
argumentów: katalog zdjęć oraz ścieżka do `korpus-zrodla.json`. Nie nadpisuje
pięciu fotografii źródłowych.

```python
import hashlib, json, struct, sys
from pathlib import Path
root = Path(sys.argv[1]).resolve()
sources = json.loads(Path(sys.argv[2]).read_text(encoding='utf-8'))
for source in sources:
    if 'download' in source:
        assert hashlib.sha256((root / source['file']).read_bytes()).hexdigest() == source['sha256']
b = (root / 'photo-5.jpg').read_bytes()
(root / 'truncated.jpg').write_bytes(b[:300])
(root / 'odd-exif.jpg').write_bytes(
    b'\xff\xd8\xff\xe1\x00\x12Exif\x00\x00MM\x00\x2a\xff\xff\xff\xff' + b[2:])
tiff = (b'II' + struct.pack('<HIH', 42, 8, 1)
        + struct.pack('<HHI', 274, 3, 1)
        + struct.pack('<H', 6) + b'\x00\x00' + struct.pack('<I', 0))
app = b'Exif\x00\x00' + tiff
(root / 'orientation-6.jpg').write_bytes(
    b[:2] + b'\xff\xe1' + struct.pack('>H', len(app) + 2) + app + b[2:])
corpus = []
for source in sources:
    path = root / source['file']
    assert hashlib.sha256(path.read_bytes()).hexdigest() == source['sha256']
    corpus.append(dict(path=str(path), sha256=source['sha256'], mime='image/jpeg',
                       expected='rejected' if path.name == 'truncated.jpg' else 'accepted'))
(root / 'corpus.json').write_text(json.dumps(corpus, indent=2), encoding='utf-8')
```

Przy rampie ustaw `KORPUS_ZDJEC` na powstały `corpus.json`. Manifest sesji
jest osobnym, prywatnym plikiem. Instrukcje utworzenia izolowanej bazy,
zasiania dużego zbioru i zalogowania kont są w istniejącej metodzie #605;
na tym stanowisku obowiązuje port 55439, nigdy domyślny port PostgreSQL.

Oczekiwane przyjęcie uszkodzonego EXIF opisuje zaobserwowany wynik tego
konkretnego pliku. Nie jest decyzją produktową, że każdy uszkodzony EXIF
ma być dopuszczany. Test przyrządu sprawdza interpretację manifestu,
a nie narzuca aplikacji polityki wobec fotografii.