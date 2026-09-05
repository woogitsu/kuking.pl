# laravelio/laravel.io — notatka researchowa

**Licencja: MIT** (`LICENSE.md`, copyright Dries Vints). To jedyne repozytorium
z Fazy 1, z którego wolno nam **wziąć kod dosłownie** — pod warunkiem zachowania
tekstu licencji i noty o prawach autorskich w naszym pliku (najlepiej sekcja
„Third-party notices” w `LICENSE`). W praktyce i tak nie kopiujemy plików:
laravel.io jest forum (threads/replies), a nie serwisem zdjęciowym, więc
wartością jest **konwencja i styl testów**, nie funkcje.

Snapshot: `git clone --depth 1` z 2026-09-05, HEAD na `main`.

---

## 1. Co wynika z licencji

- MIT pozwala kopiować, modyfikować i używać komercyjnie w zamkniętym produkcie.
- Jedyny warunek: dołączenie treści licencji i noty copyright.
- Wniosek dla nas: jeśli kiedykolwiek przeniesiemy stąd plik 1:1 (np. trait
  `SendsAlerts`), dopisujemy notę. Dla samych *wzorców* (nazwy metod, układ
  testów) żadnych zobowiązań nie ma — układ katalogów nie jest utworem.

## 2. Użyteczny model danych

laravel.io ma dużo prostszy model niż nasz, ale trzy rzeczy są warte uwagi:

| Ich rozwiązanie | Kolumny / tabele | Wniosek dla Kuking |
|---|---|---|
| Ban użytkownika z powodem | `users.banned_at`, `users.banned_reason` (`app/Models/User.php:64,162-169`) | Mamy `users.status` z CHECK-iem, ale **nie mamy powodu bana ani znacznika czasu**. `users.status='banned'` nie odpowiada na pytanie „za co i kiedy”. Ślad jest w `moderation_actions`, ale to wymaga joina przy każdym ekranie konta |
| Blokady jako pivot + scope | `blocked_users (user_id, blocked_user_id)`, scope `whereDoesntHave` (`app/Models/User.php:298-300,467-474`) | U nas `blocks (blocker_id, blocked_id)` — identycznie. Różnica: oni mają **gotowe scope'y na modelu**, my liczymy listę ID w `app/Domain/Feed/DiscoverFeed.php:70-77`. Ich wersja nie da się „zapomnieć” w nowym zapytaniu |
| Zgłoszenia spamu jako pivot many-to-many | `spam_reporters` przez `MorphToMany` (`app/Contracts/Spam.php`) | Świadomie odrzucamy — nasze `reports` mają `reason`, `details`, `status` i historię decyzji, czyli spełniają art. 16 DSA. Ich model to „licznik kliknięć”, bez śladu decyzji |
| `type` jako smallint na role | `users.type` = 1/2/3 (`app/Filament/Resources/Users/Tables/UsersTable.php:50-56`) | **Nie przenosić.** Nasze `users.role` z CHECK-iem `('user','moderator','admin')` jest czytelne w `psql`, ich `type='2'` wymaga zaglądania do kodu, żeby wiedzieć, kto to jest |

Interesujący detal indeksowy: migracja `database/migrations/2025_09_12_073227_add_new_indexes.php`
**usuwa** indeksy jednokolumnowe na `replyable_id` i `replyable_type` i zastępuje
je jednym złożonym `(replyable_id, replyable_type)`. To dojrzała lekcja: dla
relacji polimorficznej indeks na samym typie jest bezwartościowy (kardynalność 3),
a Postgres nie skorzysta z dwóch osobnych indeksów tak dobrze jak z jednego złożonego.
U nas ten sam kształt mają `reports (target_type, target_id)` i
`moderation_actions (target_type, target_id, created_at DESC)` — i **już są złożone**
(`database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php:85-86`).
Ta rekomendacja jest u nas zrealizowana.

## 3. Przepływy UX warte adaptacji

1. **Ban z formularzem powodu i opcjonalnym sprzątaniem treści.**
   `app/Filament/Resources/Users/Tables/UsersTable.php:119-148`: akcja „ban”
   otwiera modal z wymaganym polem `reason` i **checkboxem „Delete all threads”**.
   Moderator w jednym kroku decyduje, czy ban obejmuje też treści. Nasz panel
   (`app/Http/Controllers/Admin/ModerationController.php`) rozdziela to na osobne
   akcje — a to znaczy, że przy banie spamera trzeba pamiętać o drugim kroku.
   Modal ma też `modalDescription` mówiący wprost, co ban powoduje
   („prevent him from logging in, posting threads and replying”). To dokładnie
   nasza reguła z `docs/UX_50_PLUS.md`: potwierdzenie opisuje skutek, nie pyta
   „Czy jesteś pewien?”.

