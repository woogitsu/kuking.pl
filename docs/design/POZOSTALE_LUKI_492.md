# #492 — przegląd pozostałych luk, 19 września 2026

Stan odniesienia: `main` = `e306842c692dba2d1dea894d4cfb7947cf120ed9`.
Produkcja odczytana tego samego dnia: **Alfa 0.67**, SHA `ab91185`.
Bez zmian kodu aplikacji. Bez pushu i bez PR-a.

Ten przegląd **niczego nie implementuje ponownie**. Jego zadaniem było ustalić
stan faktyczny — bo trzy z czterech sprawdzonych pozycji długu weryfikacyjnego
okazały się mieć inny status, niż opisywał je dokument sprzed kilku godzin.

## 0. Najważniejsze ustalenie: produkcja jest o dwa scalenia za `main`

| | SHA | co zawiera |
|---|---|---|
| produkcja (Alfa 0.67) | `ab91185` | PR #712 (#692 — paczka z danymi) |
| `main` | `e306842c` | dodatkowo PR #717 (odbiór #568) i **PR #716 (martwa klasa `lead`)** |

Odczyt: stopka strony głównej i `/health`. Konsekwencja dla tego przeglądu:
zmiany z PR #716 **nie są jeszcze na produkcji**, więc ich odbiór produkcyjny
nie jest dziś możliwy i nie wolno go zapisać jako wykonany.

`/health` = `degraded`, jedyny niezdrowy element: `kolejka: zadania_nieudane`.
Zgadza się z A1 długu weryfikacyjnego (#713) — bez zmian.

## 1. Tabela stanu

Warstwa dowodu: **kod** = odczyt źródeł, **test** = przebieg testu/pomiaru,
**CI** = wynik zadania w CI, **produkcja** = odczyt https://kuking.pl (tylko GET,
bez logowania i bez tworzenia treści).

| Pozycja | Stan faktyczny | Warstwa dowodu | Co zostaje |
|---|---|---|---|
| **#713 D1** — job `Panel marki` gubi przyczynę porażki | **ZAMKNIĘTE, nieaktualny wpis.** Poprawka jest w `main` jako `e1ac577e`; `panel-validation.mjs` przepuszcza kody `/^[A-Z_]+$/`, a składanie komunikatu wydzielono do `scripts/panel-komunikat.mjs` **z własnym testem** `panel-komunikat.test.mjs` | kod | Nic. Wpis D1 w #713 do wykreślenia. Przyczyna samych porażek (podejrzenie: obciążenie maszyny) nadal niezmierzona — to osobna sprawa od komunikatu |
| **#713 D7** — trzecie miejsce z dwiema listami na jednym adresie | **BRAK TAKIEGO MIEJSCA.** Przejrzałem wszystkie widoki i akcje z ≥2 paginatorami: `collections/show` ma jawne `pageName` `wpisy` (poprawka #646), `RecipeController::show` ma `komentarze` i `wykonania` (#652), `profile/show` ma trzy paginatory, ale **wykluczają się zakładkami** (`@if($tab)/@elseif`), a `comment-thread` dziedziczy nazwę `komentarze`. `FeedController::home` paginuje jedną listę w gałęzi `match` | kod | Nic do naprawy. D7 można zamknąć jako sprawdzone |
| **#713 D4** — `lead → text-lead` bez oglądu na dwóch stronach | **ZMIERZONE I OBEJRZANE lokalnie.** `questions/index` i `settings/tags`: 36 konfiguracji PASS — 320/390/768/1440 × jasny/ciemny × skala 100/140 oraz rzeczywisty zoom 200 % (`deviceScaleFactor: 2`). Klasa **działa**: wstęp 22 px przy zwykłym 18 px (100 %) i 30,8 px przy 25,2 px (140 %). Zero poziomego przepełnienia, akapit mieści się w kolumnie, żadnej pozostałej `.lead` w DOM | test + kod | **Odbiór produkcyjny** — PR #716 nie jest wdrożony (§0). `questions/index` dodatkowo **nigdy nie będzie widoczny na produkcji**, dopóki flaga `KUKING_QUESTIONS_ENABLED` jest wyłączona |
| **#713 D6** — pasek górny po zalogowaniu w trybie gotowania i panelu | **NIE DA SIĘ SPRAWDZIĆ NA `main`** — zachowania tam nie ma. `layout.blade.php:362` ma `@guest data-pasek-przewijany @endguest`, więc pasek chowa się wyłącznie gościom. Zmiana siedzi w **otwartym PR #711** (`feat/pasek-takze-zalogowani`) | kod | Sprawdzenie należy do PR #711. Ułatwienie: oba ekrany używają wspólnego `<x-layout>` (`pages/recipes/cooking.blade.php`, `pages/admin/reports.blade.php`), więc jeden pomiar obejmie obie rodziny |
| **#713 D3** — regresja #707 nigdy nie przebiegła w CI | **PR #707 nadal OTWARTY**, niescalony | kod (stan PR) | Pełny hook i CI na tym SHA — w ramach #707, nie tutaj |
| **#371/#372 „Poradźcie"** | **Kod w `main`, na produkcji WYŁĄCZONY flagą.** `/pytania` → **404 na produkcji**, `200` lokalnie po `KUKING_QUESTIONS_ENABLED=true`; flagi pilnuje pięć miejsc w kodzie (`PostController`, `PublishPost`, `EditPost`, `BezOdpowiedziController`) | kod + produkcja | Decyzja produktowa o włączeniu. Do tego czasu każdy „odbiór produkcyjny" tych ekranów jest niewykonalny, nie „niezrobiony" |
| **#713 A1** — `zadania_nieudane` | Bez zmian: `/health` `degraded` wyłącznie na kolejce | produkcja | Odczyt `failed_jobs` — brak dostępu do konsoli/bazy |
| **#713 A2/A3, B1–B4, C1–C4** | Bez zmian względem #713 | — | Brak dostępu / realnych danych / urządzenia — patrz §2 |
| **#581** | OTWARTE; macierz opisuje to samo | kod (stan issue) | Ogląd wybranych stanów produkcyjnych panelu |
| **#561, #638, #646, #652, #569** | **ZAMKNIĘTE** — zgodnie z macierzą | kod (stan issue) | Nic. Nie powtarzać implementacji |
| **#666, #667, #278, #681, #692, #697** | OTWARTE, każde z własnym zakresem | kod (stan issue) | Własne issues; #492 ich nie dubluje |

