<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
 * NOTATKI Z ZESZYTU (#2438): DOMYŚLNIE WYDRUK JEST BEZ NOTATEK (decyzja
 * właściciela z 2.10.2026, D-333) — kopia trafia zwykle do rodziny, a dopisek
 * bywa prywatny. Osoba z dostępem dołącza notatki jednym kliknięciem „Z
 * notatkami” (jawny parametr `z-notatkami=1`). Wybór niczego nie zmienia
 * w bazie i nie zależy od zdjęć. Pominięcie dotyczy wyłącznie
 * `collection_items.note`, nie uwag autora przepisu.
 *
 * LIMIT: zeszyt nie ma górnej granicy liczby przepisów, a strona idzie
 * jednym żądaniem. Pierwsze `kuking.collections.print_max_recipes` pozycji;
 * reszta jest zapowiedziana zdaniem (nic nie znika po cichu).
 */
class CollectionPrintController extends Controller
{
    /** Więcej identyfikatorów w adresie nie ma sensu: limit wydruku jest dużo niższy. */
    public const MAKS_WYBRANYCH_W_ADRESIE = 500;

    public function __construct(
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
    ) {}

    public function __invoke(Request $request, Collection $collection): View
    {
        $this->authorize('view', $collection);

        $widz = $request->user();
        $limit = max(1, (int) config('kuking.collections.print_max_recipes', 100));

        // WYBRANE PRZEPISY (#2463): domyślnie cały zeszyt. Wybór to lista
        // identyfikatorów z adresu — NIE autoryzacja: wchodzą wyłącznie w
        // zawężenie zakresu `WidocznaZawartoscZeszytu`, więc cudze, spoza
        // zeszytu, usunięte i ukryte identyfikatory po prostu nic nie
        // zwracają (bez tytułu i bez śladu w spisie).
        $wybraneId = $this->wybraneId($request);
        $wybrane = $wybraneId !== null;
        $pustyWybor = $wybrane && $wybraneId === [];

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
            ->when($wybrane, fn ($q) => $q->whereIn('recipes.id', $wybraneId === [] ? [Str::uuid()->toString()] : $wybraneId))
            ->limit($limit + 1)
            ->get();

        $obcieto = $przepisy->count() > $limit;
        $przepisy = $przepisy->take($limit)->values();

        // Ile z wybranych nie jest już dostępnych (zmiana widoczności, usunięcie,
        // cofnięta współpraca między wyborem a podglądem). Tylko liczba, nigdy
        // nazwa. Przy obciętej liście nie da się jej policzyć uczciwie.
        $niedostepneWybrane = $wybrane && ! $obcieto ? max(0, count($wybraneId) - $przepisy->count()) : 0;
        $ileWZeszycie = $wybrane
            ? $this->zawartosc->przepisy($collection, $widz)->reorder()->count()
            : $przepisy->count();

        // Notatki przy pozycjach widzą tylko osoby z dostępem (właściciel
        // i współpracownicy) — tak jak na ekranie zeszytu. Obcy oglądający
        // publiczny zeszyt dostaje sam przepis.
        $dostepDoNotatek = $widz !== null
            && ($widz->getKey() === $collection->owner_id || Gate::forUser($widz)->allows('removeItem', $collection));

        // Wybór „Z notatkami / Bez notatek” (#2438) jest niezależny od zdjęć.
        // Domyślnie BEZ notatek (decyzja właściciela z 2.10.2026, D-333);
        // notatki dołącza wyłącznie jawny parametr `z-notatkami=1`, a i on
        // prawa do notatek nie przyznaje nigdy (`$dostepDoNotatek` rozstrzyga
        // o tym wyłącznie Policy). Przy „bez” notatka nie trafia do zmiennych
        // widoku, więc nie ma jej ani w HTML, ani w ukrytych elementach; samo
        // ukrycie CSS-em by nie wystarczyło. Stary adres z `bez-notatek=1`
        // nadal daje wydruk bez notatek.
        $zNotatkami = $dostepDoNotatek && $request->boolean('z-notatkami');

        return view('pages.collections.do-druku', [
            'collection' => $collection,
            'przepisy' => $przepisy,
            'obcieto' => $obcieto,
            'kolejnoscReczna' => KolejnoscPrzepisow::jestUlozony($collection),
            'limit' => $limit,
            'dostepDoNotatek' => $dostepDoNotatek,
            'zNotatkami' => $zNotatkami,
            'wybrane' => $wybrane,
            'wybraneId' => $wybraneId ?? [],
            'pustyWybor' => $pustyWybor,
            'niedostepneWybrane' => $niedostepneWybrane,
            'ileWZeszycie' => $ileWZeszycie,
            'zeZdjeciami' => ! $request->boolean('bez-zdjec'),
            'dataWydruku' => Carbon::now('Europe/Warsaw')->translatedFormat('j F Y'),
        ]);
    }

    /**
     * Wybór z adresu: `null` = cały zeszyt (brak `przepisy` i `tryb=wybrane`),
     * lista (może być pusta) = wybór. Tylko poprawne UUID-y, bez duplikatów;
     * tablica o dziwnym kształcie lub liczba ponad rozsądek są zawężane, a nie
     * odsyłane z błędem 500. Pusty wybór po `tryb=wybrane` jest osobnym stanem
     * z instrukcją, a nie cichym powrotem do całego zeszytu.
     *
     * @return list<string>|null
     */
    private function wybraneId(Request $request): ?array
    {
        $surowe = $request->query('przepisy');

        if ($surowe === null && $request->query('tryb') !== 'wybrane') {
            return null;
        }

        if (! is_array($surowe)) {
            return [];
        }

        $id = [];

        foreach (array_slice($surowe, 0, self::MAKS_WYBRANYCH_W_ADRESIE) as $wartosc) {
            if (is_string($wartosc) && Str::isUuid($wartosc)) {
                $id[strtolower($wartosc)] = true;
            }
        }

        return array_keys($id);
    }
}
