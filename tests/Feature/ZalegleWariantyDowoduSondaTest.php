<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Models\Media;
use App\Models\ZabezpieczenieDowodu;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sondy\SondaKolejki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #2437: trwały stan dowodu alarmuje także wtedy, gdy jobs/failed_jobs są puste. */
final class ZalegleWariantyDowoduSondaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_dowod_bez_zakonczenia_przeniesienia_jest_alarmem_bez_joba(): void
    {
        $zdjecie = $this->dowod();

        // Tu nie ma ani jobs, ani failed_jobs; alarm bierze się z trwałego stanu.
        $this->travel(16)->minutes();
        try {
            app(SondaKolejki::class)->sprawdz();
            $this->fail('Sonda nie zauważyła dowodu bez zakończonego przeniesienia.');
        } catch (KontrolaZdrowiaNieprzeszla $e) {
            $this->assertSame(Powody::POWOD_WARIANTY_DOWODU_ZALEGLE, $e->kod);
            $this->assertStringNotContainsString((string) $zdjecie->getKey(), $e->getMessage());
        }
    }

    public function test_dowod_przed_progiem_i_po_trwalym_zakonczeniu_nie_alarmuje(): void
    {
        $zdjecie = $this->dowod();
        app(SondaKolejki::class)->sprawdz();

        $zdjecie->update(['metadata' => array_merge($zdjecie->metadata, [
            Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT => now()->toIso8601String(),
        ])]);
        $this->travel(16)->minutes();
        app(SondaKolejki::class)->sprawdz();
    }

    public function test_pusty_lub_null_znacznik_nie_wycisza_alarmu(): void
    {
        $zdjecie = $this->dowod();
        $this->travel(16)->minutes();

        foreach ([null, '', '  '] as $znacznik) {
            $zdjecie->update(['metadata' => array_merge($zdjecie->metadata, [
                Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT => $znacznik,
            ])]);

            try {
                app(SondaKolejki::class)->sprawdz();
                $this->fail('Pusty znacznik nie może wyciszyć alarmu.');
            } catch (KontrolaZdrowiaNieprzeszla $e) {
                $this->assertSame(Powody::POWOD_WARIANTY_DOWODU_ZALEGLE, $e->kod);
            }
        }
    }

    private function dowod(): Media
    {
        $zdjecie = Media::factory()->create();
        $zdjecie->forceFill(['status' => Media::STATUS_SECURED])->save();

        $dowod = new ZabezpieczenieDowodu;
        $dowod->forceFill([
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'subject_user_id' => $zdjecie->owner_id,
            'previous_media_status' => Media::STATUS_READY,
        ])->save();

        return $zdjecie;
    }
}
