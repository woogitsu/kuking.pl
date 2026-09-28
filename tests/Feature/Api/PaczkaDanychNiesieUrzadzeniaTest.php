<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tokeny API (D-270) to urządzenia z dostępem do konta — dane tej osoby,
 * które paczka RODO ma wydać (`InwentarzDanychKonta`), tak jak sesje
 * przeglądarki. Wychodzi nazwa i daty; skrót sekretu nie wychodzi nigdy.
 */
final class PaczkaDanychNiesieUrzadzeniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_paczka_niesie_wlasne_urzadzenia_bez_sekretu_i_bez_cudzych(): void
    {
        $basia = User::factory()->create();
        $zenek = User::factory()->create();

        $tokenBasi = $basia->createToken('Telefon Basi');
        $tokenBasi->accessToken->forceFill(['last_used_at' => Carbon::parse('2026-09-20 08:00:00', 'UTC')])->save();
        $zenek->createToken('Tablet Zenka');

        $paczka = app(CollectUserExportData::class)->handle(
            $basia->fresh(),
            new ExportPhotoPlan($basia),
            Carbon::parse('2026-09-24 12:00:00', 'UTC'),
        );
        $json = (string) json_encode($paczka, JSON_UNESCAPED_UNICODE);

        $this->assertCount(1, $paczka['urzadzenia_z_dostepem'], 'Paczka Basi ma nieść wyłącznie JEJ urządzenia.');
        $this->assertSame('Telefon Basi', $paczka['urzadzenia_z_dostepem'][0]['nazwa']);
        $this->assertNotNull($paczka['urzadzenia_z_dostepem'][0]['zalogowano']);
        $this->assertStringStartsWith('2026-09-20T08:00:00', (string) $paczka['urzadzenia_z_dostepem'][0]['ostatnio_uzyte']);

        $skrot = (string) DB::table('personal_access_tokens')
            ->where('id', $tokenBasi->accessToken->getKey())
            ->value('token');
        [, $sekret] = explode('|', $tokenBasi->plainTextToken, 2);

        foreach ([$skrot, $sekret, 'Tablet Zenka'] as $nieWolno) {
            $this->assertStringNotContainsString($nieWolno, $json, "Paczka niesie coś, czego nie wolno jej nieść: {$nieWolno}");
        }
    }
}
