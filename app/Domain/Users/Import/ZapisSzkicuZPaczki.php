<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

use App\Models\Recipe;
use App\Models\User;

/**
 * Kontrakt po stronie wołającego: wczytanie paczki (`WczytajPaczke`) zapisuje
 * przepis jako prywatny szkic, ale NIE zna modułu Recipes. Gdyby importowało
 * `PublishRecipe`, zamknęłoby cykl Users → Recipes → Posts → Moderation → Users
 * (test `GrafModulowDomenyBezCykliTest`, #2149). Implementacja mieszka
 * w module niżej, a wiąże ją `AppServiceProvider` — jak
 * `App\Domain\Users\ObserwowanieGospodarza`.
 */
interface ZapisSzkicuZPaczki
{
    /**
     * @param  array<string, mixed>  $dane  tytuł, opis, składniki i kroki z paczki
     */
    public function zapisz(User $autor, array $dane): Recipe;
}
