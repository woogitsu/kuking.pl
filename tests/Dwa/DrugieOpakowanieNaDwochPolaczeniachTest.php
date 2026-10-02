<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2568: drugie opakowanie produktu to najwyżej jeden wiersz na produkt
 * (`UNIQUE (pantry_item_id)`), a każda operacja na opakowaniach trzyma blokadę
 * wiersza PRODUKTU. Dwa wyścigi, które mogłyby to złamać:
 *
 *  1. dwa równoległe „Dodaj drugie opakowanie” z RÓŻNĄ treścią — bez blokady
 *     jedno z nich kończyłoby się błędem unikalności (500) albo nadpisałoby
 *     drugie; ma wygrać jedno, a drugie dostać komunikat po polsku;
 *  2. usunięcie pierwszego opakowania (drugie awansuje na jego miejsce)
 *     równolegle z edycją drugiego — po obu operacjach nie może zostać
 *     „wskrzeszony” wiersz drugiego opakowania ani zgubić się treść.
 *
 * BARIERA trzyma wiersz produktu `FOR UPDATE`; oba procesy stają w kolejce do
 * tej samej blokady PRZED jakimkolwiek odczytem opakowań.
 */
#[Group('dwa-polaczenia')]
final class DrugieOpakowanieNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_dwa_rownolegle_dodania_drugiego_opakowania_zostawiaja_jeden_wiersz_i_jedna_odmowe(): void
    {
        $user = $this->konto();
        $id = (string) $user->pantryItems()->create(['name' => 'mleko'])->getKey();

        $bariera = $this->bariera('SELECT 1 FROM pantry_items WHERE id = ? FOR UPDATE', [$id]);
        $pierwszy = $this->wTle('zapisz-drugie-opakowanie-pantry', [
            'produkt' => $id,
            'dane' => json_encode(['rodzaj' => 'use_by', 'termin_dzien' => '20', 'termin_miesiac' => '10', 'termin_rok' => '2026', 'ilosc' => 'pierwsza ilość']),
        ]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('zapisz-drugie-opakowanie-pantry', [
            'produkt' => $id,
            'dane' => json_encode(['rodzaj' => 'best_before', 'termin_dzien' => '25', 'termin_miesiac' => '10', 'termin_rok' => '2026', 'ilosc' => 'druga ilość']),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];
        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'równoległe dodanie drugiego opakowania '.($numer + 1));
        }

        $udane = array_values(array_filter($wyniki, static fn (array $w): bool => $w['ok'] === true));
        $odrzucone = array_values(array_filter($wyniki, static fn (array $w): bool => $w['ok'] === false));
        $this->assertCount(1, $udane, 'Dokładnie jedno z dwóch równoległych dodań ma się udać.');
        $this->assertCount(1, $odrzucone);
        $this->assertSame(ValidationException::class, $odrzucone[0]['wyjatek'], 'Przegrany dostaje komunikat po polsku, nie błąd unikalności z bazy.');
        $this->assertStringContainsString('Drugie opakowanie tego produktu jest już zapisane', $odrzucone[0]['komunikat']);

        $wiersze = DB::table('pantry_second_packages')->where('pantry_item_id', $id)->get();
        $this->assertCount(1, $wiersze, 'Dwa równoległe dodania nie mogą dać dwóch drugich opakowań.');
        $this->assertContains($wiersze[0]->quantity_note, ['pierwsza ilość', 'druga ilość'], 'Treść jest w całości od jednego z zapisów, bez mieszania pól.');
    }

    public function test_usuniecie_pierwszego_kontra_edycja_drugiego_nie_wskrzesza_ani_nie_gubi_opakowania(): void
    {
        $user = $this->konto();
        $id = (string) $user->pantryItems()->create(['name' => 'mleko', 'quantity_note' => 'pierwsze'])->getKey();
        $idDrugiego = (string) DB::table('pantry_second_packages')->insertGetId(['pantry_item_id' => $id, 'quantity_note' => 'drugie'], 'id');

        $bariera = $this->bariera('SELECT 1 FROM pantry_items WHERE id = ? FOR UPDATE', [$id]);
        $usuniecie = $this->wTle('usun-opakowanie-pantry', ['produkt' => $id, 'cel' => 'pierwsze']);
        $this->czekajNaZablokowane(1);
        $edycja = $this->wTle('zapisz-drugie-opakowanie-pantry', [
            'produkt' => $id,
            'opakowanie_id' => $idDrugiego,
            'dane' => json_encode(['ilosc' => 'drugie po edycji']),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikUsuniecia = $usuniecie->wynik();
        $wynikEdycji = $edycja->wynik();
        $this->assertBezZakleszczenia($wynikUsuniecia, 'usunięcie pierwszego opakowania');
        $this->assertBezZakleszczenia($wynikEdycji, 'edycja drugiego opakowania');
        $this->assertTrue($wynikUsuniecia['ok'], 'Usunięcie padło: '.$wynikUsuniecia['komunikat']);

        $this->assertSame(0, DB::table('pantry_second_packages')->where('pantry_item_id', $id)->count(), 'Po usunięciu pierwszego nie zostaje wiersz drugiego opakowania.');
        $zostalo = DB::table('pantry_items')->where('id', $id)->value('quantity_note');

        if ($wynikEdycji['ok'] === true) {
            $this->assertSame('drugie po edycji', $zostalo, 'Edycja zdążyła przed usunięciem — jej treść awansuje na pierwsze miejsce.');
        } else {
            $this->assertSame(ValidationException::class, $wynikEdycji['wyjatek']);
            $this->assertSame('drugie', $zostalo, 'Edycja spóźniona o awans jest odrzucona, a treść drugiego opakowania nie ginie.');
        }
    }
}
