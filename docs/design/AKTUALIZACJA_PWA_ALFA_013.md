# Aktualizacja zainstalowanej aplikacji — Alfa 0.13

## Stan

Regresja i pełne testy lokalne przechodzą; CI oczekuje na uruchomienie. Ten dokument nie potwierdza jeszcze wdrożenia
Alfa 0.13. Odbiór Alfa 0.12 opisuje `AUDYT_SPOJNOSCI_ALFA_012.md`.

## Co zaobserwowano na produkcji

13 września 2026 przeglądarka otwarta jeszcze na Alfa 0.11 zachowała aktywnego
workera `/sw.js` i cache `kuking-v1` po 96 wejściach na strony Alfa 0.12.
Sam HTML wskazywał już wdrożony commit `e4f9c21`.
Odpowiedź publicznego `/sw.js` zawierała nowe źródło, ale nagłówek
`Cache-Control: public, max-age=14400`; konfiguracja Caddy zaliczała ten plik
do statyki z `max-age=3600`.

Jawne `registration.update()` w przeglądarce kontrolnej zainstalowało nowego
workera i usunęło stary cache. Dopiero wtedy przetestowano nowy ekran offline.
Nie jest to dowód poprawnej automatycznej aktualizacji. Nie ustalono też
pełnej przyczyny opóźnienia: domyślne `updateViaCache: imports` już pomija
lokalny HTTP cache głównego skryptu, a odpowiedź pośredniego CDN jest osobnym
etapem. Test regresji odtwarza utrzymywanie starych bajtów pod dawnym URL-em.

## Zakres poprawki

HTML przekazuje adres workera z pełnym commitem wydania, a bez commita
z etykietą wersji. Moduł rejestracji zachowuje zakres `/`, używa
`updateViaCache: none` i działa również po zakończeniu zdarzenia `load`.
Caddy wysyła dla `/sw.js` osobną politykę bez przechowywania odpowiedzi.

Nie dodajemy przeładowania formularza ani nowej rejestracji w drugim zakresie.
Test przeglądarkowy zachowuje niezapisany tekst podczas aktualizacji.
Nie zmieniamy uprawnień, sesji, bazy danych ani zasad prywatności HTML.

## Weryfikacja

Test w Chromium korzysta z rzeczywistego modułu rejestracji i rzeczywistego
`public/sw.js`; lokalny serwer odtwarza starą odpowiedź CDN. Oba warianty
przechodzą: moduł przed `load` oraz import już po tym zdarzeniu. Test czeka
na nowy adres kontrolera i stan `activated`, nie tylko utworzenie cache.
Sprawdza jedną rejestrację, zakres `/`, zachowany formularz oraz nowy ekran
po odłączeniu sieci. Historyczny worker jest fixture ze źródła `cc1e3bf`.

Lokalnie `AdresAktualizacjiWorkeraTest`: 3 testy, 24 asercje, passed.
Test rzeczywistego workera `service-worker-marka.test.mjs` nadal przechodzi,
w tym zasady prywatności. Pint i `git diff --check` przechodzą.

Kontrole ujemne zmieniały rzeczywiste źródła, z kopią poza repo i przywróceniem
treści oraz czasu modyfikacji. Każda zakończyła test błędem:

- stały URL workera — brak aktualizacji w Chromium;
- `updateViaCache: imports` — niewłaściwa polityka rejestracji;
- wyłącznie nasłuchiwanie `load` — brak aktualizacji po późnym imporcie;
- stały URL w prawdziwym layoucie i publiczny cache Caddy — trzy właściwe
  nieudane testy PHP (commit, fallback etykiety, polityka cache).

MD5 kopii i przywróconych źródeł są identyczne:

| Plik | MD5 |
|---|---|
| `resources/js/service-worker.js` | `c03f52b40feebb5443b620cc05fb9386` |
| `resources/views/components/layout.blade.php` | `ecb9346a3f649d85a71b8d515983d13a` |
| `docker/Caddyfile` | `99f49785974dff44d782a4f76d2c61c8` |

Po przywróceniu ponownie przeszły oba scenariusze Chromium i test PHP.
Przeglądarkową regresję podpięto do zadania dostępności CI; filtr obejmuje
również zmianę samego skryptu, fixture i Caddyfile.

Pełny lokalny PHP/PostgreSQL: **3677 passed, 74044 asercje, 213,42 s**.
Pint, Larastan, składnia PHP i powłoki, testy skryptów, odwracalność migracji
i build Vite przeszły. Źródła kopii na dysku Linuksa porównano bajtowo
z checkoutem Windows (1850 plików).

Pierwszy pełny check był czerwony: lokalne `PGPORT` bez `PGHOST` kierowało
sprawdzenie gotowości do domyślnego gniazda, zamiast izolowanej instancji TCP.
Powtórzono to oddzielnie w `ProbaOdtworzeniaTest` (odmowa „PostgreSQL nie
odpowiada”). Jawne host, port i użytkownik lokalnej bazy rozwiązały problem;
nie zmieniano testu ani aplikacji.

## Wycofanie

Revert zmiany i zwykłe wdrożenie po CI; brak migracji. Cofnięcie przywróci
również wcześniejszy sposób sprawdzania aktualizacji, więc może ponownie
opóźniać dotarcie nowego ekranu offline.
