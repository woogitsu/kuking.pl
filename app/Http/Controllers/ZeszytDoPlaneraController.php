<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Planer\Actions\DodajZestawDoPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Models\Collection;
use App\Models\User;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * „Zaplanuj wybrane przepisy" z własnego zeszytu na jeden dzień własnego
 * planera (V2, #2483). Wybór -> podgląd -> jawne zatwierdzenie; wszystkie
 * reguły (zakres dat, limit dnia, widoczność przepisów, duplikaty) siedzą
 * w `DodajZestawDoPlanu`. Zwykłe formularze, bez JavaScriptu.
 */
class ZeszytDoPlaneraController extends Controller
{
    public function __construct(
        private readonly DodajZestawDoPlanu $zestaw,
        private readonly WidocznaZawartoscZeszytu $zawartosc,
    ) {}

    /** Krok 1: lista przepisów z TEJ strony zeszytu i pole dnia. */
    public function form(Request $request, Collection $collection): View
    {
        $this->authorize('planuj', $collection);

        /** @var User $user */
        $user = $request->user();

        $przepisy = $this->zawartosc->przepisy($collection, $user)
            ->published()
            ->select(['recipes.id', 'recipes.title', 'recipes.slug'])
            ->paginate(KolejnoscPrzepisow::NA_STRONE);

        return view('pages.collections.do-planera', [
            'collection' => $collection,
            'przepisy' => $przepisy,
        ]);
    }

    /** Krok 2: podgląd (nazwy, liczba nowych pozycji). Niczego nie zapisuje. */
    public function podglad(Request $request, Collection $collection): View|RedirectResponse
    {
        $this->authorize('planuj', $collection);

        // „Zmień wybór” z podglądu: wracamy do formularza z zachowanym dniem
        // i zaznaczeniami, bez liczenia czegokolwiek.
        if ($request->input('akcja') === 'zmien') {
            return $this->wrocDoFormularza($request, $collection, null);
        }

        $dane = $this->waliduj($request);

        try {
            $podglad = $this->zestaw->podglad(
                $request->user(),
                $collection,
                CarbonImmutable::createFromFormat('!Y-m-d', $dane['dzien']),
                $dane['przepisy'],
            );
        } catch (ValidationException $e) {
            return $this->wrocDoFormularza($request, $collection, $e);
        }

        return $this->widokPodgladu($collection, $dane, $podglad, null);
    }

    /** Krok 3: zatwierdzenie. Zapis tylko, gdy wynik zgadza się z podglądem. */
    public function store(Request $request, Collection $collection): View|RedirectResponse
    {
        $this->authorize('planuj', $collection);

        $dane = $this->waliduj($request, true);
        $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $dane['dzien']);

        try {
            $wynik = $this->zestaw->zatwierdz($request->user(), $collection, $dzien, $dane['przepisy'], (string) $dane['odcisk']);
        } catch (ValidationException $e) {
            return $this->wrocDoFormularza($request, $collection, $e);
        }

        if (! $wynik['zapisano']) {
            return $this->widokPodgladu($collection, $dane, $wynik['podglad'], 'Zestaw albo dostęp do przepisów zmienił się od chwili podglądu, więc nic nie zostało dodane. Sprawdź aktualny podgląd i zatwierdź jeszcze raz.');
        }

        $kiedy = PlanerTygodnia::naDzien($dzien);
        $zdania = [];
        if ($wynik['dodano'] > 0) {
            $zdania[] = 'Dodane do planu na '.$kiedy.': '.$wynik['dodano'].' '
                .Odmiana::rzeczownik($wynik['dodano'], 'przepis', 'przepisy', 'przepisów').'.';
        } else {
            $zdania[] = 'Nic nowego nie zostało dodane do planu na '.$kiedy.'.';
        }
        if ($wynik['juz_bylo'] > 0) {
            $zdania[] = 'Już były w planie i zostały bez zmian: '.$wynik['juz_bylo'].'.';
        }
        if ($wynik['niedostepne'] > 0) {
            $zdania[] = 'Pominięte, bo przestały być dostępne: '.$wynik['niedostepne'].'.';
        }

        $komunikat = implode(' ', $zdania);

        return redirect()->route('planer.show', ['tydzien' => $dzien->toDateString()])
            ->with($wynik['dodano'] > 0 ? Komunikat::sukces($komunikat) : Komunikat::informacja($komunikat));
    }

    /**
     * @return array{dzien: string, przepisy: list<string>, strona?: string, odcisk?: string}
     */
    private function waliduj(Request $request, bool $zOdciskiem = false): array
    {
        /** @var array{dzien: string, przepisy: list<string>, strona?: string, odcisk?: string} $dane */
        $dane = $request->validate([
            'dzien' => ['required', 'date_format:Y-m-d'],
            'przepisy' => ['required', 'array', 'min:1', 'max:'.DodajZestawDoPlanu::MAKS_WYBRANYCH],
            'przepisy.*' => ['uuid'],
            'strona' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'odcisk' => $zOdciskiem ? ['required', 'string', 'size:64'] : ['nullable'],
        ], [
            'dzien.required' => 'Wybierz dzień, na który planujesz.',
            'dzien.date_format' => 'Wybierz dzień z kalendarza i zaplanuj jeszcze raz.',
            'przepisy.required' => 'Zaznacz przynajmniej jeden przepis, który chcesz zaplanować.',
            'przepisy.min' => 'Zaznacz przynajmniej jeden przepis, który chcesz zaplanować.',
            'przepisy.max' => 'Zaznaczono za dużo przepisów naraz. Zostaw najwyżej '.DodajZestawDoPlanu::MAKS_WYBRANYCH.'.',
            'przepisy.*.uuid' => 'Nie znamy takiego przepisu. Wróć do zeszytu i zaznacz go jeszcze raz.',
            'odcisk.required' => 'Podgląd jest nieaktualny. Wybierz przepisy jeszcze raz.',
            'odcisk.size' => 'Podgląd jest nieaktualny. Wybierz przepisy jeszcze raz.',
        ]);

        return $dane;
    }

    private function wrocDoFormularza(Request $request, Collection $collection, ?ValidationException $e): RedirectResponse
    {
        $strona = (int) $request->input('strona', 1);

        $powrot = redirect()
            ->route('collections.planer', ['collection' => $collection] + ($strona > 1 ? ['page' => $strona] : []))
            ->withInput($request->only('dzien', 'przepisy'));

        return $e === null ? $powrot : $powrot->withErrors($e->errors());
    }

    /**
     * @param  array{dzien: string, przepisy: list<string>, strona?: string, odcisk?: string}  $dane
     * @param  array<string, mixed>  $podglad
     */
    private function widokPodgladu(Collection $collection, array $dane, array $podglad, ?string $ostrzezenie): View
    {
        $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $dane['dzien']);

        return view('pages.collections.do-planera-podglad', [
            'collection' => $collection,
            'dzien' => $dzien,
            'kiedy' => PlanerTygodnia::naDzien($dzien),
            'podglad' => $podglad,
            // Wszystkie wybrane identyfikatory, także te, które przestały być
            // dostępne: odcisk liczy i je (jako liczbę), więc zatwierdzenie musi
            // dostać dokładnie ten sam wybór co podgląd.
            'wybrane' => $dane['przepisy'],
            'strona' => (int) ($dane['strona'] ?? 1),
            'ostrzezenie' => $ostrzezenie,
        ]);
    }
}
