<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renderuje Markdown z PLIKÓW W REPOZYTORIUM — nigdy z treści użytkownika.
 *
 * Wydzielone z `StaticPageController` przy issue #1909 („Co nowego"), żeby
 * druga strona z zaufanym Markdownem (`resources/nowosci/tresc.md`) nie
 * duplikowała ustawień bezpieczeństwa. Rozjazd między dwiema kopiami tej
 * samej logiki jest dokładnie tym ryzykiem, przed którym ostrzega AGENTS.md:
 * ktoś poprawi jedną, zapomni o drugiej.
 *
 * `html_input: escape` + `allow_unsafe_links: false` znaczą: surowy HTML
 * wpisany w pliku Markdown nie jest renderowany (idzie jako tekst), a link
 * `javascript:`/`data:` nie staje się klikalnym odnośnikiem. Pliki, które
 * tędy przechodzą, są NASZE (redaguje je zespół, trafiają do repo przez Pull
 * Request) — ale zasada „nie renderuj cudzego HTML-a" (AGENTS.md §7)
 * obowiązuje tu tak samo, bez wyjątku na „przecież to nasz plik".
 */
final class ZaufanyMarkdown
{
    public static function doHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return self::owinTabelePrzewijaniem($html);
    }

    /**
     * Tabele w dokumentach z Markdownu są zbyt szerokie na 320 px. Bez tego
     * owinięcia CSS (`.prose table { display: block; overflow-x: auto }`)
     * ogranicza przewijanie do samej tabeli — ALE bez atrybutu, po którym
     * coś dałoby się sfokusować, to przewijanie nie jest osiągalne
     * z klawiatury (WCAG 2.1.1, `scrollable-region-focusable`).
     *
     * `tabindex="0"` wpisuje przewijaną tabelę w kolejność Tab, `role="group"`
     * + `aria-label` mówią czytnikowi ekranu, na co trafił.
     *
     * Zwykły `str_replace` wystarcza: `Str::markdown` w trybie
     * `html_input: escape` nigdy nie zagnieżdża `<table>` w `<table>`.
     */
    private static function owinTabelePrzewijaniem(string $html): string
    {
        $html = str_replace(
            '<table>',
            '<div class="table-scroll" tabindex="0" role="group" aria-label="Tabela, przewijana w poziomie"><table>',
            $html,
        );

        return str_replace('</table>', '</table></div>', $html);
    }
}
