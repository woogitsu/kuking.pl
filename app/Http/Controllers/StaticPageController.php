<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ZaufanyMarkdown;
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
        );
    }

    public function privacy(): View
    {
        return $this->markdown(
            'polityka-prywatnosci',
            'Polityka prywatności',
            'Polityka prywatności Kuking: jakie dane zbieramy, po co je przechowujemy i jak pobrać albo usunąć swoje dane.',
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
    private function markdown(string $slug, string $title, string $description): View
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
            'html' => ZaufanyMarkdown::doHtml(file_get_contents($path)),
        ]);
    }
}
