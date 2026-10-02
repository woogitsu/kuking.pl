<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Planer\Actions\DodajDoPlanu;
use App\Domain\Planer\Actions\OznaczPozycjePlanu;
use App\Domain\Planer\Actions\SkopiujPoprzedniTydzien;
use App\Domain\Planer\Actions\ZapiszDopisekPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Planer\ZakresDatPlanu;
use App\Domain\Search\SearchQuery;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Support\Czas;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Planer tygodnia (#27, D-310). Prywatny: każda trasa działa wyłącznie na
 * planie zalogowanej osoby, a usunięcie pozycji idzie przez Policy.
 */
class PlanerController extends Controller
{
    private const LIMIT_WYNIKOW_W_PLANACH = 50;

    public function show(Request $request, PlanerTygodnia $planer): View
    {
        $poniedzialek = PlanerTygodnia::poniedzialek($request->query('tydzien'));
        $user = $request->user();

        // Wyszukiwanie przepisu do jednego dnia (#2037): zwykły GET, więc
        // działa bez skryptu, a adres da się wrócić i odświeżyć.
        $szukanyDzien = $request->query('dzien');
        $szukanyDzien = is_string($szukanyDzien) ? $szukanyDzien : null;
        $dni = $planer->tydzien($user, $poniedzialek);
        if ($szukanyDzien !== null && ! array_key_exists($szukanyDzien, $dni)) {
            $szukanyDzien = null;
        }
        $fraza = trim((string) $request->query('q', ''));
        $bladFrazy = null;
        $wyniki = collect();
        if ($szukanyDzien !== null && $fraza !== '') {
            $walidator = SearchQuery::phraseValidator($fraza, 'Jakiego przepisu szukasz?');
            if ($walidator->fails()) {
                $bladFrazy = $walidator->errors()->first('q');
            } elseif (! SearchQuery::jestPrzeszukiwalna($fraza)) {
                $bladFrazy = 'Wpisz co najmniej dwie litery nazwy przepisu i szukaj jeszcze raz.';
            } else {
                // Widoczność liczy wyszukiwarka (blokady, prywatność), a
                // Policy dopina to jeszcze raz — jak przy zapisie.
                $wyniki = app(SearchQuery::class)->recipes($fraza, $user, 8)
                    ->filter(fn (Recipe $r) => $user->can('view', $r))
                    ->values();
            }
        }

        // „Szukaj w moich planach” (#2581): osobny formularz GET i osobny
        // parametr, żeby nie mylić go z `q` (przepis do dodania).
        $wPlanach = $request->query('szukaj_w_planach');
        $wPlanach = is_string($wPlanach) ? trim($wPlanach) : '';
        $szukanoWPlanach = $wPlanach !== '';
        $bladWPlanach = null;
        $wynikiWPlanach = null;
        // Tablicę w adresie zdejmuje globalnie `ParametryAdresuBezTablic`:
        // dla nas to brak frazy, czyli ekran bez wyszukiwania.
        if ($szukanoWPlanach) {
            $walidator = SearchQuery::phraseValidator($wPlanach, 'Szukaj w moich planach');
            if ($walidator->fails()) {
                $bladWPlanach = $walidator->errors()->first('q');
            } elseif (! SearchQuery::jestPrzeszukiwalna($wPlanach)) {
                $bladWPlanach = 'Wpisz co najmniej dwie litery i szukaj jeszcze raz.';
            } else {
                $wynikiWPlanach = $planer->szukajWPlanach($user, $wPlanach, self::LIMIT_WYNIKOW_W_PLANACH);
            }
        }

        return view('pages.planer.show', [
            'wPlanach' => $wPlanach,
            'bladWPlanach' => $bladWPlanach,
            'wynikiWPlanach' => $wynikiWPlanach,
            'limitWPlanach' => self::LIMIT_WYNIKOW_W_PLANACH,
            'szukanyDzien' => $szukanyDzien,
            'fraza' => $fraza,
            'bladFrazy' => $bladFrazy,
            'wyniki' => $wyniki,
            'poniedzialek' => $poniedzialek,
            'dni' => $dni,
            // Przepisy z opisanymi krokami: tylko takie da się dodać do kolejki
            // gotowania (#2450). Jedno zapytanie na cały tydzień.
            'zKrokami' => $this->przepisyZKrokami($dni),
            'dzis' => Czas::dzisiajData(),
            'tenTydzien' => PlanerTygodnia::poniedzialek(null)->equalTo($poniedzialek),
            'poprzedniMaPozycje' => $user->mealPlanEntries()
                ->whereBetween('day', [$poniedzialek->subDays(7)->toDateString(), $poniedzialek->subDay()->toDateString()])
                ->exists(),
            'wpisowNaDzien' => PlanerTygodnia::wpisowNaDzien(),
        ]);
    }

    /**
     * @param  array<string, array{dzien: CarbonImmutable, pozycje: list<array<string, mixed>>}>  $dni
     * @return array<string, bool> identyfikatory przepisów (z dnia) mających co najmniej jeden krok
     */
    private function przepisyZKrokami(array $dni): array
    {
        $idPrzepisow = [];

        foreach ($dni as $dzien) {
            foreach ($dzien['pozycje'] as $pozycja) {
                if ($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS && $pozycja['przepis'] instanceof Recipe) {
                    $idPrzepisow[] = (string) $pozycja['przepis']->getKey();
                }
            }
        }

        if ($idPrzepisow === []) {
            return [];
        }

        return DB::table('recipe_steps')->whereIn('recipe_id', array_unique($idPrzepisow))
            ->distinct()->pluck('recipe_id')
            ->mapWithKeys(fn ($id): array => [(string) $id => true])->all();
    }

    public function store(Request $request, DodajDoPlanu $dodaj): RedirectResponse
    {
        $dane = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'recipe_id' => ['nullable', 'uuid'],
            'label' => ['nullable', 'string'],
            'z_planera' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:'.SearchQuery::MAX_PHRASE_LENGTH],
        ], [
            'day.required' => 'Wybierz dzień, na który planujesz.',
            'day.date_format' => 'Wybierz dzień z listy i dodaj jeszcze raz.',
            'recipe_id.uuid' => 'Nie znamy takiego przepisu. Wróć do przepisu i dodaj go jeszcze raz.',
            'label.string' => 'Wpisz zwykły tekst, np. „obiad u mamy”.',
        ]);

        $przepis = null;
        if (($dane['recipe_id'] ?? null) !== null) {
            $przepis = Recipe::query()->findOrFail($dane['recipe_id']);
            // Identyfikator z żądania NIE JEST autoryzacją (AGENTS.md §7).
            // Bramka stoi TU, a nie tylko w akcji domenowej: tak widzi ją
            // skan tras (`AutoryzacjaTrasZWiazaniemModeluTest`), a akcja
            // pyta o to samo jeszcze raz — bo woła ją też planer.
            $this->authorize('view', $przepis);
        }

        $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $dane['day']);
        $wpis = $dodaj->handle($request->user(), $dzien, $przepis, $przepis === null ? ($dane['label'] ?? '') : null);

        // Biernik po „na” (#2246): „na środę”, nie „na środa”.
        $kiedy = PlanerTygodnia::naDzien($dzien);
        $komunikat = match (true) {
            $wpis === null && $przepis !== null => "Ten przepis już jest w planie na {$kiedy}.",
            $wpis === null => "To już jest w planie na {$kiedy}.",
            $przepis !== null => "Dodane do planu na {$kiedy}.",
            default => "Dopisane na {$kiedy}.",
        };
        // Wpis, który już był w planie, niczego nie dodał — to informacja,
        // nie potwierdzenie (#988).
        $rodzaj = $wpis === null ? Komunikat::informacja($komunikat) : Komunikat::sukces($komunikat);

        // Z wyszukiwania w planerze (#2037) wracamy do TEGO dnia, z tą samą
        // frazą — fokus ląduje na jego panelu, więc od razu można dodać
        // kolejny przepis albo przejść dalej.
        if ($przepis !== null && $request->boolean('z_planera')) {
            return redirect(route('planer.show', array_filter([
                'tydzien' => PlanerTygodnia::poniedzialek($dzien->toDateString())->toDateString(),
                'dzien' => $dzien->toDateString(),
                'q' => $dane['q'] ?? null,
            ])).'#szukaj-'.$dzien->toDateString())->with($rodzaj);
        }

        // Ze strony przepisu wracamy na nią; z planera — do tego tygodnia.
        if ($przepis !== null) {
            return redirect()->back(fallback: route('planer.show', ['tydzien' => $dzien->toDateString()]))
                ->with($rodzaj);
        }

        return redirect()->route('planer.show', ['tydzien' => $dzien->toDateString()])->with($rodzaj);
    }

    public function copy(Request $request, SkopiujPoprzedniTydzien $kopiuj): RedirectResponse
    {
        $poniedzialek = PlanerTygodnia::poniedzialek($request->input('tydzien'));
        $wynik = $kopiuj->handle($request->user(), $poniedzialek);

        $zdania = [];
        if ($wynik['skopiowane'] > 0) {
            $zdania[] = 'Skopiowane z poprzedniego tygodnia: '.$wynik['skopiowane'].' '
                .Odmiana::rzeczownik($wynik['skopiowane'], 'pozycja', 'pozycje', 'pozycji').'.';
        } elseif ($wynik['juz_byly'] > 0 && $wynik['poza_zakresem'] === 0) {
            $zdania[] = 'Wszystko z poprzedniego tygodnia już jest w tym tygodniu.';
        } elseif ($wynik['juz_byly'] > 0) {
            $zdania[] = 'Już w planie: '.$wynik['juz_byly'].' '
                .Odmiana::rzeczownik($wynik['juz_byly'], 'pozycja', 'pozycje', 'pozycji').'.';
        } elseif ($wynik['pominiete'] === 0 && $wynik['poza_zakresem'] === 0) {
            $zdania[] = 'Poprzedni tydzień jest pusty — nie ma czego skopiować.';
        } else {
            $zdania[] = 'Nic nie zostało skopiowane.';
        }
        if ($wynik['pominiete'] > 0) {
            $zdania[] = 'Pominięte: '.$wynik['pominiete'].' '
                .Odmiana::rzeczownik($wynik['pominiete'], 'pozycja', 'pozycje', 'pozycji')
                .' — przepis jest już niedostępny albo dzień ma komplet.';
        }
        if ($wynik['poza_zakresem'] > 0) {
            // Ile, dlaczego i co zrobić (#2036) — zakres z tej samej reguły,
            // która pilnuje zapisu, nie wpisany osobno w zdaniu.
            $zdania[] = 'Poza zakresem dat: '.$wynik['poza_zakresem'].' '
                .Odmiana::rzeczownik($wynik['poza_zakresem'], 'pozycja', 'pozycje', 'pozycji')
                .' — planer przyjmuje dni '.ZakresDatPlanu::opis().'. Wybierz tydzień bliżej dzisiejszego dnia.';
        }

        // Skopiowane cokolwiek — sukces (z dopiskiem, co pominięto). Nic nie
        // skopiowane, bo coś odpadło — błąd z powodem. Nic nie skopiowane,
        // bo nie było czego — informacja.
        $tresc = implode(' ', $zdania);
        $rodzaj = match (true) {
            $wynik['skopiowane'] > 0 => Komunikat::sukces($tresc),
            $wynik['pominiete'] > 0 || $wynik['poza_zakresem'] > 0 => Komunikat::blad($tresc),
            default => Komunikat::informacja($tresc),
        };

        return redirect()->route('planer.show', ['tydzien' => $poniedzialek->toDateString()])
            ->with($rodzaj);
    }

    /**
     * Prywatne „Zrobione” (#2593). Ustawia żądany stan (`zrobione` = 1 albo 0),
     * nie przełącza; formularz niesie znacznik stanu, który człowiek widział,
     * żeby stara karta nie odwróciła nowszej decyzji.
     */
    public function markDone(Request $request, MealPlanEntry $wpis, OznaczPozycjePlanu $oznacz): RedirectResponse
    {
        $this->authorize('markDone', $wpis);

        $dane = $request->validate([
            'zrobione' => ['required', 'in:0,1'],
            'stan' => ['nullable', 'string', 'max:40'],
        ], [
            'zrobione.required' => 'Nie wiemy, co zrobić z tą pozycją. Odśwież stronę i spróbuj jeszcze raz.',
            'zrobione.in' => 'Nie wiemy, co zrobić z tą pozycją. Odśwież stronę i spróbuj jeszcze raz.',
        ]);

        $zrobione = $dane['zrobione'] === '1';
        $wynik = $oznacz->handle($request->user(), (string) $wpis->getKey(), $zrobione, $dane['stan'] ?? null);

        $komunikat = match ($wynik) {
            OznaczPozycjePlanu::ZASTOSOWANO => Komunikat::sukces($zrobione
                ? 'Pozycja jest oznaczona jako zrobiona. Widzisz to tylko Ty.'
                : 'Oznaczenie cofnięte. Pozycja znów czeka w planie.'),
            OznaczPozycjePlanu::JUZ_TAK_BYLO => Komunikat::informacja($zrobione
                ? 'Ta pozycja już jest oznaczona jako zrobiona.'
                : 'Ta pozycja nie jest oznaczona jako zrobiona.'),
            OznaczPozycjePlanu::KONFLIKT => Komunikat::blad('Oznaczenie tej pozycji zmieniło się w innym oknie. Sprawdź jej aktualny stan poniżej i w razie potrzeby kliknij jeszcze raz.'),
            default => Komunikat::blad('Tej pozycji już nie ma w planie. Odśwież stronę.'),
        };

        return redirect()->route('planer.show', ['tydzien' => $wpis->day->toDateString()])
            ->with($komunikat);
    }

    /**
     * Prywatny dopisek przy pozycji z przepisem (#2549). Pusty tekst czyści
     * dopisek. Błąd wraca do pola TEJ pozycji (`_wiersz`) z wpisanym tekstem.
     */
    public function saveNote(Request $request, MealPlanEntry $wpis, ZapiszDopisekPlanu $zapisz): RedirectResponse
    {
        $this->authorize('editNote', $wpis);

        $dane = $request->validate([
            'note' => ['nullable', 'string', 'regex:/^.*$/u', 'max:'.ZapiszDopisekPlanu::MAX_ZNAKOW],
            'stan' => ['nullable', 'string', 'max:40'],
        ], [
            'note.string' => 'Wpisz zwykły tekst, np. „kolacja”.',
            'note.regex' => 'Wpisz dopisek w jednym wierszu, bez nowych linii, i zapisz jeszcze raz.',
            'note.max' => 'Dopisek może mieć najwyżej '.ZapiszDopisekPlanu::MAX_ZNAKOW.' znaków. Skróć go i zapisz jeszcze raz.',
        ]);

        $wynik = $zapisz->handle($request->user(), (string) $wpis->getKey(), $dane['note'] ?? null, $dane['stan'] ?? null);
        $wyczyszczono = ZapiszDopisekPlanu::normalizuj($dane['note'] ?? null) === null;
        $wroc = redirect()->route('planer.show', ['tydzien' => $wpis->day->toDateString()]);

        // Błędy, po których człowiek ma poprawić tekst, wracają do pola z jego
        // wpisem (nic nie znika); reszta to komunikat nad planem.
        $przyPolu = fn (string $tresc): RedirectResponse => $wroc
            ->withInput($request->only('note', '_wiersz'))
            ->withErrors(['note' => $tresc]);

        return match ($wynik) {
            ZapiszDopisekPlanu::ZASTOSOWANO => $wroc->with(Komunikat::sukces($wyczyszczono
                ? 'Dopisek usunięty. Pozycja zostaje w planie.'
                : 'Dopisek zapisany. Widzisz go tylko Ty.')),
            ZapiszDopisekPlanu::JUZ_TAK_BYLO => $wroc->with(Komunikat::informacja($wyczyszczono
                ? 'Ta pozycja nie ma dopisku.'
                : 'Ten dopisek już jest zapisany.')),
            ZapiszDopisekPlanu::KONFLIKT => $przyPolu('Dopisek tej pozycji zmienił się w innym oknie. Odśwież stronę, sprawdź aktualny dopisek i w razie potrzeby wpisz swój jeszcze raz.'),
            ZapiszDopisekPlanu::ZA_DLUGI => $przyPolu('Dopisek może mieć najwyżej '.ZapiszDopisekPlanu::MAX_ZNAKOW.' znaków. Skróć go i zapisz jeszcze raz.'),
            ZapiszDopisekPlanu::NIE_DOTYCZY => $wroc->with(Komunikat::blad('Dopisek dodasz tylko do pozycji z przepisem. Własny wpis możesz usunąć i wpisać od nowa.')),
            default => $wroc->with(Komunikat::blad('Tej pozycji już nie ma w planie. Odśwież stronę.')),
        };
    }

    public function destroy(Request $request, MealPlanEntry $wpis): RedirectResponse
    {
        $this->authorize('delete', $wpis);

        $tydzien = $wpis->day->toDateString();
        $wpis->delete();

        return redirect()->route('planer.show', ['tydzien' => $tydzien])
            ->with(Komunikat::sukces('Usunięte z planu na '.PlanerTygodnia::naDzien($wpis->day).'.'));
    }
}
