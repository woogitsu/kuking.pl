<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\TrescDigestu;
use App\Domain\Digest\ZbierzTresciDigestu;
use App\Domain\Social\Actions\BlockUser;
use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W liście nie może się znaleźć NIC, czego adresat nie zobaczyłby na stronie.
 *
 * DLACZEGO TO JEST OSOBNY PLIK, A NIE KILKA PRZYPADKÓW W GŁÓWNYM TEŚCIE
 * Bo `App\Domain\Digest\ZbierzTresciDigestu` jest jedynym miejscem w tym
 * repozytorium, które składa cudze treści dla odbiorcy, KTÓRY NIE PATRZY NA
 * EKRAN. Wszędzie indziej granicę wyznacza Policy przy wejściu albo zakres
 * `widoczneDla($widz)` przy liście — a tu widza nie ma, jest adres pocztowy.
 * Pomyłka nie skończy się cudzym 403, tylko cudzą treścią wysłaną na obcą
 * skrzynkę, skąd nie da się jej cofnąć.
 *
 * Reguły są te same co na ekranach i tak też są tu sprawdzane:
 * blokada w obie strony, status autora, widoczność wpisu.
 */
class PodsumowanieSzanujePrywatnoscTest extends TestCase
{
    use RefreshDatabase;

    private function zbierz(User $odbiorca): TrescDigestu
    {
        return app(ZbierzTresciDigestu::class)->dlaJednej($odbiorca->fresh());
    }

