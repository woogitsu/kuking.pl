<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * „Wydrukuj zeszyt” (#2351, F7) — cały zeszyt jako rodzinna książka:
 * okładka, spis treści, każdy przepis na osobnej kartce.
 *
 * TO JEST ZWYKŁA STRONA HTML, NIE PLIK. Drukuje ją przeglądarka (Ctrl+P,
 * „Zapisz jako PDF”) tym samym arkuszem co „Drukuj przepis” (#765,
 * `resources/css/wydruk-przepisu.css`). Serwerowy generator PDF odpada:
 * nowa zależność i nowy koszt, a przeglądarka robi to za darmo.
 *
 * DOSTĘP TO `CollectionPolicy::view()` — przy KAŻDYM wejściu, także przy
 * wydruku: cofnięty dostęp do wspólnego zeszytu zamyka i ekran zeszytu,
 * i wydruk (403), bez pamięci o wcześniejszym wygenerowaniu. UUID w adresie
 * nie jest autoryzacją.
 *
 * W ŚRODKU WYŁĄCZNIE TO, CO WIDZI OGLĄDAJĄCY: te same zakresy co ekran
 * zeszytu (`WidocznaZawartoscZeszytu::przepisy()` — widoczność, blokady
 * w obie strony, status przepisu i konta autora). Przepis niedostępny jest
 * pomijany w całości: bez tytułu, bez autora, bez pozycji w spisie. Liczba
 * wykonań i notatki liczą się tak samo per oglądający.
 *
 * KOLEJNOŚĆ JEST DETERMINISTYCZNA: alfabetycznie po znormalizowanym tytule
 * (`title_search`), remis rozstrzyga `id`. Książka ma mieć spis, który po
 * dwóch wydrukach wygląda tak samo; kolejność „od najnowszych” z ekranu
 * zeszytu zmieniałaby się przy każdym zapisie.
 *
 * WYJĄTEK: RĘCZNA KOLEJNOŚĆ (#2544, D-333). Gdy właściciel ułożył przepisy
 * w zeszycie, wydruk idzie jego kolejnością (zupa → danie → deser): najpierw
 * przepisy z pozycją, rosnąco, potem — gdyby jakiś jej nie miał — reszta
 * alfabetycznie. W zeszycie, którego nikt nie układał, nic się nie zmienia.
 * Dotyczy to wszystkich oglądających wydruk, bo układ jest częścią zeszytu.
 *
 * LIMIT: zeszyt nie ma górnej granicy liczby przepisów, a strona idzie
 * jednym żądaniem. Pierwsze `kuking.collections.print_max_recipes` pozycji;
 * reszta jest zapowiedziana zdaniem (nic nie znika po cichu).
 */
class CollectionPrintController extends Controller
{
    public function __construct(
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
    ) {}

    public function __invoke(Request $request, Collection $collection): View
    {
        $this->authorize('view', $collection);

        $widz = $request->user();
        $limit = max(1, (int) config('kuking.collections.print_max_recipes', 100));

        // Jedno zapytanie o przepisy + po jednym o relacje (autor, profil,
        // zdjęcie, składniki, kroki) i licznik wykonań w podzapytaniu:
        // liczba zapytań nie zależy od liczby przepisów.
        $przepisy = $this->zawartosc->przepisy($collection, $widz)
            ->reorder()
            ->orderByRaw('collection_items.position ASC NULLS LAST')
            ->orderBy('recipes.title_search')
            ->orderBy('recipes.id')
            ->with(['author.profile', 'heroMedia', 'ingredients', 'steps'])
            ->withCount(['cookedEvents as widoczne_wykonania_count' => fn ($q) => $q->widoczneDla($widz)])
            ->limit($limit + 1)
            ->get();

        $obcieto = $przepisy->count() > $limit;
        $przepisy = $przepisy->take($limit)->values();

        // Notatki przy pozycjach widzą tylko osoby z dostępem (właściciel
        // i współpracownicy) — tak jak na ekranie zeszytu. Obcy oglądający
        // publiczny zeszyt dostaje sam przepis.
        $dostepDoNotatek = $widz !== null
            && ($widz->getKey() === $collection->owner_id || Gate::forUser($widz)->allows('removeItem', $collection));

        return view('pages.collections.do-druku', [
            'collection' => $collection,
            'przepisy' => $przepisy,
            'obcieto' => $obcieto,
            'kolejnoscReczna' => KolejnoscPrzepisow::jestUlozony($collection),
            'limit' => $limit,
            'dostepDoNotatek' => $dostepDoNotatek,
            'zeZdjeciami' => ! $request->boolean('bez-zdjec'),
            'dataWydruku' => Carbon::now('Europe/Warsaw')->translatedFormat('j F Y'),
        ]);
    }
}
