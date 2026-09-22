# Odbiór Alfy 0.25 — 14 września 2026

Pełny port identyfikacji ma status **CZĘŚCIOWO**. Ten raport dotyczy
konkretnego pakietu napraw zapisu i komunikatów, nie każdego stanu portalu.

## Kod i kontrole przed scaleniem

PR #531: head `24afa9e4046da31143430fd930883908ca9f87a7`, merge
`b4d5d8e875006ad165660f7f2af8cc5b2b7ca77e`. Wszystkie dziesięć zadań CI
`34822658081` zakończyło się sukcesem. PHP: **3793 testy / 76259 asercji**
z logu joba `103908931994`. Obejmuje to także zielone wyścigi, analizę
statyczną, build obrazu, dostępność i port marki. Nie zmieniono progów CI.

Lokalny zestaw po integracji: 104 testy / 1194 asercji, 16 rzeczywistych
negatywów źródła z przywróceniem kopii, MD5 i mtime. Odbiory formularzy
i komunikatów opisują raporty #526, #528 i #530. Nie powtarzano tych
zapisów na produkcyjnych danych.

## Dowód wdrożenia

Railway deployment `6433957176` zakończył się sukcesem 14 września 2026
o **09:34:16 UTC**, dla commita `b4d5d8e875006ad165660f7f2af8cc5b2b7ca77e`.
Main CI `34826807883`: completed/success. Test po wdrożeniu, Deploy
`34828688035`: completed/success.

Rzeczywisty HTTP zwrócił **Alfa 0.25 / b4d5d8e**. CSS
`app-BZTD7N58.css`, JS `app-DXNAnudp.js` oraz oba lokalne pliki Inter
odpowiedziały 200. CSS ma SHA256
`c8603ded009b0ca0061ca2688f30c313d9a9d10378650f89c90f38c9b64428fc`, JS
`e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75`.
Zgodność nazw z poprzednią wersją jest oczekiwana: pakiet zmienia PHP/Blade,
a nie arkusze i JavaScript.

W zalogowanym Chrome odświeżono istniejący prywatny wpis. Przed wdrożeniem
0.24 pokazywał błędny komunikat o wybranych osobach i pustej stronie;
po wdrożeniu 0.25 widoczny jest tekst „Ten wpis widzisz tylko Ty” oraz
instrukcja udostępniania zgodna z nazwą przycisku. Odczytano stopkę i
obejrzano komunikat w rzeczywistym układzie. Bez edycji, komentarza,
udostępnienia, zmiany widoczności lub innych zapisów produkcyjnych.
Prywatnych treści, identyfikatora wpisu i zrzutu nie publikujemy w repo.

Ogląd ujawnił dodatkowe podejrzenie słabego kontrastu napisu przycisku
wewnątrz `.notice`. Wymaga osobnej diagnozy; nie uznajemy całego widoku
za wizualnie odebrany bez zastrzeżeń. Nie jest to błąd nowego komunikatu
prywatności ani dowód nieudanego wdrożenia.

## Granice i powiązane odbiory

- `SLOWNIK_SKLADNIKOW_526.md`: 240 znaków, normalizacja, indeksy i bezpieczny rollback.
- `AUTOZAPIS_KREATORA_528.md`: niepoprawny tekst zachowany w UI, dobry zapis zachowany w bazie, korekta i cofanie między krokami.
- `KOMUNIKATY_WIDOCZNOSCI_530.md`: trzy widoczności w HTTP/Livewire; ogląd prywatnego komunikatu lokalnie.
- `ODBIOR_TRYBU_GOTOWANIA_2026_09_14.md`: 48 konfiguracji lokalnych, rzeczywisty zoom, instrukcja 4000 znaków, przejścia i odhaczanie.
- `ODBIOR_FOKUSU_SZCZEGOLY_530.md`: cztery przebiegi Tab; brak całkowitego zasłonięcia, ale ciasnota przy zoomie i tekście 140%.
- `ZRODLO_STYLU_532.md`: sprostowanie dwóch indeksów, bez naruszania oryginalnych paczek.

Nie testowano fizycznej klawiatury ekranowej, wszystkich dostawców OAuth,
czytników ekranu ani wszystkich klientów poczty. Pomiar wersji nie
potwierdza poprawności każdej operacji na danych. Pełną macierz i datowane
ograniczenia zawiera `MACIERZ_KOMPLETNOSCI_517.md`.
