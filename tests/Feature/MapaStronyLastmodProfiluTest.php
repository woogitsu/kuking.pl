<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `lastmod` profilu w mapie strony (#1280).
 *
 * Mapa brała datę wyłącznie z `profiles.updated_at`, a profil to przede
 * wszystkim lista wpisów i przepisów autora. Nowy przepis zmieniał stronę,
 * a `lastmod` zostawał z dnia ostatniej zmiany opisu. Kontrakt jest opisany
 * przy `SitemapController::zmianyTresciAutorow()`.
 *
 * Każdy przypadek generuje XML PRZED i PO zdarzeniu i porównuje konkretną
 * wartość `lastmod` — przypadki „nie przesuwa" są kontrolą ujemną.
 */
class MapaStronyLastmodProfiluTest extends TestCase
{
    use RefreshDatabase;

    private User $autorka;

    private Post $kotwica;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-01 10:00:00'));
        $this->autorka = User::factory()->create()->refresh();
        $this->autorka->profile->forceFill(['updated_at' => now()])->saveQuietly();
        // Publiczny wpis trzyma profil w mapie; data jego zmiany = data profilu.
        $this->kotwica = Post::factory()->create(['author_id' => $this->autorka->id]);
    }

    private function lastmodProfilu(): ?string
    {
        Cache::flush();

        $xml = simplexml_load_string((string) $this->get(route('sitemap'))->assertOk()->getContent());
        $this->assertNotFalse($xml);

        $profil = route('profile.show', $this->autorka->profile->username);
        foreach ($xml->url as $url) {
            if ((string) $url->loc === $profil) {
                return isset($url->lastmod) ? (string) $url->lastmod : null;
            }
        }
        $this->fail('Profilu autorki nie ma w mapie — przypadki niżej nic nie znaczą.');
    }

    private function poTygodniu(): string
    {
        $this->travelTo(Carbon::parse('2026-09-08 12:00:00'));

        return now()->toAtomString();
    }

    public function test_publikacja_publicznego_przepisu_przesuwa_lastmod_profilu(): void
    {
        $przed = $this->lastmodProfilu();
        $this->assertSame(Carbon::parse('2026-09-01 10:00:00')->toAtomString(), $przed);

        $teraz = $this->poTygodniu();
        Recipe::factory()->create(['author_id' => $this->autorka->id, 'published_at' => now()]);

        $this->assertSame($teraz, $this->lastmodProfilu());
    }

    public function test_publikacja_i_edycja_wpisu_przesuwa_lastmod(): void
    {
        $teraz = $this->poTygodniu();
        $this->kotwica->update(['body' => 'Poprawiony opis, widoczny na profilu.']);

        $this->assertSame($teraz, $this->lastmodProfilu());
    }

    public function test_ukrycie_i_usunięcie_treści_przesuwa_lastmod(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->autorka->id]);
        $drugiWpis = Post::factory()->create(['author_id' => $this->autorka->id]);
        $this->assertSame(Carbon::parse('2026-09-01 10:00:00')->toAtomString(), $this->lastmodProfilu());

        $teraz = $this->poTygodniu();
        $przepis->forceFill(['status' => 'hidden'])->save();
        $this->assertSame($teraz, $this->lastmodProfilu(), 'Ukrycie przepisu zmienia profil.');

        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $drugiWpis->delete();
        $this->assertSame(now()->toAtomString(), $this->lastmodProfilu(), 'Usunięcie wpisu zmienia profil.');
    }

    public function test_treści_prywatne_i_szkice_nie_przesuwają_lastmod(): void
    {
        $przed = $this->lastmodProfilu();

        $this->poTygodniu();
        Recipe::factory()->create(['author_id' => $this->autorka->id, 'visibility' => 'private']);
        Post::factory()->create(['author_id' => $this->autorka->id, 'visibility' => 'followers']);
        Recipe::factory()->draft()->create(['author_id' => $this->autorka->id]);

        $this->assertSame($przed, $this->lastmodProfilu());
    }

    public function test_komentarz_pod_wpisem_nie_przesuwa_lastmod(): void
    {
        $przed = $this->lastmodProfilu();

        $this->poTygodniu();
        Comment::factory()->create(['post_id' => $this->kotwica->id]);
        $this->assertSame(1, $this->kotwica->comments()->count());

        $this->assertSame($przed, $this->lastmodProfilu());
    }

    public function test_liczba_zapytań_nie_rośnie_z_liczbą_profili(): void
    {
        $zapytania = function (): int {
            Cache::flush();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get(route('sitemap'))->assertOk();
            $liczba = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $liczba;
        };

        $jedenProfil = $zapytania();
        foreach (range(1, 5) as $_) {
            $autor = User::factory()->create();
            Post::factory()->create(['author_id' => $autor->id]);
            Recipe::factory()->create(['author_id' => $autor->id]);
        }

        $this->assertSame($jedenProfil, $zapytania(), 'lastmod profilu nie może liczyć się zapytaniem na profil (N+1).');
    }
}
