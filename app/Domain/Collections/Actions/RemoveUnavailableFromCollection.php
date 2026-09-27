<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wyjęcie z JEDNEGO zeszytu zapisów, których właściciel już nie widzi (#773).
 *
 * Ekran mówi „3 zapisy nie są dla Ciebie dostępne" i do tej zmiany jedyną
 * drogą pozbycia się ich było usunięcie całego zeszytu — razem ze wszystkim,
 * co w nim widać.
 *
 * CZEGO TA AKCJA NIE ROBI
 *  - nie rusza samej treści ani cudzych zeszytów: kasuje wyłącznie wiersze
 *    `collection_items` tego zeszytu,
 *  - nie działa sama z siebie: zawężony albo zablokowany przepis nadal wraca
 *    do zeszytu, gdy dostęp wróci — dopóki właściciel świadomie go nie wyjmie,
 *  - nie ujawnia, co było wyjęte: liczy i kasuje po identyfikatorach.
 *
 * ZAKRES Z CHWILI POTWIERDZENIA, NIE Z CHWILI WYKONANIA
 * Formularz niesie odcisk zbioru, który człowiek widział. Pod zamkiem wiersza
 * zeszytu liczymy zbiór jeszcze raz; jeśli się różni (coś wróciło, coś nowego
 * zniknęło), odmawiamy zamiast po cichu wyjąć inną grupę pozycji.
 */
final class RemoveUnavailableFromCollection
{
    public function __construct(private readonly WidocznaZawartoscZeszytu $zawartosc) {}

    /**
     * @return int ile zapisów wyjęto
     */
    public function handle(User $user, Collection $collection, string $odcisk): int
    {
        Gate::forUser($user)->authorize('update', $collection);

        return DB::transaction(function () use ($user, $collection, $odcisk): int {
            Collection::query()->whereKey($collection->getKey())->lockForUpdate()->first();

            $niedostepne = $this->zawartosc->niedostepne($collection, $user);
            $ile = count($niedostepne['przepisy']) + count($niedostepne['wpisy']);

            if ($ile === 0) {
                return 0;
            }

            if (! hash_equals($this->zawartosc->odcisk($collection, $niedostepne), $odcisk)) {
                throw new BladDlaCzlowieka(
                    'Od otwarcia tego zeszytu zmieniło się, które zapisy są niedostępne. '
                    .'Niczego nie wyjęliśmy. Sprawdź nową liczbę poniżej i potwierdź jeszcze raz.',
                );
            }

            $pozycje = DB::table('collection_items')->where('collection_id', $collection->getKey());

            return (clone $pozycje)->whereIn('recipe_id', $niedostepne['przepisy'])->delete()
                + (clone $pozycje)->whereIn('post_id', $niedostepne['wpisy'])->delete();
        });
    }
}
