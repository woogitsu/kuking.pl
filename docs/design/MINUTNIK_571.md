# Minutnik po wstrzymaniu karty — #571 i #569

Status: poprawka lokalna, bez PR i bez wdrożenia. Nie zamyka całej macierzy marki.

## Problem i zmiana

Dotychczas każde wywołanie `setInterval` odejmowało sekundę. Zatrzymanie
wykonywania JavaScript przez przeglądarkę wydłużało odliczanie. Teraz start
zapamiętuje termin końca, a wywołanie oblicza pozostały czas względem `Date.now()`.
Restart ustawia nowy termin. Poszczególne minutniki pozostają niezależne.

Aktualny czas w Blade ma `role="timer"` oraz `aria-live="off"`, zamiast
`aria-hidden="true"`. Jest dostępny w drzewie dostępności, bez komunikatu
co sekundę. Osobny komunikat rozpoczęcia i końca pozostał.

## Regresja

`scripts/minutnik-regresja.mjs` wykonuje rzeczywisty blok `resources/js/app.js`
oraz znaczniki z `resources/views/pages/recipes/cooking.blade.php`.
Podstawia dane czasu, nie zegar. CDP wstrzymuje wykonywanie skryptu na ponad
cztery sekundy. Test obejmuje zwykły koniec, restart, jednokrotną finalizację,
pauzę przed terminem i przez termin, dwa niezależne minutniki oraz aktualny
czas w drzewie dostępności. Baseline: trzy scenariusze FAIL, dwa PASS.
Po poprawce: pięć PASS. Test został podłączony do CI wraz z filtrem zmian.

Niezależny przegląd kodu nie znalazł blokera. Kontrole ujemne fizycznie
modyfikują prawdziwe źródła w izolowanej kopii wykonawczej, z kopią poza nią.
Wyniki i potwierdzenie przywrócenia MD5 oraz mtime są zapisywane w
`docs/design/evidence/minutnik571/` po zakończeniu całego przebiegu.

## Ograniczenia i dalszy odbiór

- Zegar systemowy może zostać przestawiony, co zmieni pozostały czas.
- Przeglądarka w uśpieniu nie gwarantuje alarmu w chwili terminu. Zakończenie
  nastąpi przy pierwszym dopuszczonym callbacku po terminie.
- Test `visibilitychange` sprawdza brak ponownego końca, nie natychmiastową
  aktualizację po powrocie. Implementacja nie dodaje obsługi tego zdarzenia.
- Pomiar AX nie zastępuje odsłuchu czytnika, dźwięku i rzeczywistego uśpienia urządzenia.
- Pozostają: odbiór strony Laravel, wersja i changelog po uzgodnieniu kolejności
  pakietów, pełny hook, wymagane CI, scalenie i odbiór produkcji.

Pierwszą próbę negatywów na plikach Windows przerwano, ponieważ przywrócenie
przez WSL zachowało bajty, ale zaokrągliło mtime. Oryginalny czas przywrócono
przez Windows. Powtórzenie używa izolowanej kopii na linuksowym systemie plików;
pierwsza próba nie jest zaliczonym dowodem przywrócenia.

## Wyniki lokalne z 16 września 2026

- Obie kontrole ujemne zakończone kodem 1: zatrzymany zegar w JS oraz
  ponowne ukrycie aktualnego czasu w Blade. Przywrócono dokładne bajty i mtime;
  ponowny przebieg dodatni zakończył się kodem 0 (pięć PASS).
- Build Vite przeszedł, podobnie jak 72 kontrole kontrastu.
- `MinutnikIZdjecieKrokuTest`: 33 testy, 177 asercji, bez porażek.
  Wykonano w osobnej kopii `/home/mateusz/kuking-minutnik571-runtime`, z jawnym
  `APP_BASE_PATH`, PostgreSQL na `127.0.0.1:55439`, baza `kuking_571_tests`.
  Nie zmieniano bazy przeglądarkowej ani źródeł runtime wyszukiwarki.
- Pełna strona Laravel na porcie 8071, osobna baza `kuking_571_browser`:
  kliknięcie startu, zakończenie `0:00`, ponowny start `0:03` i drugi koniec PASS.
  Wykonano 36 konfiguracji (320, 360, 390, 414, 768, 1440 px; dwa motywy;
  skale tekstu 70/100/140%). Bez poziomego overflow; rozmiary tekstu odczytane
  jako 12,6/18/25,2 px. Obejrzano zrzuty 320 px: jasny 140% i ciemny 70%.
  Licznik oraz przycisk restartu są widoczne. Ten przebieg nie jest testem
  rzeczywistego zoomu 200% ani potwierdzeniem końcowej integracji z Alfą 0.42.
