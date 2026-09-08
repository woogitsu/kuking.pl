# UI kit — plansze społecznościowe i materiały drukowane

Odtworzenie szablonów z `05-social-media/` paczki źródłowej. Podgląd pokazuje
plansze pomniejszone; w druku i w eksporcie mają swoje prawdziwe wymiary.

| Plansza | Wymiar | Do czego |
|---|---|---|
| Kwadrat A | 1080 × 1080 px | hasło albo pytanie, bez zdjęcia |
| Kwadrat B | 1080 × 1080 px | przepis w skrócie: zdjęcie u góry, składniki pod spodem |
| Pion | 1080 × 1350 px | Ugotowałem, Pinterest |
| Podgląd linku | 1200 × 630 px | link wklejony w wiadomość — najczęstsza droga przepisu, od siostry do siostry |
| Baner | 1640 × 624 px | okładka strony |
| Ulotka A4, A5 | 210 × 297, 148 × 210 mm | biblioteki, domy kultury, uniwersytety trzeciego wieku, koła gospodyń |
| Wizytówka | 90 × 50 mm | |
| Naklejka | 60 × 60 mm | |

## Trzy rzeczy, które trzeba wiedzieć

1. **Mnożnik planszy `--skala` to nie `--user-text-scale`.** Własność
   niestandardowa rozwiązuje się w miejscu deklaracji, więc token typografii
   dziedziczy się już policzony. Rozmiary mnoży się w miejscu użycia:
   `font-size: calc(var(--text-title-xl) * var(--skala))`.
2. **Wymiary w pikselach i milimetrach są jedynym wyjątkiem** od zakazu wartości
   spoza tokenów. 1080 × 1080 wymusza serwis, 210 × 297 mm wymusza drukarka.
   Każde takie miejsce ma w arkuszu komentarz ze słowem „geometria”.
3. **Tekst na zdjęciu zawsze na podkładzie** — także na planszy. To ta sama
   reguła „nigdy” nr 7 co w produkcie.

## Czego na planszach nie ma

Emoji, wykrzykników, liczb, których nie ma z czego policzyć, komplementów
(„królowa kuchni”), i gry słowem `kuKING` częściej niż raz na planszę.
Mnożnik poniżej 1 na wizytówce i naklejce jest świadomym odstępstwem od
produktowego minimum 18 px: 18 px na kartoniku 90 × 50 mm to nagłówek, a nie
tekst — adres zostaje w rozmiarze nagłówkowym.
