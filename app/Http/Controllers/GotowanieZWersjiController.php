<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Gotowanie\WersjaWykonania;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Models\CookedEvent;
use App\Models\RecipeVersion;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * „Gotuj z tej wersji” (#2491, V2, D-333 — paczka E): krokowy tryb gotowania
 * z NIEZMIENNEJ migawki wersji przypiętej do WŁASNEGO wykonania.
 *
 * ZASADY
 *  - Prywatne: wejście przez `CookedEventPolicy::viewVersion` (cudze wykonanie
 *    to 404, jak ekran wersji #2378) i przez `WersjaWykonania` (przepis wciąż
 *    dostępny, wersja nieukryta i niewyretencjonowana). Dostęp jest sprawdzany
 *    przy KAŻDYM odczycie i zapisie postępu — zapamiętany adres ani stan
 *    przeglądarki niczego nie obchodzą.
 *  - Kroki, składniki i minutniki pochodzą z TEJ SAMEJ migawki. Czego migawka
 *    nie ma (brak minutnika, brak kroków), tego nie uzupełniamy dzisiejszym
 *    przepisem; brak kroków daje instrukcję zamiast martwej akcji. Zdjęć nie
 *    pokazujemy (migawka ich nie obejmuje).
 *  - Postęp żyje w sesji pod kluczem `wersja wykonania + indeks kroku migawki`,
 *    NIE pod identyfikatorami dzisiejszych `recipe_steps`, więc nie dotyka
 *    postępu bieżącej wersji (`CookingModeController`) ani innej historycznej
 *    wersji. Minutniki w przeglądarce mają osobny klucz `wersja-<wykonanie>`.
 *  - Zamknięcie i powrót: odhaczenia zostają w sesji tej przeglądarki, dopóki
 *    ktoś ich nie usunie przyciskiem „Zacznij od początku”; zakończenie
 *    gotowania niczego nie kasuje.
 *  - Zapis wykonania idzie osobnym formularzem (`CookedEventController::zakonczZWersji`)
 *    przez zwykłe `RecordCookedEvent`.
 */
class GotowanieZWersjiController extends Controller
{
    public function show(Request $request, CookedEvent $cookedEvent): View
    {
        // 404, nie 403: obcy nie dowiaduje się, że takie wykonanie istnieje.
        abort_unless(Gate::forUser($request->user())->allows('viewVersion', $cookedEvent), 404);

        $cookedEvent->loadMissing('recipe');
        $wersja = WersjaWykonania::dla($cookedEvent, $request->user());

        if ($wersja === null) {
            return view('pages.cooked.gotuj-z-wersji', ['event' => $cookedEvent, 'wersja' => null]);
        }

        $migawka = new MigawkaWersji($wersja->snapshot ?? []);
        $kroki = $migawka->kroki();
        $razem = count($kroki);
        $zadany = $request->query('krok');
        $krok = $razem === 0 ? 0 : max(1, min($razem, is_string($zadany) && ctype_digit($zadany) ? (int) $zadany : 1));
        $zrobione = $this->zrobione($request, $cookedEvent, $wersja);

        return view('pages.cooked.gotuj-z-wersji', [
            'event' => $cookedEvent,
            'recipe' => $cookedEvent->recipe,
            'wersja' => $wersja,
            'migawka' => $migawka,
            'kroki' => $kroki,
            'razem' => $razem,
            'krok' => $krok,
            'aktualny' => $razem === 0 ? null : $kroki[$krok - 1],
            'krokZrobiony' => $razem > 0 && in_array($krok - 1, $zrobione, true),
            'maPostep' => $zrobione !== [],
            'tozsamoscKrokow' => $this->tozsamoscKrokow($wersja, $kroki),
            'idKroku' => fn (int $indeks): string => $this->idKroku($wersja, $indeks),
            'odcisk' => fn (int $indeks): string => $this->odcisk($wersja, $kroki[$indeks]),
            'etykietaMinutnika' => fn (?int $sekundy): ?string => $this->etykietaMinutnika($sekundy),
        ]);
    }

    /** Oznaczenie kroku jako zrobiony/niezrobiony — ustawienie stanu, nie przełącznik. */
    public function mark(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('viewVersion', $cookedEvent), 404);

        $dane = $request->validate([
            'krok' => ['required', 'integer', 'min:1'],
            'zrobiono' => ['required', 'in:0,1'],
        ], [
            'krok.*' => 'Nie wiemy, który to krok. Wróć do gotowania i spróbuj jeszcze raz.',
            'zrobiono.*' => 'Nie wiemy, co zrobić z tym krokiem. Wróć do gotowania i spróbuj jeszcze raz.',
        ]);

        $wersja = WersjaWykonania::dla($cookedEvent, $request->user());

        if ($wersja === null) {
            return $this->wersjaNiedostepna($cookedEvent);
        }

        $razem = count((new MigawkaWersji($wersja->snapshot ?? []))->kroki());
        $numer = (int) $dane['krok'];

        if ($numer > $razem) {
            return redirect()->route('cooked.version.cook', $cookedEvent)
                ->with(Komunikat::blad('Tego kroku nie ma w tej wersji przepisu. Nic nie zostało zapisane.'));
        }

        $zrobione = array_values(array_diff($this->zrobione($request, $cookedEvent, $wersja), [$numer - 1]));
        if ((string) $dane['zrobiono'] === '1') {
            $zrobione[] = $numer - 1;
        }
        sort($zrobione);
        $request->session()->put($this->kluczSesji($cookedEvent, $wersja), $zrobione);

        return redirect()->route('cooked.version.cook', ['cookedEvent' => $cookedEvent, 'krok' => $numer]);
    }

    public function restart(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('viewVersion', $cookedEvent), 404);

        $wersja = WersjaWykonania::dla($cookedEvent, $request->user());

        if ($wersja === null) {
            return $this->wersjaNiedostepna($cookedEvent);
        }

        $request->session()->forget($this->kluczSesji($cookedEvent, $wersja));

        return redirect()->route('cooked.version.cook', $cookedEvent)
            ->with(Komunikat::sukces('Odhaczenia tej wersji usunięte. Zaczynasz od pierwszego kroku.'));
    }

    private function wersjaNiedostepna(CookedEvent $cookedEvent): RedirectResponse
    {
        return redirect()->route('cooked.version.cook', $cookedEvent)
            ->with(Komunikat::blad('Tej wersji przepisu nie możemy już pokazać. Nic nie zostało zapisane.'));
    }

    /** Klucz sesji: wersja wykonania + wersja migawki, bez id dzisiejszych kroków. */
    private function kluczSesji(CookedEvent $wykonanie, RecipeVersion $wersja): string
    {
        return 'gotowanie_wersja.'.$wykonanie->getKey().'.'.$wersja->getKey();
    }

    /** @return list<int> indeksy (od 0) kroków migawki oznaczonych jako zrobione */
    private function zrobione(Request $request, CookedEvent $wykonanie, RecipeVersion $wersja): array
    {
        $zapisane = $request->session()->get($this->kluczSesji($wykonanie, $wersja), []);

        return is_array($zapisane) ? array_values(array_filter($zapisane, 'is_int')) : [];
    }

    private function idKroku(RecipeVersion $wersja, int $indeks): string
    {
        return 'v'.$wersja->getKey().'-'.$indeks;
    }

    /** Odcisk czynności bez zapisywania tekstu przepisu w przeglądarce (jak `RecipeStep::timerFingerprint`). */
    private function odcisk(RecipeVersion $wersja, array $krok): string
    {
        return hash_hmac('sha256', $wersja->getKey()."\0".$krok['instruction']."\0".(string) $krok['timer_seconds'], (string) config('app.key'));
    }

    /**
     * @param  list<array{instruction: string, timer_seconds: ?int, section_name: ?string}>  $kroki
     * @return list<array{id: string, fingerprint: string}>
     */
    private function tozsamoscKrokow(RecipeVersion $wersja, array $kroki): array
    {
        $wynik = [];
        foreach ($kroki as $i => $krok) {
            $wynik[] = ['id' => $this->idKroku($wersja, $i), 'fingerprint' => $this->odcisk($wersja, $krok)];
        }

        return $wynik;
    }

    /** „10 minut”, „1 minutę i 30 sekund” — po „na”, jak `RecipeStep::timerLabel(afterNa: true)`. */
    private function etykietaMinutnika(?int $sekundy): ?string
    {
        if ($sekundy === null || $sekundy <= 0) {
            return null;
        }

        $minuty = intdiv($sekundy, 60);
        $reszta = $sekundy % 60;
        $czesci = [];

        if ($minuty > 0) {
            $czesci[] = $minuty.' '.Odmiana::rzeczownik($minuty, 'minutę', 'minuty', 'minut');
        }
        if ($reszta > 0) {
            $czesci[] = $reszta.' '.Odmiana::rzeczownik($reszta, 'sekundę', 'sekundy', 'sekund');
        }

        return implode(' i ', $czesci);
    }
}
