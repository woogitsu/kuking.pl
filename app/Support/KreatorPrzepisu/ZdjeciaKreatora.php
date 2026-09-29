<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Przyjmowanie zdjęć wybranych w kreatorze przepisu (issue #1387, krok 7).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany zachowania. Klasa nie zna Livewire'a, sesji ani worka błędów:
 * dostaje wybrane pliki (zdjęcie gotowego dania i zdjęcia kroków, po indeksie
 * wiersza) i zwraca `WynikZdjecKreatora` — identyfikatory przyjętych zdjęć
 * oraz błędy pod kluczami pól (`heroPhoto`, `steps.N.photo`, #1572).
 *
 * Zdjęcie gotowego dania I zdjęcia kroków przechodzą tędy razem, bo idą tą
 * samą drogą i mają te same limity — dwie osobne ścieżki to dwa miejsca,
 * w których można zapomnieć o jednym ze sprawdzeń.
 *
 * CO ZOSTAJE W KOMPONENCIE (i dlaczego):
 *  - autoryzacja (`Gate::authorize('update', …)`) — decyzja, KOMU wolno,
 *    nie należy do klasy, która tylko przyjmuje pliki,
 *  - `#[Locked]` na `heroMediaId`, `recipeId` itd. — to ochrona stanu
 *    Livewire, poza nim nie istnieje,
 *  - reguły plików Livewire przy uploadzie (`temporary_file_upload.rules`),
 *  - ZEROWANIE wybranych plików w stanie komponentu PRZED wywołaniem tej
 *    klasy: odrzucony plik nie może blokować każdego następnego autozapisu
 *    tym samym błędem,
 *  - przeniesienie wyniku do stanu (`heroMediaId`, `steps.N.mediaId`)
 *    i do worka błędów.
 *
 * KASOWANIE PLIKÓW TYMCZASOWYCH (`livewire-tmp/`, #2050, #2178) NIE JEST TU.
 * Robi to `StoreUploadedImage::handle()`, i to dopiero po utrwaleniu
 * oryginału: przy błędzie walidacji plik tymczasowy MUSI zostać, żeby
 * człowiek poprawił formularz z tym samym zdjęciem. Ta klasa nie kasuje
 * niczego, więc nie może tego zepsuć — pilnuje tego jej test.
 */
final class ZdjeciaKreatora
{
    /** @var Closure(User, UploadedFile): string */
    private readonly Closure $przyjmij;

    /**
     * @param  (Closure(User, UploadedFile): string)|null  $przyjmij  Przyjmuje plik i oddaje identyfikator
     *                                                                zdjęcia; domyślnie `StoreUploadedImage`.
     *                                                                Wstrzykiwane tylko w testach jednostkowych.
     */
    public function __construct(?Closure $przyjmij = null)
    {
        $this->przyjmij = $przyjmij ?? static fn (User $wlasciciel, UploadedFile $plik): string => (string) app(StoreUploadedImage::class)
            ->handle($wlasciciel, $plik)
            ->getKey();
    }

    /**
     * @param  array<int|string, UploadedFile|null>  $zdjeciaKrokow  Wybrane pliki kroków pod indeksem wiersza;
     *                                                               `null` = w tym wierszu nic nie wybrano.
     */
    public function przyjmij(User $wlasciciel, ?UploadedFile $zdjecieGlowne, array $zdjeciaKrokow): WynikZdjecKreatora
    {
        $mediaIdGlownego = null;
        $mediaIdKrokow = [];
        $bledy = [];

        if ($zdjecieGlowne !== null) {
            try {
                $mediaIdGlownego = ($this->przyjmij)($wlasciciel, $zdjecieGlowne);
            } catch (BladDlaCzlowieka $e) {
                $bledy['heroPhoto'] = $e->getMessage();
            }
        }

        foreach ($zdjeciaKrokow as $indeks => $plik) {
            if ($plik === null) {
                continue;
            }

            try {
                $mediaIdKrokow[$indeks] = ($this->przyjmij)($wlasciciel, $plik);
            } catch (BladDlaCzlowieka $e) {
                $bledy["steps.{$indeks}.photo"] = $e->getMessage();
            }
        }

        return new WynikZdjecKreatora($mediaIdGlownego, $mediaIdKrokow, $bledy);
    }
}
