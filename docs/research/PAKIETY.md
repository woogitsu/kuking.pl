# Decyzje o trzech pakietach — issue #21

Wynik issue #21. Odpowiada też na warunek weryfikacyjny issue #19 wobec
`docs/INSPIRATION_DECISIONS.md` (sekcja 4 tego pliku).

## Nota wstępna

**Co czytałem:** `AGENTS.md` w całości, `docs/DECISIONS.md` (D-001…D-016),
`docs/ROADMAP.md`, `docs/INSPIRATION_DECISIONS.md` (231 linii, w całości),
`docs/decyzje/REPO_PUBLICZNE.md`, `docs/ARCHITECTURE.md`, `composer.json`,
`composer.lock`, treść issues #21 i #19 z GitHuba (`mcp__github__issue_read`),
oraz w kodzie: `app/Models/User.php`, `app/Models/AuditLogEntry.php`,
`app/Models/Media.php`, `app/Policies/*.php`, `app/Http/Middleware/*.php`,
`bootstrap/app.php`, `config/kuking.php`, `app/Jobs/ProcessUploadedImage.php`,
`app/Domain/Search/SearchQuery.php`, migracje `users`, `collections`,
`posts`/`post_media`, `trust_and_safety` (`reports`/`moderation_actions`/`audit_log`),
indeks case-insensitive na `username`, oraz `git log` (historia commitów).

**Co uruchomiłem:** `grep`/`find` po całym `app/` i `database/migrations/`,
`php artisan --version` (Laravel 13.30.1 potwierdzone lokalnie), przeszukanie
webu (`WebSearch`/`WebFetch`) dla zgodności wersji trzech pakietów z Laravel 13
oraz dla struktury tabel i cache'a `spatie/laravel-permission`, tabeli
`features` Pennanta i tabeli `activity_log` — z podanymi źródłami przy
każdej pozycji.

**Czego nie uruchomiłem:** `composer require --dry-run` dla żadnego z trzech
pakietów (zakaz w poleceniu — nie instaluję niczego), `php artisan test`,
`vendor/bin/pint` — nie zmieniłem ani jednego pliku w `app/`, `database/`,
`config/`, `tests/`, więc nie było czego testować. Nie mierzyłem realnego
czasu odpowiedzi cache'u uprawnień ani rzeczywistego zużycia miejsca w bazie —
poniższe liczby tabel/kolumn są policzone ze źródeł pakietów, nie zmierzone
na tym projekcie.

---

## Tabela werdyktów

| Pakiet | Co jest dziś | Werdykt | Koszt wdrożenia | Co tracimy przy tym werdykcie |
|---|---|---|---|---|
| `spatie/laravel-permission` | `users.role` (`user`\|`moderator`\|`admin`) z `CHECK`, `isModerator()`/`isAdmin()`, `promoteTo()`, 6 Policy klas | **PÓŹNIEJ** — próg: trzeci moderator albo pierwszy przypadek rozdzielenia uprawnień w ramach jednej roli | **S** dziś (utrzymanie 3 stałych), **M** gdyby wejść teraz (5 nowych tabel, druga ścieżka autoryzacji do pilnowania) | Nic teraz — próg jest jawny i tani do sprawdzenia (`role IN (...)` w jednym miejscu) |
| `laravel/pennant` | Brak jakiegokolwiek mechanizmu flag; „zamknięta alfa” to `KUKING_REGISTRATION_OPEN` (bool, globalny) | **PÓŹNIEJ** — próg: pierwsza funkcja z `ROADMAP.md` V1 (groups/forks/planner) wypuszczana stopniowo, albo potrzeba pokazania czegoś tylko adminowi przed pełnym wydaniem | **S** — 1 tabela (`features`), sterownik `database` domyślny, zero Redisa | Nic teraz — dzisiejsze „duże” wydania (3-krokowy kreator przepisu) już są na produkcji bez flagi i działają |
| `spatie/laravel-activitylog` | Własny `AuditLogEntry` + tabela `audit_log`: akcja, aktor, podmiot, **hash** IP, metadane — nigdy treść ani hasło | **NIE** dla ogólnego audytu. **NIE** też dla historii zmian modeli — tę potrzebę już zaspokaja dedykowany `recipe_versions`/`RecipeVersion` | S/M w zależności od konfiguracji `logOnly()` na każdym modelu | Nic — pakiet dubluje istniejący, celowo węższy mechanizm i dodaje ryzyko, którego dziś nie ma |

**Czwarty pakiet?** Szukałem go świadomie (patrz §5) i nie znalazłem
kandydata, który przechodzi próg z `AGENTS.md` §3 — patrz sekcja na końcu.

---

## a) `spatie/laravel-permission`

### 1. Co ten projekt robi dziś zamiast tego

Kolumna `role` na `users`, string, trzy wartości, `CHECK` w bazie:

