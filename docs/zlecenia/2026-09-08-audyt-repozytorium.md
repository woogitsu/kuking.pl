# Zlecenie: audyt repozytorium — niespójności i rzeczy nieaktualne

**Dla:** drugiej instancji Claude Code (Opus 5) z dostępem do zapisu w repozytorium.
**Zleca:** właściciel, 8 września 2026.
**Rozmiar:** pięć pakietów, do pięciu agentów równolegle.

---

## 0. Zanim cokolwiek zrobisz

Przeczytaj w tej kolejności:

1. **`AGENTS.md`** — komplet zasad projektu i jedyne źródło prawdy. Nie jest to
   zbiór wskazówek; łamanie tych zasad jest podstawą do odrzucenia pracy.
2. `docs/UX_50_PLUS.md` — odbiorcami są ludzie 50+. Tekst od 18 px, cel
   dotknięcia od 48 px, ikona nigdy bez podpisu, ważne funkcje działają bez
   JavaScriptu.
3. `docs/DECISIONS.md` — decyzje D-001…, każda z uzasadnieniem.
4. `docs/ROADMAP.md` — kolejność prac. Nie budujemy funkcji z V2 podczas MVP.
5. `docs/HANDOVER.md` — stan na dziś, sekcje 9 i 10.

Odpowiadasz po polsku. Komentarze w kodzie po polsku.

---

## 1. Po co to jest

W repozytorium jest **86 dokumentów** (poza wklejoną paczką projektową)
i **41 otwartych issues**. Projekt prowadzi jedna osoba, a pisze do niego
kilka modeli. Skutek jest przewidywalny i już mierzalny: **dokumenty
i kod rozjechały się w wielu miejscach, a nikt nie wie w ilu.**

To nie jest podejrzenie. Przy pobieżnym sprawdzeniu pięciu issues **trzy
okazały się już zrobione**, tylko nikt ich nie zamknął:

| Issue | Tytuł skrócony | Co jest w kodzie |
|---|---|---|
| #112 (P1) | `ProcessUploadedImage` nie ma `failed()` | `app/Jobs/ProcessUploadedImage.php:193` — metoda istnieje |
| #109 (P1) | unikalność e-maila case-sensitive w bazie | migracja `2026_09_06_120000_add_email_case_insensitive_unique_index.php` — indeks funkcyjny na `lower(email)` jest |
| #114 (P0) | komenda `kuking:wac` | `app/Console/Commands/ReportWeeklyActiveCooks.php` istnieje |

A w ciągu jednego przedpołudnia znaleziono trzy usterki, które **milczały**,
bo każda była opisana jako działająca:

* skala tekstu 140% zapisywała się na koncie i **nie zmieniała ani jednej litery**
  — konfiguracja mówiła `[100, 112, 125, 140]`, arkusz umiał `112, 125, 150`;
* `clamp` na największych tytułach stał w arkuszu i **nigdy nie zadziałał**, bo
  Tailwind generował z tokenu klasę o tej samej nazwie w warstwie wygrywającej;
* pole „Szukaj" w belce stało **156 px obok** kolumny, którą przeszukuje.

Wzorzec jest jeden i powtarza się: **obietnica w komentarzu albo w dokumencie
jest mocniejsza niż to, co sprawdza kod pod nią.** Tego szukasz.

**Produktem tego zlecenia jest lista rozjazdów z dowodami.** Poprawki są drugie
w kolejności i tylko tam, gdzie są jednoznaczne.

---

## 2. Czego NIE WOLNO dotykać

Równolegle otwarty jest **PR #126** na gałęzi
`claude/kuking-development-handover-kl1meu` — zielony, czeka na scalenie,
trzyma przebudowę wyglądu pod system projektowy v3.1. **Nie zmieniaj:**

```
resources/css/app.css
resources/css/tokens.css
resources/css/strony-publiczne.css
resources/views/components/layout.blade.php
resources/views/components/empty-state.blade.php
resources/views/pages/landing.blade.php
resources/views/pages/notifications.blade.php
scripts/dostepnosc.mjs
.github/workflows/ci.yml
docs/HANDOVER.md
docs/design/system-v3.1/**
app/Support/NumerSprawy.php
database/migrations/2026_09_07_910000_add_numer_sprawy_to_reports.php
tests/Feature/NumerSprawyTest.php
tests/Feature/BackfillNumerowSprawTest.php
tests/Feature/SkalaTekstuDzialaTest.php
tests/Feature/StronaPowitalnaPasyTest.php
tests/Feature/WylacznikKluczaWyslaniaTest.php
```

Wolno je **czytać i zgłaszać znaleziska** — nie wolno ich zmieniać. Jeśli
poprawka wymaga któregoś z nich, opisz ją i zostaw na po scaleniu #126.

Pracuj na **własnej gałęzi odbitej od `main`**: `claude/audyt-repo-<data>`.
Nie odbijaj od gałęzi #126.

---

## 3. Pięć pakietów

Każdy pakiet to jeden agent. Każdy kończy się **sekcją w jednym wspólnym
dokumencie `docs/AUDYT_2026-09.md`** — nie pięcioma osobnymi plikami.

