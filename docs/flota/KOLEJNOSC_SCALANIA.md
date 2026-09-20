# Kolejność scalania otwartych PR-ów

Stan na **20 września 2026, ok. 20:30Z**. Dokument analityczny — **niczego nie scalono,
nie pchnięto i nie zamknięto**. Podstawa: `gh pr list/view/checks`, dzienniki jobów
pobrane przez `gh api .../actions/jobs/<id>/logs`, listy plików z `gh pr view --json files`
oraz zapisane ostrzeżenia z `STAN_SESJI.md` i `STAN_SESJI_CZESC2–8.md`.

**Jak czytać:** idź grupami od (A) do (D). W każdej pozycji stoi numer PR-a, **warunek
wejścia** (co musi być prawdą, zanim naciśniesz merge) i **skutek złamania kolejności**.
Jeśli warunek wejścia nie jest spełniony — pomiń pozycję i wróć do niej później,
nie „popraw przy okazji”.

---

## 0. Jedna rzecz przed wszystkimi: `naprawa/klient-pg18-w-ci`

Ta gałąź **nie ma jeszcze PR-a**, a jest warunkiem wejścia dla jedenastu z czternastu pozycji.
Commit `fa06f5cd`, zmienia **dokładnie jeden plik: `.github/workflows/ci.yml`**.
W chwili pisania jest w kolejce pchania (kolejka 9, start 20:05:34Z).

Dlaczego to jest pierwsze: **jedenaście PR-ów ma identyczną czerwień, która nie pochodzi
z ich kodu.** Dowód w §5.

> **Skutek złamania:** scalisz cokolwiek innego wcześniej → nadal patrzysz na czerwone CI,
> którego nie da się odróżnić od regresji, a przy `checkSuites: true` każde czerwone CI
> w chwili scalenia kosztuje też **pominięte wdrożenie Railway, którego nic nie ponawia samo**
> (`STAN_SESJI.md` §11, w. 577–606).

**Kolizja:** `naprawa/klient-pg18-w-ci` i **PR #786** to jedyna para dotykająca
`.github/workflows/ci.yml`. #786 i tak jest w konflikcie z `main` (grupa C).

---

## 1. Tabela stanu — czternaście otwartych PR-ów

| PR | Gałąź | Zgłoszenia | Scalalność | CI: ile oblanych | Migracje | `resources/legal/` | Grupa |
|---|---|---|---|---|---|---|---|
| **#924** | `flota/komentarze` | #757, #759, #760, #761, #762 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#923** | `flota/zdjecia-formularze` | #742, #743, #744, #745, #747 | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#922** | `flota/relacje` | #780, #791, #793, #803, #880 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#921** | `robota/martwe-reguly-css` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#920** | `robota/bazy-stanowisk` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#919** | `flota/r73-feed` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **A** |
| **#918** | `codex/audyt-ux50plus` | — | MERGEABLE (UNSTABLE) | 4 (2 × PG18 + 2 własne) | 0 | 0 | **D** |
| **#917** | `flota/r47-skan` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **A** |
| **#916** | `flota/wyszukiwarka` | #737, #738, #753, #763 | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#915** | `robota/kaskada-straznik` | — | **CONFLICTING** | brak sprawdzeń w ogóle | 0 | 0 | **C** |
| **#914** | `flota/dsa-odwolania` | #796, #797, #799, #800 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#913** | `flota/gotowanie` | #739, #740, #751, #755, #756, #764 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#786** | `praca/izolacja-bazy-klon` | — | **CONFLICTING** | brak sprawdzeń w ogóle | 0 | 0 | **C** |
| **#725** | `codex/717-panel-kolejki` | #599 | MERGEABLE (UNSTABLE) | 1 (własna) — **PG18 na zielono** | 0 | 0 | **D** |

**Dobra wiadomość: żaden z czternastu otwartych PR-ów nie rusza migracji bazy ani plików
w `resources/legal/`.** Cała grupa „wstrzymane do decyzji właściciela ze względu na schemat
lub dokumenty prawne” jest w tej turze **pusta** — ale patrz §3, bo pułapki prawne
i migracyjne dotyczą gałęzi, które PR-ów jeszcze nie mają.

