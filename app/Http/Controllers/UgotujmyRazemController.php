<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\UgotujmyRazem\TydzienGotowania;
use App\Domain\UgotujmyRazem\UgotujmyRazem;
use App\Models\WeeklyRecipePick;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Ugotujmy razem” (F3) — strona przepisu tygodnia i archiwum.
 *
 * `/ugotujmy-razem` — bieżący tydzień (czas polski), `/ugotujmy-razem/2026-W39`
 * — tydzień z archiwum. Wejście na tydzień idzie przez
 * `WeeklyRecipePickPolicy::view`; odmowa to 404, nie 403, bo 403 potwierdzałoby,
 * że na ten tydzień jest przepis, którego widz nie może zobaczyć (także plan
 * gospodarza na przyszły tydzień).
 */
class UgotujmyRazemController extends Controller
{
    public function __construct(private readonly UgotujmyRazem $ugotujmyRazem) {}

    public function index(Request $request): View
    {
        $tydzien = TydzienGotowania::biezacy();
        $pick = $this->ugotujmyRazem->dlaTygodnia($tydzien, $request->user());

        return $this->widok($request, $tydzien, $pick);
    }

    public function tydzien(Request $request, string $tydzien): View|RedirectResponse
    {
        $wybrany = TydzienGotowania::zIso($tydzien);
        abort_if($wybrany === null, 404);

        if ($wybrany->jestBiezacy()) {
            return redirect()->route('ugotujmy-razem');
        }

        // Brak wyboru, przyszły tydzień i przepis niewidoczny dla widza
        // odpowiadają tym samym 404 (`UgotujmyRazem::dlaTygodnia` pyta Policy).
        $pick = $this->ugotujmyRazem->dlaTygodnia($wybrany, $request->user());
        abort_if($pick === null, 404);

        return $this->widok($request, $wybrany, $pick);
    }

    private function widok(Request $request, TydzienGotowania $tydzien, ?WeeklyRecipePick $pick): View
    {
        return view('pages.ugotujmy-razem', [
            'tydzien' => $tydzien,
            'biezacy' => $tydzien->jestBiezacy(),
            'pick' => $pick,
            'wykonania' => $pick === null ? null : $this->ugotujmyRazem->wykonania($pick, $request->user()),
            'archiwum' => $this->ugotujmyRazem->archiwum($request->user()),
        ]);
    }
}
