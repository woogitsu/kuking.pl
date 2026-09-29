# Audyt bezpieczeństwa (30 września 2026)

Stan: `origin/claude/paczka-i-kandydat` @ `5548c7e16` (BAZA, przyszły `main`).
Audyt tylko do odczytu: bez zmian w kodzie aplikacji, bez połączeń z produkcją.
Punkt odniesienia: `docs/audyt/2026-09-25-A5-bezpieczenstwo.md` (A5-xx)
i `docs/AUDYT_BEZPIECZENSTWA_2026-09-15.md` (A-xx … E-xx).

**Praca w toku — plik uzupełniany przyrostowo.**

Duplikaty sprawdzone na liście wszystkich 2233 issues i PR-ów pobranej przez
`GET /repos/woogitsu/kuking.pl/issues?state=all` (wyszukiwarka `search/issues`
jest z tej sesji zablokowana: „sessions are bound to their configured
repositories”), a także w `docs/audyt*` i `docs/audits/*`.

## Podsumowanie

(uzupełniane)

## Znaleziska

Odtworzenie: **T** — tymczasowy test PHPUnit na lokalnej bazie PostgreSQL
(`tests/Feature/AudytTymczasowyBezpieczenstwoTest.php`, usunięty po
uruchomieniu, treść kroków niżej), **R** — `php artisan route:list`,
**K** — czytanie kodu.

