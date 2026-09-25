<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\PrzestawZgodeNaZyczeniaMailem;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\View\View;

/**
 * Wypisanie z listu z życzeniami urodzinowymi (issue #1755, etap c).
 *
 * Jednym kliknięciem, bez logowania i bez pytania o powód — ten sam powód co
 * przy tygodniowym podsumowaniu (`PodsumowanieTygodniaController`): osoba,
 * która nie pamięta hasła, zamiast się wypisać oznacza list jako spam.
 * Autoryzacją jest podpis (`middleware('signed')`), nie identyfikator.
 */
class UrodzinyWypiszController extends Controller
{
    public function __invoke(User $user, PrzestawZgodeNaZyczeniaMailem $zgoda): View
    {
        $zgoda->handle($user, false, WpisZgody::ZRODLO_LINK_WYPISANIA);

        return view('pages.urodziny-wypisano');
    }
}
