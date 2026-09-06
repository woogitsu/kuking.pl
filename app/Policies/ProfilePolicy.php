<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Profile;
use App\Models\User;

/**
 * Widoczność profilu jako TREŚCI — dla `App\Domain\Media\DostepDoZdjecia`.
 *
 * Awatar (`profiles.avatar_media_id`) potrzebuje rodzica, którego da się
 * zapytać przez `Gate` tak samo jak wpis czy przepis. Regułę „kto może
 * zobaczyć ten profil" ma już `UserPolicy::viewProfile` i to ona zostaje
 * jedynym źródłem prawdy: tutaj jest wyłącznie przejście z wiersza `profiles`
 * na konto, którego ten profil dotyczy.
 *
 * Gdyby zamiast tego w `DostepDoZdjecia` stanął warunek „awatar widać, gdy
 * konto nie jest zbanowane i nie ma blokady", zmiana w `UserPolicy` nie
 * dosięgłaby awatarów — a to jest dokładnie ten rozjazd między warstwami,
 * który w tym repozytorium wraca najczęściej (audyt W7-02).
 */
class ProfilePolicy
{
    public function view(?User $user, Profile $profile): bool
    {
        // Profil bez konta nie powinien istnieć (klucz obcy z `cascade`),
        // ale odczyt z relacji może zwrócić `null` na wierszu w połowie
        // kasowania konta. Odmowa jest tu jedyną bezpieczną odpowiedzią.
        if ($profile->user === null) {
            return false;
        }

        return app(UserPolicy::class)->viewProfile($user, $profile->user);
    }
}
