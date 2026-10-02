<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Pdf\TekstZPdf;
use App\Domain\Import\Pdf\ZlecImportZPdf;
use App\Domain\Zgody\InformacjaTekstuZrodlaAi;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzygotujPodgladPdf;
use App\Models\ImportPrzepisu;
use App\Support\Komunikat;
use App\Support\Storage\PlikTymczasowyImportu;
use App\Support\Storage\PoczekalniaPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Wybór stron krótkiego PDF-a przed odczytem przepisu (#2535, V2, decyzja
 * właściciela z 2.10.2026).
 *
 * Droga opcjonalna: człowiek zaznacza na formularzu PDF „Najpierw pokaż strony”.
 * Plik trafia do prywatnej poczekalni (`PoczekalniaPdf`), zadanie robi podgląd
 * (bez AI), a ODCZYT ruszy dopiero po osobnym zatwierdzeniu — z jawną zgodą na
 * wysłanie skanu i z samymi wybranymi stronami. Zgoda z pierwszego formularza
 * NIE jest przenoszona: wybranie stron nie jest zgodą.
 *
 * UUID tokenu w adresie to nie autoryzacja: poczekalnia szuka pliku w katalogu
 * ZALOGOWANEJ osoby. Numery stron są walidowane względem tego konkretnego pliku.
 */
final class WyborStronPdfController extends Controller
{
    public function przyjmij(Request $request, PoczekalniaPdf $poczekalnia, TekstZPdf $tekst): RedirectResponse
    {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        $maksMb = (int) config('kuking.import.pdf.max_mb');

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
        $osoba = $request->user();

        try {
            // Najtańsze kontrole bez narzędzi: rozmiar i sygnatura `%PDF-`.
            $tekst->sprawdzWstepnie((string) $plik->getRealPath());
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['plik' => $e->getMessage()]);
        }

        $token = $poczekalnia->przyjmij($osoba, (string) $plik->getRealPath());

