## D-065 · Trzy pakiety zostają na później albo na nie: role w kolumnie, flagi w `.env`, audyt własny (issue #21)

**Data:** 10 września 2026 · Issue #21 · Status: **obowiązuje**

`docs/research/PUBLIC_REPOS.md` rekomendował trzy pakiety Laravela do
„bardzo wczesnego" wdrożenia: `spatie/laravel-permission`, `laravel/pennant`,
`spatie/laravel-activitylog`. Pełna analiza z cytatami `plik:linia` już
istniała — `docs/research/PAKIETY.md` i `docs/INSPIRATION_DECISIONS.md` §10
— ale bez wpisu w tym dzienniku, więc formalnie nierozstrzygnięta (issue #21
zostało otwarte właśnie z tego powodu). Ten wpis **potwierdza** tamte
werdykty po ponownym sprawdzeniu w dzisiejszym kodzie (nie tylko w notatce
z 6 września) i domyka issue.

**Kryterium jest jedno, z `AGENTS.md` §3: pakiet wchodzi tylko wtedy, gdy
usuwa nazwany, dziś istniejący problem.** „Przyda się później" nie jest
uzasadnieniem. Żaden z trzech pakietów **nie jest** dziś w
`composer.json`/`composer.lock` (`grep -iE "spatie|pennant|permission|activitylog"`
— zero trafień w obu plikach) i żaden nie został tu dodany — to jest wpis
decyzyjny, nie wdrożenie.

### 1. `spatie/laravel-permission` → **PÓŹNIEJ**

Dziś: `users.role`, string, **trzy** wartości (nie dwie), z `CHECK` w bazie:

```php
// database/migrations/0001_01_01_000001_create_users_table.php:35,58
$table->string('role', 20)->default('user');
DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user','moderator','admin'))");
```

Sprawdzanie roli, zmiana roli i egzekwowanie idą przez jedno źródło prawdy —
`app/Models/User.php:137-141` (`ROLE_USER`/`ROLE_MODERATOR`/`ROLE_ADMIN`),
`:617-620` (`isModerator()`), `:637-639` (`isAdmin()`), `:1128-1132`
(`promoteTo()`, jedyna droga zmiany, rola poza `$fillable` — D-006). Realny
problem, na który wskazywało issue #21 — rozdział ról moderator/administrator
przy odwołaniach — **już jest rozwiązany bez pakietu**:
`UserPolicy::resolveAppeals()` woła `isAdmin()`, nie `isModerator()`
(`app/Policies/UserPolicy.php:70-73`, D-039), i ma test regresyjny
(`tests/Feature/OdwolanieOdDecyzjiTest.php::test_moderator_bez_roli_administratora_nie_rozstrzyga_odwolania`)
sprawdzający dokładnie tę granicę na żywym żądaniu HTTP.

**Sprostowanie wobec wcześniejszej notatki (`PAKIETY.md`):** ta notatka
twierdziła, że `Gate::` nie występuje w `app/` ani razu. Dziś **występuje w
sześciu miejscach** (`app/Domain/Sharing/Udostepnianie.php:52`,
`app/Domain/Moderation/Actions/ReportContent.php:108`,
`app/Domain/Recipes/Actions/RecordCookedEvent.php:99`,
`app/Domain/Media/DostepDoZdjecia.php:100`,
`app/Http/Controllers/RecipeController.php:217`) — kod poszedł naprzód od
6 września. Nie zmienia to wniosku: każde z tych wywołań to
`Gate::forUser($x)->allows(...)`/`->denies(...)`, czyli wejście do **tych
samych** klas Policy przez fasadę frameworka, nie druga ścieżka autoryzacji
obok nich. `grep -rn "hasPermissionTo\|->can(" app/` — zero trafień; jest
dokładnie jedna rodzina bramek, i to jest dokładnie to, co ma zostać, gdyby
pakiet kiedyś wszedł.

