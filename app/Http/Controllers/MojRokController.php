<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\MojRok\MojRok;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Mój rok w kuchni” (#2353) — prywatne archiwum. Bez identyfikatora konta
 * w adresie: ekran zawsze liczy się z danych zalogowanej osoby, więc nie ma
 * cudzego podsumowania, na które dałoby się wejść podmianą adresu. Rok
 * w adresie jest tylko liczbą z ograniczonego zakresu.
 */
class MojRokController extends Controller
{
    public function __invoke(Request $request, MojRok $mojRok, ?int $rok = null): View
    {
        $this->authorize('viewMyYear', User::class);

        /** @var User $user */
        $user = $request->user();

        $biezacy = MojRok::biezacyRok();
        $rok ??= $biezacy;

        abort_if($rok > $biezacy || $rok < $biezacy - MojRok::MAKS_LAT_WSTECZ, 404);

        if (! $user->memories_enabled) {
            return view('pages.moj-rok.wylaczony');
        }

        return view('pages.moj-rok.show', [
            'rok' => $rok,
            'biezacy' => $biezacy,
            'lata' => $mojRok->lata($user),
            ...$mojRok->podsumowanie($user, $rok),
        ]);
    }
}
