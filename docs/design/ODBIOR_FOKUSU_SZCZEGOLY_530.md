# Odbiór fokusu formularza szczegółów — lokalna Alfa 0.25

14 września 2026, kod `24afa9e4046da31143430fd930883908ca9f87a7`.
Odczyt lokalnej trasy szczegółów przepisu ze slugiem
`koncowy-odbior-prywatnosci-530`, serwer `http://localhost:8029`.
Nie zmieniano pól, danych, mediów ani preferencji konta. Nie wysyłano
formularzy i nie wybierano pliku. Przeglądarki pomiaru zamknięto.

## Zakres i wynik

Rzeczywisty zoom Chromium 200% potwierdzono przez `chrome.tabs.getZoom=2`,
DPR 2 i viewport CSS 320×500 przy fizycznym oknie 640×1000.
Oba motywy oraz tekst 100/140% dały cztery przebiegi. W każdym odwiedzono
klawiszem Tab 25 elementów formularza, do ponownego wejścia na pierwszy.
Nie odtworzono całkowitego zasłonięcia fokusu.

| Tekst | Dół nagłówka | Początek dolnej nawigacji | Dostępna wysokość |
|---|---:|---:|---:|
| 100% | 194,5 px | 394,406 px | 199,906 px |
| 140% | 216,258 px | 317,758 px | 101,5 px |

Geometria obu motywów jest taka sama. Przy tekście 140% etykieta zdjęcia
ma 315,648 px wysokości. Ukryte pole `f-heroPhoto` ma 1×1 px, ale pierścień
3 px rysuje się na etykiecie. Po Tab widoczny jest jego górny fragment;
napis „Dodaj zdjęcie” początkowo przykrywa dolna nawigacja. Przewinięcie
o 90 CSS px odsłania napis i zachowuje fokus. To potwierdzona niedogodność,
nie utrata dostępu do kontrolki.

Pola „Krótko o przepisie” i „Historia tego przepisu” mają odpowiednio
176 i 192,25 px wysokości. Ich środek bywa zasłonięty, lecz górna część
pola i pierścienia pozostaje widoczna. Pomiar samego środka dawał też
fałszywe podejrzenia przy linkach dwuwierszowych, trafiając między liniami.
Dlatego dodatkowo sprawdzono `clientRects` oraz próbki wnętrza fragmentów.
W najciaśniejszym wariancie jasnym 140% wszystkie 25 elementów miało
widoczny fragment.

## Granice dowodu

Wysokie etykiety i pola wymagają przewijania. Pomiar nie uzasadnia
usuwania stałej nawigacji ani globalnego wyłączania fokusu. Nie jest
pełnym audytem dostępności. Nie wywoływano błędów walidacji, więc nie
przypisujemy temu odbiorowi sprawdzenia ich geometrii.

Lokalne dowody: `output/focus530-results.json` (4×25),
`output/focus530-supplement.json` (fragmenty 25 elementów),
`output/playwright/focus530-false-140-2.png` oraz
`output/playwright/focus530-file-scrolled.png`. Oba zrzuty obejrzano.
