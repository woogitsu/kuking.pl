# Dowody odbioru paginacji zeszytu — #646

Zebrane 18 września 2026 na **wdrożonym** SHA
`55877e2b5c0aff04d93e6db75f079c7e61d4df5d` (Alfa 0.65). Opis i granice
w [`../../PAGINACJA_ZESZYTU_646.md`](../../PAGINACJA_ZESZYTU_646.md).

Środowisko: osobny klon z jawnym `APP_BASE_PATH`, PostgreSQL
`127.0.0.1:55439`, baza `kuking_d_b_browser` ze strefą UTC, `artisan serve`
na 8153. Scena: zeszyt prywatny, 25 przepisów (3 strony) i 13 zapisanych
wpisów (2 strony) — listy celowo różnej długości. Bez kontaktu z produkcją.

## Pliki

- `raport.json` — trzy pełne przebiegi przejść (mysz, emulowany dotyk
  Pixel 5, klawiatura `Enter`): po pięć kroków, z adresem, listą numerów
  przepisów i wpisów oraz odczytanymi `href` obu przycisków; ostatni krok
  to `Wstecz`.
- `fokus-light.json`, `fokus-dark.json` — fokus osiągnięty **prawdziwymi
  Tabami** (bez `focus()`) na „Pokaż więcej zapisanych wpisów", przy 320,
  390 i 1440 px; `:focus-visible`, pierścień, wysokość przycisku,
  szerokość dokumentu i okna.
- `wizual-light.json`, `wizual-dark.json` — motyw potwierdzony atrybutem
  `data-theme` i tłem `body`, brak poziomego przepełnienia.
- `obie-listy-*.png` — jeden kadr, na którym widać koniec listy przepisów
  („Przepis 24" + przycisk) i zaraz pod nim „Zapisane wpisy" z samym
  „Wpis 13 kontrolny", czyli obie listy na stronie drugiej naraz.
- `fokus-320-jasny.png`, `fokus-1440-ciemny.png` — zbliżenie na pierścień
  fokusu.

## Uwagi do pomiaru

`.btn` ma `transition: box-shadow`. Odczyt `getComputedStyle` albo zrzut
zrobiony bezpośrednio po `Tab` pokazuje wartość z początku przejścia
(`rgba(0,0,0,0) 0px 0px 0px 0px` dwa razy) i wygląda na brak obrysu fokusu.
Trzeba odczekać na koniec przejścia. Powyższe pliki powstały po odczekaniu.

W `raport.json` klucz `obrysFokusu` nie zawiera pomiaru, tylko odesłanie:
fokus mierzą `fokus-light.json` i `fokus-dark.json`. Został po wcześniejszej
wersji skryptu, w której próbowano czytać obrys pseudoklasy `:focus-visible`
przez `getComputedStyle` z argumentem pseudoelementu — to nie działa.

Obie pułapki pomiarowe tego odbioru opisuje sekcja „Dwie pułapki pomiaru"
w raporcie nadrzędnym.
