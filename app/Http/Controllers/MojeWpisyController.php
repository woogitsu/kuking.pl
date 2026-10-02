<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Posts\FrazaWMoichWpisach;
use App\Domain\Posts\MojeWpisy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Moje wpisy” w „Moje” (D-328). Bez identyfikatora w adresie: lista
 * należy zawsze do zalogowanej osoby, więc nie ma cudzej listy, na którą
 * dałoby się wejść podmianą adresu.
 */
class MojeWpisyController extends Controller
{
    public function __invoke(Request $request, MojeWpisy $mojeWpisy): View|RedirectResponse
    {
        $fraza = FrazaWMoichWpisach::zAdresu($request->query('szukaj'));
        $wpisy = $mojeWpisy->strona($request->user(), $fraza);

        // Stary adres po usunięciu wpisu lub wyłączeniu pytań nie oznacza
        // pustego dorobku. Tak jak w zeszycie (#908) wracamy do zakresu listy.
        if ($wpisy->currentPage() > $wpisy->lastPage()) {
            $request->session()->reflash();

            return redirect()->route('collections.own-posts',
                ($wpisy->lastPage() > 1 ? ['page' => $wpisy->lastPage()] : [])
                + ($fraza->aktywna() ? ['szukaj' => $fraza->fraza] : []));
        }

        return view('pages.collections.moje-wpisy', [
            'wpisy' => $wpisy,
            'fraza' => $fraza,
            // Pole szukania ma sens tylko, gdy jest czego szukać; przy wpisanej frazie zostaje zawsze.
            'maWpisy' => $fraza->fraza !== '' || $mojeWpisy->maWpisy($request->user()),
        ]);
    }
}
