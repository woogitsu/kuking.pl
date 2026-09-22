# Odbiór wdrożenia Alfa 0.19

13 września 2026, odczyt po zakończeniu wdrożenia o 20:58 UTC.

## Kod i wdrożenie

- PR #517: scalony, końcowy head `667ace890492f02b1221e259a73977cac0897ee3`.
- Main i SHA Railway: `34b4b61109ebd1e1c808066191609672cd5ae332`.
- CI main `34781348552`: completed / success.
- Workflow Deploy `34782396483`: completed / success.
- Railway deployment `6426180376`: success od `2026-09-13T20:58:36Z`.
  Poprzedni deployment `6425234896` przeszedł w inactive.
- Publiczne HTTP 200: metryczka **Alfa 0.19**, wydanie 13 września 2026,
  22:57, `34b4b61`. Ten sam numer i SHA odczytano po odświeżeniu
  zalogowanego zeszytu w Chrome.

## Pliki produkcyjne

| Zasób | Wynik |
|---|---|
| CSS `app-CcfeIsli.css` | HTTP 200; SHA256 `7c7755382b06714497220326e3a51dcd59941c08c9126c4d378da9cea1f9fbf0` |
| JS `app-DXNAnudp.js` | HTTP 200; SHA256 `e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75` |
| Inter latin-ext `inter-latin-ext-wght-normal-DO1Apj_S.woff2` | HTTP 200, font/woff2, 85068 bajtów |
| Inter latin `inter-latin-wght-normal-Dx4kXJAl.woff2` | HTTP 200, font/woff2, 48256 bajtów |

## Ogląd i ograniczenia

Obejrzano zrzut zalogowanego zeszytu w istniejącym oknie Chrome, w jasnym
motywie. Po odświeżeniu widoczna była ciemna karta folderu oraz ostatnie
zapisy w głównej treści, z rzeczywistymi zdjęciami i krótkimi linkami.
Nie zmieniano danych ani ustawień użytkownika. Prywatnych treści i ich
zrzutów nie zapisano w repo.

Ten odbiór potwierdza wdrożenie i reprezentatywny ekran. Nie powtarza
całej lokalnej macierzy mobilnej ani wszystkich stanów zeszytów.
Zakres lokalnej regresji pozostaje w `ZESZYTY_MARKI_511.md` oraz
`ODBIOR_STANOW_ZESZYTU_511.md`.

## Ramka nagłówka — #518

Po odświeżeniu zeszytu nie zaobserwowano ramki. Następnie wejście
do powiadomień przez nawigację i odświeżenie Ctrl+R również nie
odtworzyły zgłoszenia. W powiadomieniach aktywnym elementem był BODY,
h1 nie pasował do :focus-visible; obliczone style wskazywały border
0 px oraz outline-style none. Sama wartość outline-width 3 px przy
stylu none nie rysuje obrysu.

To wynik dwóch sprawdzonych ścieżek, nie zaprzeczenie zgłoszeniu.
Nadal potrzebna jest konkretna strona lub reprodukcja objawu.
