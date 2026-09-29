<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Import\MagazynPaczek;
use App\Domain\Users\Import\PaczkaOdrzucona;
use App\Domain\Users\Import\PodgladPaczki;
use App\Domain\Users\Import\PodgladPaczkiEksportu;
use App\Domain\Users\Import\PozycjaPodgladu;
use App\Domain\Users\Import\WczytajPaczke;
use App\Http\Controllers\Controller;
use App\Models\WczytanaZPaczki;
use App\Support\Czas;
use App\Support\Komunikat;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * Wczytanie własnej paczki eksportu: wybór pliku → podgląd → zapis (issue #1985).
 *
 * Trzy kroki, trzy adresy, żaden nie wymaga JavaScriptu:
 *  1. `GET  /ustawienia/twoje-dane/wczytaj`          — wybór pliku,
 *  2. `POST /ustawienia/twoje-dane/wczytaj`          — sprawdzenie pliku, przekierowanie do podglądu,
 *  3. `GET  /ustawienia/twoje-dane/wczytaj/{paczka}` — podgląd z polami wyboru,
 *  4. `POST /ustawienia/twoje-dane/wczytaj/{paczka}` — zapis zaznaczonych pozycji.
 *
 * `{paczka}` to losowy token pliku czekającego w prywatnej poczekalni
 * (`MagazynPaczek`). UUID w adresie NIE jest autoryzacją: plik szukamy w
 * katalogu zalogowanej osoby, a podgląd i zapis czytają go od nowa i sprawdzają
 * od nowa — do zapisu trafiają wyłącznie pozycje, które ten SAM podgląd uznał za nowe.
 */
class WczytanieDanychController extends Controller
{
    public function wybor(Request $request, MagazynPaczek $magazyn): View
    {
        $this->authorize('create', WczytanaZPaczki::class);

        $magazyn->sprzatnijPrzeterminowane();

        return view('pages.settings.wczytaj', [
            'maksMb' => intdiv((int) config('kuking.import_paczki.max_kb'), 1024),
        ]);
    }

    public function sprawdz(Request $request, MagazynPaczek $magazyn, PodgladPaczkiEksportu $podglad): RedirectResponse
    {
        $this->authorize('create', WczytanaZPaczki::class);

        $maksKb = (int) config('kuking.import_paczki.max_kb');

        // Rozszerzenia ani typu MIME od przeglądarki nie traktujemy jako dowodu
        // (AGENTS.md §7): to, czy plik jest ZIP-em z danymi Kuking, rozstrzyga
        // `PodgladPaczkiEksportu` po otwarciu archiwum.
        $request->validate([
            'plik' => ['required', 'file', 'max:'.$maksKb],
        ], [
            'plik.required' => 'Wybierz plik ZIP z paczką danych przyciskiem „Wybierz plik”.',
            'plik.file' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz.',
            'plik.uploaded' => 'Nie udało się przyjąć pliku. Wybierz go jeszcze raz — najwyżej '.intdiv($maksKb, 1024).' MB.',
            'plik.max' => 'Ten plik jest za duży. Wybierz paczkę mniejszą niż '.intdiv($maksKb, 1024).' MB.',
        ]);

        /** @var UploadedFile $plik */
        $plik = $request->file('plik');
        $user = $request->user();

        try {
            // Najpierw sprawdzamy plik z miejsca, w którym leży po wysłaniu; do
            // poczekalni trafia dopiero paczka, którą da się wczytać.
            $podglad->czytaj($user, (string) $plik->getRealPath());
        } catch (PaczkaOdrzucona $e) {
            return back()->withErrors(['plik' => $e->getMessage()]);
        }

        $token = $magazyn->zapisz($user, $plik);

        return redirect()->route('settings.data.import.preview', ['paczka' => $token]);
    }

    public function podglad(Request $request, string $paczka, MagazynPaczek $magazyn, PodgladPaczkiEksportu $czytnik): View|RedirectResponse
    {
        $this->authorize('create', WczytanaZPaczki::class);

        $wynik = $this->czytaj($request, $paczka, $magazyn, $czytnik);

        if ($wynik instanceof RedirectResponse) {
            return $wynik;
        }

        $limit = max(1, (int) config('kuking.import_paczki.max_naraz'));
        $nowe = array_values(array_filter($wynik->wszystkie(), static fn (PozycjaPodgladu $p): bool => $p->mozeBycUtworzona()));

        return view('pages.settings.wczytaj-podglad', [
            'podglad' => $wynik,
            'dataPaczki' => $this->dataPaczki($wynik->wygenerowano),
            'paczka' => $paczka,
            'liczby' => $wynik->liczbyStanow(),
            'limit' => $limit,
            // Po błędzie zaznaczenie wraca takie, jakie człowiek zostawił.
            'zaznaczone' => old('pozycje', array_map(
                static fn (PozycjaPodgladu $p): string => $p->odcisk,
                array_slice($nowe, 0, $limit),
            )),
        ]);
    }

    public function zapisz(Request $request, string $paczka, MagazynPaczek $magazyn, PodgladPaczkiEksportu $czytnik, WczytajPaczke $wczytaj): RedirectResponse
    {
        $this->authorize('create', WczytanaZPaczki::class);

        $request->validate([
            'pozycje' => ['required', 'array', 'min:1', 'max:15000'],
            'pozycje.*' => ['string', 'regex:/^[0-9a-f]{64}$/'],
        ], [
            'pozycje.required' => 'Zaznacz przynajmniej jedną pozycję do wczytania.',
            'pozycje.min' => 'Zaznacz przynajmniej jedną pozycję do wczytania.',
            'pozycje.*.regex' => 'Odśwież stronę i zaznacz pozycje jeszcze raz.',
            'pozycje.max' => 'Zaznaczono za dużo pozycji naraz. Odśwież stronę i zaznacz mniej.',
        ]);

        $wynik = $this->czytaj($request, $paczka, $magazyn, $czytnik);

        if ($wynik instanceof RedirectResponse) {
            return $wynik;
        }

        $efekt = $wczytaj->handle(
            $request->user(),
            $wynik,
            array_values(array_map('strval', (array) $request->input('pozycje'))),
            $request->ip(),
        );

        $zdania = [];

        if ($efekt->razem() > 0) {
            $zdania[] = 'Wczytano: '.$this->liczby($efekt->utworzone).'. Wszystko jest prywatne — widzisz to tylko Ty. Przepisy czekają w „Szkicach”, wpisy w „Moich wpisach”, zeszyty w „Zeszycie”.';
        } else {
            $zdania[] = 'Nic nowego nie zostało wczytane.';
        }

        if ($efekt->juzByly > 0) {
            $zdania[] = 'Pominięto '.$efekt->juzByly.' — już masz to na koncie.';
        }

        if ($efekt->niewczytane !== []) {
            $zdania[] = 'Nie udało się wczytać: '.implode('; ', $efekt->niewczytane).'.';
        }

        if ($efekt->zostalo > 0) {
            // Plik zostaje: reszta zaznaczonych czeka na kolejne kliknięcie.
            $zdania[] = 'Wczytujemy po '.config('kuking.import_paczki.max_naraz').' pozycji naraz. Zostało jeszcze '.$efekt->zostalo.' — kliknij „Wczytaj zaznaczone” jeszcze raz.';

            return redirect()->route('settings.data.import.preview', ['paczka' => $paczka])
                ->with(Komunikat::informacja(implode(' ', $zdania)));
        }

        $magazyn->zapomnij($request->user(), $paczka);

        // Sukces tylko wtedy, gdy coś wczytano i nic nie odpadło; „nic nie wczytano" bez usterki to informacja,
        // a usterka przy zerze wczytanych — błąd (człowiek ma coś zrobić).
        $tresc = implode(' ', $zdania);
        $komunikat = match (true) {
            $efekt->razem() > 0 && $efekt->niewczytane === [] => Komunikat::sukces($tresc),
            $efekt->razem() === 0 && $efekt->niewczytane !== [] => Komunikat::blad($tresc),
            default => Komunikat::informacja($tresc),
        };

        return redirect()->route('settings.data')->with($komunikat);
    }

    private function czytaj(Request $request, string $paczka, MagazynPaczek $magazyn, PodgladPaczkiEksportu $czytnik): PodgladPaczki|RedirectResponse
    {
        $sciezka = $magazyn->sciezka($request->user(), $paczka);

        if ($sciezka === null) {
            return redirect()->route('settings.data.import')
                ->withErrors(['plik' => 'Ta paczka nie czeka już na wczytanie. Wybierz plik jeszcze raz.']);
        }

        try {
            return $czytnik->czytaj($request->user(), $sciezka);
        } catch (PaczkaOdrzucona $e) {
            $magazyn->zapomnij($request->user(), $paczka);

            return redirect()->route('settings.data.import')->withErrors(['plik' => $e->getMessage()]);
        }
    }

    /** Data z paczki po polsku i w polskiej strefie; brak albo nieczytelna — `null` (nie zgadujemy). */
    private function dataPaczki(?string $wygenerowano): ?string
    {
        if ($wygenerowano === null) {
            return null;
        }

        try {
            return Czas::data(CarbonImmutable::parse($wygenerowano), 'j F Y, H:i');
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** @param  array<string, int>  $utworzone */
    private function liczby(array $utworzone): string
    {
        $nazwy = [
            WczytanaZPaczki::RODZAJ_PRZEPIS => 'przepisy',
            WczytanaZPaczki::RODZAJ_WPIS => 'wpisy',
            WczytanaZPaczki::RODZAJ_ZESZYT => 'zeszyty',
        ];

        $czesci = [];

        foreach ($nazwy as $rodzaj => $nazwa) {
            if (($utworzone[$rodzaj] ?? 0) > 0) {
                $czesci[] = $nazwa.' — '.$utworzone[$rodzaj];
            }
        }

        return implode(', ', $czesci);
    }
}
