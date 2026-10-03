# #2814 — odbiór minutnika klawiaturą, przy 320 px i natywnym 200%

## Wynik i zakres

**PASS lokalnego odbioru technicznego: 2/2 rzeczywiste sceny HTTP/przeglądarki,
8 pomiarów interfejsu, 30 przejść do kontrolek klawiaturą, kod zakończenia 0.**
Uzupełniono brakujący odbiór dla naprawy `1eb0c1c06` już obecnej w O.
Commit dodaje wyłącznie ten dokument. Aplikacja, testy, rejestry i konfiguracja
repozytorium pozostają bajtowo zgodne z O.

Issue [#2814](https://github.com/woogitsu/kuking.pl/issues/2814) pozostaje
**OPEN do odbioru dokładnego wdrożenia przez koordynatora**. Ten pomiar nie
zamyka issue i nie dowodzi działania produkcji ani badania z osobą 50+.
Nie wykonano pusha, PR, CI, scalenia ani zmian danych produkcyjnych.

Odczytano świeże issue przekazane w `issue-2814-fresh.json` (OPEN, bez komentarzy),
aktualne `AGENTS.md`, standard UX i design system, D-333 (lokalne minutniki,
zgoda na #2458 w paczce E), receipt O oraz istniejące
`scripts/minutnik-powrot-http-2814.mjs` i jego fixture/kontrolę ujemną.
`ODBIOR-O-CODEX-20261003.md` dokumentuje wcześniejszy rzeczywisty GET,
właściwy fizyczny FAIL markera `MINUTNIK_2814_POWROT_ALARMU_DODAJE_CZAS`,
dokładny restore bajtów/czasu i ponowny PASS oraz 22/22 testów modułów.
W tym odbiorze **nie powtarzano tych testów, mutantów ani pełnej baterii**.

## Własna izolacja i dokładne źródło

- Bazowe O: **`91e58bd07b719c27f12b7973c526f906cbefd2db`**.
- Gałąź `codex/2814-odbior-browser`; WT
  `C:\Users\matma\.codex\worktrees\2814-odbior-browser\Portale`.
- Własny runtime:
  `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-2814-browser`.
- Przed `createdb` odczytano rzeczywisty host **127.0.0.1**, port **55488**,
  rolę i właściciela **`kuking_pg18_owner`**, `server_version_num=180006`
  (**PostgreSQL 18.6**) i nieistnienie własnej bazy. Utworzono tylko
  **`kuking_test_2814_odbior_browser`**, UTF8, `template0`, `C.utf8`.
- `APP_BASE_PATH` wskazuje własny runtime. Zależności są fizycznymi kopiami
  z własnego zakończonego odbioru #2852; oba locki porównano bajtowo.
  Zwykły `composer install`, budowa assetów i migracje nowej bazy: kod 0.
  Własny klucz wygenerowano wyłącznie w nowej instancji. `.env` powstał
  z `.env.example`, bez odczytu cudzych `.env`; własne sesje plikowe i media
  lokalne, bez produkcyjnego R2. Scena minutnika nie wymagała zdjęć.
- Bez przygotowania Dwa ani operacji na `kuking_race`. Nie użyto baz,
  runtime, profili przeglądarki ani procesów innych prac.
- Istniejący **Chrome for Testing 153.0.8010.12**:
  `/opt/woogitsu/playwright-browsers/chromium-1243/chrome-linux64/chrome`.
  Sprawdzono plik, wykonywalność i wersję. Bez instalacji; jeden własny
  browser naraz, headed + Xvfb, istniejący Playwright z locka.
- Prawdziwy serwer Laravel: `http://127.0.0.1:33047`, własny port przydzielony
  przez istniejący helper serwera. Publiczny testowy przepis utworzono przez
  `PublishRecipe`; zwykła ścieżka gościa zachowuje Policy i middleware.
  Brak atrapowego HTML, przechwytywania odpowiedzi, `route.fulfill` lub
  obchodzenia CSRF. Zapisano 18 odpowiedzi właściwych tras przepisu/gotowania
  **GET 200** (9 na wariant); 60 odpowiedzi GET 200 łącznie z zasobami pobocznymi.
- Końcowy `source-state.json`: **6977/6977 plików archiwum O zgodnych bajtowo**
  z runtime; własny browser i HTTP server zakończone.

## Rzeczywisty alarm, powrót i dodatkowy czas

Przepis testowy ma trzy niezmienione kroki: A z czasem autora **60 s**, B
z czasem autora **1200 s**, trzeci bez czasu autora. W każdym wariancie
wszystkie akcje wykonano Tab/Enter i wpisywaniem klawiaturą, bez programowego
kliknięcia kontrolek. Nie użyto sztucznego zegara, przyspieszania czasu ani
zapisu terminów do `sessionStorage` przez przyrząd.

| Pomiar | 320 px | Natywne 200% |
|---|---:|---:|
| Alarm A od odczytu po rzeczywistym starcie | 60 639 ms | 60 432 ms |
| Nowy termin po wpisaniu 5, od odczytu przed Enter | 300 002 ms | 300 001 ms |
| Czas autora w zapisie `sekundyCalkiem` po +5 | 60 s | 60 s |
| Drugi minutnik: zapis czasu/terminu/tożsamości | identyczny | identyczny |
| Kontrolki osiągnięte Tab i obsłużone Enter | 15 | 15 |
| Pomiary alarm/GET/błąd/+5 | 4 | 4 |

W obu wariantach potwierdzono:

1. Start A → zwykły „Następny krok” → start B → rzeczywisty alarm A.
   Dokładnie jeden alarm; aktywny zapis A znika, zostaje osobny znacznik
   `.po-alarmie`. B zachowuje dokładnie ten sam zapis.
2. „Przejdź do kroku 1” klawiaturą wykonuje prawdziwy GET. Dodatkowy czas
   jest dostępny; brak powtórnego alarmu i brak aktywnego zapisu A.
   Odświeżenie zachowuje tę możliwość.
3. Błędne `abc` pozostaje w polu, `aria-invalid=true`, komunikat po polsku
   przy polu i w podsumowaniu; fokus wraca do pola. Żaden termin się nie
   zmienia. „Nie dodawaj” nie uruchamia zegara i wraca fokusem do przycisku
   otwierającego formularz.
4. Wpisanie 5 uruchamia odliczanie z terminem około pięciu minut od Enter,
   usuwa znacznik zakończenia i daje dokładnie dwa aktywne zapisy A+B.
   Nie uruchamia ponownie pełnego czasu autora; `sekundyCalkiem=60` jest
   zachowaną metryką startu autora, **nie pozostałym czasem**.
5. Reload i rzeczywiste Wstecz nie odtwarzają alarmu, nie dodają zapisów
   i nie zmieniają terminów. `pageshow.persisted=false`: potwierdzono tę
   zwykłą nawigację, **nie odtworzenie z bfcache**.
6. Anulowanie A nie zmienia B. Zwykły pełny restart A nadal zapisuje 60 s;
   własny szybki minutnik na trzecim kroku zapisuje 300 s. Ich anulowanie
   pozostawia B. „Zakończ gotowanie” usuwa wszystkie lokalne klucze tego
   przepisu.
7. Całe odczytane wiersze przepisu, kroków, składników, postępu i wykonań
   są identyczne przed/po. Nie wykonano zmiany czasu autora, odhaczenia
   kroku ani POST postępu.

**Granice czasu:** oczekiwano faktycznych 60 s do dwóch alarmów. Po +5
zmierzono termin, widoczne 5:00 i trwałość zapisu; nie czekano kolejnych pięciu
minut do alarmu. Nie oceniano słyszalności dźwięku, wygaszenia karty,
zamknięcia przeglądarki ani synchronizacji urządzeń. Jeden właściciel interwału
jest objęty odziedziczonymi testami O; tutaj zmierzono brak duplikatu w DOM
oraz lokalnych zapisach na opisanych drogach.

## Natywne 200%, tekst, cele i ogląd

Natywną preferencję ustawiono w **własnym** profilu przez
`chrome://settings/appearance` → rzeczywisty select „Powiększenie strony”
→ **200%**. Zapisano jego opcje i zrzut z wybraną wartością. Nie użyto
CSS zoom ani emulacji viewport jako zastępstwa dla tego wariantu.

| Dowód | 320 px | Natywne 200% |
|---|---:|---:|
| `outerWidth` / `innerWidth` | 1280 / 320 | 1280 / 640 |
| DPR / CSS zoom / `visualViewport.scale` | 1 / 1 / 1 | 2 / 1 / 1 |
| CDP `cssVisualViewport.zoom` | 1 | 2 |
| Szerokość dokumentu, wszystkie cztery pomiary | 305 px | 632 px |
| Body / input / label / błędy | ≥18 px | ≥18 CSS px |
| Najniższa wysokość mierzonych akcji minutnika/nawigacji | 72 px | 72 CSS px |

Nie zmierzono poziomego overflow w żadnym z ośmiu stanów. Tekst pomocy
formularza ma **16 px** zgodnie z `DESIGN_SYSTEM.md` §2.1: kontekstowa pomoc
obok etykiety/pola 18 px. Zapisano tę wartość osobno; nie przedstawiono jej
jako 18 px. Wysokość pola wynosi 64 px. Tekst kroku i przycisków: co najmniej
20 px. Wszystkie 30 celów klawiatury były całe w viewport, środek nie był
zasłonięty i miały widoczne obramowanie/poświatę fokusu.

Oglądano rzeczywiste PNG: **8 pełnych stron** (alarm, GET A, błąd, +5 w obu
wariantach), oryginalne viewporty pola/błędu, zatwierdzenia, anulowania i
ustawień Chrome oraz zestawienia 30 wycinków faktycznie skupionych kontrolek.
Podpisy, zawijanie, wartość `abc`, podsumowanie/błąd, licznik 5:00 i fokus
są czytelne. Pełne PNG przy DPR2 używają CDP `contentSize` w DIP;
`screenshot-geometry.json` potwierdza zgodność wymiarów wszystkich 8 PNG.
Przykład: błąd native200 **1265×4836 px**, CSS content **632×2418** — obraz
nie został ucięty do rozmiaru CSS. Stały nagłówek w pełnym zrzucie pozostaje
na bieżącym przesunięciu viewport; dostępność akcji oceniono z oryginalnych
viewportów i pomiarów fokusu, nie z tego położenia nagłówka w pełnym PNG.

## Zachowane błędy przygotowania i granice dowodu

Pierwsza próba startu Xvfb nie uruchomiła przeglądarki: cytowanie argumentu
przez powłokę dało `/usr/bin/xvfb-run: 200: 0: not found`, exit 1.
Przeniesiono uruchomienie do `subprocess.run` z listą argumentów, poza repo.
Log/exit są zachowane w historycznym podkatalogu.

Pierwszy rzeczywisty przebieg zatrzymał błędny warunek helpera
`sekundyCalkiem=300`. Kontrakt i odebrane testy mówią, że ta metryka autora
pozostaje 60, a +5 zmienia termin. Nie była to awaria produktu. Cały surowy
FAIL oraz wykonany wtedy helper pozostają w
`attempt1-harness-contract-error`; poprawiono wyłącznie przyrząd poza repo.
Zawężono również warunek rozmiaru pomocy do jawnego wyjątku design systemu,
z zachowaniem kontroli etykiety i pola ≥18 px. Końcowe 2/2 PASS i pomiary
pochodzą z **pełnego nowego rzeczywistego przebiegu**
2026-10-03 16:56:55–16:59:07 UTC, nie z dawnych wyników.

## Surowe artefakty

Lokalnie, poza repo:
`C:\Users\matma\.codex\worktrees\2814-odbior-browser\evidence`.
Surowy runtime obok `repo-2814-browser`, w `evidence-2814-browser` na normalhp.
Profil przeglądarki i `.env` nie zostały wyeksportowane. W zapisanym HTML
wartości CSRF zostały zredagowane; dane fixture są syntetyczne.

- `SHA256.json`: **109/109 artefaktów zweryfikowanych**, SHA256
  **`46118b35437aa6b748f99bd188e5f69a0d808e9be412815f531cc2d2f484b091`**.
- `browser-result.json`:
  `22b15b03a529625141781239741051daa87820b683338ab5ffa88c537d348828`;
  surowe HTTP, stany, terminy, wymiary i fokus; `browser-run.exit=0`.
- `browser.mjs` (wykonany końcowy helper):
  `f459c01ff7df8b96dadb5155b73bffe30da7c7d0e913f8482b10f2f59d0bf3c0`.
- `source-state.json` (6977 plików, SHA/MD5/rozmiar/mtime):
  `122ef75c9ce59a1cdeed8bf74d1b67b17a1ba0d520b1a4f069bcf064ac08699b`.
- `database-before.json` = `database-after.json`, oba SHA256
  `3d9369afc44d6682ec393bdd9a89a3aea1b408b28e043bc23f5351571d3743b8`.
- `environment.json`, fixture/helper przygotowania, odczyt świeżego issue,
  natywne opcje powiększenia, 8 HTML, pełne/viewport PNG, geometria obrazów,
  zapis oglądu i zachowany historyczny błąd przyrządu objęte manifestem.

Refs #2814.
