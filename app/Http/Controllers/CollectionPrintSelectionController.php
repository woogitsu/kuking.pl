<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * „Wybierz przepisy do wydruku” (#2463) — lista tytułów z polami wyboru, z
 * której zwykły formularz GET prowadzi do `collections.print` z wybranymi
 * przepisami. Bez JavaScriptu.
 *
 * DOSTĘP TO `CollectionPolicy::view()` jak przy samym wydruku; lista to ten
 * sam zakres co wydruk (`WidocznaZawartoscZeszytu::przepisy()`: widoczność,
 * blokady, status przepisu i konta autora), w tej samej kolejności. Czytamy
 * SAME tytuły i identyfikatory — nie renderujemy całej biblioteki, a koszt
 * to jedno zapytanie niezależnie od liczby przepisów. Przy bardzo dużym
 * zeszycie lista jest obcięta do `MAKS_NA_LISCIE`, zdaniem o tym.
 *
 * Identyfikator w żądaniu niczego nie autoryzuje: wybór trafia do wydruku,
 * który sam sprawdza dostęp i widoczność każdej pozycji od nowa.
 */
class CollectionPrintSelectionController extends Controller
{
    public const MAKS_NA_LISCIE = 500;

    /** Opcje wydruku, które wybór ma przenieść dalej bez zmian. */
    private const OPCJE = ['bez-zdjec', 'bez-notatek'];

    public function __construct(
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
    ) {}

    public function __invoke(Request $request, Collection $collection): View
    {
        $this->authorize('view', $collection);

        $widz = $request->user();

        $pozycje = $this->zawartosc->przepisy($collection, $widz)
            ->reorder()
            ->orderByRaw('collection_items.position ASC NULLS LAST')
            ->orderBy('recipes.title_search')
            ->orderBy('recipes.id')
            ->limit(self::MAKS_NA_LISCIE + 1)
            ->get(['recipes.id', 'recipes.title']);

        $obcieto = $pozycje->count() > self::MAKS_NA_LISCIE;
        $pozycje = $pozycje->take(self::MAKS_NA_LISCIE)->values();

        $zaznaczone = [];
        $surowe = $request->query('przepisy');
        if (is_array($surowe)) {
            foreach ($surowe as $wartosc) {
                if (is_string($wartosc) && Str::isUuid($wartosc)) {
                    $zaznaczone[strtolower($wartosc)] = true;
                }
            }
        }

        $opcje = [];
        foreach (self::OPCJE as $nazwa) {
            if ($request->boolean($nazwa)) {
                $opcje[$nazwa] = 1;
            }
        }

        return view('pages.collections.do-druku-wybor', [
            'collection' => $collection,
            'pozycje' => $pozycje,
            'obcieto' => $obcieto,
            'maks' => self::MAKS_NA_LISCIE,
            'limit' => max(1, (int) config('kuking.collections.print_max_recipes', 100)),
            'zaznaczone' => $zaznaczone,
            'opcje' => $opcje,
        ]);
    }
}
