<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Wskazowki\OdrzucWskazowke;
use App\Domain\Wskazowki\PrzyjmijWskazowke;
use App\Domain\Wskazowki\WycofajWskazowke;
use App\Domain\Wskazowki\ZaproponujWskazowke;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\RecipeHint;
use App\Models\User;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Wskazówki od gotujących" (#2352, D-333). Kontroler jest cienki:
 * Policy → akcja domenowa → powrót na stronę wykonania, gdzie stoją i
 * prośba, i odpowiedzi. Identyfikator w adresie niczego nie otwiera —
 * `RecipeHintPolicy` pyta, KTO prosi i KTO odpowiada.
 *
 * Wszystko zwykłymi formularzami, bez skryptu.
 */
class WskazowkaController extends Controller
{
    /** Autor przepisu prosi kucharza o zgodę. */
    public function propose(Request $request, CookedEvent $cookedEvent, ZaproponujWskazowke $zaproponuj): RedirectResponse
    {
        $this->authorize('propose', [RecipeHint::class, $cookedEvent]);

        /** @var User $user */
        $user = $request->user();

        try {
            $zaproponuj->handle($user, $cookedEvent, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return $this->wroc($cookedEvent->getKey())->with(Komunikat::blad($e->getMessage()));
        }

        return $this->wroc($cookedEvent->getKey(), '#wskazowka-autor')->with(Komunikat::sukces(
            'Wysłaliśmy prośbę. Wskazówka pojawi się przy przepisie dopiero wtedy, gdy ta osoba się zgodzi.',
        ));
    }

    public function accept(Request $request, RecipeHint $wskazowka, PrzyjmijWskazowke $przyjmij): RedirectResponse
    {
        $this->authorize('answer', $wskazowka);

        /** @var User $user */
        $user = $request->user();

        try {
            $przyjmij->handle($user, $wskazowka, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return $this->wroc($wskazowka->cooked_event_id)->with(Komunikat::blad($e->getMessage()));
        }

        return $this->wroc($wskazowka->cooked_event_id)->with(Komunikat::sukces(
            'Dziękujemy. Ta uwaga stoi teraz przy przepisie jako wskazówka. Zgodę możesz wycofać w każdej chwili.',
        ));
    }

    public function decline(Request $request, RecipeHint $wskazowka, OdrzucWskazowke $odrzuc): RedirectResponse
    {
        $this->authorize('answer', $wskazowka);

        /** @var User $user */
        $user = $request->user();

        try {
            $odrzuc->handle($user, $wskazowka, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return $this->wroc($wskazowka->cooked_event_id)->with(Komunikat::blad($e->getMessage()));
        }

        return $this->wroc($wskazowka->cooked_event_id)->with(Komunikat::informacja(
            'Dobrze, nic się nie zmienia. Twoja uwaga zostaje tylko pod Twoim wykonaniem, a autor przepisu nie dostanie o tym wiadomości.',
        ));
    }

    public function withdraw(Request $request, RecipeHint $wskazowka, WycofajWskazowke $wycofaj): RedirectResponse
    {
        $this->authorize('answer', $wskazowka);

        /** @var User $user */
        $user = $request->user();

        try {
            $wycofaj->handle($user, $wskazowka, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return $this->wroc($wskazowka->cooked_event_id)->with(Komunikat::blad($e->getMessage()));
        }

        return $this->wroc($wskazowka->cooked_event_id)->with(Komunikat::informacja(
            'Zgoda wycofana. Wskazówka zniknęła ze strony przepisu, a Twoja uwaga zostaje pod wykonaniem.',
        ));
    }

    private function wroc(string $wykonanieId, string $kotwica = '#wskazowka'): RedirectResponse
    {
        return redirect(route('cooked.show', $wykonanieId).$kotwica);
    }
}