---

## 2. Kolizje plikowe między otwartymi PR-ami

Pary z częścią wspólną (wynik porównania list plików każdego PR-a z każdym):

| Para | Wspólne pliki | Ryzyko |
|---|---|---|
| **#913 ↔ #924** | `package.json`, `resources/js/app.js` | **KONFLIKT PEWNY — najgroźniejszy w tej turze.** Patrz niżej. |
| **#923 ↔ #924** | `resources/js/app.js`, `resources/views/components/field.blade.php` | **Konflikt prawdopodobny:** oba zmieniają `field.blade.php` od wiersza 115 (#923: `@@ -115,6`, #924: `@@ -115,8`). W `app.js` trafiają w różne miejsca (#924 w. 21, #923 w. 47). |
| **#921 ↔ #923** | `resources/css/app.css` | Konflikt możliwy |
| **#918 ↔ #923** | `resources/css/app.css` | Konflikt możliwy |
| **#918 ↔ #921** | `resources/css/app.css` | Konflikt możliwy |
| **#915 ↔ #921** | `resources/css/app.css`, `resources/css/marka-ekrany.css` | Konflikt możliwy (#915 i tak w konflikcie z `main`) |
| **#915 ↔ #918** | `resources/css/app.css` | jw. |
| **#915 ↔ #923** | `resources/css/app.css` | jw. |
| **#921 ↔ #922** | `resources/views/pages/profile/show.blade.php` | Konflikt możliwy |
| **#913 ↔ #923** | `resources/js/app.js` | Różne miejsca (#913 w. 21, #923 w. 47) — ryzyko niskie |
| **#786 ↔ #920** | `scripts/cleanup-test-dbs.sh`, `tests/Unit/NazwaTestowejBazyTest.php`, `tests/bootstrap.php`, `tests/skrypty/proba-odtworzenia.sh` | **Cztery pliki wspólne** — obie gałęzie robią to samo zadanie dwiema drogami |
| **#786 ↔ #915** | `docs/DECISIONS.md` | Konflikt możliwy w dzienniku decyzji |
| **#725 ↔ #923** | `resources/views/components/layout.blade.php` | Konflikt możliwy |
| **#786 ↔ `naprawa/klient-pg18-w-ci`** | `.github/workflows/ci.yml` | Patrz §0 |

### PUŁAPKA NR 1: jedna linia `package.json`, dwa PR-y, cicha strata testów

**#913 i #924 zmieniają DOKŁADNIE TĘ SAMĄ LINIĘ** — skrypt `build` w `package.json`:

- #924 dopisuje `resources/js/licznik-znakow.test.mjs`
- #913 dopisuje `resources/js/minutnik-krok.test.mjs resources/js/wake-lock-gotowania.test.mjs`

> **Skutek złamania:** git zgłosi konflikt (dobrze), ale rozwiązanie „biorę swoją wersję”
> **cicho wypisze z buildu testy drugiego PR-a**. Nic nie zaświeci na czerwono — po prostu
> od tego momentu `npm run build` przestanie uruchamiać dwa albo jeden plik testowy.
> **Poprawne rozwiązanie konfliktu to suma wszystkich trzech nazw w jednej linii, nie wybór strony.**

Ten sam PR-y kolidują też w `resources/js/app.js`: obie wstawiają `import` w hunku
`@@ -21`, więc ten konflikt zobaczysz od razu.

---

## 3. Pułapki z dokumentacji floty — z lokalizacją

Wypisane są **wszystkie** znalezione ostrzeżenia o kolejności i zależnościach, także te,
które dotyczą gałęzi bez otwartego PR-a — bo dotkną tej kolejki w następnej turze.

### 3.1 Dotyczy PR-ów otwartych DZIŚ

