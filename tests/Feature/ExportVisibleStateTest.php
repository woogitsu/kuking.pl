<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExportVisibleStateTest extends TestCase
{
    use RefreshDatabase;

    public static function deadlines(): array
    {
        return [[1, 'gotowa', true], [0, 'wygasła', false], [-1, 'wygasła', false],
            [null, 'niedostępna', false]];
    }

    public function test_termin_na_ekranie_ma_te_sama_godzine_co_list(): void
    {
        $user = $this->user();
        $export = DataExport::create(['user_id' => $user->id, 'status' => 'ready',
            'expires_at' => now()->addDay()->setTime(12, 15)]);
        $deadline = Czas::data($export->expires_at, 'j F Y, H:i');
        $this->actingAs($user)->get(route('settings.data'))->assertOk()->assertSee('Do pobrania do '.$deadline.'.');
        $this->assertStringContainsString($deadline, (new DataExportReady($export))->render());
    }

    public function test_po_sprzataniu_stan_wygaslej_paczki_i_instrukcja_pozostaja(): void
    {
        $user = $this->user();
        $export = DataExport::create(['user_id' => $user->id, 'status' => 'ready', 'expires_at' => now()->subMinute()]);
        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();
        $this->assertSame('expired', $export->refresh()->status);
        $this->actingAs($user)->get(route('settings.data'))->assertOk()->assertSee('wygasła')
            ->assertSee('Tej paczki nie można już pobrać.')->assertDontSee('Pobierz paczkę');
    }

    #[DataProvider('deadlines')]
    public function test_etykieta_i_przycisk_odpowiadaja_terminowi_bez_sprzatania(?int $offset, string $label, bool $download): void
    {
        $this->freezeSecond();
        $user = $this->user();
        $export = DataExport::create(['user_id' => $user->id, 'status' => 'ready',
            'expires_at' => $offset === null ? null : now()->addSeconds($offset)]);
        $response = $this->actingAs($user)->get(route('settings.data'))->assertOk();
        $html = $response->getContent();
        $list = substr($html, strpos($html, 'Twoje paczki'));
        $list = substr($list, 0, strpos($list, '</ul>'));
        $this->assertStringContainsString($label, $list);
        if ($download) {
            $this->assertStringContainsString('Pobierz paczkę', $list);
        } else {
            $this->assertStringNotContainsString('Pobierz paczkę', $list);
            $this->assertStringNotContainsString('— gotowa', $list);
            $this->assertStringContainsString('Przygotuj paczkę z moimi danymi', $list);
        }
        $this->assertSame('ready', $export->refresh()->status, 'GET nie sprząta magazynu ani nie zmienia stanu.');
    }
}
