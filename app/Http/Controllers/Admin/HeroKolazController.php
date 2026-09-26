<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Feed\Actions\ZapiszKolaz;
use App\Domain\Feed\HeroKolaz;
use App\Http\Controllers\Controller;
use App\Models\HeroPick;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wybór zdjęć do kolażu w hero strony powitalnej.
 *
 * Zgłoszenie właściciela: „dodaj funkcję w panelu admina by ustawiać te
 * zdjęcia spośród wszystkich publicznych od użytkowników".
 *
 * Ekran jest z tej samej rodziny co `/admin/kuking-na-dzis` i świadomie
 * trzyma się tamtej konwencji: jedna strona, lista świeżych wpisów, jedno
 * pole wyboru przy każdym zdjęciu, jeden przycisk „Zapisz" i osobna strefa
 * czyszczenia. Bez kreatora, bez sortowania po popularności i bez ani jednej
 * liczby, która podpowiadałaby wybór — gospodarz ma patrzeć na zdjęcia,
 * nie na słupki.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  BRAMKA WIDOCZNOŚCI JEST W DOMENIE, NIE W REGULE WALIDACJI
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Zapisujemy WYŁĄCZNIE to, co w chwili zapisu przepuszcza
 * `HeroKolaz::dopuszczZdjecia()` — czyli zdjęcia przy wpisach publicznych
 * i opublikowanych, od kont aktywnych, w stanie `ready`. Wszystko inne
 * wypada po cichu z zapisu i gospodarz dostaje o tym zdanie w komunikacie.
 *
 * Sama bramka zapisu NIE WYSTARCZA i nie ma wystarczać: wpis może zmienić
 * widoczność albo autor może zostać zawieszony PO wyborze. Dlatego ten sam
 * filtr liczy się drugi raz przy każdym wyświetleniu strony powitalnej
 * (`HeroKolaz::doKolazu()`). Bramka zapisu jest tu po to, żeby gospodarz nie
 * zapisał wyboru, który i tak nigdy się nie pokaże — ochroną przed wyciekiem
 * jest ten drugi filtr.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  `moderate` Z POLICY, NIE SAMO MIDDLEWARE
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Middleware grupy `/admin` pilnuje wejścia do panelu; o prawie do TEJ akcji
 * rozstrzyga `UserPolicy::moderate` — ten sam wzorzec co w pozostałych
 * kontrolerach panelu. Identyfikatory w formularzu to UUID-y cudzych zdjęć,
 * a UUID nie jest autoryzacją (AGENTS.md §7).
 */
class HeroKolazController extends Controller
{
    /**
     * Ile wpisów pokazujemy do wyboru. Liczba jak w `DailyBoardController`
     * (tam 40 wpisów z 7 dni) — tu nieco większa i BEZ ograniczenia czasem,
     * bo kolaż nie jest tablicą dnia: raz ustawiony ma stać tygodniami,
     * a najładniejsze zdjęcie w serwisie nie przestaje nim być po tygodniu.
     */
    private const KANDYDATOW = 60;

    public function __construct(
        private readonly HeroKolaz $kolaz,
        private readonly ZapiszKolaz $zapis,
    ) {}

    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        return view('pages.admin.kolaz-powitalny', [
            'kandydaci' => $this->kolaz->kandydaci(self::KANDYDATOW),
            'wybrane' => HeroPick::query()
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('media_id')
                ->map(fn ($id) => (string) $id)
                ->all(),
            'slotow' => HeroKolaz::SLOTOW,
            'podglad' => $this->kolaz->doKolazu(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'zdjecia' => ['nullable', 'array', 'max:'.HeroKolaz::SLOTOW],
            'zdjecia.*' => ['uuid'],
        ], [
            'zdjecia.max' => 'Wybierz najwyżej '.HeroKolaz::SLOTOW.' zdjęcia. Kolaż ma tyle miejsc.',
        ]);

        /** @var list<string> $zadane */
        $zadane = array_values(array_unique(array_map('strval', $data['zdjecia'] ?? [])));

        $dopuszczone = $this->kolaz->dopuszczZdjecia($zadane);

        // Blokada kolażu, zastąpienie całego wyboru i wpis audytu w jednej
        // transakcji — uzasadnienie w `ZapiszKolaz` (#1027, #1329).
        $this->zapis->zastap($request->user(), $zadane, $dopuszczone, $request->ip());

        return back()->with('status', $this->komunikat(count($dopuszczone), count($zadane) - count($dopuszczone)));
    }

    /** Czyści wybór — kolaż wraca do doboru automatycznego. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $this->zapis->wyczysc($request->user(), $request->ip());

        return back()->with('status', 'Wyczyszczone. Kolaż dobierze zdjęcia sam — najnowsze publiczne, najpierw po jednym od osoby, a w razie potrzeby po dwa.');
    }

    private function komunikat(int $zapisanych, int $odrzuconych): string
    {
        $odrzucone = $odrzuconych > 0
            ? " {$odrzuconych} pominęliśmy — te wpisy przestały być publiczne albo konto autora nie jest już aktywne."
            : '';

        if ($zapisanych === 0) {
            return 'Nic nie zostało wybrane. Kolaż dobierze zdjęcia sam.'.$odrzucone;
        }

        $brakuje = HeroKolaz::SLOTOW - $zapisanych;

        $uzupelnienie = $brakuje > 0
            ? " Brakujące {$brakuje} dobierzemy automatycznie z najnowszych publicznych zdjęć."
            : '';

        return "Zapisane. W kolażu stoi {$zapisanych} z wybranych zdjęć.".$uzupelnienie.$odrzucone;
    }
}
