<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\UstawienieNowegoHasla;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Konto użytkownika.
 *
 * Uwaga: dane publiczne (username, imię, avatar) są w App\Models\Profile.
 * Tu jest tylko to, co dotyczy logowania, ustawień i stanu konta.
 */
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_BANNED = 'banned';

    public const STATUS_PENDING_DELETE = 'pending_delete';

    /**
     * STAN KOŃCOWY: karencja wykonana, dane osobowe wymazane (D-022).
     *
     * OSOBNA WARTOŚĆ, A NIE `pending_delete` NA ZAWSZE — i to jest cała
     * naprawa D-018. Te dwa stany różnią się w jedynej rzeczy, która tu
     * cokolwiek znaczy:
     *
     *  - `pending_delete` — karencja trwa, treści są SCHOWANE, bo człowiek
     *    poprosił o usunięcie i jeszcze da się to cofnąć;
     *  - `erased` — karencja wykonana, konta nie da się zalogować, odzyskać
     *    ani wyszukać, ale zanonimizowany TEKST jest widoczny (o ile człowiek
     *    nie wybrał `delete_scope = everything`).
     *
     * Baza pilnuje równoważności `(data_erased_at IS NOT NULL) = (status =
     * 'erased')`, więc tego stanu nie da się ustawić bez faktycznego
     * wymazania danych — ani odwrotnie.
     */
    public const STATUS_ERASED = 'erased';

    /**
     * ZAKRES USUNIĘCIA, WYBRANY PRZEZ CZŁOWIEKA I ZAPISANY PRZY ŻĄDANIU
     * (D-022). Domyślny jest `minimum`, bo domyślna opcja ma być tą, której
     * skutków nie da się cofnąć w mniejszym stopniu: zostawiony tekst da się
     * skasować później, skasowanego nie da się przywrócić.
     */
    public const DELETE_SCOPE_MINIMUM = 'minimum';

    /** Pełne usunięcie: razem z przepisami, wpisami, komentarzami i wykonaniami. */
    public const DELETE_SCOPE_EVERYTHING = 'everything';

    /**
     * Statusy, przy których TREŚĆ konta jest ukryta przed wszystkimi poza
     * autorem i moderatorem.
     *
     * JEDNA DEFINICJA DLA WSZYSTKICH WARSTW — Policy, zakresy zapytań
     * i powiadomienia pytały o to samo czterema własnymi `in_array`/`whereIn`.
     * Dokładnie tak powstała usterka D-022: dodano `data_erased_at`, a te
     * cztery listy zostały nietknięte.
     *
     * `erased` TU NIE WCHODZI (D-022): tekst wymazanego konta zostaje
     * widoczny. `suspended` też nie — zawieszenie jest karą za pisanie i nie
     * kasuje tego, co ktoś już napisał.
     *
     * @var list<string>
     */
    public const STATUSY_UKRYWAJACE_TRESC = [
        self::STATUS_BANNED,
        self::STATUS_PENDING_DELETE,
    ];

    /**
     * Statusy konta ZAMKNIĘTEGO: nie wpuszczamy go do serwisu i nie
     * pokazujemy go jako OSOBY — ani w wyszukiwarce, ani na liście
     * obserwujących, ani w mapie strony, ani w liczbach analitycznych.
     *
     * Różnica wobec `STATUSY_UKRYWAJACE_TRESC` to `erased` i jest ona
     * sednem D-022: tekst zostaje, człowiek nie. Karta osoby z linkiem do
     * profilu konta, którego już nie ma, jest zaproszeniem w martwe miejsce;
     * podpis „Użytkownik usunięty" pod przepisem — nie jest.
     *
     * @var list<string>
     */
    public const STATUSY_ZAMKNIETEGO_KONTA = [
        self::STATUS_BANNED,
        self::STATUS_PENDING_DELETE,
        self::STATUS_ERASED,
    ];

    /**
     * Stany konta po polsku — jedno źródło dla panelu moderacji.
     *
     * KOLEJNOŚĆ NIE JEST ALFABETYCZNA, TYLKO OD NAJLŻEJSZEGO DO NAJCIĘŻSZEGO
     * — tak samo wypadają zakładki filtra na `/admin/uzytkownicy`. Lista
     * ułożona po nazwie stawiałaby „Usunięte" przed „Aktywne", a kolejność
     * na ekranie ma nieść znaczenie, nie alfabet.
     *
     * „Zablokowane", nie „Zbanowane" (`docs/brand/COPY_STYLE.md` — piszemy
     * po polsku), i „W trakcie usuwania" osobno od „Usunięte": różnica między
     * `pending_delete` a `erased` jest tu widoczna, bo przy pierwszym można
     * jeszcze zmienić zdanie, a przy drugim już nie (D-022).
     *
     * @var array<string, string>
     */
    public const ETYKIETY_STATUSU = [
        self::STATUS_ACTIVE => 'Aktywne',
        self::STATUS_SUSPENDED => 'Zawieszone',
        self::STATUS_PENDING_DELETE => 'W trakcie usuwania',
        self::STATUS_BANNED => 'Zablokowane',
        self::STATUS_ERASED => 'Usunięte',
    ];

    public const ROLE_USER = 'user';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_ADMIN = 'admin';

    /**
     * Role po polsku — do POKAZANIA, nigdy do wyboru z formularza.
     *
     * Rolę nadaje wyłącznie `kuking:nadaj-role` z powłoki (D-039), a `role`
     * nie jest w `$fillable` (AGENTS.md §7). Ta tablica nie jest listą opcji
     * do `<select>` i nie wolno jej w taką listę zamienić — jest podpisem pod
     * kolumną w panelu moderacji.
     *
     * @var array<string, string>
     */
    public const ETYKIETY_ROLI = [
        self::ROLE_USER => 'Użytkownik',
        self::ROLE_MODERATOR => 'Moderator',
        self::ROLE_ADMIN => 'Administrator',
    ];

    /**
     * `email` I `email_verified_at` SĄ TU CELOWO NIEOBECNE (issue #195).
     *
     * Ten sam powód co przy `status` i `role` (AGENTS.md §7): adres e-mail
     * jest jedyną drogą odzyskania konta, więc jego zmiana jest zmianą
     * STANU KONTA, nie edycją profilu. Gdyby stał na tej liście, dowolny
     * dzisiejszy i przyszły `update($request->all())` — także taki, który
     * o adresie w ogóle nie myśli — potrafiłby przestawić konto na cudzą
     * skrzynkę, a stamtąd wystarczy „nie pamiętam hasła".
     *
     * Adres zapisują wyłącznie jawne, nazwane drogi:
     *  - `assignEmail()` niżej (rejestracja i potwierdzona zmiana),
     *  - `App\Domain\Users\Actions\EraseAccountData` (anonimizacja, D-022).
     *
     * `email_verified_at` z tego samego powodu: potwierdzenie adresu ma
     * pochodzić z kliknięcia w link, nie z pola w formularzu.
     * Pilnuje tego `AdresEmailPozaMasowymPrzypisaniemTest`.
     */
    protected $fillable = [
        'password',
        'locale',
        'text_scale',
        'theme',
        'wants_weekly_digest',
        'age_confirmed_at',
        // Wspomnienia „Rok temu gotowałaś…" (issue #34). Preferencja
        // wyświetlania, nie stan konta — dlatego wolno ją tu trzymać,
        // w odróżnieniu od `status` i `role` (AGENTS.md §7).
        'memories_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Adres e-mail zawsze małymi literami i bez spacji.
     *
     * PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a klawiatury
     * telefonów lubią automatycznie kapitalizować pierwszą literę. Bez tego
     * konto założone jako „Jan@example.com” jest nie do zalogowania przez
     * „jan@example.com” — z komunikatem sugerującym złe hasło, więc osoba
     * szuka problemu w zupełnie złym miejscu.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizeEmail($value),
        );
    }

    /**
     * Jedna definicja tego, czym jest „ten sam adres e-mail”.
     *
     * Mutator wyżej zapisuje adres małymi literami, ale WALIDACJA pytała bazę
     * o wartość surową — więc `Rule::unique` nie znajdowało nic dla
     * „Jan@Example.com”, zapis przechodził dalej i dopiero PostgreSQL odbijał
     * duplikat kluczem unikalnym. Efekt: HTTP 500 na rejestracji, w miejscu,
     * w którym powinien być zwykły komunikat „na ten adres jest już konto”
     * (audyt A25).
     *
     * Ta metoda istnieje po to, żeby normalizacja miała JEDNO miejsce.
     * Wcześniej `mb_strtolower(trim(...))` było przepisane w mutatorze
     * i w logowaniu — a przypomnienie sobie o nim przy trzecim wywołaniu
     * (odzyskiwanie hasła) już nie nastąpiło, więc człowiek, który wpisał
     * adres z wielkiej litery, nie dostawał linku do zmiany hasła i nie
     * dowiadywał się dlaczego: odpowiedź jest z założenia ta sama dla adresu
     * istniejącego i nieistniejącego.
     */
    public static function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Konto po tym, co człowiek wpisał w pole „e-mail albo nazwa użytkownika”.
     *
     * Mieszka w modelu, bo pytają o to TRZY miejsca: logowanie
     * (`LoginController`), formularz odwołania dla osób zablokowanych
     * (`AppealController`, #10) i formularz cofnięcia usunięcia konta
     * (`AccountDeletionController`, audyt A8). Dwa ostatnie muszą rozpoznać
     * człowieka PRZED zalogowaniem, bo takie konto do serwisu nie wejdzie.
     * Druga kopia tej logiki rozjechałaby się przy pierwszej zmianie zasad
     * nazewnictwa — a to już raz się zdarzyło (audyt A25).
     *
     * Bez rozróżniania wielkości liter po obu stronach — klawiatura telefonu
     * podnosi pierwszą literę bez pytania.
     */
    public static function findByLogin(string $login): ?self
    {
        if (str_contains($login, '@')) {
            return self::where('email', self::normalizeEmail($login))->first();
        }

        // Od migracji `..._add_username_case_insensitive_unique_index` baza
        // gwarantuje, że pasujący wiersz jest najwyżej jeden.
        return Profile::whereRaw('lower(username) = ?', [mb_strtolower(trim($login))])->first()?->user;
    }

    /**
     * Tagi, które ta osoba obserwuje (D-021 — zastępuje usunięte już
     * `followedTopics()`/`isFollowingTopic()` z issue #31).
     *
     * `withTimestamps()` NIE, bo `tag_follows` ma tylko `created_at` — to
     * relacja, nie encja, i nie ma czego aktualizować. Data przydaje się
     * wyłącznie do pytania „od kiedy", więc ustawiamy ją ręcznie przy
     * podpięciu (ten sam powód, dla którego `followedTopics()` robił to
     * samo). Kolejność alfabetyczna po nazwie: w odróżnieniu od Tematu,
     * tagi nie mają redakcyjnej kolejności (`position`) — to jest atrybut
     * PROMOCJI (`tag_promotions.position`), nie samego tagu.
     */
    public function followedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_follows')
            ->withPivot('created_at')
            ->orderBy('tags.name');
    }

    public function isFollowingTag(Tag $tag): bool
    {
        return $this->followedTags()->whereKey($tag->getKey())->exists();
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'age_confirmed_at' => 'datetime',
            'delete_requested_at' => 'datetime',
            'data_erased_at' => 'datetime',
            'status_expires_at' => 'datetime',
            // Zapisywana WYŁĄCZNIE przez `App\Domain\Analytics\ZanotujOstatniaWizyte`
            // (throttlowany middleware, nigdy formularz) — dlatego nie ma jej
            // w `$fillable`, mimo że to nie jest `status` ani `role`.
            // Masowe przypisanie z żądania nadpisywałoby cudzy znacznik
            // aktywności dowolną wartością podaną w ciele żądania.
            'ostatnio_widziany_at' => 'datetime',
            'wants_weekly_digest' => 'boolean',
            // Kiedy poszło OSTATNIE tygodniowe podsumowanie (issue #11).
            // Poza `$fillable` z tego samego powodu co `ostatnio_widziany_at`
            // wyżej: zapisuje to WYŁĄCZNIE komenda wysyłkowa
            // (`App\Domain\Digest\OdbiorcyDigestu::oznaczWyslane()`), nigdy
            // formularz. Masowe przypisanie z żądania pozwalałoby przestawić
            // cudzy znacznik i albo wyprosić kogoś z tygodniowej wysyłki,
            // albo — cofając datę — wysłać mu drugi list w tym samym
            // tygodniu, wbrew obietnicy „nigdy więcej niż jeden".
            'weekly_digest_sent_at' => 'datetime',
            'text_scale' => 'integer',
            'memories_enabled' => 'boolean',
            'is_seeded' => 'boolean',

            // Sekret i kody zapasowe 2FA są zaszyfrowane W BAZIE (nie tylko
            // w transporcie) — wyciek kopii bazy nie może oddawać drugiego
            // składnika logowania (AGENTS.md §7, issue #12).
            //
            // `two_factor_last_used_at` NIE jest czasem w rozumieniu reszty
            // modelu — to surowy licznik z biblioteki TOTP, patrz migracja
            // `..._add_two_factor_to_users_table`. Zostaje bez castu.
            'two_factor_secret' => 'encrypted',
            'two_factor_backup_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Relacje
    // ---------------------------------------------------------------------

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'author_id');
    }

    public function cookedEvents(): HasMany
    {
        return $this->hasMany(CookedEvent::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'owner_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'owner_id');
    }

    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    /**
     * Zamówiona, ale jeszcze niepotwierdzona zmiana adresu (issue #195).
     *
     * `HasOne`, bo `pending_email_changes.user_id` jest unikalne: jedno
     * konto ma najwyżej jedno oczekujące żądanie, a nowe zastępuje stare.
     */
    public function pendingEmailChange(): HasOne
    {
        return $this->hasOne(PendingEmailChange::class);
    }

    public function notifications(): HasMany
    {
        // Drugi klucz sortowania — powiadomienia sypią się seriami w tej
        // samej sekundzie, a lista jest paginowana. Uzasadnienie:
        // `Recipe::cookedEvents()`.
        return $this->hasMany(Notification::class)
            ->latest('created_at')
            ->latest('id');
    }

    /** Osoby, które TEN użytkownik obserwuje. */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id')
            ->withPivot('created_at');
    }

    /** Osoby, które obserwują TEGO użytkownika. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id')
            ->withPivot('created_at');
    }

    /** Osoby zablokowane PRZEZ tego użytkownika. */
    public function blocking(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blocks', 'blocker_id', 'blocked_id')
            ->withPivot('created_at');
    }

    /** Osoby, które zablokowały TEGO użytkownika. */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blocks', 'blocked_id', 'blocker_id')
            ->withPivot('created_at');
    }

    // ---------------------------------------------------------------------
    // Pytania o stan konta
    // ---------------------------------------------------------------------

    /**
     * Czy ten człowiek może jeszcze CZYTAĆ serwis.
     *
     * `isActive()` odpowiada na inne pytanie: czy konto może DZIAŁAĆ.
     * Zawieszenie jest karą czasową i tylko na pisanie — `EnsureAccountIsActive`
     * i `LoginController` wpuszczają zawieszonych właśnie po to, żeby mogli
     * czytać. Bramka w `NotifyUser` pytała jednak o `isActive()`, więc konto
     * zawieszone nie dostawało ŻADNEGO powiadomienia: nie opóźnionego,
     * tylko nieistniejącego.
     *
     * Skutek był asymetryczny wobec kary. Ktoś ugotował z przepisu Haliny
     * w czwartym dniu jej tygodniowego zawieszenia; Halina nie dowiedziała
     * się o tym nigdy. „Ugotowałem" jest najcenniejszym sygnałem w tym
     * produkcie i jedynym powodem, dla którego ludzie tu publikują —
     * a skasowała go kara, która miała dotyczyć wyłącznie pisania.
     */
    public function mozeCzytac(): bool
    {
        return ! in_array($this->status, self::STATUSY_ZAMKNIETEGO_KONTA, true);
    }

    /**
     * Czy treści tej osoby wolno w ogóle komukolwiek pokazywać.
     *
     * TA SAMA REGUŁA CO `mozeCzytac()`, ale patrzona z drugiej strony: tamta
     * odpowiada „czy ta osoba może czytać serwis", ta — „czy jej wpisy i
     * przepisy mogą się komuś wyświetlić". Zbiór statusów jest ten sam
     * (`banned` i `pending_delete` odpadają, `suspended` zostaje: kara za
     * pisanie nie kasuje tego, co już napisał), więc jest jedna definicja.
     *
     * WYNIK NIE JEST JUŻ IDENTYCZNY Z `mozeCzytac()` I TO JEST NAPRAWA D-022.
     * Te dwie metody rozjeżdżają się dokładnie na jednym statusie: `erased`.
     * Konto z wymazanymi danymi NIE MOŻE czytać serwisu (nie da się do niego
     * wejść), ale JEST dostępne jako autor — jego zanonimizowany tekst
     * zostaje widoczny, bo to właśnie obiecało D-018.
     *
     * Dopóki obie metody odpowiadały tym samym zbiorem statusów, jedna
     * z nich była niepotrzebna. Teraz obie są konieczne, a pomylenie ich ma
     * widoczny skutek — dlatego nazwy mówią, o KOGO pytamy: `mozeCzytac()`
     * o widza, `jestDostepnyJakoAutor()` o autora oglądanej treści.
     */
    public function jestDostepnyJakoAutor(): bool
    {
        return ! in_array($this->status, self::STATUSY_UKRYWAJACE_TRESC, true);
    }

    /**
     * Czy tę osobę wolno pokazać JAKO OSOBĘ — na liście obserwujących,
     * w wyszukiwarce ludzi, w mapie strony, w liczniku nad listą (D-022).
     *
     * TRZECIA GRANICA, POTRZEBNA OD MOMENTU, W KTÓRYM POWSTAŁ `erased`.
     * `dostepnyJakoAutor()` obsługiwał dotąd oba pytania, bo odpowiedź była
     * ta sama. Od D-022 nie jest: podpis „Użytkownik usunięty" pod przepisem
     * ma zostać, ale karta z awatarem i linkiem do profilu na liście
     * „obserwujący" — nie. Karta osoby jest zaproszeniem do relacji
     * (obserwuj, napisz), a tej relacji nie da się już z nikim nawiązać:
     * `UserPolicy::follow()` wymaga `isActive()`.
     *
     * To ta sama klasa błędu, którą zamknęło W5-08 — tylko z drugiej strony.
     * Wtedy treść była mniej dostępna przez drzwi niż przez okno; tu byłaby
     * BARDZIEJ.
     */
    public function jestWidocznyJakoOsoba(): bool
    {
        return ! in_array($this->status, self::STATUSY_ZAMKNIETEGO_KONTA, true);
    }

    public function isErased(): bool
    {
        return $this->status === self::STATUS_ERASED;
    }

    /**
     * Konto z treści zalążkowej (`database/seeders/dane/tresc-zalazkowa.json`,
     * D-025) — atrybut POCHODZENIA danych, tego samego kształtu co
     * `Tag::is_seeded` (patrz komentarz migracji `..._add_is_seeded_to_users`).
     *
     * Nie zmienia NICZEGO w tym, co to konto może czytać/pisać — o tym
     * decydują `status` i `role`, jak zawsze. Dwa jedyne miejsca, które o to
     * pytają: widoki, żeby pokazać etykietę „Konto przykładowe" przy autorze
     * (D-025: „przy koncie, nie tylko w regulaminie" — profil, karta wpisu,
     * karta przepisu, komentarz), i `App\Domain\Analytics\CookEligibility`,
     * żeby wykluczyć te konta z Weekly Active Cooks tak samo, jak już są
     * wykluczone konta testowe i gospodarz (issue #114).
     */
    public function isSeeded(): bool
    {
        return (bool) $this->is_seeded;
    }

    /**
     * Czy człowiek poprosił o usunięcie RAZEM Z TREŚCIAMI (D-022).
     *
     * Czytane przy egzekucji karencji, nie w chwili kliknięcia — dlatego
     * wybór jest w kolumnie, a nie w żądaniu HTTP.
     *
     * `NULL` znaczy `minimum` I TO JEST ŚWIADOMA, BEZPIECZNA STRONA BŁĘDU.
     * Porównujemy wprost do `everything`, więc każda wartość inna od niej —
     * w tym brak wartości na koncie sprzed tej zmiany — daje „zostaw
     * teksty". Odwrotna konwencja (`!== minimum`) kasowałaby czyjeś przepisy
     * przy każdej niespodziance w danych, a to jest dokładnie ten kierunek
     * pomyłki, którego nie da się odwrócić.
     */
    public function chceUsunacTresci(): bool
    {
        return $this->delete_scope === self::DELETE_SCOPE_EVERYTHING;
    }

    /**
     * Ta sama reguła W ZAPYTANIU — i to jest cały powód istnienia tej metody
     * (audyt W5-08, W5-09).
     *
     * Reguła „autor dostępny" żyła dotąd WYŁĄCZNIE jako powtórzony warunek
     * w trzech politykach (`RecipePolicy`, `PostPolicy`, `UserPolicy`), po
     * jednym `in_array(...)` w każdej. Polityka pilnuje jednak WEJŚCIA NA
     * JEDNĄ TREŚĆ. Listy — zeszyt, mapa strony, profil — budują własne
     * zapytania i tej reguły nie miały skąd wziąć.
     *
     * Skutek był taki, że wejście na `/przepisy/{slug}` autora zbanowanego
     * dawało 403, ale ten sam przepis dalej stał w cudzym zeszycie z tytułem,
     * nazwiskiem i miniaturą, a mapa strony podawała jego adres Google'owi.
     * Treść była mniej dostępna przez drzwi frontowe niż przez okno.
     *
     * Jedno miejsce dla obu warstw. Kto doda czwarty status, poprawi tutaj —
     * i poprawi wszędzie.
     *
     * @param  Builder<User>  $query
     */
    public function scopeDostepnyJakoAutor(Builder $query): void
    {
        $query->whereNotIn('status', self::STATUSY_UKRYWAJACE_TRESC);
    }

    /**
     * `jestWidocznyJakoOsoba()` W ZAPYTANIU — dla list ludzi i liczników
     * nad nimi (D-022).
     *
     * Istnieje z tego samego powodu co zakres wyżej: reguła trzymana tylko
     * w metodzie na modelu nie ma jak trafić do zapytania budującego listę.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWidocznyJakoOsoba(Builder $query): void
    {
        $query->whereNotIn('status', self::STATUSY_ZAMKNIETEGO_KONTA);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Stan konta po polsku (panel moderacji).
     *
     * Zapasowo surowa wartość, a nie „nieznany": gdyby ktoś kiedyś dołożył
     * szósty status i zapomniał o etykiecie, moderator ma zobaczyć, CO tam
     * naprawdę stoi, zamiast napisu ukrywającego przed nim stan konta.
     */
    public function statusLabel(): string
    {
        return self::ETYKIETY_STATUSU[$this->status] ?? (string) $this->status;
    }

    /** Rola po polsku (panel moderacji). Ten sam zapas co przy statusie. */
    public function roleLabel(): string
    {
        return self::ETYKIETY_ROLI[$this->role] ?? (string) $this->role;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isBanned(): bool
    {
        return $this->status === self::STATUS_BANNED;
    }

    /**
     * Czy kara już minęła.
     *
     * Zadanie w harmonogramie chodzi raz na jakiś czas, więc między upływem
     * terminu a przywróceniem dostępu jest okno. Middleware pyta o to przy
     * każdym żądaniu, żeby użytkownik nie czekał na cron — jeśli termin minął,
     * konto wraca do `active` od razu, przy pierwszej próbie wejścia.
     */
    public function punishmentHasExpired(): bool
    {
        return $this->isSuspended()
            && $this->status_expires_at !== null
            && $this->status_expires_at->isPast();
    }

    public function isModerator(): bool
    {
        return in_array($this->role, [self::ROLE_MODERATOR, self::ROLE_ADMIN], true);
    }

    /**
     * Czy 2FA jest naprawdę WŁĄCZONE na tym koncie (issue #12).
     *
     * Sekret bywa zapisany PRZED potwierdzeniem — ekran włączenia zapisuje go,
     * żeby przetrwał odświeżenie strony, zanim człowiek zdąży wpisać pierwszy
     * poprawny kod. Dopóki `two_factor_confirmed_at` jest NULL, logowanie
     * i panel moderacji mają się zachowywać tak, jakby 2FA nie istniało —
     * inaczej porzucony w połowie ekran włączenia zablokowałby dostęp bez
     * jednego działającego kodu.
     */
    public function hasTwoFactorConfirmed(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Czy między tymi dwiema osobami istnieje blokada — w KTÓRĄKOLWIEK stronę.
     *
     * Blokada jest zawsze obustronna w skutkach: jeśli A zablokował B, to ani
     * A nie widzi treści B, ani B nie widzi treści A. Inaczej blokada byłaby
     * tylko połowiczną ochroną.
     */
    public function hasBlockRelationWith(?self $other): bool
    {
        if ($other === null || $other->getKey() === $this->getKey()) {
            return false;
        }

        return Block::query()
            ->where(function ($query) use ($other): void {
                $query->where('blocker_id', $this->getKey())->where('blocked_id', $other->getKey());
            })
            ->orWhere(function ($query) use ($other): void {
                $query->where('blocker_id', $other->getKey())->where('blocked_id', $this->getKey());
            })
            ->exists();
    }

    public function isFollowing(self $other): bool
    {
        return $this->following()->whereKey($other->getKey())->exists();
    }

    public function hasBlocked(self $other): bool
    {
        return $this->blocking()->whereKey($other->getKey())->exists();
    }

    /** Kolekcja "Zapisane", tworzona przy pierwszym zapisie przepisu. */
    public function defaultCollection(): Collection
    {
        $istniejaca = $this->collections()->where('is_default', true)->first();

        if ($istniejaca !== null) {
            return $istniejaca;
        }

        return $this->collections()->create([
            'name' => $this->wolnaNazwaDomyslnegoZeszytu(),
            'visibility' => 'private',
            'is_default' => true,
        ]);
    }

    /**
     * Nazwa dla domyślnego zeszytu, która nie koliduje z niczym, co ta osoba
     * już ma (issue #43).
     *
     * Od kiedy `collections` ma unikalny indeks na `(owner_id, lower(name))`,
     * ktoś, kto SAM założył wcześniej zeszyt „Zapisane”, nie mógłby zapisać
     * pierwszego przepisu — pierwsze kliknięcie „Zapisuję” kończyłoby się
     * błędem 500 na tworzeniu domyślnego zeszytu.
     *
     * Świadomie NIE przejmujemy tu cudzego zeszytu „Zapisane” jako domyślnego,
     * choć byłoby to kuszące. Po pierwsze, `CollectionPolicy::delete()` nie
     * pozwala usunąć zeszytu domyślnego — czyjś własny zeszyt przestałby się
     * dać skasować. Po drugie, jeśli ten zeszyt jest PUBLICZNY, wszystkie
     * przyszłe zapisy „na potem” trafiałyby do niego na widok publiczny,
     * czego nikt nie zamawiał (docs/DECISIONS.md: zeszyt domyślnie prywatny).
     * Zakładamy więc nowy, pod pierwszą wolną nazwą.
     */
    private function wolnaNazwaDomyslnegoZeszytu(): string
    {
        $zajete = $this->collections()
            ->pluck('name')
            ->map(fn (string $nazwa): string => mb_strtolower(trim($nazwa)))
            ->all();

        if (! in_array('zapisane', $zajete, true)) {
            return 'Zapisane';
        }

        // Sufiks liczbowy zamiast „Zapisane (kopia)” czy losowego ciągu:
        // ma być od razu widać, że to ten sam rodzaj zeszytu, tylko drugi.
        for ($numer = 2; $numer <= 100; $numer++) {
            if (! in_array('zapisane '.$numer, $zajete, true)) {
                return 'Zapisane '.$numer;
            }
        }

        // Sto zeszytów „Zapisane” to nie jest scenariusz z życia, ale zapis
        // przepisu nie może się wywalić nawet wtedy.
        return 'Zapisane '.Str::lower(Str::random(6));
    }

    /**
     * Licznik w belce u góry.
     *
     * MUSI liczyć dokładnie to, co pokazuje lista (`Notification::scopeVisibleTo`).
     * Licznik „3 nieprzeczytane" nad pustą listą powiadomień wygląda jak
     * zepsuty serwis — a osoba, która właśnie kogoś zablokowała, klika w ten
     * licznik po to, żeby sprawdzić, czy blokada zadziałała.
     */
    public function unreadNotificationsCount(): int
    {
        return $this->notifications()
            ->visibleTo($this)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Treść ostatniej decyzji moderacyjnej skierowanej do tej osoby.
     *
     * Potrzebna poza listą powiadomień, bo osoba zbanowana do serwisu nie
     * wejdzie — jedynym miejscem, w którym cokolwiek od nas przeczyta, jest
     * ekran logowania.
     */
    public function latestModerationMessage(): ?string
    {
        // Relacja `notifications()` jest już posortowana malejąco po dacie,
        // ale to NIE WYSTARCZA: `notifications.created_at` ma w bazie typ
        // `timestamp(0)`, czyli dokładność do SEKUNDY. Dwa powiadomienia
        // moderacyjne wysłane w tej samej sekundzie są dla `ORDER BY
        // created_at DESC` nierozróżnialne i baza oddaje je w dowolnej
        // kolejności — w praktyce w kolejności wstawienia, czyli NAJSTARSZE
        // PIERWSZE.
        //
        // Znalezione przy issue #10 i to nie jest przypadek brzegowy:
        // odpowiedź na odwołanie powstaje w tym samym żądaniu co przywrócenie
        // treści, a przy blokadzie konta ten komunikat jest JEDYNYM, który
        // do człowieka dociera (ekran logowania). Bez rozstrzygnięcia remisu
        // zablokowana osoba czytała po odwołaniu starą decyzję zamiast
        // odpowiedzi na swoje pismo.
        //
        // `id` rozstrzyga remis, bo klucze są UUID-ami w wersji 7 —
        // uporządkowanymi po czasie, więc większy identyfikator znaczy
        // „wstawiony później".
        $ostatnie = $this->notifications()
            ->where('type', Notification::TYPE_MODERATION)
            ->orderByDesc('id')
            ->first();

        $tresc = $ostatnie?->data['message'] ?? null;

        return is_string($tresc) && trim($tresc) !== '' ? $tresc : null;
    }

    // ---------------------------------------------------------------------
    // Listy z systemu
    //
    // Domyślne powiadomienia Laravela są po angielsku (issue #79). List
    // „Reset Password" od nieznanego nadawcy wygląda dla osoby 50+ dokładnie
    // jak phishing, przed którym ostrzega ją bank — a odzyskiwanie hasła to
    // jedyna droga powrotu dla kogoś, kto wypadł z konta.
    //
    // Podmieniamy je TUTAJ, a nie przez `ResetPassword::toMailUsing()`
    // w service providerze: wtedy widać z modelu, co ta osoba naprawdę
    // dostanie, a `Notification::fake()` w testach widzi nasze klasy.
    // ---------------------------------------------------------------------

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new UstawienieNowegoHasla($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new PotwierdzenieAdresu);
    }

    // ---------------------------------------------------------------------
    // Zmiany stanu konta
    //
    // `status` i `role` są CELOWO poza $fillable — nie wolno ich ustawić
    // masowym przypisaniem z danych żądania. Zmiana stanu konta jest zawsze
    // jawną, nazwaną operacją, nie efektem ubocznym update().
    //
    // `is_seeded` jest poza $fillable z tego samego powodu (D-025): jedyne
    // miejsce, które je ustawia, to `TrescZalazkowaSeeder`, i robi to wprost
    // przez `DB::table('users')->insert()`, nie przez formularz.
    // ---------------------------------------------------------------------

    /**
     * Ustawienie adresu e-mail — JEDYNA droga, którą adres trafia na wiersz
     * `users` poza anonimizacją konta (issue #195).
     *
     * `email` jest poza `$fillable`, więc `create()`, `update()`
     * i `firstOrCreate()` po prostu go nie widzą. Ta metoda robi to jawnie
     * i pod nazwą, którą widać w code review — dokładnie tak, jak
     * `suspend()`, `ban()` i `markForDeletion()` robią to ze statusem.
     *
     * NIE ZAPISUJE. Zapis należy do wołającego, bo dwa jedyne miejsca, które
     * tego używają, potrzebują różnych rzeczy: rejestracja składa cały nowy
     * wiersz naraz (adres MUSI być przy pierwszym `save()`, kolumna jest
     * NOT NULL), a potwierdzenie zmiany zapisuje adres razem ze znacznikiem
     * potwierdzenia, w jednej transakcji.
     *
     * `$potwierdzony` mówi, czy adres jest OD RAZU potwierdzony:
     *  - `false` przy rejestracji — nikt jeszcze nie kliknął w nic;
     *  - `true` po kliknięciu w link wysłany na TEN adres, bo kliknięcie
     *    jest dowodem dostępu do skrzynki i drugie potwierdzanie tego
     *    samego byłoby proszeniem człowieka o to samo dwa razy.
     *
     * Normalizacja (małe litery, bez spacji) dzieje się sama, w mutatorze
     * `email()` wyżej — `forceFill` przechodzi przez mutatory.
     */
    public function assignEmail(string $email, bool $potwierdzony = false): static
    {
        return $this->forceFill([
            'email' => $email,
            'email_verified_at' => $potwierdzony ? now() : null,
        ]);
    }

    /**
     * Zgłoszenie usunięcia konta — RAZEM Z WYBRANYM ZAKRESEM (D-022).
     *
     * DLACZEGO ZAKRES ZAPISUJEMY TERAZ, A NIE CZYTAMY PRZY EGZEKUCJI
     * Między zgłoszeniem a wykonaniem mija 30 dni. Ekran, na którym człowiek
     * stawiał haczyk, może już wtedy nie istnieć w tej formie — a jedyny
     * moment, w którym ta osoba naprawdę powiedziała, czego chce, jest tutaj.
     *
     * Domyślnie `minimum`: znikają zdjęcia i dane osobowe, tekst zostaje
     * zanonimizowany. Nie jest to wygoda dla serwisu, tylko wybór mniej
     * nieodwracalnej ścieżki dla osoby, która klika w pośpiechu.
     */
    public function markForDeletion(string $scope = self::DELETE_SCOPE_MINIMUM): void
    {
        if (! in_array($scope, [self::DELETE_SCOPE_MINIMUM, self::DELETE_SCOPE_EVERYTHING], true)) {
            throw new \InvalidArgumentException("Nieznany zakres usunięcia konta: {$scope}");
        }

        $this->forceFill([
            'status' => self::STATUS_PENDING_DELETE,
            'delete_requested_at' => now(),
            'delete_scope' => $scope,
        ])->save();

        // Ta sama zasada co przy `ban()`/`suspend()`: zmiana stanu konta, która
        // ma odciąć dostęp, musi kasować sesje z INNYCH przeglądarek, nie tylko
        // bieżącą. Bez tego telefon, na którym ta osoba akurat siedziała, gdy
        // zgłaszała usunięcie na komputerze, działałby dalej aż do wygaśnięcia
        // sesji — przy `SESSION_LIFETIME=10080` to siedem dni „usuniętego"
        // konta, które nadal publikuje (znalezione przy audycie A8, przy okazji
        // punktu o ślepym zaułku kasowania konta — nie było to zgłoszone wprost,
        // ale to ta sama klasa błędu co issue #39).
        $this->invalidateSessions();
    }

    /**
     * Cofnięcie zgłoszenia — razem z zapisanym zakresem.
     *
     * Zakres jest zerowany, bo przestał do czegokolwiek się odnosić: konto
     * wraca do `active`, a CHECK w bazie wiąże `delete_scope` wyłącznie ze
     * stanami usuwania. Kto zgłosi usunięcie ponownie, wybierze na nowo —
     * i to jest poprawne: po miesiącu ta decyzja może być inna.
     */
    public function cancelDeletion(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'delete_requested_at' => null,
            'delete_scope' => null,
        ])->save();
    }

    /**
     * STAN KOŃCOWY: karencja wykonana, dane wymazane (D-022).
     *
     * Osobna, nazwana metoda — a nie kolejne pole w `forceFill` gdzieś
     * w akcji domenowej — bo to jest zmiana STANU KONTA, a te w tym modelu
     * są zawsze jawnie nazwane (AGENTS.md §7). Dodatkowo trzyma razem dwie
     * kolumny, których baza nie pozwala rozdzielić: CHECK wymaga
     * równoważności `(data_erased_at IS NOT NULL) = (status = 'erased')`,
     * więc ustawienie jednej bez drugiej nie przejdzie.
     *
     * Wywołuje ją WYŁĄCZNIE `EraseAccountData`, wewnątrz swojej transakcji
     * i po sprawdzeniu pod blokadą, że jest co wymazywać.
     */
    public function markDataErased(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ERASED,
            'data_erased_at' => now(),
        ])->save();
    }

    /**
     * Zawieszenie konta — domyślnie bezterminowe.
     *
     * `$until` to termin, po którym konto wraca do `active` samo. Bez niego
     * zawieszenie trwa do decyzji człowieka. CHECK w bazie pilnuje, że termin
     * może istnieć wyłącznie przy statusie `suspended` (issue #40).
     */
    public function suspend(?DateTimeInterface $until = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUSPENDED,
            'status_expires_at' => $until,
        ])->save();

        $this->invalidateSessions();
    }

    /**
     * Ban jest bezterminowy z definicji — odwołanie idzie przez ścieżkę
     * odwoławczą (#10), nie przez zegar. Dlatego termin jest tu KASOWANY:
     * gdyby konto było wcześniej zawieszone czasowo, zostawienie terminu
     * złamałoby CHECK i — gorzej — zadanie w harmonogramie przywróciłoby
     * dostęp osobie, którą właśnie zbanowano.
     */
    public function ban(): void
    {
        $this->forceFill([
            'status' => self::STATUS_BANNED,
            'status_expires_at' => null,
        ])->save();

        $this->invalidateSessions();
    }

    /**
     * Przywrócenie konta po odsiedzeniu kary albo po decyzji moderatora.
     */
    public function reinstate(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'status_expires_at' => null,
        ])->save();
    }

    /**
     * Wyrzucenie użytkownika ze WSZYSTKICH aktywnych sesji — albo ze
     * wszystkich OPRÓCZ jednej, gdy $exceptSessionId jest podane.
     *
     * Bez tego zmiana `status` była tylko wpisem w kolumnie: osoba zbanowana
     * za nękanie działała dalej, dopóki nie wylogowała się sama. Przy
     * SESSION_LIFETIME=10080 to jest siedem dni (issue #39).
     *
     * Czyścimy tabelę `sessions` bezpośrednio, bo unieważniamy sesje CUDZE,
     * z innych przeglądarek — `Auth::logout()` dotyczy tylko bieżącego żądania,
     * a moderator nie siedzi w sesji karanego użytkownika.
     *
     * $exceptSessionId istnieje z jednego powodu (issue #12): przy „wyloguj
     * mnie z innych urządzeń” i przy zmianie hasła to sama zainteresowana
     * osoba naciska przycisk, w SWOJEJ, aktualnej sesji — i ta sesja ma
     * zostać ważna. Wylogowanie kogoś z własnej przeglądarki zaraz po tym,
     * jak zrobił dobrą rzecz (ustawił nowe hasło, zamknął dostęp reszcie
     * urządzeń), wyglądałoby jak awaria serwisu, nie jak zabezpieczenie.
     * Przy `ban()`/`suspend()`/`markForDeletion()` nie ma czego wyłączać
     * z kasowania — tam działa moderator albo automat, nie właściciel konta,
     * więc te wywołania zostają bez wyjątku (kasują WSZYSTKO).
     *
     * Przy sterowniku innym niż `database` (w testach bywa `array`) tabeli po
     * prostu nie ma i nie ma czego kasować — samo sprawdzenie statusu przy
     * każdym żądaniu i tak odcina dostęp.
     */
    public function invalidateSessions(?string $exceptSessionId = null): void
    {
        // OCZEKUJĄCY LINK DO LOGOWANIA GINIE RAZEM Z SESJAMI (issue #25, D-056).
        //
        // Ta linijka stoi PRZED wyjściem na `session.driver` niżej i to nie
        // jest przypadek: token logowania leży w bazie niezależnie od tego,
        // czym są trzymane sesje, a przy sterowniku innym niż `database`
        // (tak chodzą testy) wcześniejsze `return` zostawiłoby go żywego.
        //
        // DLACZEGO TUTAJ, A NIE OSOBNYM WYWOŁANIEM W KAŻDYM MIEJSCU
        // Bo link e-mail JEST wejściem na konto — hasłem jednorazowym leżącym
        // w skrzynce. Każda sytuacja, która kasuje cudze sesje, kasuje je
        // z tego samego powodu: „ktoś inny mógł mieć dostęp". Zostawienie
        // wtedy ważnego linku znaczyłoby, że po zmianie hasła i po
        // „wyloguj mnie z innych urządzeń" napastnik dalej ma otwarte drzwi,
        // a właściciel ma złudne poczucie, że sprawa skończona — dokładnie ten
        // błąd, który przy oczekującej zmianie adresu naprawiał #195.
        //
        // Trzy wywołania w trzech kontrolerach dawałyby ten sam skutek do
        // czasu, gdy ktoś dopisze czwarte miejsce i o jednym z nich zapomni.
        // Objęte tą drogą jest więc wszystko naraz: zmiana hasła, reset hasła,
        // „wyloguj mnie z innych urządzeń", blokada, zawieszenie i zgłoszenie
        // usunięcia konta.
        //
        // `$exceptSessionId` NIE MA TU ODPOWIEDNIKA i mieć nie powinien:
        // wyjątek istnieje dla BIEŻĄCEJ przeglądarki osoby, która właśnie
        // zrobiła dobrą rzecz. Niewykorzystany link w skrzynce nie jest
        // niczyją bieżącą przeglądarką.
        $this->invalidateLoginLinks();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->when(
                $exceptSessionId !== null,
                fn ($query) => $query->where('id', '!=', $exceptSessionId),
            )
            ->delete();
    }

    /**
     * Unieważnienie oczekującego linku do logowania (issue #25, D-056).
     *
     * Osobna, nazwana metoda — a nie zapytanie wpisane w środek
     * `invalidateSessions()` — z dwóch powodów: żeby dało się to wywołać
     * samo (np. przy „wyłączam sobie logowanie linkiem"), i żeby nazwa
     * mówiła, co dokładnie znika. Tabela ma najwyżej JEDEN wiersz na konto
     * (`login_link_tokens.user_id` jest unikalne), więc to zawsze najwyżej
     * jedno skasowanie.
     */
    public function invalidateLoginLinks(): void
    {
        LoginLinkToken::query()->where('user_id', $this->getKey())->delete();
    }

    /**
     * Początek włączania 2FA — sekret zapisany, ale JESZCZE NIEPOTWIERDZONY.
     *
     * Zapisujemy sekret od razu (zaszyfrowany, cast `encrypted`), żeby
     * odświeżenie ekranu włączenia albo powrót do niego po chwili pokazywały
     * TEN SAM kod QR — inaczej każde odświeżenie unieważniałoby poprzedni
     * skan i zmuszało do skanowania od nowa. `two_factor_confirmed_at` zostaje
     * NULL, więc konto NIE wymaga jeszcze kodu przy logowaniu ani wejściu
     * do panelu (`hasTwoFactorConfirmed()`).
     */
    public function beginTwoFactorSetup(string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_backup_codes' => null,
            'two_factor_last_used_at' => null,
        ])->save();
    }

    /**
     * Potwierdzenie 2FA pierwszym poprawnym kodem — od teraz konto go wymaga.
     *
     * @param  array<int, string>  $zahaszowaneKodyZapasowe
     */
    public function confirmTwoFactor(array $zahaszowaneKodyZapasowe): void
    {
        $this->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_backup_codes' => $zahaszowaneKodyZapasowe,
        ])->save();
    }

    /**
     * Nowy komplet kodów zapasowych BEZ ruszania sekretu i bez wyłączania 2FA.
     *
     * DLACZEGO TO JEST OSOBNA METODA, A NIE „WYŁĄCZ I WŁĄCZ JESZCZE RAZ"
     * Kody zapasowe pokazujemy raz. Kto ich nie zapisał, miał dotąd jedną
     * drogę do nowych: zdjąć 2FA i włączyć od zera. To znaczy trzy złe rzeczy
     * naraz — konto zostaje przez chwilę na samym haśle, moderator traci
     * w tym czasie wejście do panelu (`EnsureModeratorHasTwoFactor`), a cały
     * sekret trzeba przepisać do telefonu jeszcze raz, choć z nim nic nie
     * było nie tak.
     *
     * Stare kody przestają działać w tej samej chwili — o to właśnie chodzi,
     * bo powodem wymiany bywa „kartka gdzieś jest, tylko nie wiem gdzie".
     *
     * Kontroler MUSI sprawdzić hasło przed wywołaniem (jak przy wyłączaniu).
     *
     * @param  array<int, string>  $zahaszowaneKodyZapasowe
     */
    public function replaceTwoFactorBackupCodes(array $zahaszowaneKodyZapasowe): void
    {
        $this->forceFill(['two_factor_backup_codes' => $zahaszowaneKodyZapasowe])->save();
    }

    /**
     * Wyłączenie 2FA — kontroler MUSI sprawdzić hasło PRZED wywołaniem tej
     * metody (AGENTS.md §7: zmiana stanu konta jest jawną, nazwaną operacją,
     * ale to kontroler odpowiada za to, KTO może ją wywołać).
     */
    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_backup_codes' => null,
            'two_factor_last_used_at' => null,
        ])->save();
    }

    public function promoteTo(string $role): void
    {
        if (! in_array($role, [self::ROLE_USER, self::ROLE_MODERATOR, self::ROLE_ADMIN], true)) {
            throw new \InvalidArgumentException("Nieznana rola: {$role}");
        }

        $this->forceFill(['role' => $role])->save();
    }

    /** @return non-empty-string */
    public function displayName(): string
    {
        return $this->profile?->display_name ?: 'Użytkownik Kuking';
    }
}
