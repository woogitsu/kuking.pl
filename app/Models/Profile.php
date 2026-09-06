<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Publiczna twarz użytkownika. Klucz główny to user_id (jeden profil na konto).
 */
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'username',
        'display_name',
        'bio',
        'avatar_media_id',
        'region',
        'speciality',
    ];

    /**
     * Profil po nazwie użytkownika, BEZ rozróżniania wielkości liter.
     *
     * PostgreSQL porównuje teksty z rozróżnianiem wielkości, a klawiatura
     * telefonu podnosi pierwszą literę bez pytania. `ProfileController`
     * radził sobie z tym od audytu A25, ale `SocialController`
     * i `OnboardingController` pytały zwykłym `where('username', ...)` —
     * więc `/@Halina` otwierało profil, a `/@Halina/obserwujacy` dawało 404.
     * Ta sama klasa błędu, którą A25 uznał za wartą naprawy, naprawiona
     * wtedy w jednym miejscu z trzech.
     *
     * Zapytanie trafia w unikalny indeks funkcyjny `lower(username)`
     * (migracja `2026_09_05_220000`), więc nie jest to skan tabeli.
     */
    public static function poNazwie(string $username): ?self
    {
        return self::whereRaw('lower(username) = ?', [mb_strtolower(trim($username))])->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    /** Adres profilu: /@basia */
    public function url(): string
    {
        return route('profile.show', ['username' => $this->username]);
    }
}
