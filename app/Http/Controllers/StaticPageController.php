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

        return view('pages.static.legal', [
            'pageTitle' => $title,
            // Str::markdown korzysta z league/commonmark w trybie bezpiecznym:
            // surowy HTML z pliku nie jest renderowany. Te pliki są nasze,
            // ale zasada „nie renderuj cudzego HTML-a” obowiązuje wszędzie.
            'html' => Str::markdown(file_get_contents($path), [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }
}
