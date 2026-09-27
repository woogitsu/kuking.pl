<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SlugGfm;
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
 * „OD ALFA 0.68.NNN" PRZY KAŻDEJ FUNKCJI, NA STAŁE (#1932, D-318)
 * `kuking:zarejestruj-wdrozenie` zapisuje w `wdrozenia_funkcje`, pod jakim
 * numerem KAŻDY nagłówek `###` sekcji „## Najnowsze zmiany" pojawił się
 * PIERWSZY RAZ (patrz `App\Domain\Wydania\Actions\ZarejestrujWdrozenie`).
 * Ta strona doczytuje tę mapę i dokleja jedną kursywną linijkę pod KAŻDYM
 * nagłówkiem `###` W CAŁYM DOKUMENCIE, którego slug ma wiersz w tabeli —
 * nie tylko w „Najnowsze zmiany".
 *
 * DECYZJA WŁAŚCICIELA Z 26 WRZEŚNIA 2026: dopisek zostaje NA STAŁE. Gdy
 * opis funkcji przechodzi z „Najnowsze zmiany" do sekcji nazwanego wydania
 * (np. „## Alfa 0.69"), dalej ma pokazywać numer, pod którym funkcja
 * pojawiła się PIERWSZY RAZ — nie znika i nie przeskakuje na numer
 * bieżącego wdrożenia. Dlatego dopasowanie jest PO SAMYM SLUGU nagłówka,
 * niezależnie od tego, w której sekcji nagłówek dziś stoi i jaka jest
 * BIEŻĄCA `App\Support\Wersja::etykieta()` — `wdrozenia_funkcje.naglowek_slug`
 * jest `UNIQUE` sam w sobie (nie para etykieta+slug), więc każdy nagłówek ma
 * dokładnie jeden wiersz, na zawsze, i to WŁASNA etykieta tego wiersza
 * (zapisana przy pierwszym pojawieniu się, nie bieżąca etykieta aplikacji)
 * trafia do „od {etykieta}.{numer}".
 *
 * Bez wiersza w bazie (lokalnie, w testach, przy awarii bazy, i dla
 * nagłówka, pod którym jeszcze nie przeszło ŻADNE wdrożenie — w tym każdy
 * nagłówek z wydań SPRZED tej funkcji, #1932, którym nikt nie przypisze
 * numeru wstecznie) nagłówek zostaje BEZ dopisku — ten sam wybór co
 * w `App\Support\Wersja`: brakująca informacja znika po cichu, nie wywala
 * strony.
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
     * Dokleja „_od {etykieta}.{numer}_" pod każdym nagłówkiem `###` CAŁEGO
     * dokumentu, dla którego znamy numer pierwszego wdrożenia — niezależnie
     * od tego, czy nagłówek dziś stoi w „## Najnowsze zmiany", czy już
     * w sekcji nazwanego wydania (decyzja właściciela z 26 września 2026,
     * patrz komentarz klasy).
     */
    private function dopiszOdNumeru(string $tresc): string
    {
        $mapa = $this->numeryFunkcji();

        if ($mapa === []) {
            return $tresc;
        }

        return preg_replace_callback(
            '/^###\s+(.+)$/mu',
            function (array $m) use ($mapa): string {
                $slug = SlugGfm::z(trim($m[1]));
                $dopisek = $mapa[$slug] ?? null;

                if ($dopisek === null) {
                    return $m[0];
                }

                return $m[0]."\n\n_od {$dopisek}_";
            },
            $tresc,
        );
    }

    /**
     * @return array<string, string> slug nagłówka => „{etykieta}.{numer}"
     *                               (etykieta i numer WŁASNE tego wiersza — z pierwszego pojawienia
     *                               się nagłówka, nie bieżąca `Wersja::etykieta()`).
     */
    private function numeryFunkcji(): array
    {
        try {
            return DB::table('wdrozenia_funkcje')
                ->get(['naglowek_slug', 'etykieta', 'numer'])
                ->mapWithKeys(static fn ($wiersz) => [
                    $wiersz->naglowek_slug => $wiersz->etykieta.'.'.str_pad((string) $wiersz->numer, 3, '0', STR_PAD_LEFT),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
