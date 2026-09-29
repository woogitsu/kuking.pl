<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use App\Support\KreatorPrzepisu\ZdjeciaKreatora;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #1387, krok 7 — przyjmowanie zdjęć kreatora wydzielone z komponentu
 * `recipe-wizard` do `ZdjeciaKreatora`.
 *
 * Testy chodzą BEZ Livewire'a, bazy i mediów: klasa dostaje atrapę
 * „przyjmującego” zamiast `StoreUploadedImage`. Zachowanie całości
 * (zdjęcia zachowane po błędzie, błąd przy właściwym polu, sprzątanie
 * `livewire-tmp/`) pilnują testy funkcjonalne kreatora.
 */
final class ZdjeciaKreatoraTest extends TestCase
{
    private function plik(string $nazwa): UploadedFile
    {
        return UploadedFile::fake()->create($nazwa, 10, 'image/jpeg');
    }

    /** Atrapa: pliki o nazwie zaczynającej się od „zly” odpadają, reszta dostaje numer. */
    private function klasa(array &$wywolania): ZdjeciaKreatora
    {
        return new ZdjeciaKreatora(function (User $u, UploadedFile $plik) use (&$wywolania): string {
            $wywolania[] = $plik->getClientOriginalName();

            if (str_starts_with($plik->getClientOriginalName(), 'zly')) {
                throw new BladDlaCzlowieka('To zdjęcie waży za dużo.');
            }

            return 'media-'.count($wywolania);
        });
    }

    public function test_bez_wybranych_plikow_nic_sie_nie_dzieje_i_wynik_jest_udany(): void
    {
        $wywolania = [];
        $wynik = $this->klasa($wywolania)->przyjmij(new User, null, [0 => null, 1 => null]);

        $this->assertSame([], $wywolania);
        $this->assertNull($wynik->mediaIdGlownego);
        $this->assertSame([], $wynik->mediaIdKrokow);
        $this->assertTrue($wynik->udany());
    }

    public function test_przyjmuje_zdjecie_glowne_i_zdjecia_krokow_pod_wlasciwymi_indeksami(): void
    {
        $wywolania = [];
        $wynik = $this->klasa($wywolania)->przyjmij(new User, $this->plik('dobre.jpg'), [
            0 => null,
            1 => $this->plik('krok1.jpg'),
            2 => $this->plik('krok2.jpg'),
        ]);

        $this->assertSame(['dobre.jpg', 'krok1.jpg', 'krok2.jpg'], $wywolania);
        $this->assertSame('media-1', $wynik->mediaIdGlownego);
        $this->assertSame([1 => 'media-2', 2 => 'media-3'], $wynik->mediaIdKrokow);
        $this->assertTrue($wynik->udany());
    }

    public function test_blad_zdjecia_glownego_trafia_do_pola_hero_photo_i_nie_blokuje_krokow(): void
    {
        $wywolania = [];
        $wynik = $this->klasa($wywolania)->przyjmij(new User, $this->plik('zly.jpg'), [3 => $this->plik('krok.jpg')]);

        $this->assertNull($wynik->mediaIdGlownego, 'Odrzucone zdjęcie główne nie może ustawić identyfikatora — zachowane zdjęcie ma zostać.');
        $this->assertSame(['heroPhoto' => 'To zdjęcie waży za dużo.'], $wynik->bledy);
        $this->assertSame([3 => 'media-2'], $wynik->mediaIdKrokow);
        $this->assertFalse($wynik->udany());
    }

    public function test_blad_zdjecia_kroku_trafia_do_pola_tego_kroku_a_inne_kroki_sie_zapisuja(): void
    {
        $wywolania = [];
        $wynik = $this->klasa($wywolania)->przyjmij(new User, null, [
            0 => $this->plik('krok0.jpg'),
            1 => $this->plik('zly.jpg'),
            2 => $this->plik('krok2.jpg'),
        ]);

        $this->assertSame([0 => 'media-1', 2 => 'media-3'], $wynik->mediaIdKrokow);
        $this->assertSame(['steps.1.photo' => 'To zdjęcie waży za dużo.'], $wynik->bledy);
        $this->assertFalse($wynik->udany());
    }

    public function test_bledy_ida_w_kolejnosci_zdjecie_glowne_potem_kroki(): void
    {
        $wywolania = [];
        $wynik = $this->klasa($wywolania)->przyjmij(new User, $this->plik('zly.jpg'), [
            0 => $this->plik('zly-krok.jpg'),
        ]);

        $this->assertSame(['heroPhoto', 'steps.0.photo'], array_keys($wynik->bledy));
    }

    public function test_inny_wyjatek_niz_blad_dla_czlowieka_nie_jest_polykany(): void
    {
        $klasa = new ZdjeciaKreatora(function (): string {
            throw new \RuntimeException('awaria dysku');
        });

        $this->expectException(\RuntimeException::class);

        $klasa->przyjmij(new User, $this->plik('a.jpg'), []);
    }

    public function test_klasa_nie_kasuje_plikow_tymczasowych_livewire(): void
    {
        // #2050/#2178: sprzątanie `livewire-tmp/` robi wyłącznie zapis zdjęcia
        // po utrwaleniu oryginału. Przy błędzie plik ma zostać, żeby człowiek
        // poprawił formularz z tym samym zdjęciem.
        Storage::fake('local');
        Storage::disk('local')->put('livewire-tmp/wybrane.jpg', 'x');
        Storage::disk('local')->put('livewire-tmp/wybrane.jpg.json', '{}');

        $wywolania = [];
        $wynik = $this->klasa($wywolania)->przyjmij(new User, $this->plik('zly.jpg'), [0 => $this->plik('zly2.jpg')]);

        $this->assertFalse($wynik->udany());
        Storage::disk('local')->assertExists('livewire-tmp/wybrane.jpg');
        Storage::disk('local')->assertExists('livewire-tmp/wybrane.jpg.json');
    }
}
