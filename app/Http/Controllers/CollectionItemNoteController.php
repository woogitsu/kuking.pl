<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\UpdateCollectionItemNote;
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

        $request->validateWithBag(UpdateCollectionItemNote::WOREK_BLEDOW, [
            'note' => ['nullable', 'string'],
        ], [
            'note.string' => 'Wpisz notatkę zwykłym tekstem i zapisz jeszcze raz.',
        ]);

        $odcisk = $request->input('_odcisk_notatki');
        if (! is_string($odcisk) || ! preg_match('/\A[a-f0-9]{64}\z/D', $odcisk)) {
            throw ValidationException::withMessages([
                'note' => 'Nie można sprawdzić, czy notatka się zmieniła. Twój tekst został w polu. Sprawdź obecną notatkę nad polem i zapisz ponownie.',
            ])->errorBag(UpdateCollectionItemNote::WOREK_BLEDOW);
        }

        $note = $action->handle($request->user(), $collection, $typ, $pozycja, $request->input('note'), $odcisk);

        return redirect()->back(fallback: route('collections.show', $collection))->with(Komunikat::sukces($note === null
            ? 'Notatka usunięta. Zapis został w zeszycie.'
            : ($collection->members()->exists()
                ? 'Notatka zapisana. Widzą ją osoby, które mają dostęp do tego zeszytu.'
                : 'Notatka zapisana. Widzisz ją tylko Ty.')));
    }
}
