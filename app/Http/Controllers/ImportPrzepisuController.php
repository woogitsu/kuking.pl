<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\BudzetAi;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\LimitImportowOsoby;
use App\Domain\Import\LimitImportu;
use App\Domain\Import\Pdf\OdczytajPrzepisZPdf;
use App\Domain\Import\Url\OdczytajPrzepisZAdresu;
use App\Domain\Import\Url\PobieraczStron;
use App\Domain\Import\ZlecImportPrzepisu;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ImportPrzepisu;
use App\Models\PrzepisZImportu;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Import przepisu — ekran wyboru źródła, odczyt zdjęcia kartki, postęp (V2, D-298).
 *
 * Cienki: walidacja → akcja domenowa → widok. Reguły (zgoda, limit, budżet,
 * „zdjęcie zapisane przed wszystkim”) mieszkają w `App\Domain\Import`.
 */
final class ImportPrzepisuController extends Controller
{
    /** Safe URL failures can still produce a private source-only draft. */
    private const KODY_SZKICU_BEZ_TRESCI = [ImportOdrzucony::ROBOTS_ZABRANIA, ImportOdrzucony::BRAK_PRZEPISU];

    public function __construct(
        private readonly LimitImportu $limit,
        private readonly ZapiszSzkicZImportu $zapiszSzkic,
    ) {}

    public function adresForm(): View
    {
        abort_unless((bool) config('kuking.import.url.wlaczony'), 404);

        return view('pages.recipes.import-adres', ['kluczWyslania' => (string) Str::uuid7()]);
    }

    public function adres(Request $request, OdczytajPrzepisZAdresu $odczyt): RedirectResponse
    {
        abort_unless((bool) config('kuking.import.url.wlaczony'), 404);

        $dane = $request->validate([
            'adres' => ['required', 'string', 'max:2000'],
            'zgoda_ai' => ['sometimes', 'boolean'],
        ], [
            'adres.required' => 'Wklej adres strony z przepisem — skopiuj go z paska adresu przeglądarki.',
            'adres.max' => 'Ten adres jest za długi. Skopiuj go jeszcze raz z paska adresu przeglądarki.',
        ]);

        $adres = trim($dane['adres']);

        $proba = null;
        try {
            $proba = $this->limit->zuzyj($request->user(), 'url', $this->kluczImportu($request));
            if ($proba['istnieje']) {
                return $this->powtorzonyImport($proba, 'adres');
            }
            $strona = $odczyt->handle($adres, $request->user(), $request->boolean('zgoda_ai'), $proba['id']);
        } catch (ImportOdrzucony $e) {
            if (! in_array($e->kod, self::KODY_SZKICU_BEZ_TRESCI, true)) {
                if ($proba !== null) {
                    $this->limit->zakoncz($proba['id'], false);
                }
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

            $this->limit->zakoncz($proba['id'], true, (string) $recipe->getKey());

            $this->slad('url', 'bez_tresci', $e->kod);

            return redirect()->route('recipes.create', ['szkic' => $recipe->getKey()])
                ->with('status', $e->getMessage());
        } catch (\Throwable $e) {
            if ($proba !== null) {
                $this->limit->zakoncz($proba['id'], false);
            }
            throw $e;
        }

        try {
            $recipe = $this->zapiszSzkic->handle(
                autor: $request->user(),
                zrodlo: PrzepisZImportu::ZRODLO_URL,
                droga: $strona->droga,
                przepis: $strona->przepis,
                sourceUrl: $strona->url,
            );
        } catch (\Throwable $e) {
            $this->limit->zakoncz($proba['id'], false);
            throw $e;
        }

        $this->limit->zakoncz($proba['id'], true, (string) $recipe->getKey());

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
            'maksStron' => min(5, max(1, (int) config('kuking.import.pdf.max_stron'))),
            'kluczWyslania' => (string) Str::uuid7(),
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
            'zgoda_ai' => ['sometimes', 'boolean'],
        ], [
            'plik.required' => 'Wybierz plik PDF z przepisem przyciskiem „Wybierz plik”.',
            'plik.file' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz.',
            'plik.uploaded' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz — najwyżej '.$maksMb.' MB.',
            'plik.max' => 'Ten plik PDF jest za duży. Wybierz plik mniejszy niż '.$maksMb.' MB.',
        ]);

        /** @var UploadedFile $plik */
        $plik = $request->file('plik');

        $proba = null;
        try {
            $proba = $this->limit->zuzyj($request->user(), 'pdf', $this->kluczImportu($request));
            if ($proba['istnieje']) {
                return $this->powtorzonyImport($proba, 'plik');
            }
            $pdf = $odczyt->handle((string) $plik->getRealPath(), $request->user(), $request->boolean('zgoda_ai'), $proba['id']);
        } catch (ImportOdrzucony $e) {
            if ($proba !== null) {
                $this->limit->zakoncz($proba['id'], false);
            }
            return back()->withErrors(['plik' => $e->getMessage()]);
        } catch (\Throwable $e) {
            if ($proba !== null) {
                $this->limit->zakoncz($proba['id'], false);
            }
            throw $e;
        }

