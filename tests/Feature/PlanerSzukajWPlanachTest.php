<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * „Szukaj w moich planach” w planerze (#2581): zwykły GET z parametrem
 * `szukaj_w_planach`, odrębnym od `q` (przepis do dodania).
 */
final class PlanerSzukajWPlanachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function przepis(User $autor, string $tytul, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);
    }

    private function pozycja(User $kto, string $dzien, ?Recipe $przepis = null, ?string $tekst = null): MealPlanEntry
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'recipe_id' => $przepis?->getKey(), 'label' => $tekst]);
        $wpis->user_id = $kto->getKey();
        $wpis->save();

        return $wpis;
    }

    private function szukaj(User $kto, string $fraza): string
    {
        return $this->actingAs($kto)->get(route('planer.show', ['szukaj_w_planach' => $fraza]))->assertOk()->getContent();
    }

    public function test_gosc_idzie_do_logowania(): void
    {
        $this->get(route('planer.show', ['szukaj_w_planach' => 'obiad']))->assertRedirect(route('login'));
    }

    public function test_formularz_ma_widoczna_etykiete_i_inna_nazwe_pola_niz_q(): void
    {
        $html = $this->actingAs($this->user('osoba1'))->get(route('planer.show'))->assertOk()->getContent();

        $this->assertStringContainsString('Szukaj w moich planach', $html);
        $this->assertMatchesRegularExpression('~<label for="szukaj-w-planach">[^<]+</label>~', $html);
        $this->assertStringContainsString('name="szukaj_w_planach"', $html);
    }

    public function test_znajduje_wlasny_tekst_z_pelna_data_i_linkiem_do_tygodnia(): void
    {
        $user = $this->user('osoba1');
        $this->pozycja($user, '2025-03-12', null, 'Obiad u mamy');

        $html = $this->szukaj($user, 'obiad u mamy');

        $this->assertStringContainsString('Obiad u mamy', $html);
        $this->assertStringContainsString('środa, 12 marca 2025', $html);
        $this->assertStringContainsString(e(route('planer.show', ['tydzien' => '2025-03-12'])).'#dzien-2025-03-12', $html);
    }

    public function test_znajduje_po_tytule_widocznego_przepisu(): void
    {
        $user = $this->user('osoba1');
        $this->pozycja($user, '2026-10-02', $this->przepis($this->user('autor1'), 'Żurek babci Zosi'));

        $html = $this->szukaj($user, 'zurek');

        $this->assertStringContainsString('Żurek babci Zosi', $html);
        $this->assertStringContainsString('piątek, 2 października 2026', $html);
    }

    public function test_wielkosc_liter_i_polskie_znaki_nie_maja_znaczenia(): void
    {
        $user = $this->user('osoba1');
        $this->pozycja($user, '2026-10-02', null, 'Łosoś z piekarnika');

        $this->assertStringContainsString('Łosoś z piekarnika', $this->szukaj($user, 'LOSOS'));
        $this->assertStringContainsString('Łosoś z piekarnika', $this->szukaj($user, 'łoSOś'));
    }

    public function test_cudza_pozycja_nie_wyszukuje_sie(): void
    {
        $ja = $this->user('osoba1');
        $inna = $this->user('osoba2');
        $this->pozycja($inna, '2026-10-02', null, 'Obiad u cioci Basi');
        $this->pozycja($inna, '2026-10-03', $this->przepis($this->user('autor1'), 'Sernik cioci Basi'));

        $html = $this->szukaj($ja, 'basi');

        $this->assertStringNotContainsString('Obiad u cioci Basi', $html);
        $this->assertStringNotContainsString('Sernik cioci Basi', $html);
        $this->assertStringContainsString('Nic nie znaleźliśmy w Twoich planach', $html);
    }

    public function test_tytul_przepisu_niewidocznego_nie_dopasowuje_i_nie_wycieka(): void
    {
        $user = $this->user('osoba1');
        $autor = $this->user('autor1');
        $prywatny = $this->przepis($autor, 'Tajny pasztet Krysi', ['visibility' => 'private']);
        $usuniety = $this->przepis($autor, 'Pasztet usunięty Krysi');
        $widoczny = $this->przepis($autor, 'Pasztet jawny Krysi');
        $this->pozycja($user, '2026-10-02', $prywatny);
        $this->pozycja($user, '2026-10-03', $usuniety);
        $this->pozycja($user, '2026-10-04', $widoczny);
        $usuniety->delete();

        $html = $this->szukaj($user, 'pasztet');

        $this->assertStringContainsString('Pasztet jawny Krysi', $html);
        $this->assertStringNotContainsString('Tajny pasztet Krysi', $html);
        $this->assertStringNotContainsString('Pasztet usunięty Krysi', $html);
        $this->assertStringNotContainsString('piątek, 2 października 2026', $html);
        $this->assertStringNotContainsString('sobota, 3 października 2026', $html);
    }

    public function test_najnowsze_najpierw_i_limit_z_informacja_ze_jest_wiecej(): void
    {
        $user = $this->user('osoba1');
        for ($i = 0; $i < 52; $i++) {
            $this->pozycja($user, Carbon::parse('2025-01-01')->addDays($i)->toDateString(), null, 'Kolacja numer '.$i);
        }

        $html = $this->szukaj($user, 'kolacja');

        $this->assertStringContainsString('Kolacja numer 51', $html);
        $this->assertStringNotContainsString('Kolacja numer 1'."\n", $html);
        $this->assertSame(50, substr_count($html, 'Pokaż ten tydzień<'));
        $this->assertStringContainsString('jest ich więcej', $html);
        $this->assertLessThan(strpos($html, 'Kolacja numer 50'), strpos($html, 'Kolacja numer 51'));
    }

    public function test_pusta_krotka_dluga_i_tablicowa_fraza_nie_wywoluja_bledu(): void
    {
        $user = $this->user('osoba1');
        $this->pozycja($user, '2026-10-02', null, 'Obiad');

        $this->assertStringNotContainsString('Znalezione pozycje', $this->szukaj($user, ''));
        $this->assertStringContainsString('co najmniej dwie litery', $this->szukaj($user, 'o'));
        $this->assertStringContainsString('Skróć tekst', $this->szukaj($user, str_repeat('a', 121)));
        $this->actingAs($user)->get(route('planer.show', ['szukaj_w_planach' => ['a', 'b']]))
            ->assertOk()->assertDontSee('Znalezione pozycje');
    }

    public function test_procent_i_podkreslnik_nie_sa_wzorcem(): void
    {
        $user = $this->user('osoba1');
        $this->pozycja($user, '2026-10-02', null, 'Obiad u mamy');

        $this->assertStringContainsString('Nic nie znaleźliśmy', $this->szukaj($user, '%%'));
        $this->assertStringContainsString('Nic nie znaleźliśmy', $this->szukaj($user, 'o_iad'));
    }

    public function test_dawna_data_poza_oknem_zapisu_jest_znaleziona(): void
    {
        $user = $this->user('osoba1');
        $this->pozycja($user, '2019-05-15', null, 'Imieniny dziadka');

        $this->assertStringContainsString('środa, 15 maja 2019', $this->szukaj($user, 'imieniny'));
    }
}
