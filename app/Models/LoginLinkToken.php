<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Skrot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Jednorazowy token logowania linkiem e-mail (issue #25, D-056).
 *
 * TEN WIERSZ JEST HASŁEM JEDNORAZOWYM. Kto ma token, wchodzi na konto —
 * dlatego w bazie leży wyłącznie jego SKRÓT, a wartość jawna istnieje przez
 * jedno wywołanie `wystaw()`, w którym idzie prosto do listu i nigdzie się
 * nie odkłada. Nie ma jej w logu, w dzienniku audytu ani w powiadomieniu
 * zapisanym w bazie.
 *
 * Wiersz znika na PIĘĆ sposobów i to jest pełna lista:
 *  1. użycie linku — `LoginLinkController::store()` kasuje wiersz w tej samej
 *     transakcji, w której loguje (jednorazowość);
 *  2. kolejna prośba tej samej osoby — `user_id` jest unikalne, więc nowy
 *     link unieważnia poprzedni (`WyslijLinkDoLogowania`);
 *  3. zmiana albo reset hasła — przez `User::invalidateSessions()`;
 *  4. „Wyloguj mnie z innych urządzeń", blokada, zawieszenie i zgłoszenie
 *     usunięcia konta — tą samą drogą co wyżej;
 *  5. wygaśnięcie — sprzątane przy okazji następnej prośby o link
 *     (`WyslijLinkDoLogowania::posprzatajPrzedawnione()`).
 *
 * $fillable NIE ISTNIEJE i to jest celowe: wiersz zapisuje wyłącznie
 * `WyslijLinkDoLogowania`, jawnie, pole po polu. Domyślny `$guarded = ['*']`
 * Eloquenta odrzuca tu każde masowe przypisanie — ta sama zasada co
 * w `PendingEmailChange` i co przy `status`/`role` w `User` (AGENTS.md §7).
 */
class LoginLinkToken extends Model
{
    use HasUuids;

    protected $table = 'login_link_tokens';

    /**
     * Tabela ma `created_at`, ale NIE ma `updated_at` — wiersza się nie
     * edytuje. Nowa prośba zastępuje stary wiersz w całości, użycie go
     * kasuje. Ten sam wybór co w `PendingEmailChange` i `audit_log`.
     */
    public const UPDATED_AT = null;

    /**
     * Długość tokenu w postaci jawnej.
     *
     * 64 znaki z alfabetu `Str::random()` ([A-Za-z0-9]) to około 380 bitów —
     * grubo ponad wszystko, co ma sens zgadywać. Wysoka entropia jest tu
     * warunkiem, pod którym wolno trzymać SZYBKI skrót zamiast bcrypta
     * (patrz migracja): nie ma czego spowalniać, skoro nie ma czego zgadywać.
     */
    public const DLUGOSC_TOKENU = 64;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Nowy token w postaci jawnej — do wstawienia w link i do niczego innego. */
    public static function nowyToken(): string
    {
        return Str::random(self::DLUGOSC_TOKENU);
    }

    /**
     * Skrót tokenu — jedyna postać, w jakiej token wolno gdziekolwiek zapisać.
     *
     * `Skrot::hmac()`, czyli ta sama konstrukcja co przy `audit_log.ip_hash`
     * i przy kluczach limitera logowania. Jedno miejsce, bo dwa pomysły na
     * „ten sam skrót" to brak skrótu — ta lekcja jest opisana w `Skrot`.
     */
    public static function skrot(string $token): string
    {
        return Skrot::hmac($token);
    }

    /**
     * Wiersz pasujący do tokenu z adresu — albo `null`.
     *
     * Kształt wartości sprawdzamy PRZED pytaniem bazy: link ucięty przez
     * klienta pocztowego albo przepisany z błędem nie ma po co jechać do
     * Postgresa, a i tak ma skończyć się tym samym ekranem co token wygasły.
     */
    public static function znajdzPoTokenie(string $token): ?self
    {
        if (! self::wygladaJakToken($token)) {
            return null;
        }

        return self::query()->where('token_hash', self::skrot($token))->first();
    }

    public static function wygladaJakToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{'.self::DLUGOSC_TOKENU.'}$/', $token) === 1;
    }

    /**
     * Czy ten token jest jeszcze ważny.
     *
     * Pytamy o to W KODZIE, mimo że przedawnione wiersze i tak są sprzątane:
     * sprzątanie jest higieną danych, nie bramką bezpieczeństwa — link ma
     * przestać działać co do minuty. Ten sam podział ról co przy
     * `PendingEmailChange::jestWazne()`.
     */
    public function jestWazny(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
