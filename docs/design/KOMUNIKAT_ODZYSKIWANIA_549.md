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

## Kontrola pełnego hooka

Pierwszy push został prawidłowo zablokowany: StronyBleduPoPolskuTest
nadal wymagał starego nagłówka. Zaktualizowano oczekiwanie w treści
ekranu, bez usunięcia asercji. Osobny fizyczny negatyw starego H1
wykryty; restore MD5/mtime oraz 10 testów/95 asercji PASS.
Dowód: evidence/odzyskiwanie549/negatyw-starszej-regresji.json.
Ponowny zestaw rodzin zapisanych w cache wcześniejszych błędów:
302 testy/2203 asercje PASS. Cache obejmował także dawne negatywy;
nie oznacza to302 błędów w pierwszym hooku. Wymagany ponowny pełny hook.

Druga próba hooka wykryła formatowanie pomocniczego pliku PHP w dowodach
#567. Zatrzymano nieudaną próbę, poprawiono formatowanie i przygotowano
kolejny pełny hook. Nie pomijano zabezpieczeń ani asercji.

## Końcowe dostarczenie — 15 września 2026

Zwykły hook zakończył się sukcesem. PR #570: CI 34964204421, wszystkie
10 zadań success; PHP 3820 testów / 76674 asercje. Scalenie:
72b96f979a264ed1982a4803bc7e07e623428c2e. Main CI 34966287461: 10/10 success.
Railway 6458271154 success (12:23:40 UTC), Deploy 34968590919 success.
HTTP produkcji: Alfa 0.35 / 72b96f9, CSS i JS oraz oba fonty Inter HTTP 200.
Rzeczywisty POST z błędnym tokenem, bez treści zgłoszenia, zwrócił 419
z H1 „Nie udało się wysłać formularza”. Nie próbowano poprawnego zapisu.
To odbiór komunikatu produkcyjnego; nie nowy pełny odbiór ponowienia formularza.
