<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Gotowanie\KonfliktDopisku;
use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Models\CookingNote;
use App\Models\Recipe;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Prywatny roboczy dopisek podczas gotowania (#2587).
 *
 * Zwykłe formularze POST — działają bez JavaScriptu. Dopisek nie jest
 * wykonaniem: ten kontroler nigdy nie dotyka `CookedEvent`, powiadomień
 * ani niczego publicznego. Przeniesienie do formularza „Ugotowałem” robi
 * `CookedEventController::create` i tylko na wyraźną prośbę osoby.
 *
 * Przepis wchodzi po slugu, więc KAŻDE wejście przechodzi przez
 * `RecipePolicy::view` (UUID/slug w adresie to nie autoryzacja), a sam dopisek
 * przez `CookingNotePolicy`. Właściciel pochodzi z sesji, nie z żądania.
 */
class DopisekGotowaniaController extends Controller
{
    public function __construct(private readonly RoboczyDopisek $dopiski) {}

    public function zapisz(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);
        $this->authorize('create', CookingNote::class);

        $osoba = $request->user();
        abort_if($osoba === null, 403);

        $istniejacy = $this->dopiski->aktywny($osoba, $model);
        if ($istniejacy !== null) {
            $this->authorize('update', $istniejacy);
        }

        $walidator = Validator::make($request->all(), [
            'dopisek' => ['required', 'string', 'max:'.RoboczyDopisek::MAKS_ZNAKOW],
            'rewizja' => ['nullable', 'integer', 'min:0'],
        ], [
            'dopisek.required' => 'Wpisz kilka słów dopisku albo wróć do gotowania bez zapisu.',
            'dopisek.max' => 'Dopisek jest za długi. Zmieść się w '.RoboczyDopisek::MAKS_ZNAKOW.' znakach.',
            'dopisek.string' => 'Dopisek musi być zwykłym tekstem. Wpisz go jeszcze raz.',
        ]);

        if ($walidator->fails()) {
            return $this->wroc($request, $model)
                ->withErrors($walidator)
                ->withInput($request->only('dopisek'));
        }

        $tresc = trim((string) $request->input('dopisek'));

        try {
            $this->dopiski->zapisz($osoba, $model, $tresc, (int) $request->input('rewizja', 0));
        } catch (KonfliktDopisku) {
            return $this->wroc($request, $model)
                ->withInput($request->only('dopisek'))
                ->with(Komunikat::blad('Dopisek zmienił się na innym urządzeniu, więc go nie nadpisaliśmy. Twój tekst zostaje w polu — porównaj go z zapisanym dopiskiem i zapisz jeszcze raz.'));
        } catch (Throwable $wyjatek) {
            report($wyjatek);

            return $this->wroc($request, $model)
                ->withInput($request->only('dopisek'))
                ->with(Komunikat::blad('Nie udało się zapisać dopisku. Twój tekst zostaje w polu — spróbuj jeszcze raz za chwilę.'));
        }

        return $this->wroc($request, $model)
            ->with(Komunikat::sukces('Dopisek zapisany. Widzisz go tylko Ty i nic się przez to nie publikuje.'));
    }

    public function usun(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $osoba = $request->user();
        abort_if($osoba === null, 403);

        $istniejacy = $this->dopiski->aktywny($osoba, $model);
        if ($istniejacy !== null) {
            $this->authorize('delete', $istniejacy);
        }

        $this->dopiski->usun($osoba, $model);

        return $this->wroc($request, $model)->with(Komunikat::sukces('Dopisek usunięty.'));
    }

    /** Wraca do tego samego kroku i tych samych porcji, w których osoba była. */
    private function wroc(Request $request, Recipe $model): RedirectResponse
    {
        $kroki = max(1, $model->steps()->count());
        $krok = filter_var($request->input('krok'), FILTER_VALIDATE_INT) ?: 1;
        $wybor = WyborPorcji::dla($model, $request->input('porcje'));

        return redirect()->route('cooking.show', array_filter([
            'recipe' => $model->slug,
            'krok' => max(1, min($krok, $kroki)),
            'porcje' => $wybor->przeliczone() ? $wybor->doAdresu((float) $wybor->wybrane) : null,
        ], fn ($wartosc) => $wartosc !== null));
    }
}
