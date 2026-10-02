<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Gotowanie\ZapamietaneGotowania;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Gotowanie zapamiętane na koncie” w „Moje” (#2439). Bez identyfikatora
 * w adresie: lista należy zawsze do zalogowanej osoby, a właściciel pochodzi
 * z sesji, więc nie ma cudzego zasobu do podmiany. Tylko odczyt — nie zmienia
 * `expires_at` ani żadnego postępu. Ekran jest prywatny (`private, no-store`
 * z middleware dla zalogowanych). Widoczność każdego przepisu sprawdza domena.
 */
class GotowanieZapamietaneController extends Controller
{
    public function __invoke(Request $request, ZapamietaneGotowania $zapamietane): View
    {
        // Głębszy offset nie ma sensu: rekordów jest garstka (krótka retencja).
        $od = max(0, min(10_000, (int) $request->query('od', '0')));
        $wynik = $zapamietane->strona($request->user(), $od);

        return view('pages.collections.gotowanie-zapamietane', [
            ...$wynik,
            'od' => $od,
            'nastepne' => $od + ZapamietaneGotowania::NA_STRONE,
            'godziny' => (int) config('kuking.cooking_progress.retention_hours', 24),
        ]);
    }
}
