# Treść zalążkowa — `tresc-zalazkowa.json`

**To jest treść PRZYKŁADOWA, wygenerowana. Dwanaście kont w tym pliku to
persony, nie ludzie.**

Powstała 7 września 2026 na potrzeby problemu opisanego przez właściciela:
serwis działa, ale nie jest promowany i nikt z niego nie korzysta, więc osoba
zaproszona jako jedna z pierwszych dwudziestu widzi pusty ekran.

## Co zawiera

12 kont, 40 przepisów, 80 wpisów, 60 komentarzy, 10 tagów promowanych.
40 unikalnych tagów, z których każdy promowany ma pokrycie w treści.
Komentarze wskazują pozycje przez `ref` (`p1`–`p40`, `w1`–`w80`).

## Zweryfikowane maszynowo

Zero adresów e-mail, numerów telefonu, adresów pocztowych, emotikon,
wykrzykników i obietnic zdrowotnych. Nazwy kont zgodne z regułą
`[a-z0-9_]{2,20}`. Tagi: małe litery, 2–30 znaków, polskie znaki zachowane,
spacje dozwolone (patrz reguły tagów — `zupa pomidorowa` jest poprawnym tagiem).
Integralność odwołań pełna: żaden komentarz nie wskazuje na nieistniejącą
pozycję, żaden autor nie jest spoza listy kont.

## DWIE RZECZY, KTÓRYCH TEN PLIK NIE ROZWIĄZUJE

**1. Nie ma zdjęć, a „zdjęcie + kilka słów" to główna akcja produktu.**
Istniejący `DemoSeeder` tworzy wiersze `media` bez prawdziwych plików
i bez wariantów, więc `Media::url()` podstawia znak Kuking. Do pomiaru
układu to wystarcza i tak jest to opisane w komentarzu tamtej klasy. Do
pokazania serwisu człowiekowi — nie: osiemdziesiąt wpisów z logotypem
zamiast jedzenia wygląda gorzej niż osiemdziesiąt wpisów bez zdjęć.

**2. Użycie tego na produkcji jest decyzją o uczciwości, nie techniczną.**
Jako dane lokalne do pracy nad wyglądem: bez zastrzeżeń. Jako zawartość
produkcyjnego serwisu pokazywana zaproszonym ludziom: dwanaście
zmyślonych osób podpisanych imieniem i regionem, pisanych w pierwszej
osobie, jest nieodróżnialne od prawdziwych użytkowników. Zanim to trafi
na produkcję, musi być albo widocznie oznaczone jako treść przykładowa,
albo opublikowane pod kontem gospodarza jako zebrane przepisy, albo nie
trafić tam wcale. Decyzja właściciela — nie do podjęcia przez agenta.

## Co jeszcze nie istnieje

Seeder czytający ten plik. Powstanie razem z tagami (decyzja D-021),
bo bez tabel tagów nie da się zapisać ani tagów wpisów, ani listy tagów
promowanych.
