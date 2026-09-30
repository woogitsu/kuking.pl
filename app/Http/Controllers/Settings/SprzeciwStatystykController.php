<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Actions\PrzestawSprzeciwWobecStatystyk;
use App\Http\Controllers\Controller;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Nie licz mnie w statystykach” w ustawieniach prywatności (#2277).
 *
 * Dwa przyciski, bez pola wyboru: powtórne kliknięcie niczego nie psuje,
 * więc nie potrzeba tu ochrony przed nieaktualnym formularzem, jaką ma zapis
 * zgód (`PrivacySettingsController`). Trasy są za `auth` i limitem ustawień.
 */
class SprzeciwStatystykController extends Controller
{
    public function store(Request $request, PrzestawSprzeciwWobecStatystyk $sprzeciw): RedirectResponse
    {
        $sprzeciw->zglos($request->user());

        return redirect()->to(route('settings.privacy').'#statystyki')
            ->with(Komunikat::sukces('Zapisane. Nie liczymy Cię już w statystykach.'));
    }

    public function destroy(Request $request, PrzestawSprzeciwWobecStatystyk $sprzeciw): RedirectResponse
    {
        $sprzeciw->cofnij($request->user());

        return redirect()->to(route('settings.privacy').'#statystyki')
            ->with(Komunikat::sukces('Zapisane. Od teraz znów liczymy Twoje wizyty w statystykach.'));
    }
}
