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
        $this->authorize('addItem', $collection);

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
            : ($collection->members()->exists()
                ? 'Notatka zapisana. Widzą ją osoby, które mają dostęp do tego zeszytu.'
                : 'Notatka zapisana. Widzisz ją tylko Ty.'));
    }
}
