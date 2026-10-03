<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\UpdateCollectionItemNote;
use App\Domain\Collections\KonfliktNotatki;
use App\Models\Collection;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * „Dodaj notatkę dla siebie" przy pozycji własnego zeszytu (issue #978).
 */
class CollectionItemNoteController extends Controller
{
    public function __invoke(Request $request, Collection $collection, string $typ, string $pozycja, UpdateCollectionItemNote $action): RedirectResponse
    {
        $this->authorize('addItem', $collection);

        // Zły identyfikator to „nie ma takiej pozycji", nie błąd bazy
        // o niepoprawnym UUID.
        abort_unless(Str::isUuid($pozycja), 404);

        try {
            $request->validateWithBag(UpdateCollectionItemNote::WOREK_BLEDOW, [
                'note' => ['nullable', 'string'],
                // Odcisk notatki widzianej w formularzu (#2400). Brak lub tablica
                // to nie błąd walidacji, tylko brak dowodu świeżości — akcja
                // potraktuje to jak konflikt.
                UpdateCollectionItemNote::POLE_ODCISKU => ['nullable', 'string', 'max:128'],
            ], [
                'note.string' => 'Wpisz notatkę zwykłym tekstem i zapisz jeszcze raz.',
            ]);
        } catch (ValidationException $blad) {
            if ($adres = $this->adresPorcjiPoBledzie($request, $collection, $typ)) {
                $blad->redirectTo($adres);
            }

            throw $blad;
        }

        try {
            $note = $action->handle(
                $request->user(),
                $collection,
                $typ,
                $pozycja,
                $request->input('note'),
                $request->input(UpdateCollectionItemNote::POLE_ODCISKU),
            );
        } catch (ValidationException $blad) {
            if ($adres = $this->adresPorcjiPoBledzie($request, $collection, $typ)) {
                $blad->redirectTo($adres);
            }

            throw $blad;
        } catch (KonfliktNotatki $konflikt) {
            // Nic nie zapisano. Wpisany tekst wraca do pola (`old()`), ale bez
            // starego odcisku — formularz policzy nowy z aktualnej notatki,
            // więc kolejne „Zapisz notatkę" będzie świadomym zastąpieniem.
            $aktualna = $konflikt->aktualna === null
                ? 'Aktualnie notatka jest pusta (ktoś ją usunął).'
                : 'Aktualna notatka brzmi: „'.$konflikt->aktualna.'”.';

            $powrot = $this->adresPorcjiPoBledzie($request, $collection, $typ);

            return ($powrot !== null ? redirect()->to($powrot) : redirect()->back(fallback: route('collections.show', $collection)))
                ->withInput($request->except(UpdateCollectionItemNote::POLE_ODCISKU))
                ->withErrors([
                    'note' => 'Ta notatka została zmieniona przed Twoim zapisem — przez inną osobę albo przez Ciebie w innej karcie. '
                        .'Nic nie zostało nadpisane. '.$aktualna.' '
                        .'Twój tekst został w polu poniżej. Żeby zastąpić nim obecną notatkę, kliknij „Zapisz notatkę” jeszcze raz. '
                        .'Żeby zostawić obecną, po prostu nie zapisuj i zamknij formularz.',
                ], UpdateCollectionItemNote::WOREK_BLEDOW);
        }

        return redirect()->back(fallback: route('collections.show', $collection))->with(Komunikat::sukces($note === null
            ? 'Notatka usunięta. Zapis został w zeszycie.'
            : ($collection->members()->exists()
                ? 'Notatka zapisana. Widzą ją osoby, które mają dostęp do tego zeszytu.'
                : 'Notatka zapisana. Widzisz ją tylko Ty.')));
    }

    private function adresPorcjiPoBledzie(Request $request, Collection $collection, string $typ): ?string
    {
        // Doładowana porcja nie zmienia adresu dokumentu. Powrót na jego
        // pierwszą stronę zużyłby flash z old()/błędem przed renderem pola.
        $strona = filter_var($request->input('strona_notatki'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
        if ($strona === false || ! in_array($typ, [UpdateCollectionItemNote::PRZEPIS, UpdateCollectionItemNote::WPIS], true)) {
            return null;
        }

        return route('collections.show', ['collection' => $collection, $typ === UpdateCollectionItemNote::WPIS ? 'wpisy' : 'page' => $strona]);
    }
}
