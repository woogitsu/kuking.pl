<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\PantryItem;
use Illuminate\Support\Facades\DB;

/** Usuwa produkt tylko w zakresie opakowań pokazanym w potwierdzeniu. */
final class UsunProduktPoPotwierdzeniu
{
    public const USUNIETO = 'usunieto';

    public const ZMIENILO_SIE = 'zmienilo_sie';

    public const BRAK = 'brak';

    /** `brak` albo UUID drugiego opakowania z otwartego formularza. */
    public function handle(PantryItem $produkt, ?string $widzianeDrugie): string
    {
        return DB::transaction(function () use ($produkt, $widzianeDrugie): string {
            // Ta sama blokada produktu co przy dodaniu i usunięciu jednego
            // opakowania. Nie ma okna między porównaniem zakresu a DELETE.
            $swiezy = PantryItem::query()
                ->whereKey($produkt->getKey())
                ->where('user_id', $produkt->user_id)
                ->lockForUpdate()
                ->first();

            if ($swiezy === null) {
                return self::BRAK;
            }

            $aktualneDrugie = DB::table('pantry_second_packages')
                ->where('pantry_item_id', $swiezy->getKey())
                ->value('id');
            $aktualnyZakres = $aktualneDrugie === null ? 'brak' : (string) $aktualneDrugie;

            if ($widzianeDrugie === null || $widzianeDrugie !== $aktualnyZakres) {
                return self::ZMIENILO_SIE;
            }

            $swiezy->delete();

            return self::USUNIETO;
        });
    }
}
