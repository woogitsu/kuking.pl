<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\OsieroconeZdjecia;
use App\Domain\Media\ZachowaneZdjeciaPrzepisu;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Bramka zdjęć przepisu zachowanych przez błąd walidacji (issue #2050):
 * własność, „nic na nie nie wskazuje", status i wygaśnięcie.
 *
 * Identyfikator przychodzi z ukrytego pola formularza, więc każdy przypadek
 * niżej to coś, co klient może wpisać sam. UUID to nie autoryzacja.
 */
class ZachowaneZdjeciaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasne_swieze_nieprzypiete_zdjecie_przechodzi_pod_swoim_kluczem(): void
    {
        $basia = $this->user('basia');
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()]);

        $wynik = ZachowaneZdjeciaPrzepisu::przyjete(['steps.2' => $zdjecie->getKey()], $basia->getKey());

        $this->assertSame(['steps.2'], array_keys($wynik));
        $this->assertTrue($wynik['steps.2']->is($zdjecie));
    }

    public function test_cudze_zdjecie_nie_przechodzi(): void
    {
        $basia = $this->user('basia');
        $cudze = Media::factory()->create(['owner_id' => $this->user('marek')->getKey()]);

        $this->assertSame([], ZachowaneZdjeciaPrzepisu::przyjete(['hero' => $cudze->getKey()], $basia->getKey()));
    }

    public function test_bez_zalogowanej_osoby_nic_nie_przechodzi(): void
    {
        $zdjecie = Media::factory()->create();

        $this->assertSame([], ZachowaneZdjeciaPrzepisu::przyjete(['hero' => $zdjecie->getKey()], null));
    }

    public function test_zdjecie_juz_przypiete_do_wpisu_ani_przepisu_nie_przechodzi(): void
    {
        $basia = $this->user('basia');

        $weWpisie = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $wpis = Post::factory()->create(['author_id' => $basia->getKey()]);
        DB::table('post_media')->insert(['post_id' => $wpis->getKey(), 'media_id' => $weWpisie->getKey(), 'position' => 0]);

        $przepis = Recipe::factory()->zeZdjeciem()->create(['author_id' => $basia->getKey()]);

        $wynik = ZachowaneZdjeciaPrzepisu::przyjete([
            'hero' => $weWpisie->getKey(),
            'scan' => $przepis->hero_media_id,
        ], $basia->getKey());

        $this->assertSame([], $wynik, 'Zdjęcie, na które coś już wskazuje, nie jest „zachowanym z formularza".');
    }

    public function test_odrzucone_i_skasowane_nie_przechodza(): void
    {
        $basia = $this->user('basia');
        $odrzucone = Media::factory()->create(['owner_id' => $basia->getKey(), 'status' => Media::STATUS_REJECTED]);
        $skasowane = Media::factory()->create(['owner_id' => $basia->getKey(), 'status' => Media::STATUS_DELETED]);
        $wToku = Media::factory()->pending()->create(['owner_id' => $basia->getKey()]);

        $wynik = ZachowaneZdjeciaPrzepisu::przyjete([
            'hero' => $odrzucone->getKey(),
            'scan' => $skasowane->getKey(),
            'steps.0' => $wToku->getKey(),
        ], $basia->getKey());

        $this->assertSame(['steps.0'], array_keys($wynik), 'Zdjęcie jeszcze przetwarzane zostaje; odrzucone i skasowane nie.');
    }

    public function test_zdjecie_wygasa_przed_karencja_sprzatacza(): void
    {
        $basia = $this->user('basia');
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()]);

        $this->travel(ZachowaneZdjeciaPrzepisu::GODZIN_WAZNOSCI)->hours();
        $this->travel(-1)->minutes();
        $this->assertCount(1, ZachowaneZdjeciaPrzepisu::przyjete(['hero' => $zdjecie->getKey()], $basia->getKey()));

        $this->travel(2)->minutes();
        $this->assertSame([], ZachowaneZdjeciaPrzepisu::przyjete(['hero' => $zdjecie->getKey()], $basia->getKey()));
    }

    public function test_waznosc_jest_krotsza_niz_karencja_sprzatacza(): void
    {
        $karencja = (new ReflectionMethod(OsieroconeZdjecia::class, '__construct'))->getParameters()[0];

        $this->assertSame('godzinKarencji', $karencja->getName());
        $this->assertLessThan(
            (int) $karencja->getDefaultValue(),
            ZachowaneZdjeciaPrzepisu::GODZIN_WAZNOSCI,
            'Formularz nie może pokazywać jako zapamiętanego zdjęcia, które sprzątacz ma już prawo skasować.',
        );
    }

    public function test_smieci_powtorzenia_i_wielkie_litery_nie_przesuwaja_kluczy(): void
    {
        $basia = $this->user('basia');
        $pierwsze = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $drugie = Media::factory()->create(['owner_id' => $basia->getKey()]);

        $wynik = ZachowaneZdjeciaPrzepisu::przyjete([
            'steps.0' => strtoupper((string) $pierwsze->getKey()),
            'steps.1' => 'nie-uuid',
            'steps.2' => ['tablica'],
            'steps.3' => (string) Str::uuid(),
            'steps.4' => $drugie->getKey(),
            'steps.5' => $pierwsze->getKey(),
        ], $basia->getKey());

        $this->assertSame(['steps.0', 'steps.4'], array_keys($wynik));
        $this->assertTrue($wynik['steps.0']->is($pierwsze));
        $this->assertTrue($wynik['steps.4']->is($drugie));
    }
}
