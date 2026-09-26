<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Recipes\StrazPochodzeniaPrzepisu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\User;

/**
 * Reguły szkicu z importu przy każdym zapisie przez `PublishRecipe` (D-300):
 *
 *  1. szkic z ADRESU STRONY ma źródło zablokowane: `source_type = external`
 *     i `source_url` = adres, z którego go pobraliśmy — kreator ani formularz
 *     nie zamienią go na „Mój własny" bez śladu;
 *  2. publikacja szkicu z importu wymaga zaznaczenia „Sprawdziłem odczytany
 *     tekst" (atrybut `sprawdzilem_odczyt`) — raz; po pierwszej publikacji
 *     znacznik zostaje zapisany i tekst źródła jest czyszczony.
 */
final class StrazImportu implements StrazPochodzeniaPrzepisu
{
    public const KOMUNIKAT_SPRAWDZ = 'Zanim opublikujesz, porównaj odczytany tekst ze źródłem i zaznacz '
        .'„Sprawdziłem odczytany tekst”. Nic nie zginęło — szkic jest zapisany.';

    public function przedZapisem(User $author, Recipe $existing, array $attributes, bool $publish): array
    {
        $pochodzenie = PrzepisZImportu::query()->find($existing->getKey());

        if ($pochodzenie === null) {
            return $attributes;
        }

        if ($pochodzenie->zrodlo === PrzepisZImportu::ZRODLO_URL) {
            $attributes['source_type'] = Recipe::SOURCE_EXTERNAL;
            $attributes['source_url'] = $pochodzenie->source_url;
        }

        if ($publish
            && $existing->status === Recipe::STATUS_DRAFT
            && ! $pochodzenie->sprawdzony()
            && ($attributes['sprawdzilem_odczyt'] ?? false) !== true) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_SPRAWDZ);
        }

        return $attributes;
    }

    public function poPublikacji(Recipe $recipe): void
    {
        PrzepisZImportu::query()
            ->whereKey($recipe->getKey())
            ->update(['sprawdzone_at' => now(), 'tekst_zrodla' => null, 'updated_at' => now()]);
    }
}
