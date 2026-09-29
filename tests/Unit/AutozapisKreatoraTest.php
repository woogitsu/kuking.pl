<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KreatorPrzepisu\AutozapisKreatora;
use App\Support\KreatorPrzepisu\RewizjaTresci;
use App\Support\KreatorPrzepisu\StanZapisu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1387, krok 9 — autozapis i rewizje wydzielone z komponentu
 * `recipe-wizard` do `AutozapisKreatora`, `StanZapisu` i `RewizjaTresci`.
 *
 * Testy bez Laravela i bez bazy: klasy nie znają Livewire'a. Zachowanie
 * całego cyklu (autoryzacja, walidacja przed zapisem, strona nieaktualna)
 * pilnują testy funkcjonalne kreatora.
 */
final class AutozapisKreatoraTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function zmiany(): array
    {
        return [
            'krok' => ['step', true],
            'stan plakietki' => ['saveState', true],
            'tekst plakietki' => ['saveMessage', true],
            'licznik kluczy wierszy' => ['rowCounter', true],
            'pole formularza' => ['form.title', false],
            'składnik' => ['ingredients.0.text', false],
            'krok przygotowania' => ['steps.1.instruction', false],
            'zdjęcie główne' => ['heroPhoto', false],
            // Licznik zmian z przeglądarki musi uruchamiać zapis, inaczej plakietka nie dogoni tekstu.
            'rewizja interfejsu' => ['editRevision', false],
            // Tylko dokładna nazwa: klucz zaczynający się jak `step` to inne pole.
            'steps (lista)' => ['steps', false],
        ];
    }

    #[DataProvider('zmiany')]
    public function test_hook_pomija_tylko_zmiany_niebedace_edycja_tresci(string $wlasciwosc, bool $pomin): void
    {
        $this->assertSame($pomin, AutozapisKreatora::pominZmiane($wlasciwosc));
    }

    /** @return array<string, array{string, bool}> */
    public static function nazwy(): array
    {
        return [
            'pusta' => ['', false],
            'same spacje' => ['     ', false],
            'dwa znaki' => ['Zu', false],
            'dwa znaki i spacje' => ['  Zu  ', false],
            'trzy znaki' => ['Zup', true],
            'trzy znaki wokół spacji' => ['  Zup ', true],
            // Liczą się znaki, nie bajty: „żur” to trzy znaki i sześć bajtów.
            'polskie litery, trzy znaki' => ['żur', true],
            'polskie litery, dwa znaki' => ['żó', false],
            'zwykła nazwa' => ['Zupa z koperkiem', true],
        ];
    }

    #[DataProvider('nazwy')]
    public function test_nazwa_ma_co_najmniej_trzy_znaki_po_przycieciu(string $tytul, bool $ma): void
    {
        $this->assertSame($ma, AutozapisKreatora::maNazwe($tytul));
    }

    public function test_wersje_dopisuje_sie_tylko_po_swiadomym_i_udanym_zapisie(): void
    {
        $this->assertTrue(AutozapisKreatora::mogeDopisacWersje(true, StanZapisu::ZAPISANY));
        $this->assertFalse(AutozapisKreatora::mogeDopisacWersje(false, StanZapisu::ZAPISANY), 'Autozapis nie zostawia wersji.');
        $this->assertFalse(AutozapisKreatora::mogeDopisacWersje(true, StanZapisu::BLAD), 'Nieudany zapis nie zostawia wersji.');
        $this->assertFalse(AutozapisKreatora::mogeDopisacWersje(true, StanZapisu::OCZEKUJE));
        $this->assertFalse(AutozapisKreatora::mogeDopisacWersje(true, StanZapisu::PUSTY));
    }

    public function test_powtorzony_zapis_w_zadaniu_jest_udany_tylko_przy_stanie_zapisany(): void
    {
        $this->assertTrue(AutozapisKreatora::wynikPowtorzonegoZapisu(StanZapisu::ZAPISANY));
        $this->assertFalse(AutozapisKreatora::wynikPowtorzonegoZapisu(StanZapisu::BLAD));
        $this->assertFalse(AutozapisKreatora::wynikPowtorzonegoZapisu(StanZapisu::OCZEKUJE));
        $this->assertFalse(AutozapisKreatora::wynikPowtorzonegoZapisu(StanZapisu::PUSTY));
    }

    public function test_flaga_zapisu_w_zadaniu_nie_przechodzi_miedzy_instancjami(): void
    {
        $pierwsze = new AutozapisKreatora;
        $this->assertFalse($pierwsze->zapisanoWTymZadaniu());

        $pierwsze->oznaczZapisano();
        $this->assertTrue($pierwsze->zapisanoWTymZadaniu());

        // Następne żądanie Livewire'a to nowa instancja: zapis znów jest dozwolony.
        $this->assertFalse((new AutozapisKreatora)->zapisanoWTymZadaniu());
    }

    public function test_stan_zapisu_ma_dokladnie_te_teksty_co_przed_wydzieleniem(): void
    {
        $this->assertSame(['', ''], [StanZapisu::pusty()->stan, StanZapisu::pusty()->komunikat]);

        $this->assertSame('saved', StanZapisu::zapisany(false)->stan);
        $this->assertSame('Szkic zapisany.', StanZapisu::zapisany(false)->komunikat);
        $this->assertSame('Zmiany zapisane.', StanZapisu::zapisany(true)->komunikat);

        $this->assertSame('waiting', StanZapisu::brakNazwy(false)->stan);
        $this->assertSame('Szkic zapisze się, kiedy podasz nazwę przepisu.', StanZapisu::brakNazwy(false)->komunikat);
        $this->assertSame('Podaj nazwę przepisu, żeby zapisać zmiany.', StanZapisu::brakNazwy(true)->komunikat);

        $this->assertSame('error', StanZapisu::bladPol()->stan);
        $this->assertSame(
            'Nie zapisaliśmy tych zmian. Popraw zaznaczone pola. Cały tekst jest nadal w formularzu.',
            StanZapisu::bladPol()->komunikat,
        );

        $this->assertSame('error', StanZapisu::bladIdentyfikatora('Ten przepis nie jest już dostępny.')->stan);
        $this->assertSame('Ten przepis nie jest już dostępny.', StanZapisu::bladIdentyfikatora('Ten przepis nie jest już dostępny.')->komunikat);

        $this->assertSame(
            'Nie udało się zapisać szkicu: Powód. Nic nie zginęło — cały tekst jest dalej w formularzu.',
            StanZapisu::bladZapisu(false, 'Powód.')->komunikat,
        );
        $this->assertSame(
            'Nie udało się zapisać zmian: Powód. Nic nie zginęło — cały tekst jest dalej w formularzu.',
            StanZapisu::bladZapisu(true, 'Powód.')->komunikat,
        );
    }

    public function test_tylko_stan_zapisany_jest_udany(): void
    {
        $this->assertTrue(StanZapisu::zapisany(false)->udany());
        $this->assertFalse(StanZapisu::pusty()->udany());
        $this->assertFalse(StanZapisu::brakNazwy(false)->udany());
        $this->assertFalse(StanZapisu::bladPol()->udany());
        $this->assertFalse(StanZapisu::bladZapisu(false, 'x')->udany());
        $this->assertFalse(StanZapisu::bladIdentyfikatora('x')->udany());
    }

    public function test_rewizja_tresci_dla_nowego_przepisu_to_null_a_nie_zero(): void
    {
        // Nowy przepis nie ma czego nadpisać; `0` zostałoby porównane z bazą.
        $this->assertNull(RewizjaTresci::oczekiwana(null, 0));
        $this->assertNull(RewizjaTresci::oczekiwana(null, 7));
    }

    public function test_rewizja_tresci_zapisanego_przepisu_jest_przekazywana_bez_zmian(): void
    {
        // Obrona przed nadpisaniem nowszej wersji z innej karty: liczba z bazy jedzie do PublishRecipe.
        $this->assertSame(0, RewizjaTresci::oczekiwana('0195f5c2-0000-7000-8000-000000000000', 0));
        $this->assertSame(7, RewizjaTresci::oczekiwana('0195f5c2-0000-7000-8000-000000000000', 7));
    }

    public function test_potwierdzona_rewizja_interfejsu_to_dokladnie_ta_z_zadania(): void
    {
        $this->assertSame(0, RewizjaTresci::potwierdzona(0));
        $this->assertSame(12, RewizjaTresci::potwierdzona(12));
    }

    public function test_plakietka_jest_aktualna_dopoki_tekst_nie_wyprzedzi_zapisu(): void
    {
        $this->assertTrue(RewizjaTresci::plakietkaAktualna(5, 5));
        $this->assertTrue(RewizjaTresci::plakietkaAktualna(4, 5));
        $this->assertFalse(RewizjaTresci::plakietkaAktualna(6, 5), 'Znak dopisany po wysłaniu żądania wraca do „zapisuję…” (#892).');
    }
}