```php
// database/migrations/0001_01_01_000001_create_users_table.php:35
$table->string('role', 20)->default('user');
```

```php
// database/migrations/0001_01_01_000001_create_users_table.php:58
DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user','moderator','admin'))");
```

**Uwaga do treści zadania: to są TRZY wartości, nie dwie** (`user`,
`moderator`, `admin`) — wskazówka w poleceniu tego audytu mówiła o dwóch.
Sprawdzone wprost w migracji i w `User.php`:

```php
// app/Models/User.php:45-49
public const ROLE_USER = 'user';
public const ROLE_MODERATOR = 'moderator';
public const ROLE_ADMIN = 'admin';
```

Sprawdzanie roli ma dwa punkty wejścia, oba w modelu:

```php
// app/Models/User.php:295-303
public function isModerator(): bool
{
    return in_array($this->role, [self::ROLE_MODERATOR, self::ROLE_ADMIN], true);
}

public function isAdmin(): bool
{
    return $this->role === self::ROLE_ADMIN;
}
```

Zmiana roli jest jawną, nazwaną metodą (D-006), rola jest poza `$fillable`:

```php
// app/Models/User.php:579-586
public function promoteTo(string $role): void
{
    if (! in_array($role, [self::ROLE_USER, self::ROLE_MODERATOR, self::ROLE_ADMIN], true)) {
        throw new \InvalidArgumentException("Nieznana rola: {$role}");
    }
    $this->forceFill(['role' => $role])->save();
}
```

Autoryzacja idzie wyłącznie przez Policy, które wołają `isModerator()`:

```php
// app/Policies/UserPolicy.php:28-31
public function moderate(User $viewer): bool
{
    return $viewer->isModerator();
}
```

```php
// app/Policies/RecipePolicy.php:16
return $user !== null && ($user->getKey() === $recipe->author_id || $user->isModerator());
```

Dostęp do panelu moderacji idzie przez middleware, nie przez Gate:

```php
// app/Http/Middleware/EnsureUserIsModerator.php
public function handle(Request $request, Closure $next): Response
{
    abort_unless($request->user()?->isModerator() === true, 404);
    return $next($request);
}
```

zarejestrowany jako alias w `bootstrap/app.php:57-58`:

```php
$middleware->alias([
    'moderator' => EnsureUserIsModerator::class,
]);
```

`Gate::` **nie występuje ani razu** w `app/`, `bootstrap/`, `config/`
(sprawdzone `grep -rn "Gate::"` — zero trafień). Autoryzacja Laravela idzie
w 100% przez auto-discovery Policy (`app/Policies/*`), bez `AuthServiceProvider`
i bez ręcznej rejestracji.

**Ustalenie poboczne, warte odnotowania:** `isAdmin()` i `ROLE_ADMIN` są
zdefiniowane, ale `isAdmin()` **nie jest użyty nigdzie poza `User.php`** —
żadna Policy, middleware ani kontroler go nie woła (`grep -rn "isAdmin()"
app/` poza `User.php` daje zero wyników). Rola `admin` istnieje w bazie
i w kodzie, ale dziś nie różni się w działaniu od `moderator` — to trzecia
wartość istnieje na zapas, nie z realnej potrzeby. Nie jest to błąd do
zgłoszenia (nic nie psuje), ale wzmacnia argument „nie dokładać kolejnej
warstwy uprawnień, skoro nawet dwupoziomowa dzisiejsza nie jest w pełni
wykorzystana”.

### 2. Co pakiet by zastąpił

**Zniknęłoby:** nic — kolumna `role` i jej `CHECK` najpewniej zostają obok
(albo trzeba by migrować dane do tabel pakietu i usunąć kolumnę — koszt
migracji poniżej).

**Przyszłoby:** pakiet w wersji `^8.3` (aktualna na wrzesień 2026, wymaga
`illuminate/*: ^12.0|^13.0` — zgodność z Laravel 13 potwierdzona[^1])
tworzy migracją **5 nowych tabel**: `permissions`, `roles`,
`model_has_permissions`, `model_has_roles`, `role_has_permissions`[^2].
Do tego trait `HasRoles` na `User`, `Role`/`Permission` Eloquent models,
middleware `role`/`permission`/`role_or_permission`.

### 3. Czy to warte zależności

- **Liczba tabel:** 5 nowych vs. 0 dziś (mamy kolumnę + `CHECK`).
- **Złożoność:** dziś jedna funkcja (`isModerator()`) odpowiada na jedyne
  pytanie, jakie kod zadaje. Pakiet wprowadza model wiele-do-wielu
  (użytkownik ↔ rola ↔ uprawnienie), który ma sens przy **kombinacjach**
  uprawnień — a dziś nie ma ani jednej takiej kombinacji w kodzie: jest
  tylko `isModerator()` i nieużywany `isAdmin()`.
