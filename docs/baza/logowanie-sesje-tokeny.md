# Logowanie bez hasła, zaproszenia, sesje, tokeny

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### pending_email_changes
Zamówiona, ale **jeszcze nieobowiązująca** zmiana adresu e-mail (issue #195,
migracja `2026_09_09_300000_create_pending_email_changes_table`).

Do 9 września 2026 zalogowany człowiek nie widział własnego adresu **nigdzie**
w serwisie poza ekranem „Potwierdź e-mail" i paczką RODO — a reset hasła idzie
właśnie na ten adres. Literówka przy rejestracji znaczyła więc konto bez drogi
powrotu, i to od dnia, w którym poczta zaczęła realnie wysyłać listy. Prawo do
sprostowania danych (RODO art. 16) nie miało tu żadnej realizacji.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID. Trafia do podpisanego odnośnika w liście — **sam w sobie nie jest autoryzacją** (AGENTS.md §7), właściciela sprawdza kontroler osobno. |
| `user_id` | **UNIKALNY** — jedno oczekujące żądanie na konto. Nowe zastępuje poprzednie, więc odnośnik z wcześniejszego listu natychmiast przestaje działać. `cascadeOnDelete`. |
| `new_email` | Adres, na który konto ma się przenieść. **Bez indeksu unikalnego** — patrz niżej. |
| `created_at` | Kiedy zamówiono. |
| `expires_at` | Kiedy odnośnik przestaje działać: `config('kuking.account.email_change_ttl_hours')` (domyślnie 24 h) od zamówienia. |

```sql
ALTER TABLE pending_email_changes
ADD CONSTRAINT pending_email_changes_new_email_lower_check
CHECK (new_email = lower(new_email) AND new_email <> '');

ALTER TABLE pending_email_changes
ADD CONSTRAINT pending_email_changes_expires_after_created_check
CHECK (expires_at > created_at);
```

#### Dlaczego osobna tabela, a nie kolumny na `users`

Rozważana była druga droga (`users.pending_email` + `pending_email_expires_at`).
Odpadła z czterech powodów:

1. to nie jest cecha konta, tylko **żądanie z własnym życiorysem** — powstaje,
   wygasa, zostaje skasowane albo skonsumowane. Wiersz znikający w całości jest
   prostszy niż dwie kolumny, które trzeba wyzerować **razem**;
2. spójność za darmo: przy kolumnach trzeba by CHECK-a wiążącego ich
   nullowość (`num_nonnulls()`), tu warunek nie ma jak nie być spełniony;
3. `users` czyta **każde** żądanie zalogowanej osoby — nie dokładamy do niej
   kolumn, które w 99,9% wierszy są NULL-em;
4. minimalizacja danych: adres znika jednym `DELETE`, a nie `UPDATE`-em
   na najważniejszej tabeli w bazie.

#### Dlaczego `new_email` nie jest unikalny

Bo unikalny indeks tutaj zamieniłby formularz w wyrocznię „kto ma konto
w Kuking": odpowiedź „ten adres jest zajęty" da się wyklikać seriami.
Unikalność pilnuje `users_email_lower_unique` w chwili **potwierdzenia** —
czyli dowiaduje się o niej wyłącznie ten, kto czyta pocztę pod tym adresem
(`App\Domain\Users\Actions\ConfirmEmailChange`).

#### Co kasuje wiersz

Potwierdzenie, przycisk „Anuluj zmianę", **zmiana albo reset hasła** (bo list
ostrzegawczy do starego adresu radzi właśnie to i ta rada musi być prawdziwa),
kolejne żądanie tej samej osoby, anonimizacja konta (`EraseAccountData`) oraz
wygaśnięcie — `kuking:sprzataj-zmiany-adresu`, harmonogram codziennie o 04:50
(`App\Domain\Compliance\PrzedawnioneZmianyAdresu`).

