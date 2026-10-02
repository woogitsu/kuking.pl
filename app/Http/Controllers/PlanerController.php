<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Planer\Actions\DodajDoPlanu;
use App\Domain\Planer\Actions\OznaczPozycjePlanu;
use App\Domain\Planer\Actions\PrzeniesPozycjePlanu;
use App\Domain\Planer\Actions\SkopiujDzienPlanu;
use App\Domain\Planer\Actions\SkopiujPoprzedniTydzien;
use App\Domain\Planer\Actions\UstawPorcjePlanu;
use App\Domain\Planer\Actions\ZapiszDopisekPlanu;
use App\Domain\Planer\Actions\ZmienTekstPozycjiPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Planer\PlikKalendarza;
use App\Domain\Planer\ZakresDatPlanu;
use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Domain\Search\SearchQuery;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Support\Czas;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
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

    /**
     * „Wydrukuj ten tydzień” (#2498): kartka z planem WYBRANEGO tygodnia
     * (`?tydzien=`, jak ekran planera — zły parametr to bieżący tydzień).
     * Czysty odczyt WŁASNEGO planu: nic nie zapisuje, nie tworzy kopii na
     * serwerze i nie bierze identyfikatora osoby z adresu. Widoczność
     * przepisów liczy `PlanerTygodnia` (niedostępny i usunięty zostają
     * pozycjami bez tytułu); prywatne dopiski i „Zrobione” nie trafiają
     * do widoku.
     */
    public function druk(Request $request, PlanerTygodnia $planer): View
    {
        $poniedzialek = PlanerTygodnia::poniedzialek($request->query('tydzien'));

        return view('pages.planer.do-druku', [
            'poniedzialek' => $poniedzialek,
            'dni' => $planer->tydzien($request->user(), $poniedzialek),
            'dataOdczytu' => Czas::lokalnie(Carbon::now())->translatedFormat('j F Y, H:i'),
        ]);
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

    /**
     * Wybór pozycji tygodnia do pliku kalendarza (#2529). Zwykły GET: nic nie
     * jest generowane ani zapisywane, dopóki osoba nie kliknie „Pobierz plik”.
     * Lista pokazuje dokładnie te nazwy i daty, które trafią do pliku.
     */
    public function calendar(Request $request, PlanerTygodnia $planer): View
    {
        $poniedzialek = PlanerTygodnia::poniedzialek($request->query('tydzien'));

        return view('pages.planer.kalendarz', [
            'poniedzialek' => $poniedzialek,
            'pozycje' => $planer->pozycje($request->user(), $poniedzialek, $poniedzialek->addDays(6)),
        ]);
    }

    /**
     * Pobranie pliku `.ics` z wybranych pozycji JEDNEGO tygodnia (#2529).
     * Prywatna odpowiedź (bez cache), bez stałego adresu. Widoczność przepisu
     * liczona od nowa w chwili generowania; cudzy identyfikator = odmowa.
     */
    public function calendarDownload(Request $request, PlanerTygodnia $planer): Response
    {
        $dane = $request->validate([
            'tydzien' => ['required', 'date_format:Y-m-d'],
            'wpisy' => ['required', 'array', 'min:1', 'max:70'],
            'wpisy.*' => ['required', 'uuid'],
        ], [
            'tydzien.required' => 'Wybierz tydzień i zaznacz pozycje jeszcze raz.',
            'tydzien.date_format' => 'Wybierz tydzień i zaznacz pozycje jeszcze raz.',
            'wpisy.required' => 'Zaznacz co najmniej jedną pozycję, którą chcesz zapisać w pliku kalendarza.',
            'wpisy.min' => 'Zaznacz co najmniej jedną pozycję, którą chcesz zapisać w pliku kalendarza.',
            'wpisy.array' => 'Zaznacz pozycje na liście i pobierz plik jeszcze raz.',
            'wpisy.max' => 'Zaznaczono za dużo pozycji. Wybierz najwyżej 70.',
            'wpisy.*.uuid' => 'Zaznacz pozycje na liście i pobierz plik jeszcze raz.',
        ]);

        $user = $request->user();
        $poniedzialek = PlanerTygodnia::poniedzialek($dane['tydzien']);
        $ids = array_values(array_unique($dane['wpisy']));

        // Identyfikator z żądania nie jest autoryzacją (AGENTS.md §7): każda
        // zaznaczona pozycja musi należeć do zalogowanej osoby.
        $wlasne = $user->mealPlanEntries()->whereIn('id', $ids)->pluck('id')->all();
        abort_if(count($wlasne) !== count($ids), 403);

        $wTygodniu = $planer->pozycje($user, $poniedzialek, $poniedzialek->addDays(6));
        $wybrane = array_values(array_filter($wTygodniu, fn (array $p): bool => in_array((string) $p['wpis']->getKey(), $ids, true)));

        if (count($wybrane) !== count($ids)) {
            throw ValidationException::withMessages([
                'wpisy' => 'Część zaznaczonych pozycji nie jest już w tym tygodniu. Odśwież listę i zaznacz pozycje jeszcze raz.',
            ]);
        }

        foreach ($wybrane as $pozycja) {
            if (PlikKalendarza::nazwaPozycji($pozycja) === null) {
                throw ValidationException::withMessages([
                    'wpisy' => 'Przepis z jednej zaznaczonej pozycji jest już niedostępny, więc nie trafi do pliku. Odznacz ją i pobierz plik jeszcze raz.',
                ]);
            }
        }

        $tresc = PlikKalendarza::zbuduj($wybrane, now());

        return response($tresc, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="kuking-plan-'.$poniedzialek->toDateString().'.ics"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
     * „Skopiuj ten dzień” (#2494): wybór dnia docelowego i podgląd. Zwykły GET
     * — nic się nie zapisuje, a adres da się odświeżyć. Zapis idzie dopiero
     * z przycisku „Skopiuj” (`copyDay`).
     */
    public function copyDayForm(Request $request, SkopiujDzienPlanu $kopiuj): View|RedirectResponse
    {
        $zrodlo = self::dzienZParametru($request->query('dzien'));
        if ($zrodlo === null) {
            return redirect()->route('planer.show');
        }

        $bledy = new MessageBag;
        $cel = null;
        $ocena = null;

        $celParametr = $request->query('cel');
        if (is_string($celParametr) && $celParametr !== '') {
            $cel = self::dzienZParametru($celParametr);
            if ($cel === null) {
                $bledy->add('cel', 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.');
            } elseif ($cel->equalTo($zrodlo)) {
                $bledy->add('cel', 'To ten sam dzień, z którego kopiujesz. Wybierz inny dzień — nic nie zostało skopiowane.');
            } elseif (($blad = SkopiujDzienPlanu::bladZakresu($cel)) !== null) {
                $bledy->add('cel', $blad);
            } else {
                $ocena = $kopiuj->ocen($request->user(), $zrodlo, $cel);
            }
        }

        // Błędy z samego adresu (GET nie niesie sesji z błędami). Idą przez
        // współdzielone `$errors`, żeby widział je też `x-field` i
        // podsumowanie błędów; błędy po nieudanym zapisie przychodzą z sesji
        // i nie wolno ich tu przykryć pustym workiem.
        if ($bledy->isNotEmpty()) {
            view()->share('errors', (new ViewErrorBag)->put('default', $bledy));
        }

        return view('pages.planer.kopiuj-dzien', [
            'zrodlo' => $zrodlo,
            'cel' => $cel,
            'ocena' => $ocena,
            'wpisowNaDzien' => PlanerTygodnia::wpisowNaDzien(),
        ]);
    }

    public function copyDay(Request $request, SkopiujDzienPlanu $kopiuj): RedirectResponse
    {
        $zrodlo = self::dzienZParametru($request->input('dzien'));
        if ($zrodlo === null) {
            return redirect()->route('planer.show')
                ->with(Komunikat::blad('Nie wiemy, który dzień kopiować. Wybierz „Skopiuj ten dzień” przy dniu w planerze jeszcze raz.'));
        }
        $wracaDoFormularza = route('planer.copyday', ['dzien' => $zrodlo->toDateString()]);

        try {
            $dane = $request->validate([
                'cel' => ['required', 'string', 'date_format:Y-m-d'],
                'odcisk' => ['required', 'string', 'max:64'],
            ], [
                'cel.required' => 'Wybierz dzień, na który kopiujesz.',
                'cel.string' => 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.',
                'cel.date_format' => 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.',
                'odcisk.required' => 'Ta strona jest nieaktualna. Obejrzyj podgląd jeszcze raz i zatwierdź kopię.',
                'odcisk.string' => 'Ta strona jest nieaktualna. Obejrzyj podgląd jeszcze raz i zatwierdź kopię.',
                'odcisk.max' => 'Ta strona jest nieaktualna. Obejrzyj podgląd jeszcze raz i zatwierdź kopię.',
            ]);

            $cel = CarbonImmutable::createFromFormat('!Y-m-d', $dane['cel']);
            $wynik = $kopiuj->handle($request->user(), $zrodlo, $cel, $dane['odcisk']);
        } catch (ValidationException $e) {
            throw $e->redirectTo($wracaDoFormularza);
        }

        $kiedy = PlanerTygodnia::naDzien($cel);
        $tydzienCelu = route('planer.show', ['tydzien' => PlanerTygodnia::poniedzialek($cel->toDateString())->toDateString()])
            .'#dzien-'.$cel->toDateString();

        return match ($wynik['wynik']) {
            SkopiujDzienPlanu::ZASTOSOWANO => redirect($tydzienCelu)->with(Komunikat::sukces(
                'Skopiowane na '.$kiedy.': '.$wynik['dodane'].' '
                .Odmiana::rzeczownik($wynik['dodane'], 'pozycja', 'pozycje', 'pozycji')
                .'. Dzień, z którego kopiowano, został bez zmian.')),
            SkopiujDzienPlanu::NIC_NOWEGO => redirect($tydzienCelu)->with(Komunikat::informacja(
                'Nic nowego do skopiowania: wszystko z tego dnia już jest w planie na '.$kiedy.' albo przepis jest niedostępny.')),
            SkopiujDzienPlanu::PUSTY => redirect()->route('planer.show', ['tydzien' => $zrodlo->toDateString()])
                ->with(Komunikat::informacja('Ten dzień jest pusty — nie ma czego skopiować.')),
            SkopiujDzienPlanu::TEN_SAM_DZIEN => redirect($wracaDoFormularza)
                ->with(Komunikat::informacja('To ten sam dzień, z którego kopiujesz. Wybierz inny dzień — nic nie zostało skopiowane.')),
            default => redirect(route('planer.copyday', ['dzien' => $zrodlo->toDateString(), 'cel' => $cel->toDateString()]))
                ->with(Komunikat::blad('Plan zmienił się od podglądu, więc nic nie zostało skopiowane. Sprawdź nowy podgląd poniżej i zatwierdź kopię jeszcze raz.')),
        };
    }

    /** Dzień z adresu albo formularza (`Y-m-d`); cokolwiek innego to null. */
    private static function dzienZParametru(mixed $wartosc): ?CarbonImmutable
    {
        if (! is_string($wartosc) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $wartosc) !== 1) {
            return null;
        }

        try {
            $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $wartosc);
        } catch (\Throwable) {
            return null;
        }

        return $dzien instanceof CarbonImmutable && $dzien->format('Y-m-d') === $wartosc ? $dzien : null;
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

    /**
     * Prywatna liczba planowanych porcji przy pozycji (#2509). Pusta wartość
     * czyści wybór (wracają ilości autora). Błędny wpis wraca do pola z tym,
     * co człowiek wpisał, i z komunikatem przy polu.
     */
    public function savePortions(Request $request, MealPlanEntry $wpis, UstawPorcjePlanu $ustaw): RedirectResponse
    {
        $this->authorize('editServings', $wpis);

        $dane = $request->validate([
            'porcje' => ['nullable', 'string', 'max:12'],
            'stan' => ['nullable', 'string', 'max:40'],
        ], [
            'porcje.string' => 'Wpisz liczbę porcji, na przykład 6 albo 2,5.',
            'porcje.max' => 'Wpisz liczbę porcji, na przykład 6 albo 2,5.',
        ]);

        $wynik = $ustaw->handle($request->user(), (string) $wpis->getKey(), $dane['porcje'] ?? null, $dane['stan'] ?? null);
        $wyczyszczono = trim((string) ($dane['porcje'] ?? '')) === '';
        $wroc = redirect()->route('planer.show', ['tydzien' => $wpis->day->toDateString()]);

        $przyPolu = fn (string $tresc): RedirectResponse => $wroc
            ->withInput($request->only('porcje', '_wiersz'))
            ->withErrors(['porcje' => $tresc]);

        return match ($wynik) {
            UstawPorcjePlanu::ZASTOSOWANO => $wroc->with(Komunikat::sukces($wyczyszczono
                ? 'Wybór porcji usunięty. Przepis otworzy się z ilościami autora.'
                : 'Porcje na ten dzień zapisane. Widzisz je tylko Ty.')),
            UstawPorcjePlanu::JUZ_TAK_BYLO => $wroc->with(Komunikat::informacja($wyczyszczono
                ? 'Ta pozycja nie ma wybranej liczby porcji.'
                : 'Ta liczba porcji już jest zapisana.')),
            UstawPorcjePlanu::KONFLIKT => $przyPolu('Liczba porcji tej pozycji zmieniła się w innym oknie. Odśwież stronę, sprawdź aktualną liczbę i w razie potrzeby wpisz swoją jeszcze raz.'),
            UstawPorcjePlanu::NIEPRAWIDLOWE => $przyPolu('Wpisz liczbę porcji od '.WyborPorcji::NAJMNIEJ.' do '.WyborPorcji::NAJWIECEJ.', na przykład 6 albo 2,5.'),
            UstawPorcjePlanu::BEZ_PODSTAWY => $przyPolu('Ten przepis nie podaje liczby porcji, więc nie przeliczymy ilości. Ilości zostają takie, jak napisał autor.'),
            UstawPorcjePlanu::NIE_DOTYCZY => $wroc->with(Komunikat::blad('Porcje ustawisz tylko przy pozycji z dostępnym przepisem.')),
            default => $wroc->with(Komunikat::blad('Tej pozycji już nie ma w planie. Odśwież stronę.')),
        };
    }

    /**
     * Ekran „Przenieś na inny dzień” (#2447): zwykły formularz bez skryptu.
     * Niesie dzień, na którym człowiek widział pozycję — z tego znacznika
     * akcja pozna, że ktoś w innym oknie już ją przeniósł.
     */
    public function moveForm(Request $request, MealPlanEntry $wpis, PlanerTygodnia $planer): View
    {
        $this->authorize('move', $wpis);

        $dzien = CarbonImmutable::instance($wpis->day);
        $pozycja = collect($planer->pozycje($request->user(), $dzien, $dzien))
            ->first(fn (array $p): bool => $p['wpis']->getKey() === $wpis->getKey());

        $nazwa = match ($pozycja['stan'] ?? null) {
            PlanerTygodnia::STAN_PRZEPIS => $pozycja['przepis']?->title,
            PlanerTygodnia::STAN_WLASNY => $wpis->label,
            PlanerTygodnia::STAN_NIEDOSTEPNY => 'Przepis jest już niedostępny.',
            default => 'Przepis został usunięty.',
        };

        return view('pages.planer.przenies', [
            'wpis' => $wpis,
            'nazwa' => $nazwa,
            'dzien' => $dzien,
        ]);
    }

    public function move(Request $request, MealPlanEntry $wpis, PrzeniesPozycjePlanu $przenies): RedirectResponse
    {
        $this->authorize('move', $wpis);

        $wracaDoFormularza = route('planer.move.form', $wpis);

        try {
            $dane = $request->validate([
                'day' => ['required', 'string', 'date_format:Y-m-d'],
                'stan' => ['required', 'string', 'date_format:Y-m-d'],
            ], [
                'day.required' => 'Wybierz dzień, na który przenosisz pozycję.',
                'day.date_format' => 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.',
                'day.string' => 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.',
                'stan.required' => 'Ta strona jest nieaktualna. Wróć do planera i otwórz przenoszenie jeszcze raz.',
                'stan.date_format' => 'Ta strona jest nieaktualna. Wróć do planera i otwórz przenoszenie jeszcze raz.',
                'stan.string' => 'Ta strona jest nieaktualna. Wróć do planera i otwórz przenoszenie jeszcze raz.',
            ]);

            $nowyDzien = CarbonImmutable::createFromFormat('!Y-m-d', $dane['day']);
            $wynik = $przenies->handle($request->user(), (string) $wpis->getKey(), $nowyDzien, $dane['stan']);
        } catch (ValidationException $e) {
            // Błąd wraca na formularz przenoszenia (z wybranym dniem), a nie
            // tam, skąd przyszło żądanie.
            throw $e->redirectTo($wracaDoFormularza);
        }

        $kiedy = PlanerTygodnia::naDzien($nowyDzien);
        $tydzien = PlanerTygodnia::poniedzialek($nowyDzien->toDateString())->toDateString();

        return match ($wynik) {
            PrzeniesPozycjePlanu::ZASTOSOWANO => redirect(route('planer.show', ['tydzien' => $tydzien]).'#dzien-'.$nowyDzien->toDateString())
                ->with(Komunikat::sukces("Przeniesione na {$kiedy}.")),
            PrzeniesPozycjePlanu::JUZ_TAK_BYLO => redirect(route('planer.show', ['tydzien' => $tydzien]).'#dzien-'.$nowyDzien->toDateString())
                ->with(Komunikat::informacja("Ta pozycja już jest w planie na {$kiedy}. Nic nie zostało zmienione.")),
            PrzeniesPozycjePlanu::KONFLIKT => redirect()->route('planer.show', ['tydzien' => $wpis->day->toDateString()])
                ->with(Komunikat::blad('Ta pozycja została w innym oknie przeniesiona na inny dzień, więc nic nie zmieniliśmy. Sprawdź, gdzie stoi teraz (poniżej, w planie) i w razie potrzeby przenieś ją jeszcze raz.')),
            default => redirect()->route('planer.show')
                ->with(Komunikat::blad('Tej pozycji już nie ma w planie. Odśwież stronę.')),
        };
    }

    /**
     * Ekran „Zmień tekst” (#2454): zwykły formularz z obecnym tekstem. Niesie
     * znacznik tekstu, który człowiek widzi — z niego akcja pozna, że ktoś
     * w innym oknie zmienił go wcześniej.
     */
    public function editText(MealPlanEntry $wpis): View|RedirectResponse
    {
        $this->authorize('update', $wpis);

        if ($wpis->recipe_id !== null || $wpis->label === null) {
            return redirect()->route('planer.show', ['tydzien' => $wpis->day->toDateString()])
                ->with(Komunikat::blad('Tę pozycję nie da się poprawić, bo to nie jest własny wpis. Przepis możesz usunąć z planu i dodać inny.'));
        }

        return view('pages.planer.tekst', [
            'wpis' => $wpis,
            'dzien' => CarbonImmutable::instance($wpis->day),
            'znacznik' => ZmienTekstPozycjiPlanu::znacznik($wpis),
            'maxZnakow' => ZmienTekstPozycjiPlanu::MAX_ZNAKOW,
        ]);
    }

    public function updateText(Request $request, MealPlanEntry $wpis, ZmienTekstPozycjiPlanu $zmien): RedirectResponse
    {
        $this->authorize('update', $wpis);

        $wracaDoFormularza = route('planer.text.edit', $wpis);

        try {
            $dane = $request->validate([
                'label' => ['required', 'string'],
                'stan' => ['required', 'string', 'max:64'],
            ], [
                'label.required' => 'Wpisz, co planujesz na ten dzień, np. „obiad u mamy”.',
                'label.string' => 'Wpisz zwykły tekst, np. „obiad u mamy”.',
                'stan.required' => 'Ta strona jest nieaktualna. Wróć do planera i otwórz poprawianie jeszcze raz.',
                'stan.string' => 'Ta strona jest nieaktualna. Wróć do planera i otwórz poprawianie jeszcze raz.',
                'stan.max' => 'Ta strona jest nieaktualna. Wróć do planera i otwórz poprawianie jeszcze raz.',
            ]);

            $wynik = $zmien->handle($request->user(), (string) $wpis->getKey(), $dane['label'], $dane['stan']);
        } catch (ValidationException $e) {
            throw $e->redirectTo($wracaDoFormularza);
        }

        $tydzien = route('planer.show', ['tydzien' => $wpis->day->toDateString()]).'#dzien-'.$wpis->day->toDateString();

        return match ($wynik) {
            ZmienTekstPozycjiPlanu::ZASTOSOWANO => redirect($tydzien)
                ->with(Komunikat::sukces('Tekst poprawiony. Pozycja zostaje na '.PlanerTygodnia::naDzien($wpis->day).'.')),
            ZmienTekstPozycjiPlanu::JUZ_TAK_BYLO => redirect($tydzien)
                ->with(Komunikat::informacja('Ta pozycja ma już taki tekst. Nic nie zostało zmienione.')),
            // Wpisany tekst wraca do pola, a nad nim stoi aktualny tekst z planu.
            ZmienTekstPozycjiPlanu::KONFLIKT => redirect($wracaDoFormularza)->withInput($request->only('label'))
                ->with(Komunikat::blad('Tekst tej pozycji zmienił się w innym oknie, więc nic nie zapisaliśmy. Poniżej widzisz aktualny tekst, a Twoja poprawka została w polu — jeśli nadal ją chcesz, kliknij „Zapisz” jeszcze raz.')),
            ZmienTekstPozycjiPlanu::NIE_WLASNY => redirect($tydzien)
                ->with(Komunikat::blad('Tę pozycję nie da się poprawić, bo to nie jest własny wpis.')),
            default => redirect()->route('planer.show')
                ->with(Komunikat::blad('Tej pozycji już nie ma w planie. Odśwież stronę.')),
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