**Dlaczego nie teraz:** pakiet dodaje pięć tabel
(`permissions`, `roles`, `model_has_permissions`, `model_has_roles`,
`role_has_permissions`)[^1] dla kombinacji uprawnień, których w kodzie **zero**
— nawet `isAdmin()` jest dziś wołany tylko z jednego miejsca
(`UserPolicy::resolveAppeals()`). Cache pakietu (`store: default`, TTL 24h)[^2]
działa poprawnie na `CACHE_STORE=database` — to nie jest powód przeciw.
Realne ryzyko to nie koszt instalacji, tylko dyscyplina po niej: trzeba by
pilnować, żeby `hasPermissionTo()`/`$user->can()` z pakietu były wołane
wyłącznie **z wnętrza** Policy, tak jak dziś `isModerator()`, a nie **obok**
nich — inaczej powstają dwa niezależne miejsca egzekwowania, dokładnie to,
przed czym ostrzega `AGENTS.md` §7.

**Rekomendacja: PÓŹNIEJ. Próg powrotu: trzeci moderator, albo pierwszy
przypadek, w którym trzeba rozdzielić uprawnienia w ramach jednej roli**
(„może ukrywać treść, ale nie może banować kont"). Dziś jest 1–2 moderatorów
(D-012). Migracji przejściowej `users.role` → tabele pakietu **świadomie nie
piszemy teraz** — byłby to kod do wyrzucenia, gdyby próg nie nadszedł, czyli
dokładnie budowanie na zapas z `AGENTS.md` §3. Próg jest tani do sprawdzenia:
jedno spojrzenie na listę kont z rolą `moderator`/`admin`.

**Mała poprawka wykonana w tym PR-ze:** cała ta rekomendacja stoi na zdaniu
„`role IN (...)` jest pilnowane w jednym miejscu prawdy — w bazie, nie tylko
w PHP". To zdanie nie miało testu. `NadanieRoliTest::test_nieznana_rola_jest_odrzucana()`
sprawdza wyłącznie walidację PHP w `promoteTo()`; omija ją każdy zapis, który
nie przechodzi przez model (migracja danych, ręczny `UPDATE`, przyszły bug
gdzie indziej). Dodany test
`test_baza_odrzuca_role_spoza_trzech_dozwolonych_wartosci` pisze wprost przez
`DB::table('users')->update(...)`, z pominięciem `User`, i sprawdza, że
`users_role_check` naprawdę odrzuca wartość spoza trzech dozwolonych —
patrz tabela kontroli ujemnej niżej.

### 2. `laravel/pennant` → **PÓŹNIEJ**

Dziś: brak jakiegokolwiek mechanizmu flag funkcji — potwierdzone ponownie
(`grep -rniE "feature.?flag|pennant|toggle\(" app/ config/ database/ routes/`
nie znajduje nic poza niepowiązanym komentarzem w opublikowanym pliku
Livewire, `config/livewire.php:188`). „Zamknięta alfa" to jeden globalny
bool, przełącznik CAŁEGO serwisu dla nowych kont, nie flaga POJEDYNCZEJ
funkcji dla wybranych kont:

```php
// config/kuking.php:250
'registration_open' => (bool) env('KUKING_REGISTRATION_OPEN', true),
```

Trzy nazwy z treści zadania (`KUKING_SYGNALY_AUTOMATU`,
`KUKING_DIGEST_WLACZONY`, `KUKING_MODEL_*`) potwierdzają ten sam wzorzec —
`config/kuking.php:1304,1709,1795-1847` — jeden bool albo liczba na całą
funkcję, czytane raz przy starcie procesu. Różnica wobec Pennanta: zmienna
środowiskowa przełącza funkcję **dla wszystkich naraz i wymaga restartu
procesu** (na Railwayu: redeploy), podczas gdy Pennant przełącza **per
użytkownik** (np. tylko dla konta administratora) **bez restartu**, bo stan
czyta z tabeli `features`[^3] przy każdym żądaniu.

**Czy to zysk przy jednym właścicielu i jednym wdrożeniu:** dla dzisiejszych
sześciu przełączników — nie. Żaden z nich nie potrzebuje „włączone dla mnie,
wyłączone dla reszty" — to globalne ustawienia operacyjne (czy automat
moderacyjny działa, czy digest wychodzi), nie wydania funkcji stopniowane po
koncie. Restart na Railwayu przy zmianie zmiennej środowiskowej jest tu
kosztem, nie problemem: to i tak redeploy, który już się dzieje przy każdej
zmianie kodu. Konkretny przykład z issue #21 — 3-krokowy kreator przepisu —
**jest już na produkcji bez żadnej flagi**, jako osobna trasa
(`routes/web.php:195-196`, `recipes.create` obok `recipes.create.simple`),
więc to nie jest dziś przypadek czekający na Pennanta.

