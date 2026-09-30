<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\PlanerTygodnia;
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
            ->assertSessionHas('status', 'Dodane do planu na środę, 14 października.');

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
        for ($i = 0; $i < PlanerTygodnia::wpisowNaDzien(); $i++) {
            $w = new MealPlanEntry(['day' => '2026-10-14', 'label' => "Pozycja {$i}"]);
            $w->user_id = $user->getKey();
            $w->save();
        }
        $this->assertSame(PlanerTygodnia::wpisowNaDzien(), MealPlanEntry::query()->count());

        $html = $this->actingAs($user)->get(route('planer.show', ['tydzien' => '2026-10-12']))->getContent();
        $this->assertStringContainsString('Ten dzień ma komplet', $this->sekcja($html, '2026-10-14'));
        $this->assertStringNotContainsString('Dodaj przepis do tego dnia', $this->sekcja($html, '2026-10-14'));
        // Kontrola dodatnia: dzień obok ma panel.
        $this->assertStringContainsString('Dodaj przepis do tego dnia', $this->sekcja($html, '2026-10-15'));
    }

    public function test_prywatny_przepis_innej_osoby_nie_pojawia_sie_w_wynikach(): void
    {
        $user = $this->user('osoba12');
        $autor = $this->user('osoba13');
        $this->przepis($autor, 'Bigos prywatny cudzy', ['visibility' => 'private']);
        $this->przepis($autor, 'Bigos publiczny cudzy');
        $mojPrywatny = $this->przepis($user, 'Bigos prywatny mój', ['visibility' => 'private']);

        $html = $this->actingAs($user)
            ->get(route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-14', 'q' => 'bigos']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Bigos prywatny cudzy', $html);
        $this->assertStringContainsString('Bigos publiczny cudzy', $html);
        $this->assertNotNull($mojPrywatny->getKey());
        $this->assertStringContainsString('Bigos prywatny mój', $html, 'Własny prywatny przepis powinien być w wynikach.');
    }

    public function test_blad_pustego_wpisu_trafia_do_dnia_z_ktorego_przyszedl_post_a_nie_z_adresu(): void
    {
        $user = $this->user('osoba14');
        $referer = route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-15', 'q' => 'zupa']);

        $this->actingAs($user)
            ->from($referer)
            ->post(route('planer.store'), ['day' => '2026-10-14', 'label' => '   ', '_wiersz' => '2026-10-14'])
            ->assertRedirect($referer);

        $html = $this->actingAs($user)->get($referer)->assertOk()->getContent();
        $komunikat = 'Wpisz, co planujesz na ten dzień';

        $this->assertStringContainsString($komunikat, $this->sekcja($html, '2026-10-14'));
        $this->assertStringNotContainsString($komunikat, $this->sekcja($html, '2026-10-15'), 'Błąd z 14.10 pojawił się przy dniu z adresu (15.10).');
        $this->assertStringNotContainsString('aria-invalid="true"', $this->sekcja($html, '2026-10-15'));
    }

    public function test_blad_dodania_z_wynikow_nie_trafia_do_dnia_z_adresu(): void
    {
        $user = $this->user('osoba15');
        // Dzień poza zakresem planera: akcja rzuca błędem pola `day`. Formularz
        // wyniku wysłał dzień 2000-01-03, a adres strony wskazuje 15.10 —
        // komunikat NIE może wylądować przy polu wyszukiwania 15.10.
        $przepis = $this->przepis($this->user('osoba16'), 'Sernik zakresowy');
        $referer = route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-15', 'q' => 'sernik']);

        // Bez `assertSessionHasErrors`: pomocnik startuje sesję ponownie
        // i zjada błędy, zanim zobaczy je następne żądanie.
        $this->actingAs($user)
            ->from($referer)
            ->post(route('planer.store'), [
                'day' => '2000-01-03', 'recipe_id' => $przepis->getKey(), 'z_planera' => 1, 'q' => 'sernik',
            ])
            ->assertRedirect($referer);

        $html = $this->actingAs($user)->get($referer)->assertOk()->getContent();
        $this->assertStringContainsString('Sernik zakresowy', $this->sekcja($html, '2026-10-15'), 'Panel dnia z adresu powinien działać.');
        $this->assertStringNotContainsString('Wybierz dzień w zakresie planera', $this->sekcja($html, '2026-10-15'));
        $this->assertStringContainsString('error-summary', $html, 'Błąd nadal ma być w podsumowaniu na górze.');
    }

    /**
     * Każdy link z podsumowania błędów ma trafiać w istniejące `id` na
     * wyrenderowanej stronie (audyt UX 50+: `#f-day` / `#f-q` nie istniały).
     *
     * @return list<string> cele linków
     */
    private function celeLinkowPodsumowania(string $html): array
    {
        $this->assertSame(1, preg_match('~<div class="error-summary".*?</ul>~s', $html, $blok), 'Brak podsumowania błędów.');
        preg_match_all('~<a href="#([^"]+)"~', $blok[0], $m);
        $this->assertNotEmpty($m[1], 'Podsumowanie bez linków.');
        foreach ($m[1] as $cel) {
            $this->assertSame(1, preg_match('~\sid="'.preg_quote($cel, '~').'"~', $html), "Link z podsumowania prowadzi do nieistniejącego #{$cel}.");
        }

        return $m[1];
    }

    public function test_linki_podsumowania_bledow_planera_trafiaja_w_istniejace_pola(): void
    {
        $user = $this->user('osoba30');
        $przepis = $this->przepis($this->user('osoba31'), 'Sernik linkowy');
        $referer = route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-15', 'q' => 'sernik']);

        // 1. Dzień z wyników spoza zakresu planera (błąd `day`).
        $this->actingAs($user)->from($referer)->post(route('planer.store'), [
            'day' => '2000-01-03', 'recipe_id' => $przepis->getKey(), 'z_planera' => 1, 'q' => 'sernik',
        ]);
        $html = $this->actingAs($user)->get($referer)->assertOk()->getContent();
        $this->assertContains('q-2026-10-15', $this->celeLinkowPodsumowania($html));

        // 2. Za długa fraza z wyników dnia 14.10 (błąd `q`) — pole TEGO dnia.
        $this->actingAs($user)->from($referer)->post(route('planer.store'), [
            'day' => '2026-10-14', 'recipe_id' => $przepis->getKey(), 'z_planera' => 1, 'q' => str_repeat('a', 500),
        ]);
        $html = $this->actingAs($user)->get($referer)->assertOk()->getContent();
        $this->assertSame(['q-2026-10-14'], array_values(array_unique($this->celeLinkowPodsumowania($html))));

        // 3. Pusty wpis „Dopisz coś własnego” (błąd `label`).
        $this->actingAs($user)->from($referer)->post(route('planer.store'), ['day' => '2026-10-14', 'label' => '   ', '_wiersz' => '2026-10-14']);
        $html = $this->actingAs($user)->get($referer)->assertOk()->getContent();
        $this->assertSame(['f-label-2026-10-14'], $this->celeLinkowPodsumowania($html));
    }

    public function test_dodanie_pokazuje_jeden_komunikat_sukcesu(): void
    {
        $user = $this->user('osoba17');
        $przepis = $this->przepis($this->user('osoba18'), 'Sernik jedyny');

        $html = $this->actingAs($user)
            ->followingRedirects()
            ->post(route('planer.store'), ['day' => '2026-10-14', 'recipe_id' => $przepis->getKey(), 'z_planera' => 1, 'q' => 'sernik'])
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Dodane do planu na'), 'Komunikat sukcesu pokazany więcej niż raz.');
    }

    public function test_limit_zapytan_odczytu_planera_ma_wlasny_koszyk_i_bez_martwego_punktu(): void
    {
        $user = $this->user('osoba19');
        [$limit] = array_map('intval', explode(',', (string) config('kuking.limits.planer_szukaj')));
        $adres = route('planer.show', ['tydzien' => '2026-10-12', 'dzien' => '2026-10-14', 'q' => 'zupa']);

        for ($i = 0; $i < $limit; $i++) {
            $this->assertNotSame(429, $this->actingAs($user)->get($adres)->getStatusCode(), "Żądanie {$i} w granicach limitu dostało 429.");
        }

        $odbicie = $this->actingAs($user)->get($adres);
        $odbicie->assertStatus(429);
        $odbicie->assertSee('Za dużo prób');
        $odbicie->assertSee('Spróbuj ponownie za');
        // D-053: ekran nie jest ślepą uliczką — jest przycisk, który dokądś prowadzi.
        $odbicie->assertSee('Strona główna');
        $odbicie->assertSee('href="'.route('home').'"', false);

        // Zapisy planera mają inny koszyk: odbicie odczytu ich nie blokuje.
        $this->actingAs($user)->post(route('planer.store'), ['day' => '2026-10-14', 'label' => 'Obiad'])
            ->assertRedirect();
        $this->assertSame(1, MealPlanEntry::query()->count());
    }
}
