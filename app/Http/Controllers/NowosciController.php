<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ZaufanyMarkdown;
use Illuminate\View\View;
use RuntimeException;

/**
 * Strona „Co nowego” pod numerem wersji w stopce (issue #1909).
 *
 * PUBLICZNA, ŚWIADOMIE — decyzja właściciela z 26 września 2026: kto jest
 * ciekawy, co doszło w danym wydaniu, ma to zobaczyć bez logowania, tak jak
 * `/zasady` czy `/regulamin`. Nie ma tu żadnego zasobu należącego do
 * konkretnej osoby, więc nie ma czego chronić `Policy` (AGENTS.md §7 mówi
 * o UUID w adresie cudzego zasobu — tu nie ma ani UUID, ani zasobu).
 *
 * Treść jest jednym plikem Markdown w repozytorium
 * (`resources/nowosci/tresc.md`), nie automatem z `CHANGELOG.md` — decyzja
 * właściciela wprost tego zakazuje: CHANGELOG zostaje pełną, techniczną
 * listą, a ta strona ma osobny, krótki opis pisany dla czytelników. Plik
 * poprawia się Pull Requestem jak `resources/legal/*.md`, a renderuje przez
 * ten sam `App\Support\ZaufanyMarkdown` co strony prawne — bez JavaScriptu.
 */
class NowosciController extends Controller
{
    public function index(): View
    {
        $path = resource_path('nowosci/tresc.md');

        if (! is_file($path)) {
            throw new RuntimeException('Brak dokumentu resources/nowosci/tresc.md');
        }

        return view('pages.static.legal', [
            'pageTitle' => 'Co nowego w Kuking',
            'pageDescription' => 'Co nowego w Kuking: nowe funkcje po kolei, wydanie po wydaniu, i krótkie podsumowanie poprawek.',
            'html' => ZaufanyMarkdown::doHtml(file_get_contents($path)),
        ]);
    }
}
