<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\UpdateCollectionItemNote;
use App\Models\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * „Dodaj notatkę dla siebie" przy pozycji własnego zeszytu (issue #978).
 */
class CollectionItemNoteController extends Controller
{
    public function __invoke(Request $request, Collection $collection, string $typ, string $pozycja, UpdateCollectionItemNote $action): RedirectResponse
    {
        $this->authorize('update', $collection);

        // Zły identyfikator to „nie ma takiej pozycji", nie błąd bazy
        // o niepoprawnym UUID.
        abort_unless(Str::isUuid($pozycja), 404);

        $request->validateWithBag(UpdateCollectionItemNote::WOREK_BLEDOW, [
            'note' => ['nullable', 'string'],
        ], [
            'note.string' => 'Wpisz notatkę zwykłym tekstem i zapisz jeszcze raz.',
        ]);

        $note = $action->handle($request->user(), $collection, $typ, $pozycja, $request->input('note'));

        return redirect()->back(fallback: route('collections.show', $collection))->with('status', $note === null
            ? 'Notatka usunięta. Zapis został w zeszycie.'
            : 'Notatka zapisana. Widzisz ją tylko Ty.');
    }
}
