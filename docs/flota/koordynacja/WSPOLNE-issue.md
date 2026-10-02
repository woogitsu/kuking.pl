WSPÓLNE ZASADY DLA PROSTEGO ISSUE (Kuking: Laravel 13, PHP 8.4, Blade, PostgreSQL):
- Przeczytaj AGENTS.md (§2: czytaj tylko dokumenty swojego obszaru; przy UI zawsze docs/UX_50_PLUS.md). Język polski.
- Bez force-push/rebase/reset na wypchniętych gałęziach. Nie dotykaj Railway ani produkcji. Nie omijaj haków ani odmów narzędzi — przy odmowie zatrzymaj się i zgłoś.
- Gałąź: `claude/<nr>-<krótki-slug>` od `origin/codex/integracja-poprawki-20261002-c` (git fetch origin; git checkout -b … origin/codex/integracja-poprawki-20261002-c).
- vendor: `cp -a /workspace/kuking.pl/vendor ./vendor && cp /workspace/kuking.pl/.env ./ && composer dump-autoload -q` (composer.lock identyczny). NIGDY symlink (wtedy testy biegną na cudzym kodzie). Sprawdź `php artisan --version`. Lokalny PostgreSQL 16 (CI ma 18), baza kuking_test. Uwaga: inni agenci mogą równolegle używać tej samej bazy testowej — jeśli test daje dziwne błędy połączenia/„niedostepny”, powtórz go raz osobno.
- Zakres: TYLKO to, czego wymaga issue. Bez migracji, chyba że issue tego wprost wymaga (wtedy zatrzymaj się i zgłoś zamiast robić). Jeśli issue wymaga decyzji produktowej, której nie ma w issue ani w docs/decyzje — NIE zgaduj: zatrzymaj się i opisz pytanie w raporcie.
- UX 50+: tekst ≥ 18 px, przyciski ≥ 48 px, komunikaty po polsku mówią co zrobić, bez hover/swipe, poprawne dane nie znikają.
- Test regresyjny/funkcyjny + RZECZYWISTA kontrola ujemna (zepsuj kod → test oblewa z właściwą przyczyną → przywróć → zielono). Jeśli nowy test czyta źródła (file_get_contents/resource_path + preg_match/assertString…), dopisz wpis do `checks` w scripts/kontrole-negatywne-alfa08.py (3. element krotki = NAZWA testu/klasy, nie ścieżka) i wzorzec do scripts/kontrole_oczekiwana_przyczyna.py; uruchom StraznikTekstuMaKontroleDodatniaTest. Dla JS: `node --test` odpowiednich *.test.mjs.
- CHANGELOG.md: jeden wpis na górze sekcji „## Nieopublikowane” (pierwsza sekcja pliku; NIE w „## Alfa 0.78”). Jeśli to [nowa funkcja] — także krótki akapit w resources/nowosci/tresc.md pod „## Najnowsze zmiany” (przed „## Alfa 0.78”). Drobne usprawnienie UI może być wpisem „Poprawione (…)”, bez [nowa funkcja].
- Przed push: Pint na zmienionych PHP (czytaj pole result), php -l, testy zmienionych obszarów + strażnicy (ChangelogBezZdublowanychWpisowTest, StraznikNowosciKazdaNowaFunkcjaMaAkapitTest, StraznikTekstuMaKontroleDodatniaTest, KazdaTrasaZIdentyfikatoremPodPolicyTest jeśli ruszasz trasy), `python3 -m unittest discover -s scripts -p 'test_*.py'`.
- Commit (trailery:
Co-Authored-By: Claude <TWÓJ MODEL, np. Sonnet 5.5 albo Opus 5.5> <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PkJKnfZqih8F4mpm5YPvP4
), `git push -u origin <gałąź>`, DRAFT PR (mcp__github__create_pull_request; jeśli brak — ToolSearch "select:mcp__github__create_pull_request") do base `codex/integracja-poprawki-20261002-c`, opis wg .github/pull_request_template.md, „Refs #<nr>” (nie Closes), na końcu linia:
🤖 Generated with [Claude Code](https://claude.com/claude-code)
- NIE scalaj. Raport po polsku, krótki: SHA, numer PR, co zmieniono, testy i kontrola ujemna, ryzyka/pytania.
- PUŁAPKI złapane przez CI w poprzednich zadaniach (sprawdź u siebie przed push):
  * Formularz z natywną walidacją (type="date|number|email", required, pattern, min/max/maxlength) MUSI mieć `novalidate` — pilnuje FormularzeZWalidacjaMajaNovalidateTest.
  * Teksty bez rodzaju gramatycznego wobec czytającego („zapisałeś/aś” zakazane) — TekstyNiePrzypisujaPlciTest. Uruchom go, gdy dodajesz napis.
  * Nowe atrybuty style="…" w widokach zmieniają liczbę w docs/legal/SECURITY_BASELINE.md — DokumentyPrawneNieKlamiaTest.
  * Larastan: bez `$this->fail()` po kodzie, który zawsze rzuca; bez assertTrue na zawsze-prawdziwych zmiennych. Jeśli masz vendor/bin/phpstan — uruchom `vendor/bin/phpstan analyse --no-progress --memory-limit=1G <zmienione pliki>`.
  * Uruchom zawsze: TekstyNiePrzypisujaPlciTest, FormularzeZWalidacjaMajaNovalidateTest, DokumentyPrawneNieKlamiaTest, StraznikTekstuMaKontroleDodatniaTest.
- UWAGA vendor: wspólny /workspace/kuking.pl/vendor ma starszy PHPStan (2.2.13) i Larastan (3.11.0) niż composer.lock (2.2.16 / 3.12.2) — CI może zgłosić błędy, których lokalnie nie widać. Najczęstszy: „assertSame(...) will always evaluate to true” (method.alreadyNarrowedType) przy POWTÓRZONEJ asercji na tym samym wyrażeniu (np. $this->metoda(), $model->fresh()->pole). Każde ponowne odczytanie przypisz do NOWEJ zmiennej przed asercją.
- Migracja tworząca tabelę z kluczem obcym do istniejącej tabeli: sprawdź testy, które wołają down() starszej migracji (grep "require base_path('database/migrations/" w tests) — muszą najpierw cofnąć zależne.
- ZAKAZ OBCHODZENIA ODMÓW: jeśli narzędzie lub klasyfikator odmówi polecenia, NIE próbuj go przeformułować, rozbić ciągów (np. sklejanie nazw w cudzysłowach), zakodować ani wykonać inną drogą. Zatrzymaj tę czynność i opisz odmowę w raporcie. Dotyczy to także poleceń tylko do odczytu.