- **Cache uprawnień:** sprawdzone we `config/permission.php` pakietu —
  `'store' => 'default'` (czyli cokolwiek jest w `CACHE_STORE`), TTL
  `24 hours`, klucz `spatie.permission.cache`, unieważniany automatycznie
  przy zmianie ról/uprawnień[^3]. Projekt ma `CACHE_STORE=database`
  (`.env.example:50`) — **cache działa poprawnie na sterowniku `database`,
  nie wymaga Redisa**. AGENTS.md §3 nie jest tu naruszony. To jedna z
  niewielu rzeczy w tym audycie, którą można jednoznacznie odhaczyć jako
  „nie problem”.
- **Koszt migracji istniejących danych:** dziś trywialny — trzy stałe do
  przepisania na wiersze w `roles`. Rośnie tylko jeśli w międzyczasie ktoś
  zacznie ręcznie nadawać uprawnienia przez `Gate::before` albo przez
  kolejne kolumny `is_x` na `users` — nie widzę śladu takiej praktyki.
- **Koszt utrzymania przy aktualizacji Laravela:** pakiet Spatie goni
  wersje frameworka szybko (potwierdzone: `^8.3.0` z lipca 2026 wspiera już
  Laravel 13[^1]), więc to nie jest realne ryzyko na horyzoncie roku.
- **Druga ścieżka autoryzacji obok Policy — to jest realne ryzyko.** Dziś
  **każde** wejście na cudzą treść przechodzi przez jedną z 6 Policy, które
  wszystkie wołają `isModerator()`/`isAdmin()` z modelu. Gdyby ktoś zaczął
  wołać `$user->can('content.hide')` z pakietu **obok** Policy (a nie *z
  wnętrza* Policy), powstałyby dwa niezależne miejsca egzekwowania —
  dokładnie sytuacja, przed którą ostrzega `AGENTS.md` §7 („UUID w adresie
  nie jest autoryzacją” traci sens, gdy autoryzacja ma dwa wejścia zamiast
  jednego). Pakiet **da się** użyć bezpiecznie — trzymając wywołania
  `hasPermissionTo()` wyłącznie wewnątrz Policy, tak jak dziś `isModerator()`
  — ale to jest dyscyplina do utrzymania, nie właściwość pakietu.

**Scenariusz ludzkim językiem:** Ania jest dziś jedynym moderatorem. Za pół
roku dochodzi drugi — Marek, ale tylko do ukrywania spamu, bez prawa banowania
kont (bo Marek jest nowy w zespole). Dziś to wymaga: nowej wartości w `CHECK`
(`senior_moderator`), zmiany `isModerator()`/nowej metody `canHideContent()`
i przejrzenia 6 Policy. To jest dokładnie próg z issue #21 — i dokładnie
moment, w którym `spatie/laravel-permission` przestaje być „na zapas”.

### 4. Werdykt

**PÓŹNIEJ.** Próg powrotu: **trzeci moderator, albo pierwszy przypadek, w
którym potrzeba rozdzielić uprawnienia w ramach jednej roli** (np. „może
ukrywać treść, ale nie może banować”). Dziś jest 1-2 moderatorów (D-012) i
zero takich przypadków w kodzie. **Co tracimy:** nic — próg jest tani do
sprawdzenia (jedno spojrzenie na listę moderatorów) i nie wymaga przygotowań
z wyprzedzeniem. Rekomendacja migracji przejściowej z issue #21 („przygotować
migrację przejściową, żeby to nie było później niespodzianką”) — **odradzam
pisanie jej teraz**: to jest dokładnie kod do wyrzucenia, gdyby próg nie
nadszedł w ciągu kilku miesięcy, a `AGENTS.md` §3 wprost tego zabrania bez
zmierzonej potrzeby.

---

## b) `laravel/pennant`

### 1. Co ten projekt robi dziś zamiast tego

**Nic — potwierdzone brakiem.** `grep -rniE "feature.?flag|pennant|toggle\("
app/ config/ database/ routes/` nie znalazł ani jednego mechanizmu flag
funkcji poza literalnym komentarzem w opublikowanym pliku frameworka
(`config/livewire.php:167`, dotyczy niepowiązanej opcji Livewire).

„Zamknięta alfa” (D-012, `ROADMAP.md` „Closed alpha gate”) jest dziś
realizowana jednym globalnym boolem:

```php
// config/kuking.php:96
'registration_open' => (bool) env('KUKING_REGISTRATION_OPEN', true),
```

```php
// app/Http/Controllers/Auth/RegisterController.php:41
abort_unless(config('kuking.account.registration_open'), 503, 'Rejestracja jest chwilowo zamknięta.');
```

To jest przełącznik **całego serwisu dla nowych kont**, nie flaga
**pojedynczej funkcji** dla wybranych kont — różne zastosowania, żadne z
nich dziś nie potrzebuje Pennanta.

