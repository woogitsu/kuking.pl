# Zasady pracy agenta w kuking.pl (przeczytaj PRZED pierwszą zmianą)

> **Ten plik jest briefem, który wkleja się agentowi na start.** Powstał
> 10.09.2026 w trakcie sesji, w której pracowało kilkunastu agentów
> równolegle — każdy punkt tutaj wziął się z konkretnej pomyłki, która
> kogoś kosztowała pracę. Trzymaj go aktualnym: gdy złapiesz nową pułapkę,
> dopisz ją, zamiast tłumaczyć ją następnemu agentowi w rozmowie.
>
> Rozszerzeniem tego pliku o same testy jest [`docs/PULAPKI_TESTOW.md`](../PULAPKI_TESTOW.md).

## Najpierw
1. Przeczytaj `/workspace/kuking.pl/AGENTS.md` — to jedyne źródło prawdy o zasadach.
2. Przeczytaj `docs/research/audyt-2026-09-10/SPRAWDZENIE.md` — mówi, co z audytu
   jest potwierdzone, a co okazało się nieprawdą. Audyt zewnętrzny to HIPOTEZY,
   nie prawda o kodzie. Twoje zadanie też sprawdź przy pliku, zanim je zrobisz.

## Własny worktree — obowiązkowo
Kilka agentów pracuje równolegle w tym samym kontenerze. Nie pracuj w
`/workspace/kuking.pl`.

```
cd /workspace/kuking.pl
git fetch origin main
git worktree add /tmp/wt-<krotka-nazwa> -b claude/<twoja-galaz> origin/main
cp -al /workspace/kuking.pl/vendor /tmp/wt-<krotka-nazwa>/vendor   # HARDLINKI, nie symlink
cp /workspace/kuking.pl/.env /tmp/wt-<krotka-nazwa>/.env
cd /tmp/wt-<krotka-nazwa>
```

