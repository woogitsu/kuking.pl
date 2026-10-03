<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\PrzeniesPozycjeMiedzyZeszytami;
use App\Domain\Collections\KonfliktPrzeniesienia;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Support\Komunikat;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * „Przenieś do innego zeszytu" (#2430): dwa zwykłe ekrany bez JavaScriptu —
 * wybór zeszytu docelowego (radio z nazwami) i zapis. Kontroler jest cienki:
 * Policy `przenies` dla zeszytu źródłowego, kształt żądania, tłumaczenie
 * wyniku na zdanie. Zamki, ponowna kontrola obu zeszytów i widoczności treści
 * — w akcji domenowej.
 */
class CollectionItemMoveController extends Controller
{
    public function form(Request $request, Collection $collection, string $typ, string $pozycja): View
    {
        $this->authorize('przenies', $collection);
        [$kolumna] = $this->kolumna($typ, $pozycja);

        $jest = DB::table('collection_items')
            ->where('collection_id', $collection->getKey())->where($kolumna, $pozycja)->exists();
        abort_unless($jest, 404);

        $tresc = $typ === PrzeniesPozycjeMiedzyZeszytami::PRZEPIS
            ? Recipe::query()->whereKey($pozycja)->first()
            : Post::query()->whereKey($pozycja)->first();
        // Tytuł tylko, gdy osoba może dziś otworzyć treść — niedostępna nie
        // ujawnia tytułu w formularzu.
        $tytul = $tresc instanceof Recipe && Gate::forUser($request->user())->allows('view', $tresc) ? $tresc->title : null;

        $cele = $request->user()->collections()
            ->where('visibility', 'private')
            ->whereDoesntHave('members')
            ->whereKeyNot($collection->getKey())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_default']);

        return view('pages.collections.przenies', [
            'zeszyt' => $collection,
            'typ' => $typ,
            'pozycja' => $pozycja,
            'tytul' => $tytul,
            'cele' => $cele,
            'konflikt' => $request->session()->get('przeniesienie_konflikt'),
        ]);
    }

    public function store(Request $request, Collection $collection, string $typ, string $pozycja, PrzeniesPozycjeMiedzyZeszytami $akcja): RedirectResponse
    {
        $this->authorize('przenies', $collection);
        $this->kolumna($typ, $pozycja);

        $dane = $request->validate([
            'cel' => ['required', 'uuid'],
        ], [
            'cel.required' => 'Wybierz zeszyt, do którego chcesz przenieść tę pozycję.',
            'cel.uuid' => 'Wybierz zeszyt z listy.',
        ]);

        try {
            $wynik = $akcja->handle($request->user(), (string) $collection->getKey(), $dane['cel'], $typ, $pozycja);
        } catch (KonfliktPrzeniesienia $e) {
            return redirect()->route('collections.move.form', ['collection' => $collection, 'typ' => $typ, 'pozycja' => $pozycja])
                ->withInput()
                ->withErrors(['cel' => $e->getMessage()])
                ->with('przeniesienie_konflikt', [
                    'cel' => $e->nazwaCelu,
                    'zrodlo_notatka' => $e->notatkaWZrodle,
                    'cel_notatka' => $e->notatkaWCelu,
                ]);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.move.form', ['collection' => $collection, 'typ' => $typ, 'pozycja' => $pozycja])
                ->withInput()
                ->withErrors(['cel' => $e->getMessage()]);
        }

        $nazwa = $wynik->tytul === null ? 'Ta pozycja' : '„'.$wynik->tytul.'”';

        if (! $wynik->przeniesiono) {
            return redirect()->route('collections.show', $wynik->cel)
                ->with(Komunikat::informacja($nazwa.' jest już w zeszycie „'.$wynik->cel->name.'”. Nic nie zmieniono i nic się nie zdublowało.'));
        }

        return redirect()->route('collections.show', $wynik->cel)
            ->with(Komunikat::sukces(
                $nazwa.' przeniesiono do zeszytu „'.$wynik->cel->name.'” razem z notatką i datą zapisu. '
                .'Żeby to cofnąć, wybierz przy tej pozycji „Przenieś do innego zeszytu” i wskaż zeszyt „'.$wynik->zrodlo->name.'”.',
            ));
    }

    /**
     * @return array{0: string}
     */
    private function kolumna(string $typ, string $pozycja): array
    {
        abort_unless(Str::isUuid($pozycja), 404);

        return [match ($typ) {
            PrzeniesPozycjeMiedzyZeszytami::PRZEPIS => 'recipe_id',
            PrzeniesPozycjeMiedzyZeszytami::WPIS => 'post_id',
            default => abort(404),
        }];
    }
}