Konkretny przykład podany w issue #21 — 3-krokowy kreator przepisu (#1) jako
kandydat do wypuszczenia za flagą — **jest już na produkcji bez żadnej
flagi**, i to w dwóch wariantach naraz:

```php
// routes/web.php:195-196
Route::get('/dodaj/przepis', [RecipeController::class, 'create'])->name('recipes.create');
Route::get('/dodaj/przepis/jedna-strona', [RecipeController::class, 'createSimple'])->name('recipes.create.simple');
```

Czyli argument „mamy już konkretne zastosowanie” z issue #21 **nie
potwierdza się w kodzie** — funkcja wyszła bez flagi i obie ścieżki
współistnieją jako osobne trasy, nie jako warianty za jednym przełącznikiem.

### 2. Co pakiet by zastąpił

Nic nie zastępuje — dziś nie ma odpowiednika. **Przyszłoby:** 1 nowa tabela,
`features` (sterownik `database`, **domyślny** sterownik pakietu — nie
`array`, nie Redis)[^4], trait `Laravel\Pennant\Concerns\HasFeatures` na
`User`, fasada `Feature::define()`/`Feature::active()`.

### 3. Czy to warte zależności

- **Liczba tabel:** 1 (`features`) — najtańszy z trzech pakietów pod tym
  względem.
- **Sterownik:** `database` domyślny, zero Redisa — zgodne z `AGENTS.md` §3.
- **Koszt utrzymania:** pakiet pierwszej strony (`laravel/*`), wsparcie dla
  Laravel 13 od wersji `1.21.0`[^5] — ryzyko rozjazdu wersji minimalne.
- **Druga ścieżka autoryzacji?** Nie — to nie jest mechanizm autoryzacji,
  tylko włącznik/wyłącznik ścieżki kodu. Nie koliduje z Policy.
- **Realny problem, który miałby rozwiązać:** `ROADMAP.md` wprost odsuwa
  duże funkcje w czasie decyzją produktową, nie flagą technicznę:

  ```
  ## V1 gate
  Planner/groups/forks dopiero gdy WAC i D30 pokazują powroty.
  ```

  To już JEST gating — tylko na poziomie „nie zaczynamy pisać kodu”, a nie
  „kod jest napisany i ukryty za flagą”. Flaga zaczyna mieć sens dopiero
  **w trakcie** pisania jednej z tych funkcji, gdy trzeba pokazać ją
  administratorowi na produkcji przed pełnym wydaniem — dokładnie sytuacja,
  której dziś w repo nie ma, bo żadna z funkcji V1 nie jest jeszcze w
  budowie.

**Scenariusz ludzkim językiem:** Za pół roku ktoś zaczyna budować `groups`
(grupy tematyczne). Chce wypuścić szkielet na produkcję wcześnie, żeby go
testować na prawdziwej bazie, ale pokazać go tylko sobie, nie 20 osobom
alfy. Dziś jedyna droga to osobna gałąź niescalona do `main` — co przy
`D-011` („praca idzie w kodzie”, deploy odłożony) akurat nie boli, bo i tak
nikt nie wdraża. Flaga zaczyna oszczędzać czas dopiero, gdy `main` znowu
zaczyna być wdrażane na produkcję.

### 4. Werdykt

**PÓŹNIEJ**, próg: **pierwsza funkcja z `ROADMAP.md` V1 (groups/forks/
planner/ai_import) trafia w fazę aktywnego pisania kodu na scalonym `main`
po ponownym uruchomieniu deployu (koniec D-011)**, albo pojawia się
konkretna potrzeba pokazania niedokończonej funkcji tylko administratorowi.
**Co tracimy:** nic wymiernego dziś — koszt wdrożenia later jest niski (1
tabela, brak konfliktu z resztą stacku), a wdrożenie teraz byłoby dokładnie
„przyda się później” zakazanym przez `AGENTS.md` §3, bo nie ma dziś ANI
JEDNEJ funkcji czekającej na flagę.

---

## c) `spatie/laravel-activitylog`

### 1. Co ten projekt robi dziś zamiast tego

Własny, celowo minimalistyczny model:

```php
// app/Models/AuditLogEntry.php:11-38
/**
 * Wpis w dzienniku audytu.
 *
 * Zasady: logujemy FAKT i AKTORA, nigdy treści ani tokenów. IP wyłącznie jako
 * hash — do wykrywania nadużyć wystarcza, a nie tworzy zbędnego zbioru danych
 * osobowych (docs/SECURITY_PRIVACY_LEGAL.md).
 */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'action', 'subject_type', 'subject_id', 'ip_hash', 'metadata',
    ];

    public static function record(
        string $action, ?User $actor = null, ?Model $subject = null,
        array $metadata = [], ?string $ip = null,
    ): self {
        return self::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'ip_hash' => $ip === null ? null : hash('sha256', $ip.config('app.key')),
            'metadata' => $metadata,
        ]);
    }
}
```

