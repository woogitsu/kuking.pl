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
 * Dodanie przepisu do wybranego dnia z widoku tygodnia planera (#2037).
 * Czas zamrożony na czwartek 1 października 2026; wyszukiwanie to zwykły GET,
 * dodanie — zwykły POST na istniejące `planer.store`.
 */
final class PlanerDodajPrzepisDoDniaTest extends TestCase
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

    private function sekcja(string $html, string $data): string
    {
        $this->assertSame(1, preg_match('~<section[^>]*aria-labelledby="dzien-'.$data.'"[^>]*>(.*?)</section>~s', $html, $m));

        return $m[1];
    }

    public function test_kazdy_dzien_ma_szukanie_przepisu_bez_skryptu(): void
    {
        $user = $this->user('osoba1');
        $html = $this->actingAs($user)->get(route('planer.show', ['tydzien' => '2026-10-12']))->assertOk()->getContent();

        $dzien = $this->sekcja($html, '2026-10-14');
        $this->assertStringContainsString('Dodaj przepis do tego dnia', $dzien);
        $this->assertStringContainsString('<label for="q-2026-10-14">Nazwa przepisu</label>', $dzien);
        $this->assertStringContainsString('name="dzien" value="2026-10-14"', $dzien);
        $this->assertStringContainsString('Szukaj przepisu</button>', $dzien);
    }

    public function test_wyniki_pokazuja_tylko_widoczne_przepisy_i_tylko_w_wybranym_dniu(): void
    {
        $user = $this->user('osoba2');
        $autor = $this->user('osoba3');
        $this->przepis($autor, 'Zupa ogórkowa babci');
        $this->przepis($autor, 'Zupa szkicowa ukryta', ['status' => Recipe::STATUS_DRAFT]);

        $html = $this->actingAs($user)
            ->get(route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-14', 'q' => 'zupa']))
            ->assertOk()->getContent();

        $wybrany = $this->sekcja($html, '2026-10-14');
        $this->assertStringContainsString('Zupa ogórkowa babci', $wybrany);
        $this->assertStringContainsString('Dodaj do planu<span class="visually-hidden">: Zupa ogórkowa babci', $wybrany);
        $this->assertStringNotContainsString('Zupa szkicowa ukryta', $html);
        $this->assertStringNotContainsString('Zupa ogórkowa babci', $this->sekcja($html, '2026-10-15'));
    }

    public function test_za_krotka_fraza_mowi_co_zrobic(): void
    {
        $user = $this->user('osoba4');
        $html = $this->actingAs($user)
            ->get(route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-14', 'q' => 'a']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Wpisz co najmniej dwie litery nazwy przepisu', $this->sekcja($html, '2026-10-14'));
        $this->assertStringContainsString('value="a"', $this->sekcja($html, '2026-10-14'));
    }

    public function test_brak_wynikow_podpowiada_co_zrobic(): void
    {
        $user = $this->user('osoba5');
        $html = $this->actingAs($user)
            ->get(route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-14', 'q' => 'nieistniejacydanie']))
            ->getContent();

        $this->assertStringContainsString('Spróbuj krótszego słowa', $this->sekcja($html, '2026-10-14'));
    }

    public function test_dzien_spoza_tygodnia_jest_ignorowany(): void
    {
        $user = $this->user('osoba6');
        $html = $this->actingAs($user)
            ->get(route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2027-01-01', 'q' => 'zupa']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Znalezione przepisy', $html);
    }

    public function test_dodanie_dzien_za_ponad_7_dni_wraca_do_panelu_tego_dnia(): void
    {
        $user = $this->user('osoba7');
        $przepis = $this->przepis($this->user('osoba8'), 'Sernik krakowski');
        $wpis = MealPlanEntry::query()->count();

        $this->actingAs($user)->post(route('planer.store'), [
            'day' => '2026-10-14', 'recipe_id' => $przepis->getKey(), 'z_planera' => 1, 'q' => 'sernik',
        ])->assertRedirect(route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-14', 'q' => 'sernik']).'#szukaj-2026-10-14')
            ->assertSessionHas('status', 'Dodane do planu na środa, 14 października.');

        $this->assertSame($wpis + 1, MealPlanEntry::query()->count());
        $this->assertDatabaseHas('meal_plan_entries', ['user_id' => $user->getKey(), 'recipe_id' => $przepis->getKey(), 'day' => '2026-10-14']);
    }

    public function test_duplikat_i_niewidoczny_przepis_nie_wchodza(): void
    {
        $user = $this->user('osoba9');
        $autor = $this->user('osoba10');
        $przepis = $this->przepis($autor, 'Sernik');
        $szkic = $this->przepis($autor, 'Szkic', ['status' => Recipe::STATUS_DRAFT]);
        $dane = ['day' => '2026-10-14', 'z_planera' => 1, 'q' => 'sernik'];

        $this->actingAs($user)->post(route('planer.store'), $dane + ['recipe_id' => $przepis->getKey()]);
        $this->actingAs($user)->post(route('planer.store'), $dane + ['recipe_id' => $przepis->getKey()])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Ten przepis już jest w planie'));
        $this->actingAs($user)->post(route('planer.store'), $dane + ['recipe_id' => $szkic->getKey()])->assertForbidden();

        $this->assertSame(1, MealPlanEntry::query()->count());
    }

    public function test_pelny_dzien_nie_ma_szukania(): void
    {
        $user = $this->user('osoba11');
        for ($i = 0; $i < 30; $i++) {
            $w = new MealPlanEntry(['day' => '2026-10-14', 'label' => "Pozycja {$i}"]);
            $w->user_id = $user->getKey();
            try {
                $w->save();
            } catch (\Throwable) {
                break;
            }
        }
        $html = $this->actingAs($user)->get(route('planer.show', ['tydzien' => '2026-10-12']))->getContent();
        $this->assertStringContainsString('Ten dzień ma komplet', $this->sekcja($html, '2026-10-14'));
        $this->assertStringNotContainsString('Dodaj przepis do tego dnia', $this->sekcja($html, '2026-10-14'));
    }
}