| ID | Waga | Tytuł | Dowód (plik:linia na BAZIE) | Odtworzenie | Wpływ | Proponowana poprawka i test regresyjny | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| S-01 | P2 | Kreator przepisu (Livewire) omija limit `post`: zapis i publikacja bez żadnego throttle | trasa `POST livewire-*/update` ma tylko `web` i `RequireLivewireHeaders` (R); `config/livewire.php:176` ogranicza wyłącznie `upload-file`; `resources/views/components/recipe-wizard.blade.php:488` (`saveDraft`), `:575` (`publish`), `:701` (`persist`) bez `RateLimiter`; dla porównania `routes/web.php:877-878` (`POST /dodaj/przepis` z `throttle:post`, `20,10` w `config/kuking.php:1596`) | **T**: 25 nowych szkiców z 25 komponentów `recipe-wizard` (`set form.title`, `call saveDraft`) — wszystkie zapisane, `Recipe::count() = 25`; ten sam użytkownik na `POST /dodaj/przepis` dostaje 429 przy 21. żądaniu. **R**: brak `ThrottleRequests` na `livewire-*/update` | Zalogowane konto skryptem zakłada setki przepisów na minutę i publikuje je do „Świeżo z Kuking”, tagów i wyszukiwarki; limit z formularza jest pozorny. Obciążenie bazy i kolejki moderacji. To A5-15 z 25.09 — nigdy nie trafiło do issue | `RateLimiter::attempt` z kluczem `post:{user}` i progiem `kuking.limits.post` w `persist()` przy tworzeniu NOWEGO przepisu i przy `publish` (autozapis istniejącego szkicu bez limitu albo z osobnym, luźnym progiem), komunikat po polsku w `StanZapisu`. Test: 21. nowy szkic z kreatora dostaje komunikat o limicie, a 20 wcześniejszych zostaje | S | brak (A5-15 bez issue) |
| S-02 | P2 | Mutujące trasy `/api/v1` nie mają `ability:` — token tylko do czytania publikuje | `routes/api.php:21-25` (komentarz obiecuje `ability:<zakres>` na każdej trasie mutującej), `routes/api.php:135-164` (sześć tras POST/DELETE bez `ability`); alias `bootstrap/app.php:350` jest, ale nikt go nie używa; `tests/Feature/Api/ZakresyTokenuTest.php:45` sprawdza tylko trasę sztuczną | **T**: `createToken('…', [ZakresyTokenu::TRESC_CZYTAJ])`, `POST /api/v1/wpisy` ze zdjęciem → **201**, wpis powstaje | Dziś każdy wydany token ma pełny słownik (`User::DOMYSLNE_UPRAWNIENIA_API`), więc skutek jest zerowy; pierwszy token o węższym zakresie (np. dla widżetu lub zewnętrznego czytnika) dostanie zapis. API domyślnie wyłączone (`BramaApi`) | `->middleware('ability:'.ZakresyTokenu::TRESC_PISZ)` na sześciu trasach i `PROFIL_CZYTAJ`/`TRESC_CZYTAJ` na odczytach; test przechodzący po `Route::getRoutes()` i wymagający `ability` na każdej trasie `api.*` poza `api.tokeny.*` | S | **#2232** (otwarte) |
| S-03 | P2 | Historia wersji pokazuje każdemu tekst, który autor później usunął — i nie ma narzędzia, żeby usunąć wersję | `app/Domain/Recipes/Historia/HistoriaWersji.php:60` (wszystkie wersje przepisu, bez filtra); `app/Http/Controllers/HistoriaPrzepisuController.php:49,113` (dostęp = `view` bieżącego przepisu); `app/Models/RecipeVersion.php:39-41` (`updating` rzuca wyjątek); `resources/views/pages/recipes/historia.blade.php:13-15` („Pojedynczej wersji nie da się usunąć samemu … napisać do nas”); brak kodu usuwającego `RecipeVersion` w `app/Http/Controllers/Admin`, `app/Domain/Moderation`, `app/Console` (grep) | **T**: autorka publikuje przepis z opisem „Od babci Jadwigi Nowak, tel. 600 100 200.”, poprawia opis na „Od babci.” i publikuje; gość: `GET /przepisy/{slug}` — numeru nie ma, `GET /przepisy/{slug}/historia/1` — **200 z numerem telefonu** | Dane osobowe (także osoby trzeciej: babcia, sąsiadka) zostają publicznie pod stałym adresem bez końca. Strona odsyła do „Napisz do nas”, ale obsługa nie ma czym tego zrobić (jedyna droga to usunięcie całego przepisu). RODO art. 17 dla osoby trzeciej, DSA przy treści zgłoszonej i poprawionej przez autora. Ryzyko zapisane przez koordynatora (`docs/flota/sesja-koordynatora-2909-b/REJESTR.md:158`), bez issue i bez decyzji | Decyzja właściciela (D-xxx), potem jedno z dwóch: (a) akcja moderatora „Ukryj wersję N” z wpisem w `audit_log` (kolumna `hidden_at` w `recipe_versions` przez migrację z rollbackiem, `HistoriaWersji::zapytanie` pomija ukryte), albo (b) historia widoczna tylko autorowi i moderatorowi. Test: po ukryciu wersji gość dostaje 404 na `/historia/N`, a autor dalej widzi pozostałe. Dotyka też #2229 (poufne parametry `source_url` leżą w migawkach) | M | brak (pokrewne #2229) |
| S-04 | P3 | Odwołanie gościa (`POST /odwolanie`) to wyrocznia hasła bez Turnstile i omija 2FA | `routes/web.php:667-669` (tylko `throttle:appeal` `5,60` po IP); `app/Http/Controllers/AppealController.php:148-155` („Nie rozpoznajemy tych danych”) wobec `:163-169` („Nie mamy decyzji…”) — inna odpowiedź dla dobrego hasła; brak `TurnstileJestPotwierdzony` (jest w `app/Http/Controllers/Auth/LoginController.php:77`); brak kroku drugiego składnika | **T** (klucze Turnstile ustawione): `POST /login` bez tokenu → odrzucony błędem `cf-turnstile-response`; `POST /odwolanie` z tym samym kontem bez tokenu: złe hasło → „Nie rozpoznajemy tych danych…”, dobre → „Nie mamy decyzji, od której można się teraz odwołać…” | Automat sprawdza hasła aktywnych kont bez Turnstile (łagodzą to wspólne koszyki `LimitProbHasla`, więc skala jest ograniczona). Dla konta z 2FA: potwierdzenie hasła bez kodu; dla zbanowanego z 2FA: złożenie odwołania w jego imieniu bez drugiego składnika. A-02 (część Turnstile) i B-04 z 15.09 — wciąż bez issue | `TurnstileJestPotwierdzony::reguly('odwolanie')` (nowe miejsce w `kuking.turnstile.miejsca`), jeden komunikat dla złego hasła i konta bez decyzji do odwołania, a przy 2FA — kod przed złożeniem. Test: dobre i złe hasło dają tę samą odpowiedź; bez tokenu Turnstile 422 | S | brak (A-02, B-04 bez issue) |

## Sprawdzone i w porządku

(uzupełniane)
