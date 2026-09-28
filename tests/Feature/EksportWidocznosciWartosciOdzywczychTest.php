<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EksportWidocznosciWartosciOdzywczychTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function paczka_zachowuje_zarowno_ukrycie_jak_i_domyslna_widocznosc_wartosci(): void
    {
        $autor = User::factory()->create();
        Recipe::factory()->for($autor, 'author')->create(['title' => 'Widoczny przepis']);
        $ukryty = Recipe::factory()->for($autor, 'author')->create(['title' => 'Ukryty przepis']);
        DB::table('recipes')->where('id', $ukryty->getKey())->update(['pokazuj_wartosci_odzywcze' => false]);

        $paczka = app(CollectUserExportData::class)->handle(
            $autor,
            new ExportPhotoPlan($autor),
            Carbon::parse('2026-09-28 12:00', 'UTC'),
        );

        $przepisy = collect($paczka['przepisy'])->keyBy('tytul');
        $this->assertCount(2, $przepisy);
        $this->assertArrayHasKey('pokazuj_wartosci_odzywcze', $przepisy['Widoczny przepis']);
        $this->assertArrayHasKey('pokazuj_wartosci_odzywcze', $przepisy['Ukryty przepis']);
        $this->assertSame(true, $przepisy['Widoczny przepis']['pokazuj_wartosci_odzywcze']);
        $this->assertSame(false, $przepisy['Ukryty przepis']['pokazuj_wartosci_odzywcze']);

        // Generator paczki koduje tę samą tablicę do dane.json. Obie wartości
        // muszą pozostać booleanami także po serializacji, nie tekstem ani 0/1.
        $json = json_decode(json_encode(
            $paczka,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ), true, 512, JSON_THROW_ON_ERROR);
        $przepisyJson = collect($json['przepisy'])->keyBy('tytul');
        $this->assertSame(true, $przepisyJson['Widoczny przepis']['pokazuj_wartosci_odzywcze']);
        $this->assertSame(false, $przepisyJson['Ukryty przepis']['pokazuj_wartosci_odzywcze']);
    }
}
