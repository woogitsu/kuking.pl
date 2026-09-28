<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Models\ImportPrzepisu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/import/{import}` (odpytywane co 5 s przez `postep-importu.js`) ma WŁASNY
 * koszyk zapytań, osobny od zlecenia/ponowienia odczytu (issue #1959).
 *
 * Do 26 września 2026 trasa nie miała żadnego `throttle` — nieograniczona
 * liczba żądań uwierzytelnionego konta trafiała w bindowanie modelu, Policy
 * i renderowanie widoku. Zapętlony klient, kilka otwartych zakładek albo
 * przejęta sesja mogły generować nieskończone zapytania do bazy.
 */
final class PostepImportuMaWlasnyLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalny_polling_co_5_sekund_nie_dostaje_429(): void
    {
        $osoba = $this->user('basia');
        $import = $this->zlecenie($osoba);

        // 12 zapytań na minutę (co 5 s) to zwykły polling jednej zakładki —
        // z dużym zapasem poniżej koszyka `import_postep` (40/min).
        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($osoba)
                ->get(route('import.show', ['import' => $import, 'fragment' => 1]))
                ->assertOk();
        }
    }

    public function test_nadmiarowe_zadania_dostaja_429_i_nie_pokazuja_cudzej_tresci(): void
    {
        $osoba = $this->user('basia');
        $import = $this->zlecenie($osoba);

        $ostatnia = null;

        for ($i = 0; $i < 41; $i++) {
            $ostatnia = $this->actingAs($osoba)->get(route('import.show', ['import' => $import, 'fragment' => 1]));
        }

        $ostatnia->assertStatus(429);
    }

    public function test_limit_jest_niezalezny_od_limitu_zlecania_importu(): void
    {
        $osoba = $this->user('basia');
        $import = $this->zlecenie($osoba);

        // Zjedz cały koszyk `import` (10/10 min) zanim ktokolwiek odpyta
        // o postęp — gdyby oba dzieliły licznik, poniższe odpytanie odbiłoby
        // się o limit, mimo że sam nigdy go nie użył (ten sam kształt błędu
        // co w `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`).
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($osoba)->post(route('import.ponow', $import));
        }

        $this->actingAs($osoba)
            ->get(route('import.show', ['import' => $import, 'fragment' => 1]))
            ->assertOk();
    }

    public function test_limit_jest_izolowany_miedzy_kontami(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        $importBasi = $this->zlecenie($basia);
        $importMarka = $this->zlecenie($marek);

        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($basia)->get(route('import.show', ['import' => $importBasi, 'fragment' => 1]));
        }

        // Koszyk Basi jest wyczerpany, ale Marek ma swój własny.
        $this->actingAs($marek)
            ->get(route('import.show', ['import' => $importMarka, 'fragment' => 1]))
            ->assertOk();
    }

    public function test_wiele_rownoleglych_zadan_tego_samego_importu_liczy_sie_razem_do_jednego_konta(): void
    {
        $osoba = $this->user('basia');
        $pierwszy = $this->zlecenie($osoba);
        $drugi = $this->zlecenie($osoba);

        // Dwie otwarte zakładki (dwa importy), ten sam koszyk konta:
        // 20 + 20 = 40, dokładnie limit — kolejne odbija się o 429.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($osoba)->get(route('import.show', ['import' => $pierwszy, 'fragment' => 1]))->assertOk();
            $this->actingAs($osoba)->get(route('import.show', ['import' => $drugi, 'fragment' => 1]))->assertOk();
        }

        $this->actingAs($osoba)
            ->get(route('import.show', ['import' => $pierwszy, 'fragment' => 1]))
            ->assertStatus(429);
    }

    private function zlecenie(User $osoba): ImportPrzepisu
    {
        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $osoba->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_W_TOKU,
        ]);
        $zlecenie->save();

        return $zlecenie;
    }
}