        if ($token === null) {
            return back()->withErrors(['plik' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz za chwilę albo wpisz przepis ręcznie.']);
        }

        PrzygotujPodgladPdf::dispatch((string) $osoba->getKey(), $token);

        return redirect()->route('recipes.import.pdf.wybor', $token);
    }

    public function pokaz(Request $request, string $token, PoczekalniaPdf $poczekalnia): Response|RedirectResponse
    {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        $osoba = $request->user();
        $opis = $poczekalnia->opis($osoba, $token);

        if ($opis === null) {
            return $this->wygasla($osoba, $request);
        }

        if ($opis['stan'] === PoczekalniaPdf::STAN_PRZYGOTOWANIE) {
            // Bez skryptu: odświeżenie co kilka sekund, plus zwykły odnośnik w widoku.
            return response()
                ->view('pages.recipes.import-pdf-wybor', ['token' => $token, 'stan' => $opis['stan']])
                ->header('Refresh', '5')
                ->header('Cache-Control', 'private, no-store');
        }

        if ($opis['stan'] === PoczekalniaPdf::STAN_BLAD) {
            // Powód już znamy — plik nie jest dłużej potrzebny.
            $poczekalnia->zapomnij($osoba, $token);

            return redirect()->route('recipes.import.pdf')
                ->withErrors(['plik' => (string) ($opis['komunikat'] ?? 'Nie udało się przygotować podglądu tego pliku.')]);
        }

        return response()->view('pages.recipes.import-pdf-wybor', [
            'token' => $token,
            'stan' => $opis['stan'],
            'strony' => (int) ($opis['strony'] ?? 0),
            'fragmenty' => $opis['fragmenty'] ?? [],
            'kluczWyslania' => (string) Str::uuid7(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function miniatura(Request $request, string $token, int $numer, PoczekalniaPdf $poczekalnia): Response
    {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        $obraz = $poczekalnia->miniatura($request->user(), $token, $numer);

        abort_if($obraz === null, 404);

        return response($obraz, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($obraz),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="strona-'.$numer.'.jpg"',
        ]);
    }

    public function zatwierdz(
        Request $request,
        string $token,
        PoczekalniaPdf $poczekalnia,
        PlikTymczasowyImportu $pliki,
        ZlecImportZPdf $zlec,
    ): RedirectResponse {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        $osoba = $request->user();
        $opis = $poczekalnia->opis($osoba, $token);
        $klucz = $request->input('klucz_wyslania');
        $klucz = is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;

        if ($opis === null || $opis['stan'] !== PoczekalniaPdf::STAN_GOTOWY) {
            // Dwuklik: pierwsze zatwierdzenie już przyjęło zlecenie i skasowało poczekalnię.
            $juz = $klucz === null ? null : ImportPrzepisu::query()
                ->where('user_id', $osoba->getKey())
                ->where('klucz_wyslania', $klucz)
                ->first();

            return $juz !== null ? redirect()->route('import.show', $juz) : $this->wygasla($osoba, $request);
        }

        $liczbaStron = (int) ($opis['strony'] ?? 0);
        $wybor = $request->input('strony', []);
        $wybor = is_array($wybor) ? array_values(array_filter($wybor, static fn ($n): bool => is_scalar($n) && preg_match('/^\d{1,3}$/', (string) $n) === 1)) : [];
        $strony = TekstZPdf::normalizujWybor(array_map('intval', $wybor), $liczbaStron);

        // Numer spoza pliku, powtórzony albo zmyślony nie przechodzi: wybór jest
        // sprawdzany serwerowo względem TEGO pliku, a pusty wybór nic nie wysyła.
        $zaznaczono = is_array($request->input('strony')) ? count($request->input('strony')) : 0;

        if ($strony === [] || $zaznaczono !== count($strony)) {
            return back()->withInput()->withErrors([
                'strony' => $strony === []
                    ? 'Zaznacz co najmniej jedną stronę, na której jest przepis. Nic nie zostało odczytane ani wysłane.'
                    : 'Któraś zaznaczona strona nie należy do tego pliku. Zaznacz strony jeszcze raz — nic nie zostało odczytane ani wysłane.',
            ]);
        }

        $zgodaZaznaczona = $request->boolean('zgoda_ai');
        $zgodaAktualna = InformacjaTekstuZrodlaAi::aktualna($request->input(InformacjaTekstuZrodlaAi::POLE));
        $zgodaAi = $zgodaZaznaczona && $zgodaAktualna;

        $lokalna = $poczekalnia->plikLokalny((string) $osoba->getKey(), $token);

        if ($lokalna === null) {
            return $this->wygasla($osoba, $request);
        }

        try {
            $zlecenie = $zlec->handle($osoba, $lokalna, $zgodaAi, $klucz, $strony);
        } catch (BladDlaCzlowieka $e) {
            // `ImportOdrzucony` też: plik, limit osoby, powtórzona próba. Wybór zostaje na ekranie.
            return back()->withInput()->withErrors(['strony' => $e->getMessage()]);
        } finally {
            $pliki->usunLokalna($lokalna);
        }

        $poczekalnia->zapomnij($osoba, $token);

        $opisStron = count($strony) === 1
            ? 'strona '.$strony[0]
            : 'strony '.implode(', ', $strony);
        $komunikat = 'Odczytamy tylko wybrane: '.$opisStron.'. Pozostałych stron pliku nie czytamy i nie wysyłamy.';

        if ($zgodaZaznaczona && ! $zgodaAktualna) {
            $komunikat .= ' Nie użyliśmy odczytu przez komputer (AI), bo informacja przy zgodzie się zmieniła — jeśli przepis wyjdzie pusty, przeczytaj ją, zaznacz zgodę jeszcze raz i spróbuj ponownie.';
        }

        return redirect()->route('import.show', $zlecenie)->with(Komunikat::informacja($komunikat));
    }

    public function odrzuc(Request $request, string $token, PoczekalniaPdf $poczekalnia): RedirectResponse
    {
        abort_unless((bool) config('kuking.import.pdf.wlaczony'), 404);

        $poczekalnia->zapomnij($request->user(), $token);

        return redirect()->route('recipes.import.pdf')
            ->with(Komunikat::informacja('Usunęliśmy ten plik z poczekalni. Nic z niego nie zostało odczytane ani wysłane.'));
    }

    private function wygasla(mixed $osoba, Request $request): RedirectResponse
    {
        return redirect()->route('recipes.import.pdf')
            ->with(Komunikat::blad('Ten plik już nie czeka na wybór stron (mógł wygasnąć albo zostać usunięty). Wyślij go jeszcze raz.'));
    }
}
