<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Posts\MojeWpisy;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Moje wpisy” w „Moje” (D-328). Bez identyfikatora w adresie: lista
 * należy zawsze do zalogowanej osoby, więc nie ma cudzej listy, na którą
 * dałoby się wejść podmianą adresu.
 */
class MojeWpisyController extends Controller
{
    public function __invoke(Request $request, MojeWpisy $mojeWpisy): View
    {
        return view('pages.collections.moje-wpisy', [
            'wpisy' => $mojeWpisy->strona($request->user()),
        ]);
    }
}