    private function wykonanieNaPrzepisie(User $autor, User $kucharz): CookedEvent
    {
        $przepis = Recipe::factory()->for($autor, 'author')->create();

        return CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);
    }

    // -----------------------------------------------------------------
    // Wykonania „Ugotowałem"
    // -----------------------------------------------------------------

    public function test_wykonanie_widac_gdy_nic_nie_stoi_na_przeszkodzie(): void
    {
        // ASERCJA KONTROLNA CAŁEGO PLIKU. Bez niej wszystkie testy niżej
        // przechodziłyby także wtedy, gdyby zbieracz nie zwracał NIGDY nic.
        $autor = $this->user('autor_ok');
        $this->wykonanieNaPrzepisie($autor, $this->user('kucharz_ok'));

        $this->assertCount(1, $this->zbierz($autor)->wykonania);
    }

    public function test_wykonanie_osoby_zablokowanej_nie_trafia_do_listu(): void
    {
        $autor = $this->user('autor_blokujacy');
        $kucharz = $this->user('kucharz_zablokowany');

        $this->wykonanieNaPrzepisie($autor, $kucharz);
        app(BlockUser::class)->handle($autor, $kucharz);

        $this->assertSame([], $this->zbierz($autor)->wykonania);
    }

    public function test_wykonanie_osoby_ktora_zablokowala_autora_tez_nie_trafia(): void
    {
        // Blokada działa w OBIE strony (AGENTS.md §4) — także wtedy, gdy to
        // kucharz zablokował autora przepisu.
        $autor = $this->user('autor_zablokowany');
        $kucharz = $this->user('kucharz_blokujacy');

        $this->wykonanieNaPrzepisie($autor, $kucharz);
        app(BlockUser::class)->handle($kucharz, $autor);

        $this->assertSame([], $this->zbierz($autor)->wykonania);
    }

    public function test_wykonanie_konta_zbanowanego_nie_trafia_do_listu(): void
    {
        $autor = $this->user('autor_bez_bana');
        $kucharz = $this->user('kucharz_zbanowany');

        $this->wykonanieNaPrzepisie($autor, $kucharz);
        $kucharz->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->assertSame([], $this->zbierz($autor)->wykonania);
    }

    public function test_wykonanie_usunietego_przepisu_nie_trafia_do_listu(): void
    {
        $autor = $this->user('autor_kasujacy');
        $wykonanie = $this->wykonanieNaPrzepisie($autor, $this->user('kucharz_kasowanego'));

        $wykonanie->recipe->delete();

        $this->assertSame([], $this->zbierz($autor)->wykonania);
    }

    public function test_wlasne_gotowanie_z_wlasnego_przepisu_nie_jest_powodem_do_listu(): void
    {
        $autor = $this->user('sam_sobie');
        $this->wykonanieNaPrzepisie($autor, $autor);

        $this->assertSame([], $this->zbierz($autor)->wykonania);
        $this->assertTrue($this->zbierz($autor)->jestPusty());
    }

    // -----------------------------------------------------------------
    // Wpisy obserwowanych
    // -----------------------------------------------------------------

    public function test_wpis_obserwowanego_widac_takze_gdy_jest_tylko_dla_obserwujacych(): void
    {
        $odbiorca = $this->user('obserwujacy');
        $autor = $this->user('obserwowany_autor');
        $odbiorca->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        Post::factory()->for($autor, 'author')->followersOnly()->create([
            'published_at' => now()->subDay(),
        ]);

        $this->assertCount(1, $this->zbierz($odbiorca)->wpisyObserwowanych);
    }

    public function test_wpis_prywatny_nie_trafia_do_listu(): void
    {
        $odbiorca = $this->user('obserwujacy_prywatne');
        $autor = $this->user('autor_prywatnych');
        $odbiorca->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        Post::factory()->for($autor, 'author')->private()->create([
            'published_at' => now()->subDay(),
        ]);

        $this->assertSame([], $this->zbierz($odbiorca)->wpisyObserwowanych);
    }

    public function test_szkic_nie_trafia_do_listu(): void
    {
        $odbiorca = $this->user('obserwujacy_szkice');
        $autor = $this->user('autor_szkicow');
        $odbiorca->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        Post::factory()->for($autor, 'author')->draft()->create();

        $this->assertSame([], $this->zbierz($odbiorca)->wpisyObserwowanych);
    }

    public function test_wpis_kogos_nieobserwowanego_nie_trafia_do_listu(): void
    {
        $odbiorca = $this->user('nikogo_nie_obserwuje');
        $obcy = $this->user('obca_osoba');

        Post::factory()->for($obcy, 'author')->create(['published_at' => now()->subDay()]);

        $this->assertSame([], $this->zbierz($odbiorca)->wpisyObserwowanych);

        // Feed obserwowanych nie jest tablicą polecanych: brak obserwacji
        // znaczy brak treści, a nie „to podsuniemy coś ciekawego".
        $this->assertTrue($this->zbierz($odbiorca)->jestPusty());
    }

    public function test_wpis_autora_zawieszonego_nie_trafia_do_listu(): void
    {
        $odbiorca = $this->user('obserwujacy_zawieszonego');
        $autor = $this->user('autor_zawieszony');
        $odbiorca->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        Post::factory()->for($autor, 'author')->create(['published_at' => now()->subDay()]);
        $autor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        // Ta sama granica co `FollowingFeed`: serwis przestaje PODSUWAĆ
        // treści ukaranego konta, choć ich nie kasuje.
        $this->assertSame([], $this->zbierz($odbiorca)->wpisyObserwowanych);
    }

    public function test_blokada_usuwa_osobe_z_sekcji_obserwowanych(): void
    {
        $odbiorca = $this->user('blokujacy_obserwowanego');
        $autor = $this->user('zablokowany_obserwowany');
        $odbiorca->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        Post::factory()->for($autor, 'author')->create(['published_at' => now()->subDay()]);

        $this->assertCount(1, $this->zbierz($odbiorca)->wpisyObserwowanych);

        // Blokada KASUJE obserwowanie w obie strony
        // (`App\Domain\Social\Actions\BlockUser`) — i właśnie dlatego zbieracz
        // nie sprawdza tu blokady drugi raz.
        app(BlockUser::class)->handle($odbiorca, $autor);

        $this->assertSame([], $this->zbierz($odbiorca)->wpisyObserwowanych);
    }

    // -----------------------------------------------------------------
    // Nowi obserwujący
    // -----------------------------------------------------------------

    public function test_konto_zamkniete_nie_pokazuje_sie_jako_nowy_obserwujacy(): void
    {
        $odbiorca = $this->user('obserwowany_przez_zbanowanego');
        $zbanowany = $this->user('zbanowany_obserwator');

        $zbanowany->following()->attach($odbiorca->getKey(), ['created_at' => now()->subDay()]);
        $zbanowany->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->assertSame(0, $this->zbierz($odbiorca)->ileNowychObserwujacych);
    }

    public function test_stara_obserwacja_nie_jest_nowa(): void
    {
        $odbiorca = $this->user('dawno_obserwowany');
        $obserwator = $this->user('dawny_obserwator');

        $obserwator->following()->attach($odbiorca->getKey(), ['created_at' => now()->subMonths(3)]);

        $this->assertSame(0, $this->zbierz($odbiorca)->ileNowychObserwujacych);
    }

    // -----------------------------------------------------------------
    // Cała droga: od zapytania do gotowego listu
    // -----------------------------------------------------------------

    public function test_gotowy_list_nie_niesie_tresci_osoby_zablokowanej(): void
    {
        $autor = $this->user('finalny_autor');
        $dobry = $this->user('kucharz_zostaje', ['display_name' => 'Halina']);
        $zly = $this->user('kucharz_zablokowany_finalnie', ['display_name' => 'Zenon']);

        $this->wykonanieNaPrzepisie($autor, $dobry);
        $this->wykonanieNaPrzepisie($autor, $zly);
        app(BlockUser::class)->handle($autor, $zly);

        $html = (new PodsumowanieTygodnia($this->zbierz($autor)))->render();

        $this->assertStringContainsString('Halina', $html);
        $this->assertStringNotContainsString('Zenon', $html);
    }
}
