# Audyt 1 — architektura i jakość kodu

**Repozytorium:** `woogitsu/kuking.pl`  
**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Architektura jest zasadniczo właściwa dla etapu produktu: modularny monolit Laravel, PostgreSQL jako jeden silnik danych i wyszukiwania, kolejka bazodanowa oraz brak przedwczesnego rozbijania systemu. Największe ryzyko architektoniczne nie leży w wyborze stacku, lecz w stopniowym cofnięciu się od przyjętej zasady „cienki kontroler → logika domenowa/query object” do kontrolerów, które ponownie składają złożone zapytania i modele widoku.

**Ocena: 8,5/10.** Nie ma powodu zmieniać fundamentów ani dodawać nowych technologii.

## Co jest zrobione dobrze

- `docs/ARCHITECTURE.md` i `AGENTS.md` jasno definiują modularny monolit i granice odpowiedzialności.
- Logika domenowa ma własne katalogi `app/Domain/*`; ostatnie zmiany konsekwentnie tworzą klasy domenowe zamiast kopiować reguły widoczności.
- Reguły widoczności treści są centralizowane i ponownie używane (`widoczneDla`, `dostepnyJakoAutor`, Policy), co redukuje ryzyko różnic między ekranami.
- Projekt świadomie unika Redis/Kafki/SPA/osobnego search engine bez pomiaru — to poprawne przy tej skali i jednoosobowym utrzymaniu.
- `composer.json` ma mały zestaw zależności produkcyjnych; nie widać „frameworku na frameworku”.
- PostgreSQL jest używany również w CI, a nie zastępowany SQLite, co jest ważne przy FTS, `pg_trgm`, JSONB i ograniczeniach bazodanowych.

## Ustalenia

### A1 — P2 — logika zapytań i prezentacji wraca do kontrolerów

**Dowód:** `app/Http/Controllers/CollectionController.php` ma ok. 15,5 KB i zawiera m.in. prywatną metodę `ostatnioZapisane()` budującą dwa złożone zapytania, podzapytania skorelowane, mapowanie do tablic modelu widoku oraz sortowanie wyników. `show()` składa kilka niezależnych kwerend, liczników i reguł widoczności. W repo są również bardzo duże kontrolery, m.in. `CookedEventController.php` (~16,4 KB) i `HealthController.php` (~13,6 KB).

**Dlaczego to jest problem:** `AGENTS.md` mówi wprost, że kontroler ma być cienki. Sam rozmiar pliku nie jest błędem, ale w `CollectionController` istnieje już konkretna logika, którą da się testować i ponownie wykorzystać poza HTTP. Wraz z dalszym rozwojem „Moje/Zeszyt” ryzyko regresji i koszt testów kontrolera będą rosły.

**Rekomendacja:** przenieść co najmniej `ostatnioZapisane()` do nazwanego query object / use-case, np. `App\Domain\Collections\OstatnioZapisane`, a następnie podobnie potraktować kwerendy ekranu kolekcji, gdy będą ponownie modyfikowane. Nie robić refaktoru „dla czystości” całego repo naraz — tylko przy dotykaniu danego obszaru.

### A2 — P2 — komentarze w CI nie są już źródłem prawdy

**Dowód:** w `.github/workflows/ci.yml`, przy jobie `static-analysis`, nadal znajduje się rozbudowany komentarz mówiący, że „w repozytorium nie ma jeszcze `phpstan.neon` (issue #32)”. Tymczasem `phpstan.neon` istnieje w root, a ostatnie commity raportują udane `phpstan analyse --no-progress` z 0 błędów. Logika workflow sama wykrywa obecność pliku, więc funkcjonalnie analiza działa; nieaktualny jest opis.

**Skutek:** operator/agent może uznać, że analiza statyczna jest nadal opcjonalna lub nieaktywna i podejmować decyzje na podstawie starego stanu.

**Rekomendacja:** skrócić komentarz do aktualnej zasady: PHPStan jest obowiązkowym jobem, jeśli konfiguracja istnieje; usunąć historię o #32 albo przenieść ją do decyzji/historycznego dokumentu.

### A3 — P3 — śmieci w root repozytorium

**Dowód:** root zawiera pusty plik `object_key` oraz duży `R1-tagi-kopia.md` (~47 KB). Wyszukiwanie kodu nie znalazło odwołań do żadnego z nich.

**Skutek:** mały, ale realny koszt poznawczy; `object_key` wygląda jak przypadkowy produkt polecenia/redirectu, a `R1-tagi-kopia.md` jak kopia robocza bez ustalonego miejsca w dokumentacji.

**Rekomendacja:** zweryfikować historię obu plików. Jeśli nie są potrzebne, usunąć. Jeżeli `R1-tagi-kopia.md` jest materiałem referencyjnym, przenieść pod `docs/archive/` lub właściwy katalog i nadać nazwę opisującą status.

## Nie stwierdzono

- potrzeby mikroserwisów;
- potrzeby Redis na obecnym etapie;
- potrzeby osobnego silnika wyszukiwania;
- cyklicznego uzależnienia architektury od wielu frameworków frontendowych;
- masowego surowego SQL w aplikacji podczas wyszukania wzorców `DB::raw`, `whereRaw`, `selectRaw` — pojedyncze `selectRaw` występuje w kontrolerze kolekcji i jest uzasadnione agregacją, ale nie stanowi wzorca rozlanego po repo.

## Priorytet działań

1. A2 — poprawić nieaktualny komentarz CI przy najbliższej zmianie workflow.
2. A1 — ekstrakcja query object przy kolejnej modyfikacji „Moje/Zeszyt”.
3. A3 — posprzątać root przed publicznym otwarciem repo lub kolejnym większym onboardingiem deweloperskim.