2. **Odblokowanie z ustawień, nie tylko z profilu.**
   `routes/web.php` rejestruje `Settings\UnblockUserController` — lista
   zablokowanych osób żyje w ustawieniach. Bez tego użytkownik, który zablokował
   kogoś przez pomyłkę, musiałby znaleźć jego profil, a zablokowanego zwykle
   nie widzi. U nas istnieje
   `app/Http/Controllers/Settings/PrivacySettingsController.php` — czy zawiera
   listę blokad z możliwością zdjęcia, `[do weryfikacji]`.

3. **Alerty sesyjne jednym traitem.** `app/Concerns/SendsAlerts.php` — dwie
   metody `success()` / `error()` piszące do sesji. Efekt: żaden kontroler nie
   wymyśla własnego klucza flash. My mamy `->with('status', ...)` rozsiane po
   kontrolerach (np. `app/Http/Controllers/Auth/LoginController.php:85`).

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

Najcenniejsza rzecz w całym repozytorium — cztery rzeczy, których sami byśmy
nie wymyślili:

1. **Middleware `RedirectIfBanned`** (`app/Http/Middleware/RedirectIfBanned.php`)
   wylogowuje zbanowanego przy **każdym** żądaniu, nie tylko przy logowaniu.
   **To jest realna dziura u nas.** `app/Http/Controllers/Auth/LoginController.php:65`
   sprawdza `isActive()` tylko w momencie logowania, a `App\Models\User::ban()`
   (`app/Models/User.php:243`) zmienia status, nie unieważniając sesji.
   Skutek: zbanowany spamer z otwartą kartą działa dalej. Publikację posta
   i przepisu chroni `isActive()` w policies (`app/Policies/PostPolicy.php:51`,
   `app/Policies/RecipePolicy.php:50`), ale `CommentPolicy` i `CollectionPolicy`
   tego warunku nie mają, a edycja profilu i zgłoszenia nie przechodzą przez
   policy z `isActive()` wcale. → **propozycja issue** na końcu notatki.

2. **Nie można zgłosić ani zablokować moderatora.**
   `app/Policies/ThreadPolicy.php:39-49` (`reportSpam`) odrzuca zgłoszenie, gdy
   autorem jest moderator lub admin; `tests/Feature/BlockUsersTest.php:29-41`
   pilnuje tego samego dla blokad. Sens: bez tego grupa użytkowników zgłasza
   moderatora, żeby zapchać kolejkę albo podważyć decyzję. Blokada moderatora
   ma jeszcze drugi skutek — zablokowany moderator przestaje widzieć treść,
   którą ma oceniać.
   U nas `App\Domain\Moderation\Actions\ReportContent` nie ma takiej reguły
   i nie ma nawet warunku „nie zgłaszam własnej treści”.

3. **Nie można zgłosić dwa razy tego samego.** `ThreadPolicy::reportSpam`
   sprawdza `where('reporter_id', $user->id)->count()`.
   **U nas to już jest** i zrobione lepiej — `ReportContent` deduplikuje po
   `(target_type, target_id, reporter_id)` tylko w statusach otwartych
   (`app/Domain/Moderation/Actions/ReportContent.php:55-64`), więc po zamknięciu
   sprawy da się zgłosić nawrót. Ich wersja blokuje na zawsze.

4. **Reguły walidacji jako anty-spam.** `app/Rules/DoesNotContainUrlRule.php` —
   pole nie może zawierać URL-a (bio, profil). Jedna klasa, a zdejmuje
   najczęstszą formę spamu profilowego. `app/Rules/PasscheckRule.php` wymusza
   podanie aktualnego hasła przy zmianie wrażliwych danych konta.

5. **Wzmianki wyciągane regexem, ale zawsze przez `whereIn` na istniejących
   użytkownikach** (`app/Concerns/HasMentions.php:20-26`) — `@nieistniejacy`
   nie generuje niczego, więc nie da się zrobić kanału powiadomień z fikcyjnych
   nicków. Osobna reguła `InvalidMentionRule` odrzuca wzmianki nieistniejących
   kont **na etapie walidacji**, czyli użytkownik dowiaduje się o literówce od
   razu, a nie „powiadomienie poszło w pustkę”. Dla nas istotne, gdy dojdą
   wzmianki w komentarzach.

## 5. Wzorce testowe i jakościowe

To główny powód, dla którego to repozytorium jest w Fazie 1.