Tabela (append-only, bez `updated_at`):

```php
// database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php:64-77
Schema::create('audit_log', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('action', 100);
    $table->string('subject_type', 80)->nullable();
    $table->uuid('subject_id')->nullable();
    $table->string('ip_hash', 128)->nullable();
    $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
    $table->timestampTz('created_at')->useCurrent();
});
```

Wołane z **16 miejsc** w `app/Domain/*/Actions` i `app/Http/Controllers/*`
(m.in. `ResolveAppeal`, `RestoreContent`, `FileAppeal`, `ReportContent`,
`PublishRecipe`, `RecordCookedEvent`, `PublishPost`, `BlockUser`,
`UnblockUser`, `RegisterController`, `AccountDeletionController`,
`DataSettingsController`, dwie komendy konsolowe) — czyli to jest już
przyjęty, stosowany wzorzec, nie martwy kod.

Osobno istnieje **dedykowany** mechanizm historii zmian dla przepisów —
`recipe_versions` / `RecipeVersion` (`app/Models/RecipeVersion.php`,
tabela w `database/migrations/2026_09_05_000400_create_recipes_tables.php`)
— czyli jedyny realny kandydat na „historię zmian pojedynczego modelu”,
o którym mówi issue #21 jako możliwe zastosowanie `LATER`, **jest już
zbudowany osobno i celowo**, nie generycznym mechanizmem.

### 2. Co pakiet by zastąpił

Nic nie musiałby zastępować — mógłby zostać obok. **Przyszłoby:** 1 tabela
`activity_log` (kolumny: `subject_type`/`subject_id`, `causer_type`/
`causer_id`, `description`, `properties` (JSON), `event`, opcjonalnie
`batch_uuid`)[^6], trait `LogsActivity` na modelach, fasada `activity()`.

### 3. Czy to warte zależności

- **Liczba tabel:** 1, taniej niż permission, tyle samo co Pennant.
- **Złożoność:** pakiet robi dokładnie to samo pytanie „kto, co, kiedy”, ale
  **domyślnie loguje wartości pól** (`logAll()`+`logOnlyDirty()` to typowy
  setup), a nasz `AuditLogEntry::record()` **nigdy nie przyjmuje wartości
  pól** jako pierwszej klasy argumentu — trzeba by je świadomie dopisać do
  `metadata`. To jest różnica dyscypliny, nie funkcji.
- **Weryfikacja z issue #21** („czy da się skonfigurować, żeby nigdy nie
  zapisywał wartości pól”): **tak, da się** — `logOnly(['pole1', 'pole2'])`
  albo `logAll()->logExcept(['password', 'body', 'email'])` pozwalają
  zawęzić, co trafia do `properties`[^7]. **Ale** to jest lista do
  utrzymania **per model**, ręcznie — a to jest dokładnie ten sam rodzaj
  ryzyka, przed którym broni się `AGENTS.md` §7 wybierając jawne nazwane
  metody (`suspend()`, `ban()`) zamiast ogólnego `$fillable`: nowe pole
  dodane do modelu (np. `Post::$body`) domyślnie **wejdzie** do loga, jeśli
  ktoś zapomni dopisać je do `logExcept()`. Nasz obecny `AuditLogEntry`
  odwraca to ryzyko — trzeba **świadomie** dopisać coś do `metadata`, więc
  domyślne zachowanie jest bezpieczne, a nie odwrotnie.
- **Cache/koszt danych:** nieistotny — to nie jest mechanizm z cache'em.
- **Druga ścieżka autoryzacji obok Policy?** Nie dotyczy — to log, nie
  autoryzacja.
- **Koszt utrzymania przy aktualizacji Laravela:** pakiet wspiera Laravel 13
  od wersji `4.12.0`/`5.1.0`[^8] — brak ryzyka na horyzoncie.

**Scenariusz ludzkim językiem:** Ktoś zgłasza „skąd moderator zna treść mojej
usuniętej wiadomości prywatnej?”. Z dzisiejszym `AuditLogEntry` odpowiedź
jest łatwa: log nigdy nie miał tej treści, bo `record()` przyjmuje `action`
i `subject`, nie zawartość modelu. Z `spatie/laravel-activitylog` skonfigurowanym
przez `logAll()` (najczęstszy setup w tutorialach, w tym w wynikach
wyszukiwania powyżej) odpowiedź brzmiałaby „bo ktoś zapomniał dopisać `body`
do `logExcept()` przy dodawaniu kolumny” — dokładnie ten scenariusz, którego
D-006 miało nie dopuścić.

### 4. Werdykt

