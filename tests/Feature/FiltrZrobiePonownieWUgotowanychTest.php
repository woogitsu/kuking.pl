<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prywatny wybór „Zrobię ponownie” na WŁASNEJ zakładce „Ugotowane”
 * (issue #2460, decyzja właściciela z 2.10.2026, D-333).
 *
 * Wybór jest domyślnie wyłączony i zawęża już autoryzowaną listę do wykonań
 * z `would_make_again = true` (nie `false`, nie `null`). Wybiera WYKONANIA,
 * nie przepisy: późniejsze `false`/`null` tego samego przepisu nie usuwa
 * wcześniejszego `true`. Cudzy profil, gość i moderator dostają to samo co
 * bez parametru.
 */
class FiltrZrobiePonownieWUgotowanychTest extends TestCase
{
    use RefreshDatabase;

    public function test_domyslnie_lista_jest_pelna_a_wybor_wylaczony_i_opisany(): void
    {
        $kucharz = $this->user('kucharz');
        $przepis = $this->przepis('Zupa');
        $tak = $this->wykonanie($kucharz, $przepis, true, now()->subDays(3));
        $nie = $this->wykonanie($kucharz, $przepis, false, now()->subDays(2));
        $brak = $this->wykonanie($kucharz, $przepis, null, now()->subDay());

        $html = $this->actingAs($kucharz)->get($this->adres())->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame([$brak->getKey(), $nie->getKey(), $tak->getKey()], $this->karty($html));
        $this->assertMatchesRegularExpression('/<input id="f-ponownie-ugotowane" type="checkbox" name="ponownie" value="1"(?![^>]*checked)/', $html);
        $this->assertStringContainsString('Tylko wykonania, przy których zaznaczono „Zrobię ponownie”', $html);
        $this->assertStringContainsString('f-ponownie-ugotowane-help', $html);
        $this->assertStringNotContainsString('data-wyniki-ponownie', $html);
    }

    public function test_wlaczony_wybor_zostawia_tylko_true_a_nie_false_ani_brak_odpowiedzi(): void
    {
        $kucharz = $this->user('kucharz');
        $przepis = $this->przepis('Zupa');
        $tak = $this->wykonanie($kucharz, $przepis, true, now()->subDays(3));
        $this->wykonanie($kucharz, $przepis, false, now()->subDays(2));
        $this->wykonanie($kucharz, $przepis, null, now()->subDay());

        $html = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1']))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame([$tak->getKey()], $this->karty($html));
        $this->assertMatchesRegularExpression('/<input id="f-ponownie-ugotowane" type="checkbox"[^>]*checked/', $html);
        $this->assertStringContainsString('data-wyniki-ponownie', $html);
        $this->assertStringContainsString('Znaleźliśmy 1 wykonanie', $html);
        $this->assertStringContainsString('Wyłącz ten wybór', $html);
        $this->assertStringContainsString('Wyczyść wszystkie filtry', $html);
    }

    public function test_wiele_wykonan_tego_samego_przepisu_to_osobne_karty_a_pozniejsze_nie_kasuje_wczesniejszego_tak(): void
    {
        $kucharz = $this->user('kucharz');
        $przepis = $this->przepis('Bigos');
        $wczesniej = $this->wykonanie($kucharz, $przepis, true, now()->subDays(10));
        $pozniej = $this->wykonanie($kucharz, $przepis, true, now()->subDays(5));
        $this->wykonanie($kucharz, $przepis, false, now()->subDay());

        $html = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1']))->getContent();

        $this->assertIsString($html);
        $this->assertSame([$pozniej->getKey(), $wczesniej->getKey()], $this->karty($html));
    }

    public function test_laczy_sie_z_fraza_tytulu_i_zachowuje_chronologie_z_remisem_po_id(): void
    {
        $kucharz = $this->user('kucharz');
        $zurek = $this->przepis('Żurek');
        $sernik = $this->przepis('Sernik');
        $remis = now()->subDays(2)->startOfMinute();

        $a = $this->wykonanie($kucharz, $zurek, true, $remis);
        $b = $this->wykonanie($kucharz, $zurek, true, $remis);
        $this->wykonanie($kucharz, $zurek, false, $remis);
        $this->wykonanie($kucharz, $sernik, true, now()->subDay());

        $html = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1', 'szukaj' => 'zurek']))->getContent();

        $this->assertIsString($html);
        $this->assertSame([$b->getKey(), $a->getKey()], $this->karty($html), 'remis cooked_at rozstrzyga id malejąco');
        $this->assertStringContainsString('i frazą „zurek”', $html);
    }

    public function test_pusty_wynik_nie_udaje_pustego_archiwum_ani_braku_przepisu(): void
    {
        $kucharz = $this->user('kucharz');
        $this->wykonanie($kucharz, $this->przepis('Zupa'), false, now()->subDay());

        $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1']))
            ->assertOk()
            ->assertSee('nie ma zaznaczonego „Zrobię ponownie”', false)
            ->assertSee('odznacz ten wybór', false)
            ->assertDontSee('Nie masz jeszcze żadnego wykonania')
            ->assertSee('Wyłącz ten wybór');
    }

    public function test_wylaczenie_wyboru_zachowuje_fraze_a_wyczyszczenie_zdejmuje_wszystko(): void
    {
        $kucharz = $this->user('kucharz');
        $this->wykonanie($kucharz, $this->przepis('Zupa'), true, now()->subDay());

        $html = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1', 'szukaj' => 'zupa']))->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/<a class="btn btn-secondary" href="'.preg_quote(e($this->adres(['szukaj' => 'zupa'])), '/').'">Wyłącz ten wybór<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<a class="btn btn-secondary" href="'.preg_quote(e($this->adres()), '/').'">Wyczyść wszystkie filtry<\/a>/', $html);
    }

    public function test_pokaz_wiecej_niesie_wybor_a_zmiana_wyboru_wraca_na_pierwsza_strone(): void
    {
        $kucharz = $this->user('kucharz');
        $przepis = $this->przepis('Zupa');
        $oczekiwane = [];

        for ($i = 0; $i < 14; $i++) {
            $oczekiwane[] = $this->wykonanie($kucharz, $przepis, true, now()->subHours($i + 1))->getKey();
            $this->wykonanie($kucharz, $przepis, false, now()->subHours($i + 1)->subMinutes(30));
        }

        $strona1 = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1']))->getContent();
        $this->assertIsString($strona1);
        $this->assertCount(12, $this->karty($strona1));
        $this->assertMatchesRegularExpression('/href="[^"]*ponownie=1[^"]*"[^>]*>Następna strona wykonań/', $strona1);
        preg_match('/href="([^"]+)"[^>]*>Następna strona wykonań/', $strona1, $m);
        $strona2 = $this->actingAs($kucharz)->get(html_entity_decode($m[1]))->getContent();
        $this->assertIsString($strona2);

        $wszystkie = array_merge($this->karty($strona1), $this->karty($strona2));
        $this->assertSame($oczekiwane, $wszystkie);

        // Formularz GET nie niesie numeru strony — zmiana wyboru zaczyna od pierwszej.
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*role="search"[^>]*>(?:(?!<\/form>).)*name="page"/s', $strona2);
    }

    public function test_cudzy_profil_gosc_i_moderator_dostaja_to_samo_z_parametrem_i_bez(): void
    {
        $kucharz = $this->user('kucharz');
        $obcy = $this->user('obcy');
        $moderator = $this->moderator();
        $przepis = $this->przepis('Zupa');
        $this->wykonanie($kucharz, $przepis, true, now()->subDays(3));
        $this->wykonanie($kucharz, $przepis, false, now()->subDays(2));
        $this->wykonanie($kucharz, $przepis, null, now()->subDay());

        foreach ([$obcy, $moderator, null] as $widz) {
            $zadanie = $widz === null ? $this : $this->actingAs($widz);
            $bez = $zadanie->get($this->adres())->assertOk()->getContent();
            $zParametrem = $zadanie->get($this->adres(['ponownie' => '1']))->assertOk()->getContent();

            $this->assertIsString($bez);
            $this->assertIsString($zParametrem);
            $this->assertCount(3, $this->karty($zParametrem), 'parametr nie zawęża cudzej listy');
            $this->assertSame($this->karty($bez), $this->karty($zParametrem));
            $this->assertStringNotContainsString('Zrobię ponownie”', explode('<main', $zParametrem)[1] ?? '', 'brak pola i komunikatów filtra');
            $this->assertStringNotContainsString('data-wyniki-ponownie', $zParametrem);
        }
    }

    public function test_dziwne_wartosci_nie_wlaczaja_wyboru_i_nie_daja_500(): void
    {
        $kucharz = $this->user('kucharz');
        $przepis = $this->przepis('Zupa');
        $this->wykonanie($kucharz, $przepis, true, now()->subDays(2));
        $this->wykonanie($kucharz, $przepis, false, now()->subDay());

        foreach (['ponownie[]=1', 'ponownie[a]=1', 'ponownie=0', 'ponownie=', 'ponownie=true', 'ponownie=tak'] as $zapytanie) {
            $html = $this->actingAs($kucharz)
                ->get('/@kucharz?zakladka=ugotowane&'.$zapytanie)
                ->assertOk()->getContent();

            $this->assertIsString($html);
            $this->assertCount(2, $this->karty($html), $zapytanie);
            $this->assertStringNotContainsString('data-wyniki-ponownie', $html, $zapytanie);
        }
    }

    public function test_nie_odslania_tytulu_za_blokada_przez_laczenie_z_fraza(): void
    {
        $kucharz = $this->user('kucharz');
        $autor = $this->user('autor');
        $ukryty = $this->przepis('Tajemniczy gulasz', ['author_id' => $autor->getKey()]);
        $wykonanie = $this->wykonanie($kucharz, $ukryty, true, now()->subDay());

        // Kontrola dodatnia: przed blokadą fraza z wyborem znajduje wykonanie.
        $przed = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1', 'szukaj' => 'gulasz']))->getContent();
        $this->assertIsString($przed);
        $this->assertSame([$wykonanie->getKey()], $this->karty($przed));

        DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $kucharz->getKey(), 'created_at' => now()]);

        $html = $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1', 'szukaj' => 'gulasz']))->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('Tajemniczy gulasz', $html);
        $this->assertCount(0, $this->karty($html), 'fraza nie dopasowuje tytułu, który karta chowa');
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_kart(): void
    {
        $kucharz = $this->user('kucharz');
        $przepis = $this->przepis('Zupa');
        $this->wykonanie($kucharz, $przepis, true, now()->subDays(1));

        $zlicz = function () use ($kucharz): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($kucharz)->get($this->adres(['ponownie' => '1']))->assertOk();

            return count(DB::getQueryLog());
        };

        $jedna = $zlicz();

        for ($i = 0; $i < 8; $i++) {
            $this->wykonanie($kucharz, $this->przepis('Danie '.$i), true, now()->subHours($i + 2));
        }

        $this->assertSame($jedna, $zlicz(), 'liczba zapytań zależy od liczby kart (N+1)');
    }

    /**
     * @param  array<string, string>  $dodatkowe
     */
    private function adres(array $dodatkowe = []): string
    {
        return route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane'] + $dodatkowe);
    }

    /**
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepis(string $tytul, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create($atrybuty + [
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);
    }

    private function wykonanie(User $kucharz, Recipe $przepis, ?bool $ponownie, \DateTimeInterface $kiedy): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'would_make_again' => $ponownie,
            'cooked_at' => $kiedy,
        ]);
    }

    /**
     * @return list<string>
     */
    private function karty(string $html): array
    {
        preg_match_all('/data-klucz="wykonanie-([0-9a-f-]+)"/', $html, $m);

        return $m[1];
    }
}
