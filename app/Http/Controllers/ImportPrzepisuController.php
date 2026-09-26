<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\LimitImportu;
use App\Domain\Import\Pdf\OdczytajPrzepisZPdf;
use App\Domain\Import\Url\OdczytajPrzepisZAdresu;
use App\Domain\Import\Url\PobieraczStron;
use App\Models\PrzepisZImportu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Import przepisu z adresu strony i z pliku PDF (V2, D-300).
 *
 * Cienki kontroler: auth (grupa tras), autoryzacja (`create` na Recipe),
 * walidacja kształtu, limit (`LimitImportu` + throttle trasy), a cała reszta
 * w `app/Domain/Import`. Wynik to ZAWSZE prywatny szkic otwierany w kreatorze
 * — nigdy publikacja.
 *
 * Odczyt idzie w tym żądaniu: JSON-LD i PDF z tekstem są lokalne i szybkie,
 * a pobieranie strony ma twardy limit czasu całości. Ścieżka z modelem
 * (strona bez JSON-LD) przejdzie do kolejki razem z fundamentem importu.
 */
final class ImportPrzepisuController extends Controller
{
    /** Kody, przy których adres jest bezpieczny i znany — szkic z samym źródłem ma sens. */
    private const KODY_SZKICU_BEZ_TRESCI = [ImportOdrzucony::ROBOTS_ZABRANIA, ImportOdrzucony::BRAK_PRZEPISU];

    public function __construct(
        private readonly LimitImportu $limit,
        private readonly ZapiszSzkicZImportu $zapiszSzkic,
    ) {}

    public function adresForm(): View
    {
        abort_unless((bool) config('kuking.import.url.wlaczony'), 404);

        return view('pages.recipes.import-adres');
    }

    public function adres(Request $request, OdczytajPrzepisZAdresu $odczyt): RedirectResponse
    {
        abort_unless((bool) config('kuking.import.url.wlaczony'), 404);

        $dane = $request->validate([
            'adres' => ['required', 'string', 'max:2000'],
        ], [
            'adres.required' => 'Wklej adres strony z przepisem — skopiuj go z paska adresu przeglądarki.',
            'adres.max' => 'Ten adres jest za długi. Skopiuj go jeszcze raz z paska adresu przeglądarki.',
        ]);

        $adres = trim($dane['adres']);

        try {
            $this->limit->zuzyj($request->user());
            $strona = $odczyt->handle($adres);
        } catch (ImportOdrzucony $e) {
            if (! in_array($e->kod, self::KODY_SZKICU_BEZ_TRESCI, true)) {
                return back()->withInput()->withErrors(['adres' => $e->getMessage()]);
            }

            $recipe = $this->zapiszSzkic->handle(
                autor: $request->user(),
                zrodlo: PrzepisZImportu::ZRODLO_URL,
                droga: 'bez_tresci',
                przepis: null,
                sourceUrl: PobieraczStron::bezSledzenia($adres),
                tytulZastepczy: 'Przepis ze strony '.(string) parse_url($adres, PHP_URL_HOST),
            );

            $this->slad('url', 'bez_tresci', $e->kod);

            return redirect()->route('recipes.create', ['szkic' => $recipe->getKey()])
                ->with('status', $e->getMessage());
        }

        $recipe = $this->zapiszSzkic->handle(
            autor: $request->user(),
            zrodlo: PrzepisZImportu::ZRODLO_URL,
            droga: $strona->droga,
            przepis: $strona->przepis,
            sourceUrl: $strona->url,
        );

        $this->slad('url', $strona->droga, null);

        return redirect()->route('recipes.create', ['szkic' => $recipe->getKey()])
            ->with('status', 'Szkic gotowy — widzisz go tylko Ty. Ten tekst odczytał komputer: porównaj go ze stroną '
                .'i popraw, co trzeba. Opis przygotowania napisz własnymi słowami, zanim opublikujesz.');
    }

    public function pdfForm(): View
    {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        return view('pages.recipes.import-pdf', [
            'maksMb' => (int) config('kuking.import.pdf.max_mb'),
            'maksStron' => (int) config('kuking.import.pdf.max_stron'),
        ]);
    }

    public function pdf(Request $request, OdczytajPrzepisZPdf $odczyt): RedirectResponse
    {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        $maksMb = (int) config('kuking.import.pdf.max_mb');

        // Rozszerzenia ani typu MIME od przeglądarki nie sprawdzamy jako
        // dowodu (AGENTS.md §7) — sygnaturę `%PDF-` czyta `TekstZPdf`.
        $request->validate([
            'plik' => ['required', 'file', 'max:'.($maksMb * 1024)],
        ], [
            'plik.required' => 'Wybierz plik PDF z przepisem przyciskiem „Wybierz plik”.',
            'plik.file' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz.',
            'plik.uploaded' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz — najwyżej '.$maksMb.' MB.',
            'plik.max' => 'Ten plik PDF jest za duży. Wybierz plik mniejszy niż '.$maksMb.' MB.',
        ]);

        /** @var UploadedFile $plik */
        $plik = $request->file('plik');

        try {
            $this->limit->zuzyj($request->user());
            $przepis = $odczyt->handle((string) $plik->getRealPath());
        } catch (ImportOdrzucony $e) {
            return back()->withErrors(['plik' => $e->getMessage()]);
        }

        $recipe = $this->zapiszSzkic->handle(
            autor: $request->user(),
            zrodlo: PrzepisZImportu::ZRODLO_PDF,
            droga: 'tekst_pdf',
            przepis: $przepis,
        );

        $this->slad('pdf', 'tekst_pdf', null);

        return redirect()->route('recipes.create', ['szkic' => $recipe->getKey()])
            ->with('status', 'Szkic gotowy — widzisz go tylko Ty. Ten tekst odczytał komputer: porównaj go '
                .'z plikiem i popraw, co trzeba, zanim opublikujesz.');
    }

    /** Ślad w dzienniku bez adresu i bez treści — tylko rodzaj, droga i kod. */
    private function slad(string $zrodlo, string $droga, ?string $kod): void
    {
        Log::info('Import przepisu zakończony szkicem.', [
            'stage' => 'import_przepisu',
            'zrodlo' => $zrodlo,
            'droga' => $droga,
            'kod' => $kod,
        ]);
    }
}
