<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\KonfliktKolejnosci;
use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Wróć do kolejności zapisu" (#2544): zeruje ręczne pozycje przepisów
 * w zeszycie, więc lista znów idzie od najnowszego zapisu. To jedyna droga
 * powrotu do zwykłego porządku, dlatego ręczne układanie jest odwracalne.
 *
 * Te same zabezpieczenia co przesunięcie: zamek, Policy `reorder` na świeżym
 * stanie i odcisk układu — człowiek potwierdził zapomnienie TEGO układu, który
 * widział, nie układu zmienionego w drugiej karcie. Przepisy, notatki i daty
 * zapisów zostają nietknięte.
 */
final class PrzywrocKolejnoscZapisu
{
    public function __construct(private readonly ZamekZapisuDoZeszytu $zamek) {}

    /**
     * @return int ile przepisów miało ręczną pozycję
     *
     * @throws KonfliktKolejnosci gdy układ zmienił się od wyświetlenia strony
     */
    public function handle(User $user, Collection $zeszyt, ?string $odcisk): int
    {
        return $this->zamek->mutuj($user, $zeszyt, function (User $aktor, Collection $cel) use ($odcisk): int {
            Gate::forUser($aktor)->authorize('reorder', $cel);

            if ($odcisk === null || ! hash_equals(KolejnoscPrzepisow::odcisk(KolejnoscPrzepisow::uklad($cel)), $odcisk)) {
                throw new KonfliktKolejnosci;
            }

            return DB::table('collection_items')
                ->where('collection_id', $cel->getKey())
                ->whereNotNull('position')
                ->update(['position' => null]);
        });
    }
}