### Pakiet A — backlog wobec kodu

Wszystkie **41 otwartych issues**. Dla każdego jeden z czterech wyników:

| Wynik | Kiedy |
|---|---|
| **ZROBIONE** | funkcja jest w kodzie **i ma test**, który ją pilnuje |
| **CZĘŚCIOWO** | część jest; nazwij dokładnie, czego brakuje |
| **OTWARTE** | nie ma tego w kodzie |
| **NIE DA SIĘ Z KODU** | zależy od konta, prawnika, ludzi albo pieniędzy (#8 prawnik, #29 pierwszych 20 użytkowników, #3 deploy, #120 dowód na R2) |

**„ZROBIONE" bez wskazania pliku, linii i testu nie jest wynikiem — jest
zgadywaniem.** Funkcja bez testu to **CZĘŚCIOWO**, a brakiem jest test.

Wynik: tabela posortowana P0 → P1 → P2, plus trzy listy — **do zamknięcia**,
**do zrobienia teraz**, **czeka na właściciela**. **Nie zamykaj issues sam.**

### Pakiet B — dokumenty wobec kodu

86 dokumentów w `docs/` (pomiń `docs/design/system-v3.1/uploads/` — to wklejona
paczka właściciela, nie nasz tekst). Szukasz **zdań, które są nieprawdziwe**:
opisanej funkcji, której nie ma; opisanego pola bazy, które nazywa się inaczej;
opisanej trasy, która nie istnieje; liczby, która się nie zgadza.

Zacznij od tych, które najczęściej kłamią, bo najczęściej się zmieniają:
`DATABASE.md`, `FEATURES.md`, `FLOWS_AND_SCREENS.md`, `ARCHITECTURE.md`,
`MEDIA_PIPELINE.md`, `MODERATION.md`, `SECURITY_PRIVACY_LEGAL.md`,
`TESTING.md`, `docs/design/STAN_WDROZENIA_KITU.md`, `docs/design/COMPONENTS_BLADE.md`.

Przy każdym znalezisku: cytat zdania, plik i linia dokumentu, plik i linia
kodu, który mu przeczy, i jednozdaniowa propozycja poprawki.

### Pakiet C — dokumenty wobec siebie

Sprzeczności **między** dokumentami. Trzy znane, żebyś wiedział, czego szukać
— **nie rozstrzygaj ich, to są decyzje właściciela**, tylko znajdź resztę:

* **„Zapisz" czy „Zapisuję"** — `BRAND_EXTENDED.md` §1.2 mówi jedno,
  `COPY_STYLE.md` §3 i §6 drugie, a `BRAND_EXTENDED.md` §3 zabrania synonimów
  wprost.
* **Plakietka konta przykładowego** krąży w **pięciu** brzmieniach.
* **Kolumna ilości przy składniku** — makieta obiecuje przeliczanie porcji,
  `STAN_WDROZENIA_KITU.md` mówi „nie i nie będzie, D-017".

Sprawdź też, czy któraś decyzja `D-nnn` została **milcząco odwrócona** w kodzie
bez wpisu w `DECISIONS.md` — to jest cichszy i groźniejszy przypadek.

Wynik: lista par sprzecznych zdań, z cytatami obu stron. Przy każdej: czy to
jest decyzja produktowa (do właściciela), czy zwykła literówka w dokumencie
(do poprawienia od ręki).

### Pakiet D — kod i konfiguracja bez użycia

Rzeczy, które istnieją i nie są przez nic wołane:

* metody publiczne, których nikt nie wywołuje (znany przypadek:
  `NumerSprawy::poprawny()` — docblock mówi, że służy do walidacji, a nie woła
  jej nic w `app/`, `database/` ani `tests/`);
* klucze w `config/kuking.php`, których nie czyta żaden kod;
* zmienne środowiskowe wymienione w `.env.example`, których nie czyta nic;
* trasy w `routes/web.php` bez widoku albo bez dojścia z interfejsu
  (**uwaga: „bez dojścia" bywa usterką prawną, nie sprzątaniem** — formularz
  zgłoszenia nielegalnej treści musi być łatwo dostępny, DSA art. 16 ust. 1);
* komponenty Blade w `resources/views/components/`, których nikt nie używa;
* klasy CSS zdefiniowane i nieużywane w żadnym widoku — **tu tylko zgłaszaj,
  nie kasuj**, bo pliki CSS są na liście z §2.

**Niczego nie usuwaj bez sprawdzenia, czy to nie jest droga awaryjna albo
wymóg prawny.** Zgłoś, uzasadnij, zaproponuj — kasowanie jest decyzją
właściciela.

### Pakiet E — testy, które nic nie sprawdzają

Najważniejszy pakiet i najtrudniejszy. Dla każdego pliku w `tests/` zadaj jedno
pytanie: **co dokładnie musiałoby się zepsuć w kodzie, żeby ten test padł?**

Szukasz:

* testów, które przeszłyby po **usunięciu** funkcji, którą rzekomo pilnują
  (znany wzorzec z tego repozytorium: test twierdził, że pilnuje indeksu
  unikalności, a kod robił najpierw `SELECT` i nigdy nie dochodził do `INSERT`
  — `DROP INDEX` zostawiłby test zielony);
* pętli, które mogą się nie wykonać ani razu, bez **asercji kontrolnej**, że
  dane w ogóle są;
* asercji na komunikat, gdy sensem jest zachowanie (albo odwrotnie);
* testów sprawdzających obecność **nazwy klasy** zamiast **reguły**;
* miejsc, gdzie `RefreshDatabase` daje pustą tabelę, a test twierdzi, że
  sprawdza migrację danych na niepustej.

Wynik: lista testów z oceną, co realnie łapią, i propozycja jednej konkretnej
asercji, która by to naprawiła. **Poprawki testów wolno wprowadzić od razu** —
to jedyny pakiet, w którym zmiana jest zawsze bezpieczna, pod warunkiem że
poprawiony test **pada na obecnym kodzie, jeśli usterka jest realna** (pokaż
to w opisie commita).

---

## 4. Zasady wspólne

* **Dowód albo nic.** Każde znalezisko ma plik, linię i sposób pomiaru.
  Jeśli czegoś nie udało się rozstrzygnąć — napisz „nie rozstrzygnięte"
  i dlaczego. To jest wartościowa odpowiedź. Wymyślone znalezisko nie jest.
* **Nie zgłaszaj preferencji.** Nie interesuje nas, że coś dałoby się napisać
  ładniej. Interesuje nas to, co jest **nieprawdziwe** albo **nie działa**.
* **Nie refaktoryzuj przy okazji.** Jeden problem, jedna zmiana, jeden commit.
* **Nie dodawaj zależności.** Decyzje o pakietach to osobny, otwarty issue (#21).
* **Bugfix = test regresyjny**, padający przed poprawką. Pokaż w opisie commita,
  że padał.
* **Zmiana schematu = migracja + test + wpis w `docs/DATABASE.md` + plan
  wycofania**, a plan wycofania ma być **wykonany testem**, nie opisany.
* `status` i `role` użytkownika **nigdy** w `$fillable`. UUID w adresie to nie
  autoryzacja — każde wejście przez Policy. Testy na PostgreSQL, nie SQLite.

---

## 5. Środowisko

* **CI chodzi na własnych runnerach właściciela**, bez zapasu na runnerach
  GitHuba. Trzy z sześciu bywają wyłączone, więc przebieg trwa 4–10 minut,
  a zadania potrafią iść po jednym. **Nie pushuj kilkanaście razy pod rząd.**
* Zadanie **Dostępność (axe-core)** rusza tylko przy zmianach w warstwie widoku:
  23 ekrany × 4 warianty (jasny, ciemny, tekst 140%, 320 px), plus przewijanie
  w bok i wyrównanie belki do siatki treści. Czytaj jego **wynik**, nie tylko
  kolor lampki — podaje liczby.
* Jeśli **`composer install` nie przechodzi** w Twoim środowisku (u zlecającego
  nie przechodził: pakiety obce ciągną się z `api.github.com`, a dostęp bywa
  zawężony do jednego repozytorium — 403), to `php artisan test` nie zadziała.
  Wtedy **powiedz to wprost w raporcie i w opisie PR-a**. `npm run build`,
  `php -l` i `node --check` działają zawsze.
* **Nie pisz, że coś zweryfikowałeś, jeśli tego nie uruchomiłeś.** To jedyna
  rzecz gorsza niż błąd: błąd się znajduje, a fałszywe „sprawdzone" wyłącza
  szukanie.

### Pułapka, która w jedno przedpołudnie złapała dwa razy

`resources/css/app.css` ma wczesny blok `@layer components {` (linia 29),
kończący się w okolicy **linii 1763**; wszystko po nim stoi **poza warstwą**.
Reguła nielayerowana bije layerowaną **niezależnie od kolejności w pliku**,
a `@media` **nie podnosi wagi selektora**. Jeśli audytujesz CSS: sprawdzaj
**zbudowany** arkusz (`npm run build`, potem pozycje reguł
w `public/build/assets/app-*.css`), nie źródło.

---

## 6. Co ma wyjść

1. Gałąź `claude/audyt-repo-<data>` z PR-em do `main`, **gotowym do przeglądu,
   nie szkicem**.
2. **`docs/AUDYT_2026-09.md`** — pięć sekcji, po jednej na pakiet, każda
   z dowodami. Na początku dokumentu **streszczenie na jedną stronę**:
   dziesięć najpoważniejszych znalezisk, po jednym zdaniu każde. Właściciel
   ma zobaczyć najważniejsze, zanim zdecyduje, czy czytać resztę.
3. Osobne commity na poprawki, które były jednoznaczne — głównie z pakietu E.
4. W opisie PR-a: co zweryfikowano i **czym**, oraz osobno czego nie udało się
   sprawdzić i dlaczego.

Jeśli po audycie wyjdzie, że repozytorium jest spójne i cała praca to lista —
**to jest dobry wynik.** Wiarygodna lista jest tu warta więcej niż kolejna
funkcja; właśnie jej brak jest dziś największym kosztem w tym projekcie.
