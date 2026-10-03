<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Udostepnienia;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** Potwierdzenie jednej osoby, jednego przepisu i jednej wersji formularza. */
final class PotwierdzenieOdbiorcy
{
    private const WERSJA = 1;

    private const MINUTY = 15;

    private const ZAKRES = 'odczyt_jednego_przepisu';

    public static function wystaw(User $autor, Recipe $przepis, User $odbiorca): string
    {
        return Crypt::encryptString(json_encode([
            'wersja' => self::WERSJA,
            'zakres' => self::ZAKRES,
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'odbiorca' => (string) $odbiorca->getKey(),
            'nazwa' => $odbiorca->profile?->username,
            'wazne_do' => now()->addMinutes(self::MINUTY)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{odbiorca: string, nazwa: string}|null */
    public static function odczytaj(string $token, User $autor, Recipe $przepis): ?array
    {
        try {
            $dane = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($dane)
            || ($dane['wersja'] ?? null) !== self::WERSJA
            || ($dane['zakres'] ?? null) !== self::ZAKRES
            || ($dane['autor'] ?? null) !== (string) $autor->getKey()
            || ($dane['przepis'] ?? null) !== (string) $przepis->getKey()
            || ! is_string($dane['odbiorca'] ?? null)
            || ! is_string($dane['nazwa'] ?? null)
            || $dane['nazwa'] === ''
            || ! is_int($dane['wazne_do'] ?? null)
            || $dane['wazne_do'] <= now()->timestamp) {
            return null;
        }

        return ['odbiorca' => $dane['odbiorca'], 'nazwa' => $dane['nazwa']];
    }
}