| # | Ostrzeżenie | Źródło | Dotyczy |
|---|---|---|---|
| P1 | „`SearchQuery.php` i `SearchController.php` **mogą wymagać ręcznego połączenia z gałęzią `wyszukiwarka`** — tamta gałąź ma zmiany `%`, `_` i typu `q` (#753/#738), które trzeba zachować. Po połączeniu uruchomić ponownie testy wyszukiwania na izolowanej bazie.” | `STAN_SESJI_CZESC7.md` w. 232–233 | **PR #916** (`flota/wyszukiwarka`) ↔ `gpt/wyszukiwanie-granice` (#885, #886, bez PR-a) |
| P2 | „Wspólny plik to `resources/views/pages/recipes/cooking.blade.php`; nasze zmiany dotyczą końca widoku i zaproszenia »Ugotowałem«, a tamte składników i minutnika.” + „**Wersja 0.68 jest lokalnym podbiciem z D-134; przy integracji wielu gałęzi koordynator musi rozstrzygnąć ewentualną kolizję numeracji.**” | `STAN_SESJI_CZESC2.md` w. 99 i 101 | **PR #913** (`flota/gotowanie`) ↔ `gpt/ugotowalem-dostep` (#902, #903, #910, bez PR-a) |
| P3 | „**PUŁAPKA SCALANIA:** cofnięcie #803 przywróci możliwość usunięcia nowszego zdjęcia starym formularzem, więc nie jest zalecanym sposobem naprawiania problemów wdrożenia.” | `STAN_SESJI_CZESC2.md` w. 167 | **PR #922** (niesie #803) |
| P4 | „**PUŁAPKA SCALANIA:** wycofanie kodu nie wymaga operacji na bazie, ale cofnięcie #880 przywraca ryzyko nadpisania nowszej decyzji — preferowana jest poprawka do przodu.” | `STAN_SESJI_CZESC2.md` w. 380 | **PR #922** (niesie #880) |
| P5 | „po przestawieniu zmiennej repozytorium `CI_RUNS_ON` na `ubuntu-latest` dwa joby zaczęły padać, ponieważ klient PostgreSQL na tym runnerze jest starszy niż 18, a `pg_restore` odmawia odczytu zdrowego archiwum (kod 51 zamiast 0). Na `self-hosted` te same joby były zielone.” | `STAN_SESJI_CZESC4.md` w. 84–104 | **11 PR-ów** — cała grupa D-PG18, patrz §5 |
| P6 | „przy `checkSuites: true` każde czerwone CI w chwili scalenia kosztuje nie tylko czerwień, ale i **pominięte wdrożenie**, którego nic nie ponawia samo.” + „**Zielone CI po fakcie NIE wskrzesza wdrożenia zamkniętego jako pominięte.**” | `STAN_SESJI.md` §11, w. 577–606 | **wszystkie** |
| P7 | „W opisie każdego PR-a stoi wprost, że **wyniki pochodzą ze stanowiska i nie były weryfikowane ponownie przy otwieraniu** — rozstrzyga CI.” | `STAN_SESJI.md` w. 813–820 | **PR #913–#921** |
| P8 | „kopia stanowiska mogła powstać na starszym `main`, więc zmiany w plikach, których dane stanowisko nigdy nie dotykało, to **nie jego praca, tylko cofnięcie cudzej** — trzeba je przywrócić, nie commitować.” | `STAN_SESJI.md` w. 1376–1379 | wszystkie gałęzie floty po awarii 20.09 ~21:00 |

### 3.2 Zależności gałęzi, które PR-ów jeszcze nie mają — przeczytaj przed następną turą

| # | Ostrzeżenie | Źródło | Dotyczy |
|---|---|---|---|
| Q1 | „**PUŁAPKA SCALANIA:** przy przenoszeniu do kolejki najpierw musi znaleźć się implementacja #871–#874; jeśli już jest na main, nie trzeba ponownie przenosić `b03621e7`.” (commit `b03621e7` to cherry-pick `6718d527`) | `STAN_SESJI_CZESC2.md` w. 244–250 | `gpt/zdjecia-limity` **po** `gpt/zdjecia-publikacja` |
| Q2 | „**Numer wersji 0.68 podnoszą co najmniej DWIE gałęzie** (`gpt/zalegle` i `gpt/zdjecia-publikacja`). Przy szeregowym scalaniu druga dostanie konflikt albo, gorzej, cicho nadpisze pierwszą.” — a z P2 wynika, że podnosi go też **trzecia**, `gpt/ugotowalem-dostep` | `STAN_SESJI.md` w. 546–560 + `CZESC2` w. 101 | `gpt/zalegle`, `gpt/zdjecia-publikacja`, `gpt/ugotowalem-dostep` |
| Q3 | „**PUŁAPKA WYCOFANIA, CIĘŻSZA NIŻ SAMA POPRAWKA:** nie wolno cofnąć klasy #888, gdy czekają zadania NOWEGO formatu. Stara klasa oczekuje modelu `User`, a nowe zadania niosą jawny adres. Wycofanie wymaga skoordynowania zatrzymania workerów, obsługi zaległości i wersji kodu.” + „**Przed wdrożeniem trzeba rozstrzygnąć, co zrobić z już oczekującymi starymi ostrzeżeniami.**” | `STAN_SESJI.md` P16, w. 907–922 | `gpt/konto-poczta` (#888, commit `c4db01c3`) |
| Q4 | „**`flota/zeszyty` JEST ZASTĄPIONA przez `robota/jedna-droga-z-zeszytu`. NIE scalać osobno.**” — inaczej na ekranie zeszytu renderują się dwa przyciski wyjścia naraz | `STAN_SESJI.md` §9, w. 406–422 | `flota/zeszyty` (wyjęta), `robota/jedna-droga-z-zeszytu` |
| Q5 | „**Migracje ODMAWIAJĄ wycofania, gdy groziłoby ono utratą danych** […] Wycofanie licznika wersji wymaga wyłączenia zapisu i unieważnienia otwartych formularzy **przed** ponownym uruchomieniem.” — trzy migracje | `STAN_SESJI.md` w. 372–385 | `gpt/kontakt-panel` (#844) |
| Q6 | „**Krok druku wymaga `pdftotext` i `pdfinfo` NA RUNNERZE.** […] Trzeba to sprawdzić PRZED scaleniem, bo inaczej pierwsze czerwone CI po scaleniu będzie wyglądało na regresję kodu, a będzie brakiem pakietu.” | `STAN_SESJI.md` w. 546–556 | `gpt/zalegle` — **ta sama klasa pułapki co PG18** |
| Q7 | „**integrator musi rozstrzygnąć, z której gałęzi bierze finalną wersję sondy, żeby nie zdublować lub nie cofnąć zmian.**” | `STAN_SESJI_CZESC7.md` w. 103–104 | `gpt/cloudflare-cache` ↔ `gpt/sonda-wdrozenia` |
| Q8 | „Przy scalaniu obu gałęzi zachować również rozdzielenie zadań i bramkę dostępu z `gpt/moderacja-ai` — nie przywracać całego starego `PrzeanalizujTresc` z tej gałęzi.” | `STAN_SESJI_CZESC7.md` w. 185 | `gpt/openai-granice` ↔ `gpt/moderacja-ai` |
| Q9 | „Kolejność wdrożenia obowiązkowa: najpierw poprawka harmonogramu na `all`, potem worker/scheduler, na końcu web — nigdy odwrotnie.” (+ 5 dalszych punktów kolejności rollbacku) | `STAN_SESJI.md` w. 1189–1209 | `gpt/rozbicie-uslug` (#595, #598, #600) |
| Q10 | „Wycofanie: najpierw wyłączyć reguły eligible, zachować końcowy BYPASS, wyczyścić istniejące wpisy cache; **dopiero potem** `git revert` aplikacji.” + „**#610 — autor jawnie pisze: NIE WŁĄCZAĆ.**” | `STAN_SESJI_CZESC7.md` w. 111, 101, 77 | `gpt/cloudflare-cache` (#597, #610) |
| Q11 | „Przed cofnięciem ochrony zdjęć wyłączyć ocenę obrazów lub wyczyścić klucz modelu, żeby nie przywrócić znanej drogi wysyłki dużego obrazu.” | `STAN_SESJI_CZESC7.md` w. 197 | `gpt/openai-granice` (#912) |
| Q12 | `resources/legal/polityka-prywatnosci.md:9` obiecuje „serwery w Unii Europejskiej”, a jurysdykcja bucketów nie jest odczytana; „samo dopisanie »`.eu.`« do endpointu nie migruje danych”. | `STAN_SESJI.md` w. 1115–1120, 1150–1154, 1231 | `gpt/r2-jurysdykcja` (#619) — **jedyna droga, którą `resources/legal/` wejdzie do kolejki** |
| Q13 | „**maksymalnie jedno zbiorcze powiadomienie push dziennie.** Nie wolno stworzyć drugiej, konkurencyjnej reguły.” | `STAN_SESJI.md` w. 1246–1247 | `gpt/zeszyt-droga` (#906) ↔ `gpt/pwa-push` (#35) |
| Q14 | „pilotów nie podpinać pod ten sam klucz [`OPENAI_MODERATION_KEY`], bo nie da się potem rozdzielić rachunku ani wyłączyć jednego bez drugiego.” | `STAN_SESJI.md` w. 1233 | `gpt/ai-piloty` ↔ `gpt/moderacja-ai` |
| Q15 | „nie skopiowano ani nie nadpisano jej poprawek […] »mają trafić własną drogą przez kolejkę integracji«” | `STAN_SESJI.md` w. 1070–1073 | `gpt/pytania-poradzcie` ↔ `gpt/pytania-widoki` |
| Q16 | „ekran obserwowanych tagów pozostaje w gestii `gpt/tagi-filtr`” | `STAN_SESJI_CZESC2.md` w. 11 | `gpt/tagi-miejsce` ↔ `gpt/tagi-filtr` |
| Q17 | „uwaga na próbę scalenia samej propozycji jako gotowej poprawki” — gałąź niesie dokument, nie poprawkę | `STAN_SESJI_CZESC3.md` w. 136–145 | `gpt/widget-wyglad` (#684) |
| Q18 | „powrót do tematu wymaga wyniku #605 (obciążenie) i osobnej decyzji” | `STAN_SESJI_CZESC7.md` w. 137 | `gpt/dr-zdjecia` (#617, #602) po #605 |
| Q19 | Cztery raporty stanowisk **nie mają commitów** (padły metadane Gita) — `gpt/offline-obietnica`, `gpt/grupy-tematyczne`, `gpt/planer-zakupy`, `gpt/redis-ha`. Niczego z nich nie ma w kolejce. | `STAN_SESJI_CZESC8.md` | cztery gałęzie dokumentacyjne |

**Czego NIE znaleziono:** nigdzie nie ma gotowej, uporządkowanej listy „kolejność scalania 1, 2, 3”.
Ten dokument jest pierwszą taką listą i **nie zastępuje decyzji właściciela** tam, gdzie
dokumentacja wprost o nią prosi.

---

## 4. GRUPY — kolejność do wykonania

### GRUPA A — gotowe i bezpieczne

Wejdą jako pierwsze, bo **nie kolidują z niczym** i nie niosą żadnej zapisanej pułapki.

| Kolejność | PR | Warunek wejścia | Skutek złamania kolejności |
|---|---|---|---|
| **A1** | **#919** `flota/r73-feed` — strażnik feedu | `naprawa/klient-pg18-w-ci` na `main` **i** ponowione CI na zielono | Zobaczysz czerwień z `ProbaOdtworzeniaTest`, która nie ma nic wspólnego z tym PR-em — a przy `checkSuites: true` zapłacisz pominiętym wdrożeniem |
| **A2** | **#917** `flota/r47-skan` — strażnik poświadczeń | jw. | jw. |

#919 zmienia **jeden plik** (`tests/Feature/FeedNieSortujePoMierzeReakcjiTest.php`),
#917 **dwa** (`docs/MAPA_REGUL_DOWODY.md`, `tests/Feature/PoswiadczeniaPozaRepozytoriumTest.php`).
Żaden nie ma części wspólnej z jakimkolwiek innym otwartym PR-em.

### GRUPA B — gotowe, ale kolejność ma znaczenie

| Kolejność | PR | Warunek wejścia | Skutek złamania kolejności |
|---|---|---|---|
| **B1** | **#920** `robota/bazy-stanowisk` — nazwa testowej bazy z katalogu, nie z nieistniejącego `.git` | PG18 na `main`, CI ponowione. **Scalać PRZED #786.** | #786 robi to samo zadanie inną drogą i dotyka **czterech tych samych plików**; scalone w odwrotnej kolejności dadzą dwa mechanizmy nazywania bazy naraz. #920 rusza też `tests/skrypty/proba-odtworzenia.sh`, czyli ten sam skrypt, który dziś oblewa CI — po jego scaleniu **obowiązkowo przeczytaj nowy log, zanim uznasz kolejną czerwień za starą** |
| **B2** | **#921** `robota/martwe-reguly-css` | PG18 na `main`. **Scalać PRZED #923 i #918**, bo usuwa martwe reguły z `resources/css/app.css`, na którym stoją tamte dwa | Scalony po nich będzie kasował reguły, których tamte PR-y właśnie zaczęły używać — konflikt albo, gorzej, ciche usunięcie żywego stylu. Uwaga też na `resources/views/pages/profile/show.blade.php` wspólny z #922 |
| **B3** | **#923** `flota/zdjecia-formularze` (#742–#747) | PG18 na `main`, **#921 już scalone**. Ma tylko czerwień PG18, żadnej własnej | Wejście przed #921 → konflikt na `app.css`. Wejście po #924 → konflikt na `field.blade.php` (oba od w. 115) |
| **B4** | **#916** `flota/wyszukiwarka` (#737, #738, #753, #763) | PG18 na `main`. Bez konfliktów z otwartymi PR-ami | **Pułapka P1:** gdy później przyjdzie `gpt/wyszukiwanie-granice` (#885, #886), trzeba **ręcznie połączyć** `SearchQuery.php` i `SearchController.php`, zachowując z tego PR-a obsługę `%`, `_` i typu `q`, a potem **ponownie uruchomić testy wyszukiwania na izolowanej bazie**. Naiwne scalenie tamtej gałęzi cofnie te trzy poprawki |

### GRUPA C — wstrzymane do decyzji, nie do scalenia dziś

| PR | Powód wstrzymania | Co trzeba rozstrzygnąć |
|---|---|---|
| **#786** `praca/izolacja-bazy-klon` | **CONFLICTING z `main`**, brak jakichkolwiek sprawdzeń CI, **41 plików**, dotyka `.github/workflows/ci.yml` (kolizja z `naprawa/klient-pg18-w-ci`) i czterech plików wspólnych z #920 | Czy #920 (mały, zielony po PG18) nie zastępuje tego PR-a — dokładnie tak, jak `robota/jedna-droga-z-zeszytu` zastąpiła `flota/zeszyty` (Q4). Jeśli nie zastępuje: rebase na `main` **po** scaleniu PG18 i #920, potem CI od zera |
| **#915** `robota/kaskada-straznik` | **CONFLICTING z `main`**, brak sprawdzeń CI, 19 plików, kolizje CSS z #921/#918/#923 i `docs/DECISIONS.md` z #786 | Rebase po #921; `docs/DECISIONS.md` rozstrzygnąć ręcznie — to dziennik decyzji, nie plik do scalenia „biorę swoją wersję” |

> **Skutek złamania:** scalenie #786 przed #920 i przed PG18 wywróci `ci.yml` w trakcie
> naprawy CI — czyli zabierze narzędzie, którym mierzysz wszystko pozostałe.

### GRUPA D — czerwone z własnego powodu

Wszystkie mają **także** czerwień PG18, ale po jej usunięciu **nadal będą czerwone**.
Przyczyny poniżej pochodzą z dzienników jobów, nie z domysłu.

| PR | Własna czerwień | Dowód z logu | Warunek wejścia |
|---|---|---|---|
| **#918** `codex/audyt-ux50plus` | 2 joby portu marki | `Error: K509_OVERFLOW /login {"width":320,"scroll":329,"rootFont":32,...}` oraz `Error: NAV638_DUZY_FONT_OVERFLOW` | Naprawić przepełnienie ekranu logowania przy 320 px i dużym foncie. **Ironia do odnotowania: PR o minimach UX 50+ oblewa własny strażnik UX 50+** |
| **#913** `flota/gotowanie` | „Port marki — rodziny ekranów” | 5 scenariuszy minutnika `FAIL`: `locator.click: Timeout 30000ms exceeded … waiting for locator('.cook-timer').first().locator('.cook-timer-start') … locator resolved to <button hidden="" …>` | Przycisk startu minutnika renderuje się z atrybutem `hidden` i nigdy nie staje się widoczny. To **własna funkcja tego PR-a**. Dodatkowo: konflikt `package.json` z #924 (pułapka nr 1) i pułapka P2 z `gpt/ugotowalem-dostep` |
| **#914** `flota/dsa-odwolania` | „Panel marki — puste i pełne widoki” | `DOMAIN_CHANGED` — asercja `requireThat(isDeepStrictEqual(before, await take()), 'DOMAIN_CHANGED')` w `scripts/panel-validation.mjs:142` | Scenariusz `appeal-no-outcome` **zmienił stan dziedziny** przy nieudanej walidacji formularza odwołania. To dokładnie obszar tego PR-a. Nie scalać: odwołanie bez wyniku nie ma prawa niczego zapisać |
| **#725** `codex/717-panel-kolejki` | „Panel marki” — **jedyna czerwień, PG18 jest zielone** | `P581_MENU_BEZ_JS_KOMPLET` | PR zmienia `resources/views/components/layout.blade.php` (menu) i oblewa strażnika kompletności menu bez JavaScriptu. Zgodne z D-053: menu ma działać bez JS. Poprawić, nie obchodzić |
| **#924** `flota/komentarze` | „Port marki — rodziny ekranów” | `page.waitForFunction: Timeout 30000ms exceeded at sprawdzBladPodWygladem (scripts/szybki-wyglad.mjs:240)` | **Stan nierozstrzygnięty — patrz niżej** |
| **#922** `flota/relacje` | „Port marki — rodziny ekranów” | **identyczny ślad** co #924, ten sam etap, po tym samym `NOTICE_OK` | **Stan nierozstrzygnięty — patrz niżej** |

#### #924 i #922 — czerwień, której NIE przypisuję jednoznacznie

Obie gałęzie oblewają **ten sam etap, tym samym komunikatem, w tym samym miejscu**
(`sprawdzBladPodWygladem`, czyli sprawdzenie, że błąd walidacji pojawia się pod polem).
Przemawia to za wspólną przyczyną albo za chwiejnym testem. **Ale** trzeci PR
uruchomiony w tej samej minucie (#923) ten sam job **zaliczył**, więc nie jest to
awaria całego runnera.

> **Nie rozstrzygam tego z logu i nie zgaduję.** Warunek wejścia dla obu: po scaleniu
> PG18 **ponowić CI i porównać, czy oba nadal padają w tym samym punkcie**. Jeśli tak —
> to jest wspólna regresja do osobnego zadania, nie do naprawy przy scalaniu.
> Oba PR-y dotykają walidacji formularzy, więc wspólna przyczyna jest prawdopodobna.

---

## 5. Czerwień PG18 kontra czerwień własna — dowód

To najważniejsze rozróżnienie w tym dokumencie. **Jedenaście PR-ów ma czerwień,
która nie pochodzi z ich kodu.**

### Sygnatura A — job „Testy (PostgreSQL 18)”

Na **wszystkich jedenastu** (#913, #914, #916, #917, #918, #919, #920, #921, #922, #923, #924)
wynik końcowy jest identyczny co do wzoru:

```
FAILED  Tests\Feature\ProbaOdtworzeniaTest > skrypty kopii i proby odtwor…
Tests:    1 failed, 4393–4419 passed (83694–83892 assertions)
```

Jedyny oblany test, z przyczyną wprost w logu:

```
Expected: PostgreSQL nie odpowiada — nie ma czego dowodzić.\n KOD=1
To contain: KOD=0
at tests/Feature/ProbaOdtworzeniaTest.php:68
```

### Sygnatura B — job „Pint (styl kodu)”

Ten job uruchamia też baterię kontroli kopii bazy. Na wszystkich jedenastu:

```
── Archiwum OBCIĘTE: na PRAWDZIWYM pliku i PRAWDZIWYM pg_restore ──
  ✓ fikstura odkodowuje się do niepustego pliku
  ✗ pełne, zdrowe archiwum PRZECHODZI weryfikację
     oczekiwano: kod=0
     otrzymano:  kod=51
  ✗ archiwum obcięte do 99% zostaje ODRZUCONE
     oczekiwano: kod=53
     otrzymano:  kod=51
  … (i tak osiem razy)
Oblane: 9, zdane: 158
```

**Kod 51 pada nawet na ZDROWYM archiwum** — to nie jest wynik o jakości kodu, tylko
o wersji klienta `pg_restore` na runnerze. Zgadza się co do kodu błędu z zapisem
w `STAN_SESJI_CZESC4.md` w. 84–104 (pułapka P5).

### Czego te dwie sygnatury NIE oznaczają

- **Nie oznaczają, że po scaleniu PG18 wszystko zaświeci na zielono.** Oznaczają, że
  te dwa joby przestaną kłamać. Sześć PR-ów z grupy D ma osobne, własne czerwienie.
- **Nie sprawdzałem, czy poprawka `fa06f5cd` faktycznie usuwa kod 51 na `ubuntu-latest`** —
  nie uruchamiałem CI z tą gałęzią. Potwierdzi to dopiero pierwsze ponowienie po jej scaleniu.
- Bloki „8 failed, 6 passed” i „1 failed (3 assertions)”, które widać w środku każdego
  logu przy `WyborZeszytuMaWalidacjeTest`, `KafelDodawaniaPrzyDuzymTekscieTest`
  i `PanelModeracjiWMenuTest`, to **zamierzone kontrole negatywne** z kroku
  `python3 scripts/kontrole-negatywne-alfa08.py`, zakończonego komunikatem
  „Pięć kontroli negatywnych wykryły regresje; źródła przywrócone.”
  **Nie liczyć ich jako porażek.**

---

## 6. Kolejność w jednej linii

```
naprawa/klient-pg18-w-ci  →  ponowić CI wszystkim  →  #919  →  #917
   →  #920  →  #921  →  #923  →  #916
   →  [decyzja: #786 vs #920]  →  [rebase #915]
   →  [naprawa własnych czerwieni: #918, #913, #914, #725, potem #924 i #922]
```

Przy każdym kroku: **scalać pojedynczo i czekać na zielone CI przed następnym**,
bo przy `checkSuites: true` czerwień w chwili scalenia kasuje wdrożenie bezpowrotnie (P6).

---

## 7. Granice tego dokumentu

- To **analiza, nie wykonanie**. Nic nie scalono, nie pchnięto, nie zamknięto,
  nie skomentowano w żadnym PR-ze.
- Stany CI są **migawką z ok. 20:30Z**. Każde ponowienie je zmienia.
- Kolizje plikowe wyliczono z **list plików**, nie z próbnego scalenia. Para z częścią
  wspólną **może** dać konflikt; para bez części wspólnej **nie da konfliktu tekstowego**,
  ale wciąż może dać konflikt semantyczny (dwa PR-y zmieniające to samo zachowanie
  w różnych plikach).
- Przyczyny czerwieni w grupie D pochodzą **z dzienników jobów**. Tam, gdzie log nie
  rozstrzyga (#924, #922), jest to napisane wprost zamiast zgadywania.
- Dokument **nie rozstrzyga** żadnej z decyzji, które dokumentacja floty rezerwuje dla
  właściciela: losu #786 wobec #920, numeracji 0.68 (Q2), obsługi zaległych zadań #888 (Q3)
  ani treści polityki prywatności (Q12).