Termin stoi w kolumnie, a **nie** jest liczony przy odczycie — dzięki temu nie
da się tu powtórzyć pułapki `subMonths()` kontra `subMonthsNoOverflow()`
z `PrzedawnionePowiadomienia` (A6-04): próg jest policzony raz, przy
zamówieniu, i od tego momentu jest faktem, a nie wynikiem arytmetyki na datach.
Sprzątanie **nie jest** bramką bezpieczeństwa — odnośnik przestaje działać co
do minuty dzięki `PendingEmailChange::jestWazne()`, a nie dzięki nocnej komendzie.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()` kasuje tabelę.
Bezstratne dla kont: żaden wiersz `users` nie jest przez tę migrację dotykany,
żaden adres nie zmienia się w ani jedną stronę. Ginie tylko to, co było
w drodze — listy wysłane, ale jeszcze niepotwierdzone; kto kliknie taki
odnośnik, przeczyta „zamów zmianę jeszcze raz". Kolejność wycofywania:
**najpierw kod, potem migracja** — sam ekran `/ustawienia/e-mail` bez tabeli
odda 500.

### login_link_tokens
Jednorazowy token logowania linkiem e-mail — „magic link" (issue #25, D-056,
migracja `2026_09_10_100000_create_login_link_tokens_table`).

Ten wiersz **jest hasłem jednorazowym**: kto ma token, wchodzi na konto. Stąd
wszystko poniżej — jeden wiersz na konto, krótki termin, kasowanie przy użyciu
i skrót zamiast wartości.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID. **Nie trafia nigdzie** — link niesie sam token, nie identyfikator wiersza. |
| `user_id` | **UNIKALNY** — jeden ważny link na konto. Nowa prośba zastępuje poprzednią, więc link z wcześniejszego listu natychmiast przestaje działać. `cascadeOnDelete`. |
| `token_hash` | **UNIKALNY. HMAC-SHA256 tokenu** (`App\Support\Skrot`), nigdy token. Po tej kolumnie szukamy wiersza przy kliknięciu w link. |
| `created_at` | Kiedy poproszono. |
| `expires_at` | Kiedy link przestaje działać: `config('kuking.login_link.waznosc_minut')` (domyślnie 30 min) od prośby. |

```sql
ALTER TABLE login_link_tokens
ADD CONSTRAINT login_link_tokens_expires_after_created_check
CHECK (expires_at > created_at);