**NIE** — dla ogólnego audytu **i** dla historii zmian modeli. Istniejący
`AuditLogEntry` jest już wdrożony w 16 miejscach, ma bezpieczniejszy
domyślny kierunek (trzeba świadomie coś dopisać, nie świadomie coś wykluczyć),
a jedyny realny przypadek użycia „historia zmian jednego rekordu” (przepisy)
ma już dedykowane, celowo zaprojektowane rozwiązanie (`recipe_versions`).
**Co tracimy:** nic — pakiet nie robi niczego, czego nie robimy, a robi to
z gorszym domyślnym profilem bezpieczeństwa dla naszego przypadku (treści
i dane osobowe użytkowników 50+).

---

## Czy istnieje czwarty pakiet warty rozważenia?

Szukałem świadomo, z tą samą surowością. Kandydaci, których **odrzucam**
z uzasadnieniem:

- **Rate limiting jako pakiet** — nie ma potrzeby, `config('kuking.rate_limits')`
  i wbudowany throttle middleware Laravela już to robią (widoczne w
  komentarzu `bootstrap/app.php` o `trustProxies` i `$request->ip()`).
- **Panel administracyjny (Filament)** — już rozstrzygnięte w
  `docs/INSPIRATION_DECISIONS.md` poz. 6.1 jako `LATER — blokada techniczna`
  (Filament 4.x wymaga `livewire/livewire: ^3.7`, projekt ma `^4.0`). Nie
  powtarzam tej analizy tutaj.
- **Cokolwiek do wyszukiwania, kolejek, mediów** — już rozstrzygnięte D-003/
  D-004 i potwierdzone przez `docs/INSPIRATION_DECISIONS.md` §7.

Nie znalazłem pakietu, który rozwiązuje **nazwany, dziś istniejący**
problem, a którego dziś nie ma w `composer.json`. To jest zgodne z ogólnym
obrazem: repozytorium ma za sobą już bardzo dużo iteracji audytowych (ponad
30 numerowanych „audytów” widocznych w `git log`, np. „audyt A25”,
„audyt A31”, „audyt C1” w komentarzach kodu) — większość oczywistych dziur
jest już zamknięta.

---

## Weryfikacja `docs/INSPIRATION_DECISIONS.md` wobec issue #19

**Zakres issue #19:** notatka na repozytorium (7 sztuk) odpowiadająca na 7
pytań + `docs/INSPIRATION_DECISIONS.md` z każdą decyzją oznaczoną `ADOPT`/
`ADAPT`/`REJECT`/`LATER`, jednozdaniowym uzasadnieniem, i odesłaniem do
notatki źródłowej.

**Sprawdzone punkt po punkcie:**

| Kryterium z polecenia audytu | Wynik |
|---|---|
| (a) każda pozycja ma znacznik ADOPT/ADAPT/REJECT/LATER | **Tak** — sprawdziłem wszystkie 4 tabele główne (§1–§4: pozycje 1.1–1.12, 2.1–2.28, 3.1–3.17, 4.1–4.15) plus §5 (5.1–5.16) i §6 (6.1–6.7); każdy wiersz ma znacznik w kolumnie 3 |
| (b) każda ma jedno zdanie uzasadnienia | **Tak** — kolumna „Uzasadnienie” wypełniona w każdym wierszu, faktycznie zwięzła (jedno zdanie, czasem z dwukropkiem rozwijającym) |
| (c) nie brakuje żadnego z siedmiu źródeł | **Tak** — `docs/research/repos/` zawiera dokładnie 7 plików: `TandoorRecipes-recipes.md`, `discourse-discourse.md`, `filamentphp-filament.md`, `fresns-fresns.md`, `laravelio-laravel.io.md`, `mealie-recipes-mealie.md`, `pixelfed-pixelfed.md`, `reaper47-recipya.md` — **czekaj, to jest 8 plików, nie 7** (patrz niżej) |
| (d) pozycje odsyłają do notatek | **Tak** — kolumna „Notatka” w każdym wierszu wskazuje plik i paragraf (np. `pixelfed-pixelfed.md §4.1, §8 R1`) |

**Poprawka do punktu (c):** policzyłem pliki w `docs/research/repos/` —
jest ich **osiem**: `TandoorRecipes-recipes.md`, `discourse-discourse.md`,
`filamentphp-filament.md`, `fresns-fresns.md`, `laravelio-laravel.io.md`,
`mealie-recipes-mealie.md`, `pixelfed-pixelfed.md`, `reaper47-recipya.md`.
To zgadza się z **siedmioma repozytoriami z Fazy 1-3** wymienionymi w treści
issue #19 (`laravelio/laravel.io`, `pixelfed/pixelfed`, `fresns/fresns`,
`TandoorRecipes/recipes`, `mealie-recipes/mealie`, `reaper47/recipya`,
`discourse/discourse`, `filamentphp/filament` — **to jest już osiem
nazw**, licząc poprawnie). Innymi słowy: polecenie tego audytu mówiło
„siedem notatek”, ale zarówno issue #19, jak i `INSPIRATION_DECISIONS.md`
§ Źródła, jak i katalog na dysku, zgodnie liczą **osiem** — Faza 3 ma dwa
repozytoria (`discourse` i `filamentphp`), nie jedno. To nie jest brak w
pliku; to jest nieścisłość w poleceniu, którym się kierowałem, i odnotowuję
ją tutaj zamiast przemilczeć. Plik `INSPIRATION_DECISIONS.md` jest
**kompletny względem własnej listy źródeł** — nic nie brakuje.