## 2. Które luki da się domknąć bez realnych użytkowników

**Da się (i zostało zrobione w tym przeglądzie):** D1 — przez odczyt kodu;
D7 — przez przeszukanie repozytorium; D4 — przez pomiar i ogląd lokalny.

**Da się, ale należy do cudzego, otwartego PR-a:** D6 (PR #711), D3 (#707).
Nie dotykałem ich, żeby nie powtarzać cudzej pracy ani nie przejmować gałęzi.

**Nie da się bez dostępu:** A1 (konsola/baza produkcji), A2 (panel Cloudflare),
A3 (panel EmailLabs).

**Nie da się bez realnych danych:** B1 (#666 — odmiana `2/5/22 wykonania`
wymaga przepisu ugotowanego wiele razy przez ludzi), B4 (#605 — seria
obciążeniowa), B3 (#646/#652 — ekran z ponad dwunastoma pozycjami w obu
listach naraz), B2 (#370).

**Nie da się bez urządzenia albo człowieka:** C1 (odsłuch NVDA/VoiceOver),
C2 (fizyczny telefon, Safari, iOS), C3 (drukarka i rozpakowanie paczki).

**Zależy od decyzji produktowej, nie od pracy:** włączenie flagi „Poradźcie".

## 3. Dowody

Stanowisko: własny klon `/home/mateusz/kuking-492-run`, **`vendor` skopiowany,
nie dowiązany** (dowiązanie przestawia PSR-4 dla `App\` na katalog dawcy i cały
pomiar chodzi wtedy na cudzym kodzie), PostgreSQL **127.0.0.1:55439**, bazy
`kuking_492_run` i `kuking_492_testy`. Przed każdym uruchomieniem chodziła
bramka przerywająca pracę, gdy aplikacja celuje poza 55439/`kuking_492_*` —
`phpunit.xml` ma zaszyte `DB_PORT=5432`, a `.env` bywa przepisywany przez inne
sesje. Serwer oglądu startował z `--no-reload`.

Pomiar D4: `node pomiar-d4.mjs http://127.0.0.1:8492` → `D4_OK 36 konfiguracji`.
Skrypt sprawdza obecność `.text-lead`, brak pozostałej `.lead`, brak poziomego
przepełnienia, mieszczenie się w kolumnie oraz **czy klasa w ogóle coś robi**
(wstęp musi być większy od zwykłego akapitu) — bez tego ostatniego warunku
pomiar przechodziłby także wtedy, gdyby `text-lead` była równie martwa jak
`lead`.

**Pułapka zrzutów obsłużona:** przed każdym zrzutem ustawiałem
`localStorage['kuking-wyglad-poznany'] = '1'`, więc podpowiedź pierwszej wizyty
widgetu „Wygląd" nie przykrywa treści. Sam widget pozostaje widoczny — to jest
znane #684 i tego nie ukrywałem.

Obejrzane zrzuty: `questions/index` 1440 jasny (wstęp wyraźnie większy od
akapitu pod nim, pusty stan „Nie ma pytań pasujących do tego wyboru") oraz
`settings/tags` 390 ciemny po zalogowaniu (wstęp większy, pełne etykiety dolnej
nawigacji, brak przewijania w bok).

## 4. Czego ten przegląd NIE jest

Nie jest odbiorem produkcyjnym niczego — produkcja stoi dwa scalenia wstecz.
Nie jest oglądem na fizycznym telefonie ani w Safari. Nie jest odsłuchem
czytnika ekranu. Nie rozstrzyga przyczyny przerywanych porażek joba `Panel
marki`, a jedynie stwierdza, że komunikat o przyczynie został już naprawiony.
Nie sprawdzałem rodzin ekranów spoza dwóch stron z D4.
