<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * „Odłóż na później” / „Wróć do pracy” przy własnym szkicu przepisu (#2550, V2).
 *
 * Prywatne oznaczenie organizacyjne, NIE status: `status`, widoczność, treść,
 * zdjęcia, kroki, składniki, wersje i `updated_at` zostają bez zmian (odłożenie
 * nie udaje edycji treści, więc nie przesuwa szkicu w kolejności listy).
 * Niczego nie publikuje i nikogo nie powiadamia.
 *
 * Ustawia ŻĄDANY stan, nie przełącza: to samo żądanie wysłane dwa razy daje
 * ten sam wynik. Konto i wiersz przepisu są czytane pod blokadą, po świeżej
 * kontroli konta i autorstwa — także przy wołaniu wprost z domeny, nie tylko
 * z trasy. Blokada wiersza przepisu to ta sama, którą bierze `PublishRecipe`,
 * więc publikacja i odłożenie nie mijają się po cichu.
 *
 * Konflikt kart: formularz niesie znacznik stanu, który człowiek widział
 * (wartość `odlozony_at` albo pusty). Gdy stan zmienił się w innej karcie,
 * żądanie jest odrzucane z komunikatem — nowsza decyzja nie jest nadpisywana.
 */
final class OdlozSzkicPrzepisu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    /** Przepis jest już opublikowany albo zamrożony przez moderację — nie ma czego odkładać. */
    public const NIE_SZKIC = 'nie_szkic';

    public const BRAK = 'brak';

    /** Format znacznika stanu w formularzu (mikrosekundy odróżniają dwie decyzje w jednej sekundzie). */
    public const FORMAT_ZNACZNIKA = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::NIE_SZKIC|self::BRAK
     */
    public function handle(User $user, string $idPrzepisu, bool $odloz, ?string $widzianyZnacznik): string
    {
        return DB::transaction(function () use ($user, $idPrzepisu, $odloz, $widzianyZnacznik): string {
            $swiezy = AktywneKontoPlanu::podBlokada($user);

            // Cudzy szkic jest dla pytającego tym samym, co nieistniejący.
            $przepis = Recipe::query()
                ->whereKey($idPrzepisu)
                ->where('author_id', $swiezy->getKey())
                ->lockForUpdate()
                ->first();

            if ($przepis === null) {
                return self::BRAK;
            }

            if ($przepis->status !== Recipe::STATUS_DRAFT || $przepis->published_at !== null) {
                return self::NIE_SZKIC;
            }

            if (($przepis->odlozony_at !== null) === $odloz) {
                return self::JUZ_TAK_BYLO;
            }

            if (self::znacznik($przepis) !== ($widzianyZnacznik ?? '')) {
                return self::KONFLIKT;
            }

            // Wprost, nie przez `$fillable`: oznaczenie jest decyzją tej akcji.
            // `updated_at` bez zmian — to nie edycja treści szkicu.
            $przepis->timestamps = false;
            $przepis->odlozony_at = $odloz ? now() : null;
            $przepis->save();

            return self::ZASTOSOWANO;
        });
    }

    public static function znacznik(Recipe $przepis): string
    {
        return $przepis->odlozony_at?->utc()->format(self::FORMAT_ZNACZNIKA) ?? '';
    }
}
