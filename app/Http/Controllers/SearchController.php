<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Search\SearchQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wyszukiwanie. Parametr nazywa się `q`, ale zakładka po polsku: `sekcja`.
 *
 * Strona /search nigdy nie jest indeksowana (noindex) — to nie jest treść,
 * a niekończące się kombinacje zapytań to klasyczna pułapka SEO.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchQuery $search) {}

    public function index(Request $request): View
    {
        $phrase = trim((string) $request->query('q', ''));
        $section = $request->query('sekcja') === 'ludzie' ? 'ludzie' : 'przepisy';

        return view('pages.search', [
            'phrase' => $phrase,
            'section' => $section,
            // Widz przekazywany po to, żeby wyszukiwarka respektowała blokady
            // (issue #41). Bez niego blokada kończyła się na widoku i liście.
            'recipes' => $section === 'przepisy' ? $this->search->recipes($phrase, $request->user()) : collect(),
            'people' => $section === 'ludzie' ? $this->search->people($phrase, $request->user()) : collect(),
        ]);
    }
}