**Rekomendacja: PÓŹNIEJ. Próg powrotu: pierwsza funkcja z `ROADMAP.md` V1**
(grupy, forki, planer, import AI) **trafia w fazę aktywnego pisania kodu na
scalonym `main`**, albo pojawia się konkretna potrzeba pokazać niedokończoną
funkcję tylko administratorowi przed pełnym wydaniem. Koszt wdrożenia jest
niski i nie rośnie od czekania (jedna tabela `features`, sterownik
`database` domyślny[^3], zero Redisa — `AGENTS.md` §3 nie jest tu naruszone),
więc nie ma powodu wchodzić wcześniej, tylko dlatego że wejście jest tanie.

### 3. `spatie/laravel-activitylog` → **NIE**, bez warunku powrotu

Dziś: własny, celowo minimalistyczny `AuditLogEntry` + tabela `audit_log`
(`app/Models/AuditLogEntry.php`) — append-only, aktor, akcja, podmiot, IP
**wyłącznie jako hash**, metadane dobierane jawnie. `record()` przyjmuje
`action` i `subject`, **nie ma parametru na treść modelu** — więc nie da się
przez pomyłkę przekazać mu wpisu, e-maila ani hasła. Wołany z 16 miejsc w
`app/Domain/*/Actions` — to jest przyjęty wzorzec, nie martwy kod. Retencja:
`kuking:sprzataj-audyt` (`app/Console/Commands/SprzatajAudyt.php`) kasuje
wpisy starsze niż `config('kuking.audit_log.retention_months')` miesięcy,
**z wyjątkiem** zamkniętej listy `AuditLogEntry::NIGDY_NIE_KASUJ`
(`account.data_erased`, `account.delete_requested`, `account.delete_cancelled`
— `app/Models/AuditLogEntry.php:66-70`), bo to jedyny dowód w całej bazie, że
prawo do usunięcia konta (RODO art. 17) zostało faktycznie wykonane, albo że
ktoś zgłosił i cofnął takie żądanie.

**Co dałby pakiet:** gotowe śledzenie zmian modeli (`LogsActivity`,
`activity()`), jedną tabelę `activity_log`[^4] zamiast ręcznych wywołań
`AuditLogEntry::record()` w 16 miejscach.

**Co by zabrał — i to jest sedno odpowiedzi „nie":** domyślny setup pakietu
loguje **wartości pól** (`logAll()` + `logOnlyDirty()`); da się to zawęzić
przez `logOnly()`/`logExcept()`[^5], **ale to jest lista do ręcznego
utrzymania per model**. Domyślny kierunek ryzyka się odwraca: dziś trzeba
**świadomie dopisać coś** do `metadata`, żeby trafiło do logu; z pakietem
trzeba **świadomie wykluczyć pole**, inaczej nowa kolumna na modelu (np.
`Post::$body`, `User::$email`) domyślnie wejdzie do `properties` następnym
razem, gdy ktoś zapomni dopisać ją do `logExcept()`. To jest dokładnie
sytuacja, przed którą broni się `AGENTS.md` §7, wybierając jawne nazwane
metody zamiast ogólnego `$fillable` — automatyczne logowanie zmian modelu to
automatyczne logowanie **cudzych treści**, a w logu audytowym Kuking treści
użytkowników nie ma nigdy, z zasady.

**Czy migracja istniejących wpisów byłaby bezpieczna:** nie, z dwóch
niezależnych powodów.

