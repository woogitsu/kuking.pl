# Panel #581 — potwierdzenia i pomoc pocztowa

Stan roboczy, 17 września 2026. Baza: `ca438c8a5de48773cb05705f8f9b97adc9497944`.
Zmiany tego pakietu nie są jeszcze wysłane ani wdrożone. Wersja robocza: Alfa 0.52.

## Odtworzony problem i poprawka

Na tablicy moderatora przy szerokości 1440 px i tekście 140% przejście Tab
do potwierdzenia czyszczenia pozostawiało dolną krawędź obrysu na 905,03 px
przy wysokości ekranu 900 px. Wynik utrzymywał się po ustabilizowaniu geometrii.
`marka-panel.css` dodaje zapas przewijania `.5rem` do podsumowań potwierdzeń
i ich przycisków. Treść, formularze, uprawnienia i działanie czyszczenia bez zmian.

## Zakres nowych regresji

- Kolaż: rozwinięcie i zamknięcie „Wyczyść wybór zdjęć”.
- Tablica: rozwinięcie i zamknięcie „Wyczyść dzisiejszy wybór”.
- Wiadomość: pomoc pocztowa, adres mailto, końcowy przycisk zapisu i komunikat retencji.
- 24 konfiguracje: 320/1440 px, dwa motywy, tekst 100/140%, trzy widoki.
- 6 konfiguracji rzeczywistego zoomu 200%: trzy widoki, dwa motywy, tekst 140%.
  Rozszerzenie Chromium ustawia i odczytuje zoom 2; pomiar wymaga CSS 320×900 i DPR 2.
- Tab/Shift+Tab/Enter, pełne teksty, cele przycisków i summary co najmniej 48 px,
  widoczność obrysu, brak obcięcia tekstu, końcowy akapit dostępny do przeczytania.
- Tylko GET/HEAD do dokładnego lokalnego origin. Bez wysyłania poczty i decyzji moderacyjnych.

## Korekty metody pomiaru

Hit-test końca prostokąta zawiniętej linii mailto trafiał w rodzica mimo widocznego
tekstu. Pomiar sprawdza teraz prostokąty i środki każdego niebiałego grafemu,
z zachowaniem kontroli całych granic, przezroczystości, przodków i zasłaniania.

Zwykły zapis zrzutu Playwright dawał pusty obraz przy natywnym zoomie po przewinięciu.
Bezpośredni `Page.captureScreenshot` z `captureBeyondViewport: false` zapisuje
rzeczywisty widok. Obejrzano potwierdzenia tablicy i kolażu w ciemnym motywie
oraz pomoc pocztową w jasnym przy zoomie 200%. Sam poprawny DOM nie stanowił odbioru obrazu.

## Dowody lokalne i granice

Zapis: [wyniki celowane i skróty źródeł](evidence/panel-details581/local-targeted.json).
W tym samym katalogu są trzy obejrzane zrzuty kompozytora przy zoomie 200%.
Nie zawierają sesji ani poświadczeń; widoczny adres example.test jest lokalną fixture.

- Przed poprawką CSS: podstawowy runner 288 pustych + 312 pełnych przypadków PASS.
  Nowe szczegółowe kontrole wykryły opisane problemy; cały tamten przebieg zakończył się błędem.
- Po poprawce CSS i metody: 24 szczegółowe + 6 zoomu PASS.
- Pięć fizycznych kontroli ujemnych: cofnięcie zapasu przewijania, przycisk 46 px,
  niewidoczny komunikat, usunięty komunikat, obcięty obrys przez overflow.
  Każda wymaga właściwego kodu porażki oraz ponownego sukcesu po przywróceniu.
  Kopie są poza repo; przywracane i sprawdzane są MD5 oraz nanosekundowy mtime.
  Po zmianie i odtworzeniu Blade czyszczona jest lokalna pamięć widoków.
- PostgreSQL wyłącznie 127.0.0.1:55439, wydzielona baza `kuking_581_details`,
  lokalne dane syntetyczne, mailer array. Historyczne bazy i wyniki zachowano oddzielnie.
- Końcowy pełny runner po poprawce CSS: 288 pustych + 312 pełnych przypadków,
  24 szczegółowe, 6 zoomu szczegółów, 8 menu i 4 zoomu menu — PASS.
  Wynik procesu 0; osobno odczytano wszystkie raporty i ich liczby.
- Hook, CI, PR i odbiór produkcji: jeszcze niewykonane.

Nie zamykać #581 na podstawie tego pakietu. Pozostają rozszerzone stany decyzji,
odwołań, walidacji i ich produkcyjny odbiór opisane w `PANEL_STANY_ROZWINIETE_581.md`.
Pełny port marki #492 nadal: **CZĘŚCIOWO**.