- **Trait `CreatesUsers`** (`tests/CreatesUsers.php`) z metodami
  `login()`, `loginAs()`, `loginAsModerator()`, `loginAsAdmin()`.
  Test moderacji ma wtedy jedną linijkę setupu:
  `$this->loginAsModerator();` (`tests/Feature/ModeratorTest.php:12`).
  Nasz `tests/TestCase.php` jest pod tym kątem ubogi — jeśli każdy test
  moderacji tworzy usera z `['role' => 'moderator']` ręcznie, to jest tyle
  miejsc do zmiany, ile testów, gdy w `docs/MODERATION.md` dojdzie
  `senior_moderator`.
- **Nazwy testów jako zdania**, Pest `test('...')`, każdy plik z
  `uses(TestCase::class); uses(RefreshDatabase::class);`. Testy są tak krótkie,
  że czyta się je jak specyfikację: „cannot block self”, „cannot block moderator”.
  Zgadza się z wymogiem z `AGENTS.md`, żeby test opisywał regułę produktu.
- **Testy negatywne przed pozytywnymi.** W `tests/Feature/BlockUsersTest.php`
  cztery pierwsze testy to „nie wolno”, dopiero potem „wolno”. Przy pracy
  agentów AI to bardzo dobra kolejność — agent czytający plik najpierw widzi
  granice, a nie happy path.
- **Stałe nazw uprawnień na policy**: `ThreadPolicy::UPDATE`, `::LOCK`,
  `::REPORT_SPAM`, używane potem jako `$user->can(UserPolicy::BAN, $user)`
  w panelu (`app/Filament/Resources/Users/Tables/UsersTable.php:147`).
  Literówka w stringu `'ban'` staje się błędem statycznym, nie cichym `false`
  — a cichy `false` w autoryzacji to najgorszy rodzaj błędu, bo wygląda jak
  poprawnie działające zabezpieczenie.
- Osobne katalogi `tests/Unit`, `tests/Integration`, `tests/Feature`, przy czym
  `Integration` testuje modele, joby, maile i **query objects**. My mamy tylko
  `tests/Feature` — dla `App\Domain\Search\SearchQuery` i akcji domenowych test
  bez HTTP byłby szybszy i czytelniejszy.

## 6. Wzorce wydajnościowe

- **`DB::whenQueryingForLongerThan()` w `AppServiceProvider`**
  (`app/Providers/AppServiceProvider.php:41,63-68`) → powiadomienie z treścią
  zapytania, czasem i URL-em (`app/Notifications/SlowQueryLogged.php`).
  Kosztuje 6 linijek i daje odpowiedź na pytanie „co jest wolne na produkcji”
  bez APM-a. U nas kanałem powinien być Sentry, nie Telegram, ale mechanizm
  jest do wzięcia 1:1.
- **Query objects** (`app/Queries/SearchArticles.php`, `SearchReplies.php`) —
  statyczna metoda `get(string $keyword, int $perPage)` zwracająca `Paginator`.
  Kontroler nie widzi Eloquenta. Dokładnie ten kształt ma nasz
  `app/Domain/Search/SearchQuery.php`. **Zrealizowane.**
- **Ostrzeżenie negatywne**: ich search to `where('body', 'like', "%$keyword%")`
  (`app/Queries/SearchReplies.php:16`) — pełny skan bez indeksu. Na forum
  z kilkudziesięcioma tysiącami wpisów przechodzi, ale to nie jest wzorzec.
  Nasze `pg_trgm` + FTS (`recipes_title_trgm_idx`,
  `recipe_ingredients_text_trgm_idx`) jest o klasę lepsze.
- **`PreparesSearch`** (`app/Concerns/PreparesSearch.php`) dzieli długi tekst na
  fragmenty ≤5000 znaków po akapitach, a jeśli akapit i tak jest za długi — po
  liniach. Nam niepotrzebne (`tsvector` nie ma takiego limitu), ale gdybyśmy
  kiedykolwiek dostawiali embeddingi, to gotowy algorytm chunkowania.
