<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Udostepnienia;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;

/**
 * Koniec jednego udostępnienia przepisu (#2650): autor odbiera dostęp,
 * odbiorca sam z niego rezygnuje, autor usuwa przepis.
 *
 * WIERSZ ZNIKA, NIE ZMIENIA STANU. Udostępnienie bez wiersza nie istnieje,
 * więc nie ma stanu „odebrane", który ktoś mógłby po cichu przywrócić —
 * ani odblokowanie, ani zmiana widoczności, ani przywrócenie przepisu przez
 * moderację. Ponowny dostęp to ponowne, świadome udostępnienie.
 *
 * OD RAZU. `RecipePolicy::readShared()` pyta bazę przy KAŻDYM żądaniu, bez
 * pamięci podręcznej, a strony z przepisem idą z `private, no-store`.
 * Następne żądanie po zatwierdzeniu `DELETE` dostaje odmowę. Wcześniej
 * wystawiony podpisany adres zdjęcia działa do końca swojej ważności
 * (`kuking.media.signed_url_minutes`, domyślnie 5 minut) — tego nie
 * obiecujemy cofnąć, a nowego adresu po odebraniu już nikt nie dostanie.
 */
final class OdbierzDostepDoPrzepisu
{
    /** Autor odbiera dostęp. Wolno ZAWSZE autorowi — także zawieszonemu: to zawęża, nie rozszerza. */
    public function odbierz(User $autor, RecipeShare $udostepnienie): bool
    {
        $przepis = Recipe::withTrashed()->find($udostepnienie->recipe_id);

        if ($przepis === null || $przepis->author_id !== $autor->getKey()) {
            throw new BladDlaCzlowieka('Dostęp do przepisu może odebrać tylko jego autor.');
        }

        return RecipeShare::query()->whereKey($udostepnienie->getKey())->delete() > 0;
    }

    /** Odbiorca sam rezygnuje z dostępu. */
    public function zrezygnuj(User $odbiorca, RecipeShare $udostepnienie): bool
    {
        if ($udostepnienie->recipient_id !== $odbiorca->getKey()) {
            throw new BladDlaCzlowieka('Z dostępu może zrezygnować tylko osoba, której przepis udostępniono.');
        }

        return RecipeShare::query()->whereKey($udostepnienie->getKey())->delete() > 0;
    }

    /**
     * Autor usuwa przepis: udostępnienia znikają razem z nim, a nie czekają
     * w bazie na trwałe usunięcie wiersza `recipes`.
     */
    public function wszystkieDlaPrzepisu(Recipe $przepis): void
    {
        RecipeShare::query()->where('recipe_id', $przepis->getKey())->delete();
    }
}