`cp -al` jest istotne: symlink na `vendor` sprawia, że Composer rozwiązuje
`App\` do głównego katalogu i Twoje nowe klasy są niewidoczne.

**Nigdy `git add -A` ani `git commit -a`.** Dodawaj wyłącznie własne pliki
z nazwy. Kiedyś jeden agent wciągnął tak plik drugiego do swojego commita.

## Zanim uznasz coś za zrobione
- `php artisan test` — PEŁNY zestaw, na PostgreSQL (nie SQLite), zielony.
  Jeśli zobaczysz błędy typu „relation ... does not exist", to kolizja z inną
  sesją na tej samej bazie testowej — powtórz przebieg, nie „napraw" testu.
- `vendor/bin/pint` — czysto.
- `vendor/bin/phpstan analyse --no-progress` — 0 błędów.
- `npm run build` — przechodzi (jeśli ruszasz `resources/`).
- **Kontrola negatywna dla KAŻDEGO nowego testu:** zepsuj kod, który test ma
  pilnować, pokaż że test OBLEWA, przywróć kod, sprawdź `git diff` = pusty.
  Test, który przechodzi też po zepsuciu kodu, nie jest testem. Wpisz do
  podsumowania tabelkę: test → sabotaż → wynik.
- Uwaga na pułapkę, która złapała już czterech: **assercja na całym HTML strony
  łapie to samo słowo z innego miejsca.** Sprawdzaj wewnątrz konkretnego
  elementu, nie w całej odpowiedzi.
- `node scripts/dostepnosc.mjs` NIE jest warunkiem ukończenia — na współdzielonym
  runnerze pada z powodu zbieżności (#262). Jeśli go odpalasz i padnie, sprawdź
  jednym uruchomieniem, czy pada tak samo na `origin/main`, i zapisz wynik jako
  niepewny, a nie „zielony".

## Zasady produktu, które łatwo złamać
- Wszystko po polsku: kod komentarze, commity, PR, teksty w UI.
- Tekst ≥ 18 px, przyciski ≥ 48 px, ikona nigdy sama bez podpisu, nic nie może
  zależeć wyłącznie od hover ani swipe.
- Błędy po polsku mówiące CO ZROBIĆ, nie co się stało. Poprawne dane nigdy nie
  znikają z formularza.
- Ważne funkcje działają bez JavaScriptu.
- Zmiana schematu = migracja + test + wpis w `docs/DATABASE.md` + opisany rollback.
- Bugfix = test regresyjny.
- `status` i `role` użytkownika nigdy w `$fillable`.
- UUID w adresie to nie autoryzacja — każde wejście przez Policy.
- Żadnych mikroserwisów, SPA, Redisa, osobnego search engine.
- Brak destrukcyjnych operacji na produkcji.
- Komentarz w kodzie ma mówić DLACZEGO, nie co robi linijka. Patrz na styl
  komentarzy w istniejących plikach — długie, wyjaśniające decyzję. Trzymaj ten styl.

## Na koniec
1. Commit z opisem mówiącym CO i DLACZEGO (styl: zobacz `git log`). Stopka:
   ```
   Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
   Claude-Session: https://claude.ai/code/session_019cjMJ7ocGyFiFJG4pvnS2w
   ```
2. `git push -u origin claude/<twoja-galaz>` (przy błędzie sieci powtórz z 2s, 4s, 8s, 16s).
3. Otwórz PR do `main`, wypełniając `.github/pull_request_template.md`. Na końcu opisu:
   ```
   🤖 Generated with [Claude Code](https://claude.com/claude-code)

   https://claude.ai/code/session_019cjMJ7ocGyFiFJG4pvnS2w
   ```
   Odhaczaj tylko to, co naprawdę zrobiłeś. Nie pisz „zielone" o czymś, czego nie
   uruchomiłeś.
4. W podsumowaniu do mnie napisz: gałąź, numer PR, stan sprzed zmiany
   (sprawdzony w kodzie), co zrobiłeś, tabelka kontroli negatywnych, wyniki
   testów, i **co świadomie pominąłeś i dlaczego**. Nie chowaj rzeczy
   niedokończonych — ja to weryfikuję, i wolę usłyszeć od Ciebie.

---

## Pięć rzeczy, które w tej sesji naprawdę złapały agentów

Nie są hipotetyczne. Każda kosztowała czyjąś pracę.

### 1. NIE WIERZ BRIEFOWI W TO, CO JEST NA `main`
Dwa razy napisałem agentowi, że jakaś klasa albo decyzja jest już na `main`,
a nie była — leżała na niescalonej gałęzi. Jeden agent to sprawdził i świadomie
się na tym nie oparł; drugi mógł zbudować zależność od czegoś, czego nie ma.
**Sprawdź `git show origin/main:<plik>` zamiast wierzyć zdaniu w zadaniu.**
To dotyczy też numerów decyzji: `grep "^## D-0" docs/DECISIONS.md` na `main`.

### 2. TWÓJ SABOTAŻ MOŻE BYĆ ZA SŁABY, A TY UZNASZ TEST ZA POZORNY
Dwóch agentów niezależnie próbowało zepsuć `catch` przez podmianę na
`\RuntimeException` — i test nadal przechodził, bo
`UniqueConstraintViolationException → QueryException → PDOException →
RuntimeException`. Prawdziwym sabotażem było dopiero `\LogicException`.
**Kontrola ujemna, która nie oblewa, może znaczyć „zły sabotaż", a nie „zły
test". Sprawdź, zanim uznasz test za atrapę — i zanim uznasz go za dobry.**

Odwrotny przypadek z tej samej sesji: dwie kontrole ujemne PRZESZŁY i to
wykryło błąd w testach — dwa testy trafiały w tę samą gałąź warunku, więc
drugi nie sprawdzał niczego.

### 3. TEST SKANUJĄCY PLIKI TRZEBA SABOTOWAĆ ODWROTNIE
Test, który przechodzi po plikach i szuka wzorca, przechodzi też wtedy, gdy
nie znajduje ŻADNEGO pliku (zła ścieżka, zły glob). **Wstaw tymczasowo
łamiącą treść i sprawdź, że test OBLEWA.** Dołóż też asercję na minimalną
liczbę przeskanowanych plików. W tej sesji istniejący test „teksty bez
ukośnika rodzajowego" był zielony przy pięciu żywych ukośnikach, bo jego
wzorzec ich nie łapał.

### 4. ASERCJA NA CAŁYM HTML-U ŁAPIE TO SAMO SŁOWO SKĄDINĄD
Powtarzam, bo to złapało już sześć osób w tym projekcie, mnie włącznie.
Licznik komentarzy, prawa szyna, stopka — wszystkie zawierają te same liczby
i słowa co element, który sprawdzasz. **Wycinaj konkretną sekcję** (jest na to
gotowy wzorzec: `wycinek()` w testach onboardingu, DOMXPath w testach landingu,
`fragmentPoId()` w testach 2FA).

### 5. `git merge origin/main` PRZED PUSHEM, ZA KAŻDYM RAZEM
`main` w tej sesji przesuwał się kilka razy na godzinę. Gałąź z zielonym CI
sprzed godziny nie da się scalić, a jeśli się da — konflikt trzeba PRZECZYTAĆ,
nie ufać automatowi. Był w tej sesji konflikt dwóch poprawek w jednej linii,
gdzie poprawne rozwiązanie brało jedno z jednej strony i drugie z drugiej,
a każda strona osobno wyglądała kompletnie.

### Dwie rzeczy o środowisku

- **Katalog scratchpada jest współdzielony między agentami.** Jeden agent
  nadpisał plik z opisem PR-a treścią cudzego zadania. Używaj nazw plików
  z własną gałęzią w nazwie.
- **Nie uruchamiaj dwóch `php artisan test` na tej samej bazie testowej.**
  Objaw: `relation … does not exist` albo zakleszczenie na `ALTER TABLE`. To
  kolizja, nie usterka repozytorium — powtórz przebieg pojedynczo. Baza jest
  per-KATALOG (`tests/nazwa-bazy.php` liczy ją ze ścieżki kopii roboczej),
  więc wystarczy pracować we własnym katalogu — worktree albo zwykły klon,
  obojętne. Nazwę swojej bazy zobaczysz poleceniem:
  `php -r 'require "tests/nazwa-bazy.php"; echo kuking_nazwa_testowej_bazy(__DIR__);'`
- **Nie kończ tury, czekając na polecenie w tle.** Kilku agentów zatrzymało
  się na „czekam na wynik testów" i tura się skończyła, więc nic nie czekało.
  Uruchamiaj pełny zestaw w pierwszym planie, z długim timeoutem.
