# Tu wrzuca się pliki fontu

Ten katalog jest pusty celowo. Paczka źródłowa v3.1 nie zawierała binariów
kroju, więc `tokens/fonts.css` ładuje „Inter Variable” z serwera Google.

## Jak przejść na wersję lokalną

1. Pobierz **`InterVariable.woff2`** — rodzina Inter, wersja wariabilna
   (jeden plik zawiera wszystkie grubości od 100 do 900).
   Licencja SIL Open Font License pozwala hostować go u siebie.
2. Wrzuć plik tutaj: `assets/fonts/InterVariable.woff2`.
3. W `tokens/fonts.css`:
   - usuń wiersz `@import url("https://fonts.googleapis.com/...")`,
   - w `src:` zamień adres `https://fonts.gstatic.com/...` na
     `url("../assets/fonts/InterVariable.woff2")`.

Nic poza tym się nie zmienia — nazwa rodziny, stos zapasowy i wszystkie tokeny
typografii zostają takie same.

## Po co to robić

- **Prywatność.** Sekcja „Prywatność” obiecuje, że nie ma tu narzędzi z zewnątrz.
  Font z obcego serwera jest zapytaniem do obcego serwera.
- **Szybkość pierwszego ekranu.** Font z Google dojeżdża jedno połączenie
  później niż arkusz.
- **Niezawodność.** Przy zablokowanym Google (sieć firmowa, wtyczka) zostaje sam
  stos zapasowy.

## Czym jest `.woff2`

Web Open Font Format 2 — skompresowany plik z krojem pisma, jedyny format,
jakiego dziś potrzebuje przeglądarka. Waży zwykle 2–4 razy mniej niż `.ttf`,
z którego powstaje. Wersja **wariabilna** zawiera wszystkie grubości w jednym
pliku, mniej więcej tej samej wielkości co jeden plik statyczny — dlatego jeden
`InterVariable.woff2` zastępuje cztery osobne pliki (400, 600, 700, 800).