ALTER TABLE login_link_tokens
ADD CONSTRAINT login_link_tokens_token_hash_format_check
CHECK (token_hash ~ '^[0-9a-f]{64}$');
```

#### `UNIQUE (user_id)` wymaga serializacji, nie tylko constraintu (D-075)

Constraint pilnuje niezmiennika „jeden ważny link na konto", ale **sam nie
ustawia próśb w kolejce**. Dwie równoległe prośby o link na to samo konto
przechodziły obie `DELETE` (każda kasując zero wierszy) i obie szły do
`INSERT` — druga odbijała się o unikalność i, przed poprawką, kończyła się
**500**. A 500 zdarzało się wyłącznie tam, gdzie konto istnieje, czyli para
równoległych żądań stawała się wyrocznią „kto ma konto w Kuking".

Dlatego wymiana tokenu idzie pod **blokadą wiersza `users`**
(`WyslijLinkDoLogowania::wymienToken()`), branej PRZED `DELETE`, plus
defensywnym przechwyceniem konfliktu. Kolejność blokad w tym repozytorium
jest jedna i obowiązuje wszędzie: **konto najpierw**, potem tabela żądania
(tu `login_link_tokens`, przy zmianie adresu `pending_email_changes`). Kto
dopisze drugie miejsce zapisujące tę tabelę, musi wejść tą samą drogą; dwie
różne kolejności blokad to zakleszczenie, które PostgreSQL rozwiązuje
zabiciem jednego z żądań.

#### Dlaczego skrót szybki, a nie bcrypt jak w `password_reset_tokens`

Bo bcrypt istnieje po to, żeby spowolnić zgadywanie wartości o **niskiej
entropii** (hasło człowieka), a tu wartością jest 64 losowe znaki
z `Str::random()` — zgadywania nie ma czego spowalniać. Za to bcrypt
uniemożliwiłby wyszukanie wiersza po skrócie: trzeba by wstawić do adresu
w liście jeszcze identyfikator wiersza, czyli wynieść do poczty i do historii
przeglądarki jedną informację więcej bez żadnego zysku.

Drugi CHECK nie jest ozdobą: token z `Str::random()` ma wielkie litery, więc
**zapisany wprost łamie ten warunek i baza go odrzuci**. Dlatego token nie jest
szesnastkowy i nie wolno go na taki zmienić — zabrałoby to CHECK-owi całą
wartość.

#### Dlaczego osobna tabela, a nie kolumny na `users`

Ten sam wywód co przy `pending_email_changes`: to nie jest cecha konta, tylko
żądanie z własnym życiorysem; wiersz znikający w całości nie wymaga CHECK-a
wiążącego nullowość dwóch kolumn; `users` czyta każde żądanie zalogowanej
osoby i nie dokładamy do niej kolumn NULL-owych w 99,9% wierszy.

#### Czego w tej tabeli świadomie nie ma

**`used_at`** — zużyty link **znika** (`DELETE`), a nie zostaje oznaczony.
Wiersz po użyciu nie odpowiadałby na żadne pytanie, którego nie odpowiada wpis
`account.login_link_used` w `audit_log`, a byłby kolejnym miejscem, w którym
trzeba pamiętać o warunku „AND used_at IS NULL". Zapomnienie o takim warunku
znaczy link wielokrotnego użytku — czyli dokładnie ta usterka, przed którą ta
tabela ma bronić.

**Adresu IP i `user_agent`** — nie ma pytania, na które musiałyby odpowiedzieć,
a byłby to kolejny zbiór adresów IP w bazie (AGENTS.md §7). Fakt prośby notuje
`audit_log`, z adresem e-mail w skrócie i z adresem IP w haszu.

#### Co kasuje wiersz

Użycie linku, kolejna prośba tej samej osoby, **zmiana i reset hasła**,
**„wyloguj mnie z innych urządzeń"**, blokada, zawieszenie i zgłoszenie
usunięcia konta (wszystkie przez `User::invalidateSessions()` →
`invalidateLoginLinks()`) oraz wygaśnięcie — sprzątane przy okazji następnej
prośby o link (`WyslijLinkDoLogowania::posprzatajPrzedawnione()`), bez osobnej
komendy w harmonogramie: tabela mieści najwyżej jeden wiersz na konto i żyje
minutami.

Sprzątanie **nie jest** bramką bezpieczeństwa — link przestaje działać co do
minuty dzięki `LoginLinkToken::jestWazny()`, a nie dzięki `DELETE`.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()` kasuje tabelę.
Bezstratne dla kont: migracja nie dotyka ani jednego wiersza `users`, nie
zmienia haseł i nie zamyka logowania hasłem. Ginie tylko to, co w drodze —
linki wysłane, a jeszcze niekliknięte; kto kliknie taki link po wycofaniu,
przeczyta „ten link już nie działa, poproś o nowy". Kolejność wycofywania:
**najpierw kod, potem migracja** — trasy `/logowanie/link` bez tabeli oddadzą
500. Samo wyłączenie funkcji migracji nie wymaga wcale:
`KUKING_LOGOWANIE_LINKIEM=false`.

### registration_invites
Zaproszenie do założenia konta dla adresu, na którym konta **nie ma** (D-085,
migracja `2026_09_10_400000_create_registration_invites_table`).

Powstaje wtedy, gdy ktoś poprosi o „link do zalogowania" dla adresu bez konta.
Ten wiersz **nie jest hasłem jednorazowym** — nie wpuszcza na żadne konto, bo
konta nie ma. Jest **dowodem dostępu do skrzynki**: kto kliknął link z tej
skrzynki, udowodnił, że jest jej właścicielem, więc adres wchodzi do rejestracji
jako już potwierdzony i drugiej wiadomości weryfikacyjnej nie wysyłamy. Stąd
termin dłuższy niż przy logowaniu linkiem (24 h kontra 30 min): poświadczenie
jest słabsze, a droga po jego kliknięciu dłuższa (cały formularz rejestracji).

| Kolumna | Uwagi |
|---|---|
| `id` | UUID. **Nie trafia do listu** — link niesie sam token. Trafia za to do SESJI po przyjęciu zaproszenia (`rejestracja.zaproszenie_id`), i to on, a nie pole formularza, rozstrzyga, na jaki adres powstaje konto. |
| `email` | **UNIKALNY.** Jedno ważne zaproszenie na adres — kolejna prośba zastępuje poprzednią. Zapisywany ZNORMALIZOWANY (małe litery), tak jak `users.email`, inaczej UNIQUE nic by nie pilnował. |
| `token_hash` | **UNIKALNY. HMAC-SHA256 tokenu** (`App\Support\Skrot`), nigdy token. Po tej kolumnie szukamy wiersza przy kliknięciu w link. |
| `created_at` | Kiedy wysłano zaproszenie. Bez `updated_at` — wiersza się nie edytuje. |
| `expires_at` | Kiedy link przestaje działać: `config('kuking.login_link.zaproszenia.waznosc_godzin')` (domyślnie 24 h) od wysłania. Indeksowana — chodzi po niej sprzątanie. |

