<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Kolejka\NieudaneZadania;
use App\Domain\Kolejka\StanKolejki;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

/**
 * `/admin/kolejka` — DLACZEGO `/health` mówi `degraded`.
 *
 * Ekran powstał, bo stan `degraded` z powodem `zadania_nieudane` trwał od
 * 9 września 2026 i nikt nie umiał powiedzieć, co go trzyma. Narzędzia
 * istniały — `kuking:martwe-zadania`, `kuking:kto-nie-dostal-listu`,
 * `kuking:sprawdz-kolejke` — ale wszystkie trzy wymagają POWŁOKI SERWERA,
 * której na Railway nie ma (`proc_open` wyłączony w `docker/php.ini`),
 * i bazy produkcyjnej, do której nie ma połączenia. Przyrząd stojący po
 * drugiej stronie ściany nie jest przyrządem.
 *
 * CZEGO TEN EKRAN NIE ROBI
 * Niczego nie ponawia, nie kasuje i nie dotyka kolejki — wyłącznie `SELECT`.
 * To jest świadome: `queue:retry` na starym żeton resetu hasła wysyła
 * człowiekowi martwy link, a `failed_jobs` to jedyny ślad po awarii.
 * Decyzja o wyrzuceniu wiersza zapada w `kuking:martwe-zadania`, po tym,
 * jak ktoś zobaczy, kogo dotyczył.
 *
 * DLACZEGO DWIE LICZBY, A NIE JEDNA
 * `/health` liczy WSZYSTKIE wiersze `failed_jobs` i dlatego od 9 września
 * świeci nieprzerwanie. `StanKolejki` (issue #599) pyta o ZDARZENIE — co
 * padło w oknie ostatnich godzin — i mierzy zaległość, czyli jedyny
 * sygnał widzący MARTWEGO workera. Na ekranie są obie, bo odpowiadają na
 * dwa różne pytania: „czy coś się psuje teraz" i „co tu w ogóle zalega".
 */
class KolejkaController extends Controller
{
    public function index(NieudaneZadania $nieudane, StanKolejki $stan): View
    {
        $this->authorize('diagnozujKolejke', User::class);

        return view('pages.admin.kolejka', [
            'nieudane' => $nieudane->pogrupowane(),
            'stan' => $stan->sprawdz(),
        ]);
    }
}
