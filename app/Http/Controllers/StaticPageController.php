<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\JakDobieramyWpisy;
use App\Domain\Ukrycia\Ukrycia;
use App\Domain\Zgody\ArchiwumDokumentu;
use App\Support\ZaufanyMarkdown;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Strony statyczne: pomoc, zasady, regulamin, prywatność.
 *
 * Treść prawna i regulaminowa żyje jako Markdown w `resources/legal/`,
 * a nie w Blade. Powód jest praktyczny: te dokumenty poprawia prawnik
 * i osoba nietechniczna. Markdown da się im wysłać, odesłać i porównać
 * w Pull Requeście; Blade z klasami CSS — nie.
 */
class StaticPageController extends Controller
{
    public function help(): View
    {
        return view('pages.static.help');
    }

    public function about(): View
    {
        return view('pages.static.about');
    }

    public function rules(): View
    {
        return $this->markdown(
            'zasady',
            'Zasady Kuking',
            'Zasady Kuking: publikuj własne zdjęcia i przepisy, szanuj innych i zgłaszaj treści, które Cię niepokoją.',
        );
    }

    public function terms(): View
    {
        return $this->markdown(
            'regulamin',
            'Regulamin',
            'Regulamin Kuking: zasady publikowania zdjęć i przepisów, prawa autorskie, moderacja treści i usuwanie konta.',
            // Pobranie i wszystkie wersje (#2220, kryterium 4) — patrz
            // `ArchiwumDokumentuController`.
            ['archiwum' => ArchiwumDokumentu::regulamin()],
        );
    }

    /**
     * „Jak dobieramy wpisy" (#1811, D-305). Zdania stoją w
     * `JakDobieramyWpisy`, nie w widoku — `JakDobieramyWpisyMowiPrawdeTest`
     * wiąże każde z kodem. Zalogowany dostaje pod spodem drogę do własnych
     * ustawień: obserwowane osoby, tagi i listę „Ukryte".
     */
    public function feedRules(Request $request, Ukrycia $ukrycia): View
    {
        $user = $request->user();

        return view('pages.static.jak-dobieramy-wpisy', [
            'sekcje' => JakDobieramyWpisy::sekcje(),
            'ukryteOsoby' => $user !== null ? $ukrycia->ileOsob($user) : 0,
            'ukryteWpisy' => $user !== null ? $ukrycia->ileWpisow($user) : 0,
        ]);
    }

    public function privacy(): View
    {
        return $this->markdown(
            'polityka-prywatnosci',
            'Polityka prywatności',
            'Polityka prywatności Kuking: jakie dane zbieramy, po co je przechowujemy i jak pobrać albo usunąć swoje dane.',
            // Pobranie i wszystkie wersje (#2220) — jak przy regulaminie.
            ['archiwum' => ArchiwumDokumentu::polityka()],
        );
    }

    /**
     * Meta description OSOBNO dla każdej strony prawnej (issue #191).
     *
     * Jeden wspólny opis dla trzech różnych dokumentów byłby dokładnie tym
     * szablonem „Strona X w serwisie Y", którego to zgłoszenie prosi
     * unikać — a w wynikach Google trzy identyczne opisy pod trzema różnymi
     * tytułami wyglądają na pomyłkę. Każdy tekst mówi, co NAPRAWDĘ jest
     * w danym dokumencie, nie tylko jak się nazywa.
     */
    /** @param  array<string, mixed>  $dodatkowe */
    private function markdown(string $slug, string $title, string $description, array $dodatkowe = []): View
    {
        $path = resource_path("legal/{$slug}.md");

        if (! is_file($path)) {
            throw new RuntimeException("Brak dokumentu resources/legal/{$slug}.md");
        }

        return view('pages.static.legal', [
            'pageTitle' => $title,
            'pageDescription' => $description,
            // Renderowanie i zabezpieczenia — patrz `App\Support\ZaufanyMarkdown`.
            // Te pliki są nasze, ale zasada „nie renderuj cudzego HTML-a”
            // (AGENTS.md §7) obowiązuje tu tak samo, bez wyjątku.
            'html' => $this->kotwicaZmian(ZaufanyMarkdown::doHtml(file_get_contents($path))),
            ...$dodatkowe,
        ]);
    }

    /**
     * Kotwica `#co-sie-zmienilo` przy sekcji „Co się zmieniło" (#1811, D-306).
     * Pasek „Zmieniliśmy regulamin" prowadzi prosto do niej. Markdown w trybie
     * bezpiecznym nie nadaje nagłówkom identyfikatorów, więc dokładamy jeden,
     * nazwany — tylko dla tego nagłówka, żeby nie zmieniać reszty dokumentów.
     */
    private function kotwicaZmian(string $html): string
    {
        return str_replace('<h2>Co się zmieniło</h2>', '<h2 id="co-sie-zmienilo">Co się zmieniło</h2>', $html);
    }
}
