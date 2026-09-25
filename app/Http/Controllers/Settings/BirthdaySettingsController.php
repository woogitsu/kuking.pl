<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Rocznice\Urodziny;
use App\Domain\Users\Actions\UstawUrodziny;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Urodziny: dzień i miesiąc, bez roku (issue #1755).
 *
 * OSOBNY EKRAN, NIE POLE W FORMULARZU PROFILU. Formularz profilu wysyła
 * wszystkie pola naraz, więc błąd przy nazwie użytkownika blokowałby zapis
 * daty (ten sam powód, dla którego zdjęcie ma własny ekran). A profil to
 * dane publiczne — urodziny są prywatne.
 */
class BirthdaySettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('pages.settings.birthday', [
            'user' => $user,
            'dataSlownie' => Urodziny::slownie($user),
        ]);
    }

    public function update(Request $request, UstawUrodziny $urodziny): RedirectResponse
    {
        $dane = $request->validate([
            'birthday_day' => ['required', 'integer', 'between:1,31'],
            'birthday_month' => ['required', 'integer', 'between:1,12', function (string $pole, mixed $miesiac, Closure $blad) use ($request): void {
                $dzien = $request->input('birthday_day');

                // Tylko gdy oba pola z osobna są poprawne — zły dzień ma już własny
                // komunikat przy swoim polu, drugi przy miesiącu byłby szumem.
                if (filter_var($dzien, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]) !== false
                    && filter_var($miesiac, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) !== false
                    && ! Urodziny::poprawna((int) $dzien, (int) $miesiac)) {
                    $blad('Ten miesiąc nie ma '.(int) $dzien.' dni. Wybierz inny dzień albo inny miesiąc.');
                }
            }],
        ], [
            'birthday_day.required' => 'Wybierz dzień urodzin z listy.',
            'birthday_day.*' => 'Wybierz dzień urodzin z listy — od 1 do 31.',
            'birthday_month.required' => 'Wybierz miesiąc urodzin z listy.',
            'birthday_month.integer' => 'Wybierz miesiąc urodzin z listy.',
            'birthday_month.between' => 'Wybierz miesiąc urodzin z listy.',
        ]);

        $urodziny->zapisz($request->user(), (int) $dane['birthday_day'], (int) $dane['birthday_month']);

        return back()->with('status', 'Zapisane.');
    }

    /**
     * Wybory przy dacie: dziś wyłącznik życzeń na stronie głównej (etap b).
     * Osobny formularz, żeby przestawienie wyłącznika nie wymagało ponownego
     * wybierania daty.
     */
    public function preferences(Request $request, UstawUrodziny $urodziny): RedirectResponse
    {
        // Odznaczony checkbox nie przychodzi w żądaniu — brak pola znaczy „nie".
        $request->mergeIfMissing(['birthday_wishes_enabled' => '0']);
        $request->validate([
            'birthday_wishes_enabled' => ['boolean'],
        ], [
            'birthday_wishes_enabled.*' => 'Zaznacz albo odznacz pole i zapisz ponownie.',
        ]);

        $urodziny->ustawZyczenia($request->user(), $request->boolean('birthday_wishes_enabled'));

        return back()->with('status', 'Zapisane.');
    }

    public function destroy(Request $request, UstawUrodziny $urodziny): RedirectResponse
    {
        $urodziny->usun($request->user());

        return redirect()->route('settings.birthday')->with('status', 'Data urodzin usunięta.');
    }
}
