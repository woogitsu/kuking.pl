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
