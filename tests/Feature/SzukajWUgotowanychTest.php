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
 * „Szukaj w moich wykonaniach” na WŁASNEJ zakładce „Ugotowane” (issue #2070).
 *
 * Właściciel profilu zawęża swoje wykonania do tych z przepisu o danym
 * tytule. Ta sama normalizacja co reszta serwisu (`title_search`,
 * `FrazaWyszukiwania`), ten sam porządek i ta sama paginacja co lista bez
 * frazy. Cudzy profil się nie zmienia: nie ma pola, a `?szukaj=` w adresie
 * niczego nie filtruje.
 *
 * Fraza pasuje WYŁĄCZNIE do tytułu, który karta i tak pokazuje. Przepis
 * autora za blokadą (karta chowa tytuł, #1394) i przepis usunięty (karta
 * mówi „już nie ma”) nie pasują do niczego — inaczej wyszukiwarka byłaby
 * wyrocznią zgadującą tytuł, którego ekran celowo nie pokazuje.
 *
 * Każda scena ujemna ma obok kontrolę dodatnią (PULAPKI_TESTOW §4).
 */
class SzukajWUgotowanychTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_znajduje_wykonania_po_tytule_z_ogonkami_i_bez(): void
    {
        $kucharz = $this->user('kucharz');
        $zurek = $this->przepis('Żurek babci Heleny');
        $sernik = $this->przepis('Sernik na zimno');

        $zurekStare = $this->wykonanie($kucharz, $zurek, now()->subDays(40));
        $zurekNowe = $this->wykonanie($kucharz, $zurek, now()->subDays(2));
        $sernikWykonanie = $this->wykonanie($kucharz, $sernik, now()->subDay());

        foreach (['zurek', 'Żurek', 'ŻUREK', 'babci hel'] as $fraza) {
            $html = $this->actingAs($kucharz)->get($this->adres($fraza))->assertOk()->getContent();

            $this->assertSame(
                [$zurekNowe->getKey(), $zurekStare->getKey()],
                $this->kartyNaStronie($html),
                "Fraza „{$fraza}” nie dała obu wykonań żurku w kolejności od najnowszego.",
            );
            $this->assertStringNotContainsString('wykonanie-'.$sernikWykonanie->getKey(), $html);
            $this->assertStringContainsString('value="'.e($fraza).'"', $html);
            $this->assertStringContainsString('Wyniki dla „'.e($fraza).'”', $html);
        }

        // Kontrola: bez frazy lista jest pełna, a pole wyszukiwania stoi z etykietą.
        $this->actingAs($kucharz)->get($this->adres())
            ->assertOk()
            ->assertSee('<label for="f-szukaj-ugotowane">Szukaj w moich wykonaniach</label>', false)
            ->assertSee('wykonanie-'.$sernikWykonanie->getKey(), false)
            ->assertSee('wykonanie-'.$zurekStare->getKey(), false)
            ->assertDontSee('Wyniki dla', false);
    }

    public function test_brak_wynikow_mowi_co_zrobic_i_prowadzi_do_wszystkich_wykonan(): void
    {
        $kucharz = $this->user('kucharz');
        $sernik = $this->wykonanie($kucharz, $this->przepis('Sernik na zimno'), now()->subDay());

        $this->actingAs($kucharz)->get($this->adres('pierogi'))
            ->assertOk()
            ->assertSee('Nie znaleźliśmy wśród Twoich wykonań przepisu, który ma w tytule „pierogi”.', false)
            ->assertSee('Spróbuj krótszego kawałka tytułu.')
            ->assertSee('value="pierogi"', false)
            ->assertDontSee('wykonanie-'.$sernik->getKey(), false)
            // Brak dopasowań to nie pusty profil — bez „Nie masz jeszcze żadnego wykonania”.
            ->assertDontSee('Nie masz jeszcze żadnego wykonania');

        // Droga powrotu to przycisk w sekcji wyników — ten sam adres ma też
        // zakładka „Ugotowane” wyżej, więc sprawdzamy odnośnik z napisem.
        $html = $this->actingAs($kucharz)->get($this->adres('pierogi'))->getContent();
        $this->assertMatchesRegularExpression('/<a class="btn btn-secondary" href="'.preg_quote(e($this->adres()), '/').'">Pokaż wszystkie wykonania<\/a>/', $html);
    }

    public function test_pokaz_wiecej_niesie_fraze_i_nie_gubi_ani_nie_dubluje_wykonan_przy_remisach(): void
    {
        $kucharz = $this->user('kucharz');
        $zurek = $this->przepis('Żurek');
        $sernik = $this->przepis('Sernik');
        $remis = now()->subDays(3)->startOfMinute();

        $oczekiwane = [];
        for ($i = 0; $i < 14; $i++) {
            // Co drugie wykonanie w tej samej chwili — remis rozstrzyga `id DESC`.
            $oczekiwane[] = $this->wykonanie($kucharz, $zurek, $i % 2 === 0 ? $remis : $remis->copy()->subDays($i))->getKey();
            $this->wykonanie($kucharz, $sernik, $remis->copy()->subHours($i));
        }

        $strona1 = $this->actingAs($kucharz)->get($this->adres('zurek'))->assertOk()->getContent();
        $karty1 = $this->kartyNaStronie($strona1);
        $this->assertCount(12, $karty1);

        $this->assertMatchesRegularExpression('/href="[^"]*szukaj=zurek[^"]*"[^>]*>Następna strona wykonań/', $strona1);
        preg_match('/href="([^"]+)"[^>]*>Następna strona wykonań/', $strona1, $m);
        $nastepna = html_entity_decode($m[1]);
        $this->assertStringContainsString('page=2', $nastepna);

        $strona2 = $this->actingAs($kucharz)->get($nastepna)->assertOk()->getContent();
        $karty2 = $this->kartyNaStronie($strona2);
        $this->assertCount(2, $karty2);
        $this->assertStringContainsString('value="zurek"', $strona2);

        $wszystkie = array_merge($karty1, $karty2);
        $this->assertSame(count($wszystkie), count(array_unique($wszystkie)), 'To samo wykonanie na dwóch stronach.');
        sort($wszystkie);
        sort($oczekiwane);
        $this->assertSame($oczekiwane, $wszystkie, 'Druga strona zgubiła frazę albo wykonanie.');
    }

    public function test_cudzy_profil_nie_ma_pola_a_fraza_w_adresie_niczego_nie_filtruje_ani_nie_odslania(): void
    {
        $kucharz = $this->user('kucharz');
        $obcy = $this->user('obcy');
        $publiczny = $this->wykonanie($kucharz, $this->przepis('Sernik na zimno'), now()->subDay());
        $prywatny = $this->wykonanie($kucharz, $this->przepis('Nalewka tajemna', ['visibility' => 'private']), now()->subDays(2));

        foreach ([$obcy, null] as $widz) {
            $zadanie = $widz ? $this->actingAs($widz) : $this;
            $html = $zadanie->get($this->adres('nalewka'))->assertOk()->getContent();

            $this->assertStringNotContainsString('Szukaj w moich wykonaniach', $html);
            $this->assertStringNotContainsString('Wyniki dla', $html);
            $this->assertStringNotContainsString('Nalewka tajemna', $html);
            $this->assertStringNotContainsString('wykonanie-'.$prywatny->getKey(), $html);
            // Lista jest taka sama jak bez frazy — fraza jej nie zawęziła.
            $this->assertStringContainsString('wykonanie-'.$publiczny->getKey(), $html);
        }

        // Kontrola dodatnia: właściciel ten sam adres widzi jako wynik.
        $html = $this->actingAs($kucharz)->get($this->adres('nalewka'))->getContent();
        $this->assertSame([$prywatny->getKey()], $this->kartyNaStronie($html));
    }

    public function test_przepis_za_blokada_ani_usuniety_nie_pasuja_do_frazy_ale_zostaja_na_pelnej_liscie(): void
    {
        $kucharz = $this->user('kucharz');
        $autor = $this->user('autor');
        $bigos = $this->wykonanie($kucharz, $this->przepis('Bigos myśliwski', ['author_id' => $autor->getKey()]), now()->subDay());
        $nalewkaPrzepis = $this->przepis('Nalewka wiśniowa');
        $nalewka = $this->wykonanie($kucharz, $nalewkaPrzepis, now()->subDays(2));
        $ukryty = $this->wykonanie($kucharz, $this->przepis('Pierogi ruskie', ['status' => 'hidden']), now()->subDays(3));

        // Kontrola dodatnia przed zmianą.
        $this->assertSame([$bigos->getKey()], $this->kartyNaStronie($this->actingAs($kucharz)->get($this->adres('bigos'))->getContent()));
        $this->assertSame([$nalewka->getKey()], $this->kartyNaStronie($this->actingAs($kucharz)->get($this->adres('nalewka'))->getContent()));
        // Przepis ukryty przez moderację: karta i tak pokazuje tytuł (#766),
        // więc fraza go znajduje — nic, czego ekran nie mówi.
        $this->assertSame([$ukryty->getKey()], $this->kartyNaStronie($this->actingAs($kucharz)->get($this->adres('pierogi'))->getContent()));

        DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $kucharz->getKey(), 'created_at' => now()]);
        $nalewkaPrzepis->delete();

        $this->assertSame([], $this->kartyNaStronie($this->actingAs($kucharz)->get($this->adres('bigos'))->assertOk()->getContent()));
        $this->assertSame([], $this->kartyNaStronie($this->actingAs($kucharz)->get($this->adres('nalewka'))->assertOk()->getContent()));

        // Pełna lista się nie zmieniła: obie karty dalej są (#1394, A23).
        $pelna = $this->actingAs($kucharz)->get($this->adres())->getContent();
        $this->assertContains($bigos->getKey(), $this->kartyNaStronie($pelna));
        $this->assertContains($nalewka->getKey(), $this->kartyNaStronie($pelna));
        $this->assertStringNotContainsString('Bigos myśliwski', $pelna);
    }

    public function test_metaznaki_sa_tekstem_a_za_krotka_lub_za_dluga_fraza_mowi_co_zrobic(): void
    {
        $kucharz = $this->user('kucharz');
        $chleb = $this->wykonanie($kucharz, $this->przepis('Chleb razowy'), now()->subDay());

        foreach (['%%', '__'] as $metaznaki) {
            $html = $this->actingAs($kucharz)->get($this->adres($metaznaki))->assertOk()->getContent();
            $this->assertSame([], $this->kartyNaStronie($html), "Fraza „{$metaznaki}” zadziałała jak wzorzec LIKE.");
        }

        $this->actingAs($kucharz)->get($this->adres('a'))
            ->assertOk()
            ->assertSee('Wpisz co najmniej dwie litery z tytułu przepisu.')
            ->assertSee('value="a"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('Wyniki dla', false)
            // Błędna fraza nie chowa listy.
            ->assertSee('wykonanie-'.$chleb->getKey(), false);

        $this->actingAs($kucharz)->get($this->adres(str_repeat('ż', 121)))
            ->assertOk()
            ->assertSee('Skróć tekst w polu „Szukaj w moich wykonaniach” do 120 znaków i spróbuj ponownie.', false)
            ->assertSee('wykonanie-'.$chleb->getKey(), false);
    }

    public function test_bez_zadnego_wykonania_nie_ma_pola_tylko_droga_do_przepisow(): void
    {
        $kucharz = $this->user('kucharz');

        $this->actingAs($kucharz)->get($this->adres())
            ->assertOk()
            ->assertSee('Nie masz jeszcze żadnego wykonania')
            ->assertDontSee('Szukaj w moich wykonaniach');
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wynikow(): void
    {
        $licznik = 0;
        DB::listen(function () use (&$licznik): void {
            $licznik++;
        });

        $zapytania = [];
        foreach ([2, 12] as $ile) {
            $kucharz = $this->user('kucharz'.$ile);
            $przepis = $this->przepis('Żurek '.$ile);
            for ($i = 0; $i < $ile; $i++) {
                $this->wykonanie($kucharz, $przepis, now()->subMinutes($i));
            }

            $licznik = 0;
            $html = $this->actingAs($kucharz)->get($this->adres('zurek', 'kucharz'.$ile))->assertOk()->getContent();
            $zapytania[$ile] = $licznik;
            $this->assertCount($ile, $this->kartyNaStronie($html));
        }

        $this->assertSame($zapytania[2], $zapytania[12], "Zapytania: {$zapytania[2]} przy 2 wynikach, {$zapytania[12]} przy 12.");
    }

    private function adres(?string $fraza = null, string $kto = 'kucharz'): string
    {
        return route('profile.show', array_filter([
            'username' => $kto,
            'zakladka' => 'ugotowane',
            'szukaj' => $fraza,
        ], fn ($v) => $v !== null));
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

    private function wykonanie(User $kucharz, Recipe $przepis, \DateTimeInterface $kiedy): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => $kiedy,
        ]);
    }

    /**
     * Identyfikatory kart wykonań w kolejności z ekranu.
     *
     * @return list<string>
     */
    private function kartyNaStronie(string $html): array
    {
        preg_match_all('/data-klucz="wykonanie-([0-9a-f-]+)"/', $html, $m);

        return $m[1];
    }
}