1. Trzy kategorie z `NIGDY_NIE_KASUJ` są chronione dziś **jedną zamkniętą
   listą w kodzie PHP**, czytaną przez `PrzedawnioneWpisyAudytu::posprzataj()`.
   Przeniesienie tych wierszy do `activity_log` wyprowadza je spod tej
   ochrony w tabelę z **własnym**, generycznym poleceniem retencji pakietu
   (`activitylog:clean`[^6], kasującym po wieku, bez pojęcia „kategorii
   dowodowej"). Odtworzenie tej samej ochrony nad pakietem oznaczałoby
   napisanie tego samego zamkniętego wyjątku po raz drugi, na wierzchu
   zależności — więcej kodu do utrzymania, nie mniej.
2. Migracja jednorazowa musiałaby przepisać `actor_id`/`action`/
   `subject_type`/`subject_id`/`metadata` na kształt `causer`/`subject`/
   `description`/`properties`/`event` pakietu. To jest dokładnie miejsce,
   w którym trzeba by ręcznie przejrzeć **każdy** historyczny wiersz, żeby
   upewnić się, że żadne `metadata` z 16 miejsc wywołania nigdy nie
   przemyciło czegoś, czego być tam nie powinno — czyli dokładnie tę pracę,
   którą pakiet miał oszczędzić.

Jedyny realny kandydat na „historię zmian pojedynczego modelu" — przepisy —
ma już dedykowane, celowo zaprojektowane rozwiązanie: `recipe_versions` /
`RecipeVersion` (`app/Models/RecipeVersion.php`).

**Rekomendacja: NIE, dla ogólnego audytu i dla historii zmian modeli, bez
warunku powrotu.** Żadne z dwóch zastosowań nie ma dziś nienazwanej potrzeby,
a domyślny profil bezpieczeństwa pakietu jest gorszy niż to, co już działa.
Gdyby to się kiedyś zmieniło, powodem musiałby być nowy, nazwany przypadek —
nie „mniej kodu do utrzymania" w oderwaniu od tego, co ten kod dziś chroni.

### Kontrola ujemna (dowód, że nowy test coś sprawdza)

| Krok | Stan `users_role_check` | Wynik `test_baza_odrzuca_role_spoza_trzech_dozwolonych_wartosci` |
|---|---|---|
| 1. Bazowo | obecny (migracja bez zmian) | **zielony** — `DB::table('users')->update(['role' => 'superadmin'])` rzuca `QueryException` |
| 2. Zepsute | `DB::statement(...)` z `CHECK` zakomentowany, `migrate:fresh` na bazie testowej | **czerwony** — „Failed asserting that exception of type Illuminate\\Database\\QueryException is thrown." |
| 3. Przywrócone | ograniczenie z powrotem w migracji | **zielony**, cały plik `NadanieRoliTest` (10/10) przechodzi |

### Co zostaje nierozstrzygnięte, jeśli próg kiedyś nadejdzie

`docs/INSPIRATION_DECISIONS.md` §10 (poz. 10.1–10.4) ma te same cztery
werdykty z odesłaniem do `docs/research/PAKIETY.md` — ten wpis jest ich
formalnym potwierdzeniem w dzienniku decyzji, nie nową analizą. Kolejny
agent, który natrafi na pytanie „czy wziąć jeden z tych trzech pakietów",
ma zacząć **tutaj**, nie od nowa.

**Zmiana wymaga:** dla (1) trzeciego moderatora albo potrzeby rozdzielenia
uprawnień w jednej roli; dla (2) pierwszej funkcji V1 wchodzącej w aktywne
pisanie kodu na `main`; dla (3) — nic przewidzianego, próg nie istnieje.

📄 `app/Models/User.php` · `app/Policies/UserPolicy.php` ·
`app/Console/Commands/NadajRole.php` ·
`database/migrations/0001_01_01_000001_create_users_table.php` ·
`config/kuking.php` (`account.registration_open`, `digest.wlaczony`, `moderation.sygnaly.wlaczone`, `moderation.model.*`) ·
`app/Models/AuditLogEntry.php` · `app/Console/Commands/SprzatajAudyt.php` ·
`app/Domain/Compliance/PrzedawnioneWpisyAudytu.php` ·
`app/Models/RecipeVersion.php` ·
`tests/Feature/OdwolanieOdDecyzjiTest.php` ·
`tests/Feature/NadanieRoliTest.php` ·
`docs/research/PAKIETY.md` · `docs/INSPIRATION_DECISIONS.md` §10 ·
issue #21
