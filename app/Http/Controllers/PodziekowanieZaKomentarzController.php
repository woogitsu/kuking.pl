<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\ThankForComment;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Requests\Comments\PodziekowanieZaKomentarzRequest;
use App\Models\Comment;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;

/**
 * „Dziękuję" pod komentarzem (issue #2355, F11) — zwykły formularz POST,
 * bez JavaScriptu. Wejście (Policy) sprawdza
 * `PodziekowanieZaKomentarzRequest`, przypadek użycia i idempotencję —
 * `ThankForComment`.
 */
class PodziekowanieZaKomentarzController extends Controller
{
    public function __construct(private readonly ThankForComment $thank) {}

    public function store(PodziekowanieZaKomentarzRequest $request, Comment $comment): RedirectResponse
    {
        // Jawne `authorize()` zostaje tu celowo, jak w `CookedEventController::thank()`:
        // bramkę na trasie z wiązaniem modelu widać w kontrolerze
        // (`AutoryzacjaTrasZWiazaniemModeluTest`).
        $this->authorize('thank', $comment);

        try {
            $powstalo = $this->thank->handle($request->user(), $comment);
        } catch (BladDlaCzlowieka $e) {
            return back()->with(Komunikat::informacja($e->getMessage()));
        }

        $komunikat = $powstalo
            ? Komunikat::sukces('Podziękowanie wysłane. Autor komentarza dostanie o nim powiadomienie.')
            : Komunikat::informacja('Za ten komentarz już podziękowano. Nic więcej nie trzeba robić.');

        return back()->withFragment('komentarz-'.$comment->getKey())->with($komunikat);
    }
}