**Dodatkowa treść ponad wymagany zakres** (§7 „Co research mówi o już
podjętych decyzjach”, §8 „Znaleziska w naszym kodzie”, §9 „Gdyby trzeba
było wybrać pięć rzeczy”) — poza formalnym zakresem `ADOPT`/`ADAPT`/
`REJECT`/`LATER`, ale wartościowa i jawnie oznaczona jako propozycje, nie
decyzje wymagające zmiany w `DECISIONS.md`.

**Rozbieżność, którą trzeba nazwać wprost:** §8 tego pliku („Znaleziska w
naszym kodzie — do osobnych issues”) twierdzi, że **sześć** błędów **nie
zostało naprawionych**. Zweryfikowałem w kodzie **wszystkie sześć** — patrz
tabela niżej. **Pięć z sześciu jest już naprawionych w obecnym stanie
`main`.** Nie jest to wina autora `INSPIRATION_DECISIONS.md` — plik trafnie
opisuje stan **w chwili pisania notatek researchowych**; kod poszedł
naprzód od tamtej pory (widać to w `git log`: „Blokada przeciekała przez
galerię i wyszukiwarkę” #90, „Trzy zdjęcia z telefonu kasowały napisany
wpis” #94 i kilkanaście innych PR-ów audytowych po dacie researchu). To
jest dokładnie sytuacja opisana w poleceniu tego audytu: „Cztery issues
zamknięto dziś jako już-zaimplementowane, ale nigdy nieoznaczone” — tu jest
ich więcej niż cztery.

| # | Błąd wg `INSPIRATION_DECISIONS.md` §8 | Stan naprawdę (sprawdzone) | Dowód |
|---|---|---|---|
| 1 | Zdjęcia z telefonu publikują się obrócone — brak orientacji EXIF | **NAPRAWIONE.** Orientacja jest odczytywana z metadanych i stosowana przed każdym wariantem, z obsługą 8 wartości EXIF (w tym luster) | `app/Jobs/ProcessUploadedImage.php:65,70,131-145` — `applyOrientation()` z `match ($orientation) { 2 => flop(), 3 => rotate(180), 4 => flip(), ... }` |
| 2 | `Media::url()` może zwrócić surowy plik z GPS-em | **NAPRAWIONE.** Fallback na `object_key` usunięty; komentarz w kodzie opisuje to jako przeszłość: „Wcześniejsza wersja miała `?? $this->object_key` jako zabezpieczenie... To był wyciek” | `app/Models/Media.php:92-125` |
| 3 | Konto `Jan@…` nie do zalogowania/odzyskania | **NAPRAWIONE.** Mutator `email()` normalizuje na małe litery przy zapisie, `normalizeEmail()` jest jednym miejscem definicji, `findByLogin()` używa go po obu stronach | `app/Models/User.php:63-121` |
| 4 | Indeksy trigramowe nieużywane — pełny skan przy każdym wyszukiwaniu | **NAPRAWIONE.** `SearchQuery` odpytuje `kuking_normalize(...)`, tą samą funkcją co indeks GIN z migracji `2026_09_05_001300`; komentarz w kodzie wprost opisuje starą wersję jako błąd naprawiony | `app/Domain/Search/SearchQuery.php:18-23,61-68` |
| 5 | Zbanowane konto działa do końca sesji, brak middleware | **NAPRAWIONE.** `EnsureAccountIsActive` jest globalnym middleware grupy `web` (issue #39), wylogowuje natychmiast przy `isBanned()`/`pending_delete`, inwaliduje sesję | `app/Http/Middleware/EnsureAccountIsActive.php` (cała klasa) · `bootstrap/app.php:47-53` |
| 6 | Ten sam przepis dwa razy w zeszycie / ten sam plik dwa razy w albumie | **NAPRAWIONE.** `collection_items` ma `primary(['collection_id', 'recipe_id'])`; `post_media` ma `primary(['post_id', 'media_id'])` — oba wykluczają duplikat na poziomie bazy | `database/migrations/2026_09_05_000800_create_collections_tables.php:38-42` · `database/migrations/2026_09_05_000500_create_posts_tables.php:50-56` |

Sprawdziłem też, że żaden z trzech pakietów z tego audytu (`spatie/laravel-permission`,
`laravel/pennant`, `spatie/activitylog`) **nie jest** dziś w `composer.json`
ani `composer.lock` (`grep -iE "spatie|pennant|permission|activitylog"` —
zero trafień w obu plikach) — dyskusja w issue #21 jest więc rzeczywiście
otwarta, nie spóźniona.

**Rekomendacja co do issue #19:** **zaproponować zamknięcie jako
zrobione**, z jednym zastrzeżeniem do wpisania w komentarzu: sekcja §8
(„Znaleziska w naszym kodzie”) jest nieaktualna wobec obecnego stanu `main`
— pięć z sześciu opisanych tam błędów jest już naprawionych. To nie jest
usterka zakresu badawczego (research opisał stan, jaki zastał), ale warto
to odnotować, żeby nikt nie otworzył identycznych issues po raz drugi.
**Nie edytuję pliku** — zgodnie z twardą granicą tego zadania.

---

## Czego nie sprawdziłem

- Nie uruchomiłem `composer require --dry-run` dla żadnego z trzech
  pakietów (zakazane w poleceniu) — liczby tabel i konfiguracja cache'a
  pochodzą z oficjalnej dokumentacji/źródeł pakietów (linki niżej), nie
  z próby instalacji w tym repozytorium.
- Nie zmierzyłem realnego czasu zapytań `SearchQuery` przed/po naprawie
  indeksów trigramowych (punkt 4 w tabeli wyżej) — sprawdziłem tylko, że
  kod **dziś** jest spójny z indeksem (ta sama funkcja po obu stronach),
  nie że to daje konkretną liczbę milisekund.
- Nie sprawdziłem pozostałych ~223 pozycji `docs/INSPIRATION_DECISIONS.md`
  linia po linii pod kątem *stanu w kodzie* — weryfikacja objęła zakres z
  polecenia (cztery/sześć wskazanych błędów) plus strukturalną kompletność
  całego pliku (znaczniki, uzasadnienia, źródła, odesłania). Reszta pozycji
  (`ADOPT`/`LATER`/`REJECT` dla rzeczy jeszcze niezbudowanych, np. 2.14-2.20,
  4.9-4.10) nie ma jeszcze kodu do sprawdzenia — są to decyzje na przyszłość,
  nie stwierdzenia o obecnym stanie.
- Nie sprawdziłem historii commitów pod kątem tego, **kto i kiedy** naprawił
  sześć błędów z §8 — `git log` pokazuje PR-y pasujące tematycznie (#90,
  #94, #101 i inne), ale nie łączyłem każdego wiersza z konkretnym numerem
  commitu 1:1.
- Nie testowałem `spatie/laravel-permission` ani `laravel/pennant` w
  praktyce (nie mam ich zainstalowanych) — ocena cache'a i liczby tabel
  opiera się na dokumentacji źródłowej, cytowanej w przypisach.

---

## Źródła (wersje i zgodność pakietów, sprawdzone przez WebSearch/WebFetch)

[^1]: [spatie/laravel-permission — Packagist](https://packagist.org/packages/spatie/laravel-permission) i [Laravel Shift — can-i-upgrade](https://laravelshift.com/can-i-upgrade-laravel/spatie/laravel-permission) — wersja `8.3.0` (lipiec 2026), `illuminate/*: ^12.0|^13.0`.
[^2]: [spatie/laravel-permission — migracja `create_permission_tables.php.stub`](https://raw.githubusercontent.com/spatie/laravel-permission/main/database/migrations/create_permission_tables.php.stub) — pięć `Schema::create()`: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
[^3]: [spatie/laravel-permission — `config/permission.php`](https://raw.githubusercontent.com/spatie/laravel-permission/main/config/permission.php) — `'store' => 'default'`, `'expiration_time' => DateInterval::createFromDateString('24 hours')`, `'key' => 'spatie.permission.cache'`.
[^4]: [Laravel 13.x Docs — Pennant](https://laravel.com/docs/13.x/pennant) — „Pennant can store resolved feature flag values persistently in a relational database via the `database` driver, which is the default storage mechanism used by Pennant”; migracja tworzy tabelę `features`.
[^5]: [GitHub — laravel/pennant releases](https://github.com/laravel/pennant/releases) — wsparcie Laravel 13 dodane w `1.21.0`.
[^6]: [spatie/laravel-activitylog — README](https://raw.githubusercontent.com/spatie/laravel-activitylog/main/README.md) — jedna tabela `activity_log`, kolumny `subject_id`/`subject_type`, `causer_id`/`causer_type`, `description`, `properties`.
[^7]: Search wyników dla `logOnly`/`logExcept`/`dontLogEmptyChanges` w dokumentacji `spatie/laravel-activitylog` (sekcja „Log Options”, `docs/advanced-usage/log-options.md` w repozytorium pakietu).
[^8]: Wersje `4.12.0` i `5.1.0` pakietu `spatie/laravel-activitylog` wspierają Laravel 13 (`5.1.0` wymaga `illuminate/*: ^13.0`; `5.0.x` zostaje przypięty dla Laravel 12).