```sql
ALTER TABLE registration_invites
ADD CONSTRAINT registration_invites_email_lower_check
CHECK (email = lower(email) AND email <> '');

ALTER TABLE registration_invites
ADD CONSTRAINT registration_invites_expires_after_created_check
CHECK (expires_at > created_at);

ALTER TABLE registration_invites
ADD CONSTRAINT registration_invites_token_hash_format_check
CHECK (token_hash ~ '^[0-9a-f]{64}$');
```

Ostatni CHECK nie jest ozdobą: token powstaje przez `Str::random(64)`, więc ma
wielkie litery — **zapisany wprost łamie ten warunek i baza go odrzuci**.
Dlatego token nie jest szesnastkowy i nie wolno go na taki zmienić.

#### `UNIQUE (email)` też wymaga przechwycenia konfliktu (D-085)

Ta sama lekcja co przy `login_link_tokens` i D-075, tylko bez konta, na którym
dałoby się postawić blokadę wiersza. Dwie równoległe prośby o ten sam adres bez
konta przechodziły oba `DELETE` (każda kasując zero wierszy) i obie szły do
`INSERT`; druga odbijała się o unikalność i kończyła **500**. A 500 zdarzało się
wyłącznie na ścieżce adresu BEZ konta — adres z kontem swój wyścig sprowadza do
302 (D-075) — czyli para równoległych żądań znowu odpowiadała na pytanie „kto ma
konto w Kuking", tylko odwrotnie niż przed D-075.

`SELECT ... FOR UPDATE` nie ma tu na czym stanąć (konta nie ma, a blokada
nieistniejącego wiersza nie blokuje niczego), więc zostaje druga połowa tamtej
konstrukcji: `WyslijZaproszenieDoRejestracji` **przechwytuje
`UniqueConstraintViolationException`** i oddaje tę samą neutralną odpowiedź co
każda inna odmowa, oddając przy okazji miejsce w dobowym suficie.

#### Co kasuje wiersz — pełna lista

1. **założenie konta** — `RegisterController::store()` kasuje wiersz w tej samej
   transakcji, w której powstaje konto, pod `lockForUpdate()`
   (`ZaproszenieWSesji::zuzyj()`). To jest cała jednorazowość tej drogi;
2. **kolejna prośba z tego samego adresu** — `email` jest unikalne;
3. **wygaśnięcie** — `kuking:sprzataj-zaproszenia` raz na dobę
   (`App\Domain\Compliance\PrzedawnioneZaproszenia`), plus sprzątanie przy
   okazji każdej prośby.

Sprzątanie **nie jest bramką bezpieczeństwa**: link przestaje działać co do
minuty dzięki `RegistrationInvite::jestWazne()`, a nie dzięki `DELETE`. Jest
higieną danych — i ważniejszą niż przy `login_link_tokens`, bo tutaj w wierszu
leży adres e-mail osoby, która **nie ma u nas konta** i nigdy nie musi mieć.

#### Wycofanie (rollback)

`down()` **odmawia**, dopóki w tabeli leży choć jedno WAŻNE zaproszenie — każde
z nich to człowiek, który ma w skrzynce wiadomość i jeszcze jej nie kliknął.
Dwie drogi wyjścia:

1. poczekać, aż zaproszenia wygasną (najwyżej 24 h), i wtedy
   `php artisan migrate:rollback` — wygasłe wiersze odmowy nie wywołują;
2. `php artisan kuking:sprzataj-zaproszenia --wszystkie` (pyta o potwierdzenie
   i mówi, ilu osób to dotyczy), a potem wycofać świadomie.

Wycofanie samej funkcji **nie wymaga wycofywania migracji**: wystarczy
`KUKING_ZAPROSZENIA_DO_REJESTRACJI=false`. Adres bez konta wraca wtedy do
zachowania z D-056, a linki będące w drodze dostają ekran po polsku.

### cache (tabela Laravela)