- Zliczanie odsłon nie w żądaniu, a w komendzie
  `app/Console/Commands/UpdateArticleViewCounts.php` — licznik nigdy nie
  blokuje renderu strony i nie generuje zapisu przy każdym GET.

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| `users.type` jako liczba (1/2/3) | Nasze `role` ze CHECK-iem jest czytelne w bazie; ich wymaga kodu do interpretacji |
| Model spamu jako `MorphToMany` reporterów | Nie daje powodu, opisu ani śladu decyzji — nie spełnia art. 16/17 DSA, który nas dotyczy |
| Search przez `LIKE '%...%'` | Pełny skan; mamy `pg_trgm` i FTS |
| Logowanie przez GitHub (`app/Social/`, `app/Actions/ConnectGitHubAccount.php`) | Nasza grupa 50+ w większości nie ma konta na GitHubie; dodatkowy przycisk to dodatkowa decyzja na ekranie logowania |
| `->spa()` i `topNavigation()` w panelu Filament | Detale ich panelu; nasza decyzja o Filamencie jest jeszcze otwarta (issue #20) |
| Podział `Threads` / `Replies` / `Articles` | Forum ≠ Kuking; nasze `posts` + `recipes` + `cooked_events` to inny model treści |
| `isModerator()` / `isAdmin()` jako całość systemu uprawnień | Przy rolach z `docs/MODERATION.md` (`user`/`moderator`/`senior_moderator`/`admin`) i uprawnieniach per akcja to się nie skaluje — tu wchodzi osobna decyzja o Spatie Permission |
| Powiadomienia na Telegram | Kanał operacyjny bez śladu w bazie; u nas Sentry + `audit_log` |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | Middleware `EnsureAccountIsUsable`: przy każdym żądaniu wylogować konto `banned`, zablokować akcje pisania dla `suspended` | `bootstrap/app.php:22-25` (grupa `web`), nowy plik w `app/Http/Middleware/`; wzór: `RedirectIfBanned` | **P0 — realna dziura**; `User::ban()` (`app/Models/User.php:243`) nie unieważnia sesji |
| R2 | `ReportContent`: odrzucać zgłoszenie własnej treści oraz treści moderatora/admina | `app/Domain/Moderation/Actions/ReportContent.php` (obok deduplikacji z linii 55-64) | P1 |
| R3 | `users.banned_at` + `users.banned_reason_code`, żeby ekran konta nie wymagał joina po `moderation_actions` | `database/migrations/0001_01_01_000001_create_users_table.php`, `docs/DATABASE.md` sekcja `users` | P2 — najpierw sprawdzić, czy zapytanie po `moderation_actions_target_idx` nie wystarcza |
| R4 | Stałe nazw akcji na policies (`RecipePolicy::COOK = 'cook'`) używane w kontrolerach i panelu | `app/Policies/*.php`, `app/Http/Controllers/Admin/ModerationController.php` | P2 |
| R5 | Helpery testowe `loginAsModerator()` / `loginAsAdmin()` / `createUser()` | `tests/TestCase.php`, `tests/Feature/ModerationTest.php` | P2 — spłaca się przy każdym nowym teście moderacji |
| R6 | Reguła walidacji „bio, nazwa i `speciality` nie zawierają URL-a” | `app/Http/Controllers/Settings/ProfileSettingsController.php`, nowa klasa w `app/Rules/`, kolumny `profiles.bio` i `profiles.speciality` | P1 przed publiczną betą — najtańsza obrona przed spamem profilowym |
| R7 | Scope `withoutBlocked($viewer)` na `Post`/`Recipe`/`Comment` zamiast liczenia listy ID w każdej klasie feedu | `app/Models/Post.php`, `app/Domain/Feed/DiscoverFeed.php:70-77` | P1 — dziś nowe zapytanie może „zapomnieć” o blokadach |
| R8 | `DB::whenQueryingForLongerThan(300 ms)` → Sentry | `app/Providers/AppServiceProvider.php` | P2 |
| R9 | Akcja „Zbanuj” w panelu z wymaganym powodem i checkboxem „ukryj też wszystkie treści tej osoby” w jednym kroku | `app/Http/Controllers/Admin/ModerationController.php`, `docs/MODERATION.md` | P1 |
| R10 | Katalog `tests/Integration` na testy akcji domenowych bez HTTP (`SearchQuery`, `PublishRecipe`, `BlockUser`) | `tests/`, `phpunit.xml` | P2 |

### Propozycja issue (nie naprawiam, zgodnie z zakresem tego zadania)

**„Zbanowane konto działa do końca sesji”.** `App\Models\User::ban()` zmienia
`status`, ale nie istnieje middleware ani inwalidacja sesji; `isActive()` jest
sprawdzane tylko w `app/Http/Controllers/Auth/LoginController.php:65` oraz
w części policies (`PostPolicy:51`, `RecipePolicy:50`, `UserPolicy:23-24`) —
`CommentPolicy` i `CollectionPolicy` tego warunku nie mają.
Kryteria akceptacji: (1) test, w którym zalogowany użytkownik zostaje zbanowany
w trakcie sesji i następne żądanie kończy się wylogowaniem; (2) test, że konto
`suspended` nie może opublikować komentarza ani zapisać przepisu do zeszytu.
