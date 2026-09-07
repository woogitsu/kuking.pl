<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Str;
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
        return $this->markdown('zasady', 'Zasady Kuking');
    }

    public function terms(): View
    {
        return $this->markdown('regulamin', 'Regulamin');
    }

    public function privacy(): View
    {
        return $this->markdown('polityka-prywatnosci', 'Polityka prywatności');
    }

    private function markdown(string $slug, string $title): View
    {
        $path = resource_path("legal/{$slug}.md");

        if (! is_file($path)) {
            throw new RuntimeException("Brak dokumentu resources/legal/{$slug}.md");
        }

        $html = Str::markdown(file_get_contents($path), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return view('pages.static.legal', [
            'pageTitle' => $title,
            // Str::markdown korzysta z league/commonmark w trybie bezpiecznym:
            // surowy HTML z pliku nie jest renderowany. Te pliki są nasze,
            // ale zasada „nie renderuj cudzego HTML-a” obowiązuje wszędzie.
            'html' => $this->owinTabelePrzewijaniem($html),
        ]);
    }

    /**
     * Tabele w dokumentach prawnych (dziś: polityka prywatności) są zbyt
     * szerokie na 320 px. Bez tego owinięcia CSS (`.prose table { display:
     * block; overflow-x: auto }`) potrafi wprawdzie ograniczyć przewijanie
     * do samej tabeli zamiast całej strony (WCAG 1.4.10, zmierzone
     * `scripts/dostepnosc.mjs`) — ALE bez atrybutu, po którym coś w ogóle
     * dałoby się sfokusować, to przewijanie nie jest osiągalne z klawiatury.
     * Osoba, która nie używa myszy ani ekranu dotykowego, w ogóle nie
     * dowie się, że w tabeli jest więcej kolumn — axe to złapał:
     * `scrollable-region-focusable` (WCAG 2.1.1 Klawiatura, waga „serious").
     *
     * `tabindex="0"` wpisuje przewijaną tabelę w kolejność Tab, a
     * `role="group"` + `aria-label` mówią czytnikowi ekranu, NA CO trafił —
     * bez tego usłyszałby gołe „grupa”, nic nie znaczące.
     *
     * Zwykły `str_replace` wystarcza: `Str::markdown` w tym trybie
     * (`html_input: escape`) nigdy nie zagnieżdża `<table>` w `<table>`,
     * a treść dokumentów jest nasza, więc nie ma tu nic do oszukania.
     */
    private function owinTabelePrzewijaniem(string $html): string
    {
        $html = str_replace(
            '<table>',
            '<div class="table-scroll" tabindex="0" role="group" aria-label="Tabela, przewijana w poziomie"><table>',
            $html,
        );

        return str_replace('</table>', '</table></div>', $html);
    }
}
