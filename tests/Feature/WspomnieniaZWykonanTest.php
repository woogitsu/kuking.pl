<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Wspomnienia z własnych wykonań „Ugotowałem” (F6, research z 30.09.2026).
 *
 * Te same reguły co wspomnienia z wpisów (`WspomnieniaTest`): jeden element,
 * ten sam dzień w strefie czytelnika, wyłącznik, ukrycie pojedyncze. Do tego
 * dwie granice, których wpis nie miał: wspomnienie jest TYLKO kucharza (cudze
 * wykonanie mojego przepisu nie jest moim wspomnieniem) i nie wraca, gdy
 * przepisu nie wolno tej osobie już zobaczyć.
 *
 * Asercje czytają SAMĄ sekcję `section.wspomnienie` (pułapka 1): tytuł
 * przepisu stoi też w szynie „ostatnio odłożone”, a „Rok temu” mogłoby
 * przyjść z rocznicy dołączenia.
 */
class WspomnieniaZWykonanTest extends TestCase
{
    use RefreshDatabase;

    /** Południe lokalnie — bezpiecznie w środku dnia po obu stronach stref (patrz `WspomnieniaTest`). */
    private function wykonanieSprzed(User $kucharz, int $lat, ?Recipe $przepis = null, string $notatka = 'Wyszły jak u mamy.'): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => ($przepis ?? Recipe::factory()->create(['title' => 'Pierogi Basi']))->getKey(),
            'note' => $notatka,
            'cooked_at' => Czas::lokalnie(Carbon::now())->subYears($lat)->setTime(12, 0),
        ]);
    }

    /** Zawartość bloku wspomnienia albo `null`, gdy go nie ma. */
    private function blok(User $kto): ?string
    {
        $html = (string) $this->actingAs($kto)->get(route('home'))->assertOk()->getContent();

        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $wezel = (new DOMXPath($dokument))->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' wspomnienie ')]")->item(0);

        return $wezel === null ? null : (string) $dokument->saveHTML($wezel);
    }

    public function test_wlasne_ugotowanie_sprzed_roku_wraca_z_przyciskiem_ugotuj_znowu(): void
    {
        $zofia = $this->user('zofia');
        $wykonanie = $this->wykonanieSprzed($zofia, 1);

        $blok = $this->blok($zofia);

        $this->assertNotNull($blok, 'Blok wspomnienia nie pojawił się mimo wykonania sprzed roku.');
        $this->assertStringContainsString('Rok temu, ', $blok);
        $this->assertStringContainsString('Pierogi Basi', $blok);
        $this->assertStringContainsString('Wyszły jak u mamy.', $blok);
        $this->assertStringContainsString(
            'href="'.route('recipes.show', $wykonanie->recipe->slug).'">Ugotuj znowu</a>',
            $blok,
        );
        $this->assertStringContainsString('action="'.route('wspomnienia.ukryj-wykonanie', $wykonanie).'"', $blok);
        // Cichy ton — żadnego podsumowania, żadnej animacji.
        $this->assertStringNotContainsString('<script', $blok);
        $this->assertStringNotContainsString('podsumowanie', mb_strtolower($blok));
    }

    public function test_dwa_lata_temu_mowi_prawde(): void
    {
        $zofia = $this->user('zofia');
        $this->wykonanieSprzed($zofia, 2);

        $this->assertStringContainsString('Dwa lata temu, ', (string) $this->blok($zofia));
    }

    public function test_dzisiejsze_ugotowanie_nie_jest_wspomnieniem(): void
    {
        $zofia = $this->user('zofia');
        $this->wykonanieSprzed($zofia, 0);

        $this->assertNull($this->blok($zofia));
    }

    public function test_cudze_ugotowanie_mojego_przepisu_nie_jest_moim_wspomnieniem(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Pierogi Basi']);
        $this->wykonanieSprzed($this->user('zofia'), 1, $przepis);

        $this->assertNull($this->blok($basia), 'Cudze wykonanie trafiło do wspomnień autorki przepisu.');
    }

    public function test_wylaczone_wspomnienia_chowaja_tez_wykonania(): void
    {
        $zofia = $this->user('zofia');
        $zofia->forceFill(['memories_enabled' => false])->save();
        $this->wykonanieSprzed($zofia, 1);

        $this->assertNull($this->blok($zofia->fresh()));
    }

    public function test_schowane_wykonanie_nie_wraca_ale_zostaje_na_profilu(): void
    {
        $zofia = $this->user('zofia');
        $wykonanie = $this->wykonanieSprzed($zofia, 1);

        $this->actingAs($zofia)->post(route('wspomnienia.ukryj-wykonanie', $wykonanie))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($wykonanie->fresh()->hide_as_memory);
        $this->assertNull($this->blok($zofia), 'Schowane wspomnienie wróciło na stronę główną.');
        // Nic nie zniknęło: wykonanie dalej jest pod swoim adresem.
        $this->actingAs($zofia)->get(route('cooked.show', $wykonanie))->assertOk()->assertSee('Wyszły jak u mamy.');
    }

    public function test_nikt_poza_kucharzem_nie_schowa_wspomnienia(): void
    {
        $zofia = $this->user('zofia');
        $wykonanie = $this->wykonanieSprzed($zofia, 1);

        $this->actingAs($this->user('obcy'))
            ->post(route('wspomnienia.ukryj-wykonanie', $wykonanie))
            ->assertForbidden();

        auth()->logout();
        $this->post(route('wspomnienia.ukryj-wykonanie', $wykonanie))->assertRedirect(route('login'));

        $this->assertFalse($wykonanie->fresh()->hide_as_memory);
    }

    public function test_przepis_ktorego_juz_nie_widac_nie_wraca_jako_wspomnienie(): void
    {
        $zofia = $this->user('zofia');

        $prywatny = Recipe::factory()->create(['visibility' => 'private', 'title' => 'Prywatny przepis cudzy']);
        $this->wykonanieSprzed($zofia, 1, $prywatny);
        $this->assertNull($this->blok($zofia), 'Wspomnienie wyciągnęło cudzy prywatny przepis.');

        $usuniety = Recipe::factory()->create(['title' => 'Przepis usunięty']);
        $this->wykonanieSprzed($zofia, 1, $usuniety);
        $usuniety->delete();
        $this->assertNull($this->blok($zofia), 'Wspomnienie wyciągnęło usunięty przepis.');

        $autorka = $this->user('autorka');
        $zaBlokada = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Przepis za blokadą']);
        $this->wykonanieSprzed($zofia, 1, $zaBlokada);
        app(BlockUser::class)->handle($autorka, $zofia);
        $this->assertNull($this->blok($zofia), 'Wspomnienie przeszło przez blokadę z autorką przepisu.');
    }

    public function test_kontrola_dodatnia_widoczny_przepis_tej_samej_osoby_wraca(): void
    {
        // Bez tego trzy odmowy wyżej przeszłyby także wtedy, gdy blok nie
        // pokazuje wykonań wcale (pułapka 4).
        $zofia = $this->user('zofia');
        $autorka = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Przepis autorki']);
        $this->wykonanieSprzed($zofia, 1, $przepis);

        $this->assertStringContainsString('Przepis autorki', (string) $this->blok($zofia));
    }

    public function test_jedno_wspomnienie_starsze_z_dwoch_zrodel(): void
    {
        $zofia = $this->user('zofia');
        Post::create([
            'author_id' => $zofia->getKey(),
            'body' => 'Wpis sprzed roku.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => Czas::lokalnie(Carbon::now())->subYear()->setTime(12, 0),
        ]);
        $this->wykonanieSprzed($zofia, 3, notatka: 'Ugotowane trzy lata temu.');

        $blok = (string) $this->blok($zofia);

        $this->assertStringContainsString('Trzy lata temu, ', $blok);
        $this->assertStringContainsString('Ugotowane trzy lata temu.', $blok);
        $this->assertStringNotContainsString('Wpis sprzed roku.', $blok, 'Strona główna pokazała dwa wspomnienia zamiast jednego.');

        $this->assertInstanceOf(CookedEvent::class, app(Wspomnienia::class)->doPokazania($zofia));
    }
}
