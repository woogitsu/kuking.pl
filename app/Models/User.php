<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

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

    public const ROLE_USER = 'user';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'email',
        'password',
        'locale',
        'text_scale',
        'wants_weekly_digest',
        'age_confirmed_at',
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
     * konto założone jako „Jan@example.com" jest nie do zalogowania przez
     * „jan@example.com" — z komunikatem sugerującym złe hasło, więc osoba
     * szuka problemu w zupełnie złym miejscu.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizeEmail($value),
        );
    }

    /**
     * Jedna definicja tego, czym jest „ten sam adres e-mail".
     *
     * Mutator wyżej zapisuje adres małymi literami, ale WALIDACJA pytała bazę
     * o wartość surową — więc `Rule::unique` nie znajdowało nic dla
     * „Jan@Example.com", zapis przechodził dalej i dopiero PostgreSQL odbijał
     * duplikat kluczem unikalnym. Efekt: HTTP 500 na rejestracji, w miejscu,
     * w którym powinien być zwykły komunikat „na ten adres jest już konto"
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

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'age_confirmed_at' => 'datetime',
            'delete_requested_at' => 'datetime',
            'status_expires_at' => 'datetime',
            'wants_weekly_digest' => 'boolean',
            'text_scale' => 'integer',
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

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('created_at');
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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
        return $this->collections()->firstOrCreate(
            ['is_default' => true],
            ['name' => 'Zapisane', 'visibility' => 'private'],
        );
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

    // ---------------------------------------------------------------------
    // Zmiany stanu konta
    //
    // `status` i `role` są CELOWO poza $fillable — nie wolno ich ustawić
    // masowym przypisaniem z danych żądania. Zmiana stanu konta jest zawsze
    // jawną, nazwaną operacją, nie efektem ubocznym update().
    // ---------------------------------------------------------------------

    public function markForDeletion(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING_DELETE,
            'delete_requested_at' => now(),
        ])->save();
    }

    public function cancelDeletion(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'delete_requested_at' => null,
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
     * Wyrzucenie użytkownika ze WSZYSTKICH aktywnych sesji.
     *
     * Bez tego zmiana `status` była tylko wpisem w kolumnie: osoba zbanowana
     * za nękanie działała dalej, dopóki nie wylogowała się sama. Przy
     * SESSION_LIFETIME=10080 to jest siedem dni (issue #39).
     *
     * Czyścimy tabelę `sessions` bezpośrednio, bo unieważniamy sesje CUDZE,
     * z innych przeglądarek — `Auth::logout()` dotyczy tylko bieżącego żądania,
     * a moderator nie siedzi w sesji karanego użytkownika.
     *
     * Przy sterowniku innym niż `database` (w testach bywa `array`) tabeli po
     * prostu nie ma i nie ma czego kasować — samo sprawdzenie statusu przy
     * każdym żądaniu i tak odcina dostęp.
     */
    private function invalidateSessions(): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->delete();
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
