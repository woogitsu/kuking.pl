<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * Czy opis przygotowania szkicu z adresu jest „prawie taki sam jak na stronie
 * źródłowej" (projekt §4 pkt 4; decyzja właściciela 26.09: OSTRZEŻENIE przy
 * publikacji, nie blokada).
 *
 * Miarą jest `similarity()` z `pg_trgm` — to rozszerzenie już jest (D-004),
 * więc nie dokładamy biblioteki. Porównujemy obecny tekst kroków z tekstem
 * zapamiętanym przy imporcie; próg w `kuking.import.podobienstwo_ostrzezenie`.
 */
final class PodobienstwoDoZrodla
{
    /** Podobieństwo 0–1 albo `null`, gdy przepis nie jest szkicem z adresu. */
    public function wynik(Recipe $recipe, string $obecneKroki): ?float
    {
        $pochodzenie = PrzepisZImportu::query()->find($recipe->getKey());

        if ($pochodzenie === null || $pochodzenie->zrodlo !== PrzepisZImportu::ZRODLO_URL) {
            return null;
        }

        $zrodlo = trim((string) $pochodzenie->tekst_zrodla);
        $obecneKroki = trim($obecneKroki);

        if ($zrodlo === '' || $obecneKroki === '') {
            return null;
        }

        $wiersz = DB::selectOne('SELECT similarity(lower(?), lower(?)) AS s', [$obecneKroki, $zrodlo]);

        return (float) $wiersz->s;
    }

    public function ostrzegac(Recipe $recipe, string $obecneKroki): bool
    {
        $wynik = $this->wynik($recipe, $obecneKroki);

        return $wynik !== null && $wynik >= (float) config('kuking.import.podobienstwo_ostrzezenie', 0.6);
    }
}
