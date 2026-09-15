# Neutralny komunikat odzyskiwania — #549

15 września 2026. Alfa 0.35; kod bazowy odbioru 43faa3b.
Status: poprawka i lokalny odbiór zakończone; CI oraz wdrożenie oczekują.

## Problem i zmiana

419 występuje także przy błędnym tokenie bez upływu czasu. Stary tytuł
i opis przypisywały odrzucenie długo otwartej stronie. Widok errors/419
podaje teraz neutralny wynik i kroki dalszego działania. Usunięto też
gwarancję publikacji po ponowieniu. Mechanizm odzyskania, logowanie,
pliki, metoda PUT, token CSRF i formularz nie zostały zmienione.

## Wykonane kontrole

- Pint oraz 18 testów / 330 asercji: OdzyskanyTekstBezSprzecznychObietnicTest
  i PelnyPrzepisPrzezywaOdzyskiwanieTest. Rzeczywisty zły token, brak zapisu,
  cztery stany oraz istniejące ponowienie pełnego przepisu i PUT.
- Trzy fizyczne mutacje prawdziwego Blade: stary H1, stara przyczyna,
  fałszywe pełne odzyskanie. Każda FAIL → restore MD5 i mtime → view:clear
  → PASS. [Dowód](evidence/odzyskiwanie549/negatywy.json). Nazwy dopisano
  na podstawie wykonanej kolejności helpera; wspólny plik logu helpera
  zachował tylko ostatnią mutację, a nie trzy osobne logi.
- Chromium: 16 konfiguracji (pełne/częściowe/brak odzyskanego tekstu/
  wymagane logowanie × dwa motywy × 320 px z rzeczywistym zoomem 200%
  lub 1440 px z zoomem 100%). Wszędzie tekst aplikacji 140%, brak
  poziomego overflow. Zoom potwierdzony tabs.getZoom i innerWidth.
- Obejrzano cztery reprezentatywne zrzuty w katalogu dowodów: pełny jasny
  desktop, częściowy ciemny telefon, brak ciemny desktop, logowanie jasny
  telefon. Nagłówek i ostrzeżenia czytelne, bez obcinania szerokości.
- Niezależne readonly review kodu 43faa3b: brak blokera.

## Granice dowodów

Pomiar przeglądarki pobiera rzeczywistą odpowiedź Laravel 419 i wyświetla
jej niezmieniony HTML przez route.fulfill. Service worker wyłączono
w izolowanym profilu odbioru. To odbiór komunikatu, nie pełnego przepływu
wysłania formularza w przeglądarce. Nie przypisujemy mu testu ponowienia,
Tab, pozostałych szerokości, czytnika ekranu ani danych produkcyjnych.
Ponowienie pokrywa wymieniony PHP i wcześniejszy raport
[odzyskiwania](ODBIOR_ODZYSKIWANIA_492.md).

[Wyniki przeglądarki](evidence/odzyskiwanie549/przegladarka.json).
Skrypt odbioru w tym samym katalogu zapisuje lokalne ścieżki środowiska;
nie jest nową bramką CI. Pełny port marki pozostaje CZĘŚCIOWO.
