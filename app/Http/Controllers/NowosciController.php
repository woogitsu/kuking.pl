<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SlugGfm;
use App\Support\Wersja;
use App\Support\ZaufanyMarkdown;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

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
 *
 * „OD ALFA 0.68.NNN" PRZY KAŻDEJ FUNKCJI Z „## NAJNOWSZE ZMIANY" (#1932)
 * `kuking:zarejestruj-wdrozenie` zapisuje w `wdrozenia_funkcje`, pod jakim
 * numerem KAŻDY nagłówek `###` tej sekcji pojawił się PIERWSZY RAZ (patrz
 * `App\Domain\Wydania\Actions\ZarejestrujWdrozenie`). Ta strona doczytuje tę
 * mapę i dokleja jedną kursywną linijkę pod każdym takim nagłówkiem —
 * WYŁĄCZNIE w sekcji „Najnowsze zmiany": wydania już opisane w osobnych
 * sekcjach (np. „## Alfa 0.68 — …") mają swój opis napisany ręcznie, jako
 * proza, i tej wstawki nie dostają.
 *
 * Bez wiersza w bazie (lokalnie, w testach, przy awarii bazy, i dla
 * nagłówka, pod którym jeszcze nie przeszło ŻADNE wdrożenie) nagłówek
 * zostaje BEZ dopisku — ten sam wybór co w `App\Support\Wersja`: brakująca
 * informacja znika po cichu, nie wywala strony.
 */
class NowosciController extends Controller
{
    public function index(): View
    {
        $path = (string) config('kuking.nowosci.tresc');

        if (! is_file($path)) {
            throw new RuntimeException('Brak dokumentu resources/nowosci/tresc.md');
        }

        $tresc = (string) file_get_contents($path);
        $tresc = $this->dopiszOdNumeru($tresc);

        return view('pages.static.legal', [
            'pageTitle' => 'Co nowego w Kuking',
            'pageDescription' => 'Co nowego w Kuking: nowe funkcje po kolei, wydanie po wydaniu, i krótkie podsumowanie poprawek.',
            'html' => ZaufanyMarkdown::doHtml($tresc),
        ]);
    }

    /**
     * Dokleja „_od Alfa 0.68.NNN_" pod każdym nagłówkiem `###` sekcji
     * „## Najnowsze zmiany", dla którego znamy numer pierwszego wdrożenia.
     */
    private function dopiszOdNumeru(string $tresc): string
    {
        $mapa = $this->numeryFunkcji();

        if ($mapa === []) {
            return $tresc;
        }

        $wzor = '/^##\s+Najnowsze zmiany\R(.*?)(?=^##\s|\z)/ms';

        if (preg_match($wzor, $tresc, $dopasowanie, PREG_OFFSET_CAPTURE) !== 1) {
            return $tresc;
        }

        [$sekcja, $offset] = $dopasowanie[1];

        $poprawiona = preg_replace_callback(
            '/^###\s+(.+)$/mu',
            function (array $m) use ($mapa): string {
                $slug = SlugGfm::z(trim($m[1]));
                $numer = $mapa[$slug] ?? null;

                if ($numer === null) {
                    return $m[0];
                }

                return $m[0]."\n\n_od ".Wersja::etykieta().'.'.str_pad((string) $numer, 3, '0', STR_PAD_LEFT).'_';
            },
            $sekcja,
        );

        return substr($tresc, 0, $offset).$poprawiona.substr($tresc, $offset + strlen($sekcja));
    }

    /** @return array<string, int> slug nagłówka => numer wdrożenia, dla bieżącej etykiety. */
    private function numeryFunkcji(): array
    {
        try {
            return DB::table('wdrozenia_funkcje')
                ->where('etykieta', Wersja::etykieta())
                ->pluck('numer', 'naglowek_slug')
                ->map(static fn ($numer) => (int) $numer)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