Sterownik cache `database` (`CACHE_STORE=database`): `key` (klucz główny,
`cache_pkey`), `value`, `expiration` (znacznik uniksowy). Trzyma limitery
(klucze adresów jako skróty HMAC, `App\Support\KluczeLimitow`), budżet listów
(D-076) i drobne wartości podręczne. Blokady harmonogramu leżą osobno,
w `cache_locks`.

**Sprzątanie (#2292, audyt wydajności F6).** Laravel kasuje wygasły wiersz
wyłącznie przy odczycie tego samego klucza, więc klucz limitera gościa z adresu,
który już nie wróci, zostawał na zawsze. `kuking:sprzataj-cache`
(`App\Support\WygasleWpisyCache`) codziennie o 02:45 UTC kasuje wiersze
z `expiration <= teraz` — dokładnie te, które sterownik i tak uznaje za
nieistniejące — partiami (`UsuwanieWPartiach`, `kuking.retencja.partia`
i `.budzet`). Wpisy `forever()` i `cache_locks` zostają. `--na-sucho` tylko
liczy. Test: `SprzatanieWygaslegoCacheTest`. Schematu nie zmienia, więc nie ma
migracji ani rollbacku.

### password_reset_tokens (tabela Laravela)

Kluczowana **adresem e-mail zapisanym jawnie** (`email`, `token` — bcrypt,
`created_at`). Retencja (audyt B5 pkt 6): `kuking:sprzataj-resety-hasel` (to samo co `auth:clear-resets`) codziennie
o 05:40 kasuje żetony starsze niż `auth.passwords.users.expire`; wymazanie
konta (`EraseAccountData`) kasuje wiersz po `lower(email)` sprzed
anonimizacji, zmiana adresu (`ConfirmEmailChange`) — po starym adresie.

### personal_access_tokens
Tokeny osobistego dostępu Laravel Sanctum — logowanie aplikacji mobilnej
(D-014 zmienione decyzją właściciela 25.09.2026, D-270, migracja
`2026_09_25_100000_create_personal_access_tokens_table`).

Ten wiersz **jest poświadczeniem**: kto ma jawną postać tokenu, działa na
koncie przez API (prefiks `api/v1`). Jawna postać to `<id>|kuking_<sekret>` i istnieje
wyłącznie w odpowiedzi HTTP, która token wydaje (`User::createToken()`).
W bazie leży skrót.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, nie `bigint` jak w pakiecie. Stoi w jawnej części tokenu i w adresie odwołania urządzenia — kolejny numer zdradzałby, ile tokenów serwis wydał. |
| `tokenable_type` | Nazwa narzucona przez Sanctum (relacja polimorficzna). **CHECK: zawsze `App\Models\User`** — tokeny ma tylko konto. `varchar(100)`. |
| `tokenable_id` | Konto. **Prawdziwy klucz obcy do `users`**, `ON DELETE CASCADE` — relacja polimorficzna bez klucza zostawiałaby wiersz żywy po koncie. Kont się nie kasuje (anonimizuje je `EraseAccountData`, D-022), więc kaskada jest drugą linią obrony; pierwszą jest `User::invalidateSessions()`. |
| `name` | Nazwa urządzenia podana przy logowaniu („Telefon Ani"), do 100 znaków, **CHECK: nie pusta po obcięciu spacji**. Widzi ją właściciel konta na liście urządzeń. |
| `token` | **UNIKALNY. SHA-256 sekretu, szesnastkowo** — nigdy sekret. **CHECK `^[0-9a-f]{64}$`**: sekret zaczyna się od `kuking_`, więc zapisany jawnie odbije się od bazy. Poza `$fillable` (AGENTS.md §7) — zapisuje go `forceFill()` w `User::createToken()`. |
| `abilities` | Uprawnienia jako JSON (`text`), dziś zawsze `["*"]`. Poza `$fillable`. |
| `last_used_at` | Kiedy token ostatnio otworzył żądanie — aktualizuje Sanctum przy każdym uwierzytelnieniu. Na liście urządzeń odpowiada na pytanie „czy ten telefon jeszcze tego używa". |
| `expires_at` | Termin ważności, dziś `NULL` (token działa do odwołania, `config/sanctum.php` → `expiration`). Indeks. |
| `created_at`, `updated_at` | `timestamptz`. |

**Paczka danych (RODO art. 15).** Sekcja `urzadzenia_z_dostepem` w `dane.json`
niesie `name`, `created_at`, `last_used_at` i `expires_at` — bez `token`
(poświadczenie) i bez `abilities`. Wpis w `InwentarzDanychKonta`
(`personal_access_tokens.tokenable_id`), pilnują
`EksportObejmujeKazdaTabeleKontaTest` i `tests/Feature/Api/PaczkaDanychNiesieUrzadzeniaTest.php`.

```sql
ALTER TABLE personal_access_tokens
ADD CONSTRAINT personal_access_tokens_tokenable_type_check
CHECK (tokenable_type = 'App\Models\User');

ALTER TABLE personal_access_tokens
ADD CONSTRAINT personal_access_tokens_token_format_check
CHECK (token ~ '^[0-9a-f]{64}$');

ALTER TABLE personal_access_tokens
ADD CONSTRAINT personal_access_tokens_name_not_blank_check
CHECK (length(btrim(name)) > 0);
```

Indeksy: `UNIQUE (token)`, `(tokenable_type, tokenable_id)`, `(expires_at)`.

#### Dlaczego skrót szybki (SHA-256), a nie bcrypt

Ten sam wywód co przy `login_link_tokens`: sekret to 40 losowych znaków
z `Str::random()` — nie ma czego zgadywać, więc nie ma czego spowalniać,
a bcrypt uniemożliwiłby wyszukanie wiersza. Wiersz szukany jest po `id`
z jawnej części tokenu, skrót porównywany `hash_equals()`
(`App\Models\PersonalAccessToken::findToken()`). Identyfikator, który nie jest
UUID-em, odpada przed zapytaniem — inaczej PostgreSQL odpowiadał błędem
składni, a klient dostawał 500 zamiast 401.

#### Co kasuje wiersz

`User::invalidateSessions()` → `invalidateApiTokens()`: zmiana i reset hasła,
„wyloguj mnie z innych urządzeń", włączenie 2FA, blokada, zawieszenie
i zgłoszenie usunięcia konta — ta sama lista co przy sesjach i linkach
logowania, z tego samego powodu: token jest wejściem na konto. Do tego
kaskada przy skasowaniu wiersza `users`.

#### Czego w tej tabeli świadomie nie ma

**Adresu IP i `user_agent`.** Nazwę urządzenia podaje człowiek i to wystarcza
do rozpoznania go na liście; adres IP byłby kolejnym zbiorem adresów w bazie
(AGENTS.md §7), bez pytania, na które musiałby odpowiedzieć.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()` kasuje tabelę
**bez odmowy**, świadomie. D-088 zabrania cichego odwracania **decyzji
człowieka**; token nie jest decyzją, tylko poświadczeniem. Po `down()` +
`migrate` tabela wraca **pusta**, czyli każde urządzenie loguje się jeszcze
raz — kierunek bezpieczny (odebranie dostępu), nie groźny. Konta, hasła
i logowanie na WWW zostają nietknięte. Kolejność: **najpierw kod, potem
migracja** — `auth:sanctum` bez tabeli odda 500. Samo zamknięcie API migracji
nie wymaga: `KUKING_API_ENABLED=false`. Pilnuje tego
`tests/Feature/Api/TabelaTokenowDostepuTest.php`.

### sessions

Tabela sterownika sesji Laravela (`SESSION_DRIVER=database` — wartość
**domyślna** w `config/session.php`, ta sama w `.env.example`, w `docker/php.ini`
i w `.railway/railway.ts`). Zakłada ją domyślna migracja frameworka
`0001_01_01_000001_create_users_table`, nie nasza.

**Jest tu opisana, chociaż nie jest naszą tabelą — i to jest cała rzecz.**
Do 21.09.2026 `docs/DATABASE.md` nie wspominał o niej ani razu, a leżą w niej
dane osobowe. Sześć kolejnych audytów prywatności czytało ten dokument jako
spis danych i żaden nie zauważył, że serwis trzyma adres IP zalogowanego
człowieka — bo nikt tej tabeli nie „dodawał", więc nikt nie przeszedł ścieżki
„migracja + test + `docs/DATABASE.md`", która takie rzeczy wyłapuje
(badanie RZ-01, 21.09.2026). Cisza w dokumencie nie znaczyła, że nic tam nie ma.

| Kolumna | Uwagi |
|---|---|
| `id` | Identyfikator sesji z ciasteczka, `varchar` PRIMARY KEY. Nadaje go framework, nie my. |
| `user_id` | Kto jest zalogowany; `null` dla gościa. **Kolumna, nie klucz obcy** — `foreignUuid()` bez `constrained()` tworzy samą kolumnę `uuid` z indeksem. Kasowanie konta zabiera te wiersze jawnie (`User::invalidateSessions()`, `EraseAccountData`), nie kaskadą. Samo skasowanie nie jest trwałym unieważnieniem — wolne żądanie potrafi wiersz odtworzyć; odrzuca go dopiero generacja sesji (`users.session_generation`, #1046). |
| `ip_address` | **ZGRUBNY adres IP, nie dokładny** (RZ-01). IPv4 bez ostatniego oktetu (`203.0.113.0`), IPv6 obcięty do `/48` (`2001:db8:1234::`). Zapisuje go `App\Support\Sesja\UchwytSesjiBezPelnegoAdresu` — nasze nadpisanie `DatabaseSessionHandler::ipAddress()`, zarejestrowane w `AppServiceProvider`. `varchar(45)` (długość na pełny IPv6) zostaje ze schematu frameworka. |
| `user_agent` | **Pełny nagłówek `User-Agent`, do 500 znaków** — obcina go framework, nie my. To jest niezły odcisk palca przeglądarki i **dana osobowa**, gdy stoi obok `user_id`. Nie maskujemy go: to osobna decyzja, nie porządek przy okazji. |
| `payload` | Zawartość sesji (`text`, base64 + `serialize`). Jedyna kolumna, którą obejmuje `SESSION_ENCRYPT` — patrz niżej. |
| `last_activity` | Uniksowy znacznik czasu (`integer`, nie `timestamptz`), aktualizowany przy każdym zapisie sesji. Po nim liczy się retencja. |

Zmiana hasła w ustawieniach oraz „Wyloguj inne urządzenia” sprawdzają hasło
przed rozpoczęciem akcji, ale ten odczyt może wyprzedzić reset. Przed zapisem
hasła lub unieważnieniem sesji obie akcje sprawdzają **ponownie** świeży skrót
hasła i generację sesji tego żądania, pod `ZamekKonta` (#2851, #2854).
Odmowa nie podnosi generacji i nie przypisuje jej odwołanej sesji; człowiek
wraca do logowania. Reset linkiem nadal ma własne ponowne sprawdzenie tokenu
pod tą samą blokadą (#2055). Nie ma zmiany schematu ani migracji; cofnięcie
samej poprawki przywróciłoby okno wyścigu, więc rollback wydania wymaga
pozostawienia tej bramki albo osobnej zweryfikowanej poprawki.

```sql
CREATE TABLE sessions (
    id            varchar(255) PRIMARY KEY,
    user_id       uuid NULL,
    ip_address    varchar(45) NULL,
    user_agent    text NULL,
    payload       text NOT NULL,
    last_activity integer NOT NULL
);

CREATE INDEX sessions_user_id_index ON sessions (user_id);
CREATE INDEX sessions_last_activity_index ON sessions (last_activity);
```

#### `SESSION_ENCRYPT` NIE zasłania adresu ani przeglądarki

To jest pułapka, na którą łatwo wejść przy czytaniu `.railway/railway.ts`
(planuje `SESSION_ENCRYPT: "true"`). Flaga szyfruje **wyłącznie kolumnę
`payload`**. `ip_address` i `user_agent` są dokładane OBOK, jako osobne
kolumny, przez `DatabaseSessionHandler::addRequestInformation()`, i zostają
jawne niezależnie od niej. Kto weźmie zrzut tej tabeli, dostaje parę
(`user_id`, zgrubny adres, pełny `User-Agent`).

#### Dlaczego adres jest zgrubny, a nie dokładny i nie pusty

Po `App\Http\Middleware\NormalizeForwardedFor` `$request->ip()` zwraca
**prawdziwy adres człowieka** zza łańcucha Cloudflare → Railway → kontener,
czyli adres domowy albo komórkowy, a nie adres infrastruktury. RZ-01 ustaliło
przy tym, że **nic tej kolumny nie czyta**: jedyne odwołania do tabeli
w całym `app/` to dwa `->delete()` po `user_id` i deklaracja nazwy tabeli.
Nie ma wykrywania przejęcia sesji ani ekranu „Twoje aktywne urządzenia".

Pełna precyzja nie ma więc dziś odbiorcy, a ma koszt. Zgrubny adres zostawia
tyle, ile wystarcza przy incydencie na ręczne pytanie „czy te sesje szły
z jednego miejsca, czy z pół świata"; wyzerowanie kolumny zamknęłoby tę drogę
bez powrotu i jest osobną decyzją, której nikt nie podjął.

**Uczciwa granica: zamaskowany adres NADAL jest daną osobową**, gdy leży obok
`user_id` — u operatora, który deleguje abonentowi całe `/48`, ta maska nie
zabiera nic. Zmiana zmniejsza szkodę przy wycieku; **nie znosi obowiązku
opisania tego przetwarzania w polityce prywatności**, którego ten dokument nie
zastępuje i którego nie wolno domknąć zmianą w kodzie.

#### Retencja — twarda, nie loteryjna

`kuking:sprzataj-sesje` (`App\Domain\Compliance\PrzedawnioneSesje`), co noc
o 05:10: kasuje wiersze bez aktywności od `config('kuking.sessions.retention_days')`
dni (domyślnie 7), **nigdy jednak krócej niż `SESSION_LIFETIME`** — wiersz
młodszy niż czas życia sesji należy do sesji ŻYWEJ, a jego skasowanie to
wylogowanie człowieka w środku pracy.

Do 21.09.2026 kasowała tu wyłącznie loteria frameworka
(`config/session.php` → `'lottery' => [2, 100]`, czyli `gc()` przy 2% żądań).
Przy małym ruchu wiersz leżał dłużej niż `lifetime`, bez żadnej gwarantowanej
górnej granicy — `sessions` była jedyną tabelą z danymi osobowymi bez nocnego
zadania. **Loteria zostaje włączona obok**, świadomie: dwa mechanizmy o różnych
trybach awarii (loteria czyści przy ruchu nawet po śmierci harmonogramu,
zadanie czyści co noc nawet bez ruchu).

#### Czego tu świadomie nie ma i co zostało zrobione z wierszami sprzed zmiany

**Nie ma migracji nadpisującej adresy, które już leżały w tabeli** — i to jest
decyzja, nie przeoczenie. Powody, w kolejności ważności:

1. **Te wiersze znikają same, bez żadnej destrukcyjnej operacji.**
   `addRequestInformation()` przepisuje `ip_address` przy KAŻDYM zapisie sesji,
   więc adres w sesji żywej zostaje zamaskowany przy pierwszym żądaniu po
   wdrożeniu. Sesja, do której nikt nie wraca, wygasa i zabiera ją
   `kuking:sprzataj-sesje`. Po okresie retencji nie zostaje ani jeden
   niezamaskowany adres.
2. **`down()` takiej migracji nie umiałby nic przywrócić.** `AGENTS.md` wymaga
   działającego wycofania; migracja nadpisująca dane jest nieodwracalna
   z definicji, a nieodwracalna migracja udająca odwracalną jest gorsza niż
   jej brak.
3. **Kasowanie i nadpisywanie danych na produkcji wymaga osobnej, jawnej zgody
   właściciela** (zasady floty). Zatwierdzone zostało maskowanie zapisu, nie
   operacja na istniejących wierszach.

Gdyby właściciel zdecydował inaczej, jest to bezpieczne: jednorazowy
`UPDATE sessions SET ip_address = …` nikogo nie wylogowuje (kolumny nie czyta
ani framework, ani nasz kod), ale jest nieodwracalny i dlatego ma być osobną,
wyraźną decyzją, a nie skutkiem ubocznym tej zmiany.

Nie ma też ekranu „aktywne urządzenia" ani wykrywania przejęcia sesji —
gdyby kiedyś powstały, będą czytały ZGRUBNY adres i to trzeba wiedzieć przed
projektowaniem takiego ekranu.

**Rollback.** Ta zmiana **nie dotyka schematu** — nie ma czego wycofywać
migracją. Wycofanie samego zachowania to zdjęcie rejestracji
`Session::extend('database', …)` z `AppServiceProvider` (wracają pełne adresy)
oraz zdjęcie zadania z `routes/console.php` (wraca sama loteria). Adresy
zamaskowane w międzyczasie **nie wracają** do pełnej postaci i wrócić nie mogą.
