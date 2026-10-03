<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\MojeRozmowy;
use App\Domain\Comments\StronaRozmow;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Moje rozmowy” w „Moje” (#2432). Bez identyfikatora w adresie: lista
 * należy zawsze do zalogowanej osoby, więc nie ma cudzej listy, na którą
 * dałoby się wejść podmianą adresu. Dostęp do każdej treści rozstrzyga
 * `MojeRozmowy` przy budowaniu listy, a ekran rozmowy — ponownie po kliknięciu.
 */
class MojeRozmowyController extends Controller
{
    public function __invoke(Request $request, MojeRozmowy $mojeRozmowy): View
    {
        $kursor = $request->query(StronaRozmow::PARAMETR);

        return view('pages.collections.moje-rozmowy', [
            'rozmowy' => $mojeRozmowy->porcja($request->user(), is_string($kursor) ? $kursor : null),
        ]);
    }
}
