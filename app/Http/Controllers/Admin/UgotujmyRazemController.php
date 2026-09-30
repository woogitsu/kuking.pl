<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\UgotujmyRazem\TydzienGotowania;
use App\Domain\UgotujmyRazem\ZapisPrzepisuTygodnia;
use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WeeklyRecipePick;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Panel gospodarza „Ugotujmy razem” (F3): jeden przepis na tydzień.
 *
 * Ta sama bramka co tablica na dziś i tagi promowane (`Gate` `moderate`).
 * Zwykły formularz, bez JavaScriptu: lista tygodni (bieżący i osiem
 * następnych) i pole na adres przepisu. Wybór jest zawsze ręczny — ekran
 * nie podpowiada przepisów i nie pokazuje żadnych liczb (D-275).
 */
class UgotujmyRazemController extends Controller
{
    /** Ile tygodni naprzód można zaplanować, licząc bez bieżącego. */
    private const TYGODNI_NAPRZOD = 8;

    public function __construct(private readonly ZapisPrzepisuTygodnia $zapis) {}

    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $biezacy = TydzienGotowania::biezacy();

        return view('pages.admin.ugotujmy-razem', [
            'tygodnie' => $this->tygodnieDoWyboru(),
            'zaplanowane' => WeeklyRecipePick::query()
                ->whereDate('week_starts_on', '>=', $biezacy->dzienStartu())
                ->with('recipe.author.profile')
                ->orderBy('week_starts_on')
                ->get(),
            'zakonczone' => WeeklyRecipePick::query()
                ->whereDate('week_starts_on', '<', $biezacy->dzienStartu())
                ->with('recipe.author.profile')
                ->orderByDesc('week_starts_on')
                ->limit(8)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $tygodnie = $this->tygodnieDoWyboru();

        $dane = $request->validate([
            'tydzien' => ['required', 'string', 'in:'.implode(',', array_keys($tygodnie))],
            'przepis' => ['required', 'string', 'max:500'],
        ], [
            'tydzien.required' => 'Wybierz tydzień z listy.',
            'tydzien.in' => 'Tego tygodnia nie ma już na liście. Wybierz tydzień jeszcze raz.',
            'przepis.required' => 'Wklej adres przepisu, np. z paska przeglądarki na stronie przepisu.',
            'przepis.max' => 'Ten adres jest za długi. Wklej sam adres strony przepisu.',
        ]);

        $recipe = $this->znajdzPrzepis($dane['przepis']);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'przepis' => 'Nie znaleziono przepisu pod tym adresem. Otwórz przepis w serwisie i skopiuj adres z paska przeglądarki.',
            ]);
        }

        $tydzien = TydzienGotowania::zIso($dane['tydzien']);

        $this->zapis->wybierz($request->user(), $tydzien, $recipe, $request->ip());

        return redirect()->route('admin.ugotujmy-razem')
            ->with(Komunikat::sukces("Zapisane. W tygodniu {$tydzien->opis()} gotujemy razem: „{$recipe->title}”."));
    }

    public function destroy(Request $request, WeeklyRecipePick $wybor): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        // Akcja destrukcyjna (AGENTS.md §5): bez `potwierdzam=1` z rozwiniętego
        // pytania nic nie znika.
        if (! $request->boolean('potwierdzam')) {
            return redirect()->route('admin.ugotujmy-razem')
                ->with(Komunikat::blad('Nic nie usunięto. Żeby zdjąć przepis tygodnia, kliknij „Zdejmij ten wybór” i potwierdź.'));
        }

        $this->zapis->usun($request->user(), $wybor, $request->ip());

        return redirect()->route('admin.ugotujmy-razem')
            ->with(Komunikat::sukces('Wybór zdjęty. Przepis i wykonania zostały bez zmian.'));
    }

    /**
     * Bieżący tydzień i osiem następnych, jako `2026-W40` => opis.
     *
     * @return array<string, string>
     */
    private function tygodnieDoWyboru(): array
    {
        $tygodnie = [];
        $tydzien = TydzienGotowania::biezacy();

        for ($i = 0; $i <= self::TYGODNI_NAPRZOD; $i++) {
            $przedrostek = match ($i) {
                0 => 'Ten tydzień: ',
                1 => 'Następny tydzień: ',
                default => '',
            };
            $tygodnie[$tydzien->iso()] = $przedrostek.$tydzien->opis();
            $tydzien = $tydzien->nastepny();
        }

        return $tygodnie;
    }

    /**
     * Adres przepisu (`https://kuking.pl/przepisy/golabki-basi?porcje=4`)
     * albo sama jego końcówka (`golabki-basi`) → przepis albo `null`.
     */
    private function znajdzPrzepis(string $wpisane): ?Recipe
    {
        $tekst = trim($wpisane);
        $sciezka = (string) (parse_url($tekst, PHP_URL_PATH) ?? $tekst);

        if (preg_match('#/przepisy/([^/?\#]+)#u', $sciezka, $m) === 1) {
            $slug = rawurldecode($m[1]);
        } else {
            $slug = trim($sciezka, '/');
        }

        if ($slug === '' || str_contains($slug, '/')) {
            return null;
        }

        return Recipe::query()->where('slug', $slug)->first();
    }
}