        try {
            $recipe = $this->zapiszSzkic->handle(
                autor: $request->user(),
                zrodlo: PrzepisZImportu::ZRODLO_PDF,
                droga: $pdf->droga,
                przepis: $pdf->przepis,
            );
        } catch (\Throwable $e) {
            $this->limit->zakoncz($proba['id'], false);
            throw $e;
        }

        $this->limit->zakoncz($proba['id'], true, (string) $recipe->getKey());

        $this->slad('pdf', $pdf->droga, null);

        return redirect()->route('recipes.create', ['szkic' => $recipe->getKey()])
            ->with('status', 'Szkic gotowy — widzisz go tylko Ty. Ten tekst odczytał komputer: porównaj go '
                .'z plikiem i popraw, co trzeba, zanim opublikujesz.');
    }

    /**
     * Cztery duże przyciski: kartka, adres strony, PDF, „Wpiszę sam”.
     * Wyłączone źródło = brak przycisku (D-053). „Wpiszę sam” jest zawsze.
     */
    public function wybor(): View
    {
        return view('pages.import.wybor', [
            'zdjecie' => ZlecImportPrzepisu::dostepnyOdczytZdjecia(),
            'url' => (bool) config('kuking.import.url.wlaczony') && Route::has('recipes.import.url'),
            'pdf' => (bool) config('kuking.import.pdf.wlaczony') && Route::has('recipes.import.pdf'),
        ]);
    }

    public function zdjecie(Request $request, PrzestawZgodeNaOdczytAi $zgoda, LimitImportowOsoby $limit, BudzetAi $budzet): View|RedirectResponse
    {
        if (! ZlecImportPrzepisu::dostepnyOdczytZdjecia()) {
            return redirect()->route('recipes.create')
                ->with('status', 'Odczytywanie przepisów ze zdjęć jest teraz wyłączone. Możesz wpisać przepis ręcznie i dodać do niego zdjęcie kartki.');
        }

        $osoba = $request->user();

        if (! $zgoda->udzielona($osoba)) {
            return view('pages.import.zgoda');
        }

        $brakBudzetu = $budzet->brakMiejscaNa(BudzetAi::szacunek(KlientLuna::ZADANIE_OCR) ?? 0);

        return view('pages.import.zdjecie', [
            'kluczWyslania' => $this->kluczDlaFormularza(),
            'limitOsoby' => $limit->przekroczony($osoba),
            'brakBudzetu' => $brakBudzetu,
            'naDzien' => (int) config('kuking.import.limity.na_osobe_dzien'),
        ]);
    }

    public function zlec(Request $request, ZlecImportPrzepisu $zlec): RedirectResponse
    {
        $request->validate([
            'zdjecie' => ['required', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
        ], [
            'zdjecie.required' => 'Wybierz zdjęcie kartki albo strony zeszytu. Połóż kartkę na stole przy oknie i zrób zdjęcie z góry.',
            'zdjecie.file' => 'Nie udało się odczytać pliku. Wybierz zdjęcie jeszcze raz.',
            'zdjecie.max' => 'To zdjęcie waży za dużo. Maksymalny rozmiar to '.LimityZdjec::maksMegabajtowDoKomunikatu().' MB — wybierz mniejsze zdjęcie.',
        ]);

        $klucz = $request->input('klucz_wyslania');

        try {
            $zlecenie = $zlec->handle(
                $request->user(),
                $request->file('zdjecie'),
                is_string($klucz) && Str::isUuid($klucz) ? $klucz : null,
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['zdjecie' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $zlecenie);
    }

    /**
     * Postęp słowami. `?fragment=1` oddaje sam blok postępu — czyta go mały
     * skrypt co 5 s. Bez skryptu działa odnośnik „Sprawdź, czy już gotowe”.
     */
    public function show(Request $request, ImportPrzepisu $import): View|Response
    {
        $this->authorize('view', $import);

        $dane = ['import' => $import, 'szkic' => $import->recipe];

        if ($request->boolean('fragment')) {
            return response()->view('pages.import.partials.postep', $dane)
                ->header('Cache-Control', 'no-store');
        }

        return view('pages.import.show', $dane);
    }

    public function ponow(Request $request, ImportPrzepisu $import, ZlecImportPrzepisu $zlec): RedirectResponse
    {
        $this->authorize('update', $import);

        try {
            $nowe = $zlec->ponow($request->user(), $import);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('import.show', $import)->withErrors(['ponow' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $nowe);
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

    private function kluczImportu(Request $request): string
    {
        $klucz = $request->input('klucz_wyslania');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : (string) Str::uuid7();
    }

    /** @param array{id: string, status: string, recipe_id: ?string, istnieje: bool} $proba */
    private function powtorzonyImport(array $proba, string $pole): RedirectResponse
    {
        if ($proba['recipe_id'] !== null) {
            return redirect()->route('recipes.create', ['szkic' => $proba['recipe_id']]);
        }

        return back()->withErrors([$pole => 'Ta próba importu została już przyjęta. Otwórz formularz ponownie, jeśli chcesz rozpocząć nową próbę.']);
    }

    /** Ta sama zasada co w `RecipeController::kluczDlaFormularza()`. */
    private function kluczDlaFormularza(): ?string
    {
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $stary = old('klucz_wyslania');

        return is_string($stary) && Str::isUuid($stary) ? $stary : (string) Str::uuid7();
    }
}
