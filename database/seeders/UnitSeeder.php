<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Jednostki miary używane w polskiej kuchni domowej.
 *
 * Uwaga na „szklankę”: to nie jest błąd ani niedokładność do poprawienia.
 * Polskie przepisy domowe tak się pisze i tak się je czyta. Zmuszanie ludzi
 * do przeliczania na gramy wyrzuciłoby z serwisu dokładnie tych, dla których
 * on powstaje.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['g', 'gram', 'gramy', 'waga'],
            ['dag', 'dekagram', 'dekagramy', 'waga'],
            ['kg', 'kilogram', 'kilogramy', 'waga'],
            ['ml', 'mililitr', 'mililitry', 'objetosc'],
            ['l', 'litr', 'litry', 'objetosc'],
            ['szklanka', 'szklanka', 'szklanki', 'objetosc'],
            ['lyzka', 'łyżka', 'łyżki', 'objetosc'],
            ['lyzeczka', 'łyżeczka', 'łyżeczki', 'objetosc'],
            ['szczypta', 'szczypta', 'szczypty', 'objetosc'],
            ['garsc', 'garść', 'garście', 'objetosc'],
            ['szt', 'sztuka', 'sztuki', 'ilosc'],
            ['pecz', 'pęczek', 'pęczki', 'ilosc'],
            ['zabek', 'ząbek', 'ząbki', 'ilosc'],
            ['opak', 'opakowanie', 'opakowania', 'ilosc'],
            ['plaster', 'plaster', 'plastry', 'ilosc'],
        ];

        foreach ($units as [$code, $name, $plural, $type]) {
            Unit::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'name_plural' => $plural, 'unit_type' => $type],
            );
        }
    }
}
