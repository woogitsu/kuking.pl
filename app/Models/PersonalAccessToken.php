<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token osobistego dostępu aplikacji mobilnej (D-014, D-270).
 *
 * TEN WIERSZ JEST POŚWIADCZENIEM: kto ma jawną postać tokenu, działa na
 * koncie. W bazie leży wyłącznie SHA-256 sekretu; wartość jawna istnieje
 * przez jedno wywołanie `User::createToken()` i idzie prosto w odpowiedź
 * HTTP — nie do logu, nie do dziennika audytu.
 *
 * Różnice względem modelu z pakietu, każda z powodem:
 *
 *  - UUID zamiast kolejnego numeru (uzasadnienie w migracji);
 *  - `$fillable` BEZ `token` (AGENTS.md §7: żadnego poświadczenia
 *    w `$fillable`). Pakiet wpisuje tam `token`, bo sam zapisuje wiersz
 *    przez `create()`; u nas zapis idzie przez `User::createToken()`
 *    z `forceFill()`, więc masowe przypisanie skrótu nie ma skąd przyjść;
 *  - `findToken()` odrzuca identyfikator, który nie jest UUID-em, ZANIM
 *    zapyta bazę. Pakiet woła `find($id)` na czymkolwiek, co stoi przed `|`,
 *    a PostgreSQL na `WHERE id = 'abc'` przy kolumnie `uuid` rzuca błędem
 *    składni — czyli dowolny śmieć w nagłówku `Authorization` dawał 500
 *    zamiast 401.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;

    protected $table = 'personal_access_tokens';

    /**
     * Nazwa urządzenia i termin. Skrót tokenu i uprawnienia zapisuje wyłącznie
     * `User::createToken()`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @param  string  $token
     */
    public static function findToken($token)
    {
        if (! str_contains($token, '|')) {
            // Bez identyfikatora nie szukamy po samym skrócie: każdy token
            // wydany przez ten serwis ma postać `<uuid>|<sekret>`.
            return null;
        }

        [$id, $sekret] = explode('|', $token, 2);

        if (! Str::isUuid($id) || $sekret === '') {
            return null;
        }

        $wiersz = static::query()->find($id);

        if (! $wiersz instanceof self) {
            return null;
        }

        return hash_equals($wiersz->token, hash('sha256', $sekret)) ? $wiersz : null;
    }
}
