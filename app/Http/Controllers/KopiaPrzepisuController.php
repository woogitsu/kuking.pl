<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Users\Exports\KopiaJednegoPrzepisu;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Przenośna kopia jednego własnego przepisu (#2531, V2).
 *
 * Dwa kroki bez JavaScriptu: ekran z opisem, co kopia zawiera i czego nie (GET,
 * niczego nie tworzy), i pobranie po osobnym kliknięciu (POST). UUID ani adres
 * nie są autoryzacją: wejście idzie przez `RecipePolicy::exportCopy`, a akcja
 * domenowa jeszcze raz sprawdza autorstwo. Odpowiedź jest prywatna
 * (`no-store`), bez stałego adresu; plik tymczasowy znika po wysłaniu.
 */
class KopiaPrzepisuController extends Controller
{
    public function pokaz(Request $request, string $recipe, KopiaJednegoPrzepisu $kopia): View
    {
        $przepis = Recipe::query()->where('slug', $recipe)->firstOrFail();
        $this->authorize('exportCopy', $przepis);

        $dane = $kopia->dane($przepis, Carbon::now());
        $wiersz = $dane['przepisy'][0];

        return view('pages.recipes.kopia', [
            'przepis' => $przepis,
            'liczbaSkladnikow' => count($wiersz['skladniki']),
            'liczbaKrokow' => count($wiersz['kroki']),
            'maZdjecia' => $przepis->hero_media_id !== null
                || $przepis->source_scan_media_id !== null
                || $przepis->steps->contains(fn ($krok): bool => $krok->media_id !== null),
            'maPochodzenie' => filled($przepis->source_person) || filled($przepis->source_note)
                || filled($przepis->source_url) || $przepis->family_since_year !== null,
            'maSkan' => $przepis->source_scan_media_id !== null,
        ]);
    }

    public function pobierz(Request $request, string $recipe, KopiaJednegoPrzepisu $kopia): BinaryFileResponse
    {
        $przepis = Recipe::query()->where('slug', $recipe)->firstOrFail();
        $this->authorize('exportCopy', $przepis);

        $plik = $kopia->zbuduj($request->user(), $przepis);

        return response()
            ->download($plik['sciezka'], $plik['nazwa'], [
                'Content-Type' => 'application/zip',
                // Prywatne pobranie: żadnego wspólnego cache ani proxy.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
            ])
            ->deleteFileAfterSend(true);
    }
}
